<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt to view_annual_plan.php: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher exists
$teacher_sql = "SELECT id, name FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Teacher not found for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}

// Get plan ID and term from URL
$plan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? 0;
$term = filter_input(INPUT_GET, 'term', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 0;
if ($plan_id <= 0 || !in_array($term, [1, 2, 3])) {
    error_log("Invalid plan ID ($plan_id) or term ($term) in view_annual_plan.php");
    $_SESSION['error_msg'] = "Invalid plan or term specified.";
    header("Location: /teacher/annual_plan.php");
    exit();
}

// Fetch plan details
$plan_sql = "SELECT ap.id, ap.week, ap.term_1, ap.term_2, ap.term_3, ap.class_id, ap.subject_id, ap.department_id, 
                    ap.created_at, ap.updated_at, c.class_name, s.subject_name, d.name AS department_name
             FROM annual_plans ap
             JOIN classes c ON ap.class_id = c.id
             JOIN subjects s ON ap.subject_id = s.id
             JOIN departments d ON ap.department_id = d.id
             WHERE ap.id = ? AND ap.teacher_id = ?";
$plan_stmt = $conn->prepare($plan_sql);
$plan_stmt->bind_param("ii", $plan_id, $teacher_id);
$plan_stmt->execute();
$plan = $plan_stmt->get_result()->fetch_assoc();
$plan_stmt->close();

if (!$plan) {
    error_log("Plan not found or unauthorized access for plan_id: $plan_id, teacher_id: $teacher_id");
    $_SESSION['error_msg'] = "Annual plan not found or you do not have permission to view it.";
    header("Location: /teacher/annual_plan.php");
    exit();
}

// Determine which term to display
$term_field = "term_$term";
$term_content = $plan[$term_field] ?? '';
$term_name = "Term $term";

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at)
            VALUES (?, 'Viewed Annual Plan', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher viewed annual plan ID: $plan_id for $term_name";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log page access: " . $log_stmt->error);
}
$log_stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

// Handle form submission for deletion
$errors = [];
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE) ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $posted_csrf)) {
        $errors[] = "Invalid CSRF token. Please try again.";
    } else {
        $csrf_check_sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
        $csrf_check_stmt = $conn->prepare($csrf_check_sql);
        $csrf_check_stmt->bind_param("is", $teacher_id, $posted_csrf);
        $csrf_check_stmt->execute();
        $csrf_valid = $csrf_check_stmt->get_result()->num_rows > 0;
        $csrf_check_stmt->close();

        if (!$csrf_valid) {
            $errors[] = "Invalid or expired CSRF token.";
        }
    }

    if (empty($errors)) {
        $action = filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW) ?? '';
        if ($action === 'delete') {
            $conn->begin_transaction();
            try {
                $delete_sql = "DELETE FROM annual_plans WHERE id = ? AND teacher_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
                if ($delete_stmt->execute()) {
                    $log_desc = "Teacher deleted annual plan ID: $plan_id";
                    $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at)
                                                VALUES (?, 'Deleted Annual Plan', ?, ?, ?, NOW())");
                    $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                    if (!$log_stmt->execute()) {
                        error_log("Failed to log annual plan deletion: " . $log_stmt->error);
                    }
                    $log_stmt->close();

                    $success_msg = "Annual plan deleted successfully!";
                    $conn->commit();
                    $_SESSION['success_msg'] = $success_msg;
                    header("Location: /teacher/annual_plan.php");
                    exit();
                } else {
                    $errors[] = "Failed to delete annual plan: " . $conn->error;
                    error_log("Annual plan delete error: " . $conn->error);
                    $conn->rollback();
                }
                $delete_stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Error deleting annual plan: " . $e->getMessage();
                error_log("Annual plan delete exception: " . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View annual plan details for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, annual plan, teacher portal, view plan">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School - View Annual Plan">
    <meta property="og:description" content="Teacher interface for viewing annual lesson plans in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/view_annual_plan.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School - View Annual Plan">
    <meta name="twitter:description" content="Teacher interface for viewing annual lesson plans in QueensPa School.">
    <title>View Annual Plan - Term <?php echo htmlspecialchars($term); ?> - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Bootstrap for tooltips and modals -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- W3CSS for animations -->
    <link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                        orbitron: ['Orbitron', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#fff7ed',
                            100: '#ffedd5',
                            200: '#fed7aa',
                            300: '#fdba74',
                            400: '#fb923c',
                            500: '#f97316',
                            600: '#ea580c',
                            700: '#c2410c',
                            800: '#9a3412',
                            900: '#7c2d12',
                        },
                        accent: {
                            500: '#1e3a8a',
                        },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out',
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'zoom-in': 'zoomIn 0.6s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'glow': 'glow 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(30px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        zoomIn: { '0%': { opacity: '0', transform: 'scale(0.95)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                        glow: {
                            '0%, 100%': { boxShadow: '0 0 5px rgba(249, 115, 22, 0.5)' },
                            '50%': { boxShadow: '0 0 20px rgba(249, 115, 22, 0.8)' },
                        },
                    },
                    backdropBlur: { xs: '2px' },
                },
            },
            darkMode: 'class',
        }
    </script>

    <style>
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Orbitron', sans-serif; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        .dark ::-webkit-scrollbar-track { background: #1f2937; }
        .dark ::-webkit-scrollbar-thumb { background: #f97316; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-orange-900 dark:bg-gradient-to-br dark:from-gray-900 dark:via-gray-800 dark:to-orange-900">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-80 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-orange-500 text-4xl animate-pulse-slow"></i>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="content" id="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-center mb-8 animate-fade-in">
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white text-center flex-grow mb-4 md:mb-0 animate-glow" aria-label="View Annual Plan">
                    <i class="fas fa-calendar-alt mr-2"></i> View Annual Plan - <?php echo htmlspecialchars($term_name); ?>
                </h1>
                <button class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism" id="theme-toggle" aria-label="Toggle dark mode">
                    <i class="fas fa-moon mr-2"></i> Toggle Theme
                </button>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="glassmorphism rounded-2xl shadow-lg mb-8 p-4 bg-red-500 bg-opacity-20 border border-red-400 text-red-200 animate-fade-in" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p class="mb-0"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="glassmorphism rounded-2xl shadow-lg mb-8 p-4 bg-green-500 bg-opacity-20 border border-green-400 text-green-200 animate-fade-in" role="alert">
                    <p class="mb-0"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?></p>
                </div>
            <?php endif; ?>

            <!-- Plan Details Section -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl flex justify-between items-center">
                    <h3 class="text-xl font-semibold mb-0">Annual Plan Details</h3>
                    <div class="flex gap-2">
                        <a href="/teacher/annual_plan.php?class_id=<?php echo htmlspecialchars($plan['class_id']); ?>&subject_id=<?php echo htmlspecialchars($plan['subject_id']); ?>&department_id=<?php echo htmlspecialchars($plan['department_id']); ?>" 
                           class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                           data-bs-toggle="tooltip" title="Edit Annual Plan">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this annual plan?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" data-bs-toggle="tooltip" title="Delete Annual Plan">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
                <div class="p-6 text-white">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm font-medium text-gray-200">Class</p>
                            <p class="text-lg font-semibold text-orange-300"><?php echo htmlspecialchars($plan['class_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-200">Subject</p>
                            <p class="text-lg font-semibold text-orange-300"><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-200">Department</p>
                            <p class="text-lg font-semibold text-orange-300"><?php echo htmlspecialchars($plan['department_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-200">Week</p>
                            <p class="text-lg font-semibold text-orange-300"><?php echo htmlspecialchars($plan['week']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-200">Created At</p>
                            <p class="text-lg font-semibold text-orange-300"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-200">Last Updated</p>
                            <p class="text-lg font-semibold text-orange-300"><?php echo htmlspecialchars($plan['updated_at'] ? date('Y-m-d H:i', strtotime($plan['updated_at'])) : 'N/A'); ?></p>
                        </div>
                    </div>
                    <hr class="my-6 border-gray-500">
                    <div>
                        <p class="text-sm font-medium text-gray-200"><?php echo htmlspecialchars($term_name); ?> Plan</p>
                        <div class="bg-gray-800 bg-opacity-50 p-4 rounded-lg mt-2">
                            <?php if (empty($term_content)): ?>
                                <p class="text-gray-400 italic">No plan content available for <?php echo htmlspecialchars($term_name); ?>.</p>
                            <?php else: ?>
                                <p class="text-white whitespace-pre-wrap"><?php echo htmlspecialchars($term_content); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-6 flex gap-3">
                        <?php for ($i = 1; $i <= 3; $i++): ?>
                            <?php if ($i != $term): ?>
                                <a href="/teacher/view_annual_plan.php?id=<?php echo htmlspecialchars($plan['id']); ?>&term=<?php echo $i; ?>" 
                                   class="bg-<?php echo $i == 1 ? 'blue' : ($i == 2 ? 'green' : 'purple'); ?>-500 hover:bg-<?php echo $i == 1 ? 'blue' : ($i == 2 ? 'green' : 'purple'); ?>-600 text-white px-4 py-2 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                   data-bs-toggle="tooltip" title="View Term <?php echo $i; ?> Plan">
                                    <i class="fas fa-eye"></i> View Term <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <a href="/teacher/annual_plan.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm transition-colors duration-300 glassmorphism">
                            <i class="fas fa-arrow-left"></i> Back to Plans
                        </a>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loadingOverlay = document.getElementById('loading-overlay');
            loadingOverlay.classList.add('hidden');

            // Dark mode toggle
            const themeToggle = document.getElementById('theme-toggle');
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark');
                themeToggle.innerHTML = '<i class="fas fa-sun mr-2"></i> Toggle Theme';
            }
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                const isDark = document.body.classList.contains('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                themeToggle.innerHTML = isDark ? '<i class="fas fa-sun mr-2"></i> Toggle Theme' : '<i class="fas fa-moon mr-2"></i> Toggle Theme';
            });

            // Tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

            // Auto-dismiss alerts after 5s
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>