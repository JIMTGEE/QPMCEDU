<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
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

// Validate teacher exists and fetch department
$teacher_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher'";
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
$teacher_department_id = $teacher['department_id'];

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Oxford Lesson Plan Dashboard', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed Oxford lesson plan dashboard";
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

// Handle delete actions
$errors = [];
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW) ?? '';
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
        $action = $_POST['action'] ?? '';
        $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
        if (!$plan_id || $plan_id <= 0) {
            $errors[] = "Invalid plan ID.";
        } else {
            $conn->begin_transaction();
            try {
                if ($action === 'delete_annual') {
                    $check_sql = "SELECT id FROM annual_plans WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $delete_sql = "DELETE FROM annual_plans WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $log_desc = "Teacher deleted Oxford annual plan ID: $plan_id";
                } elseif ($action === 'delete_termly') {
                    $check_sql = "SELECT id FROM termly_plans WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $delete_sql = "DELETE FROM termly_plans WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $log_desc = "Teacher deleted Oxford termly plan ID: $plan_id";
                } elseif ($action === 'delete_weekly') {
                    $check_sql = "SELECT id FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $delete_sql = "DELETE FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $log_desc = "Teacher deleted Oxford weekly plan ID: $plan_id";
                } else {
                    $errors[] = "Invalid action specified.";
                    $conn->rollback();
                    goto post_processing;
                }

                // Verify plan exists and belongs to teacher
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ii", $plan_id, $teacher_id);
                $check_stmt->execute();
                $plan_exists = $check_stmt->get_result()->num_rows > 0;
                $check_stmt->close();

                if (!$plan_exists) {
                    $errors[] = "Plan not found or you do not have permission to delete it.";
                    $conn->rollback();
                } else {
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
                    if ($delete_stmt->execute()) {
                        $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Deleted Plan', ?, ?, ?, NOW())");
                        $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                        if (!$log_stmt->execute()) {
                            error_log("Failed to log plan deletion: " . $log_stmt->error);
                        }
                        $log_stmt->close();
                        $success_msg = "Plan deleted successfully!";
                        $conn->commit();
                    } else {
                        $errors[] = "Failed to delete plan: " . $conn->error;
                        error_log("Plan delete error: " . $conn->error);
                        $conn->rollback();
                    }
                    $delete_stmt->close();
                }
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Error deleting plan: " . $e->getMessage();
                error_log("Plan delete exception: " . $e->getMessage());
            }
        }
    }
}

post_processing:
// Fetch annual plans (Oxford format)
$annual_sql = "SELECT ap.id, c.class_name, s.subject_name, ap.week, ap.created_at 
               FROM annual_plans ap
               JOIN classes c ON ap.class_id = c.id
               JOIN subjects s ON ap.subject_id = s.id
               WHERE ap.teacher_id = ? AND ap.department_id = ? AND ap.format = 'oxford'
               ORDER BY ap.created_at DESC LIMIT 100";
$annual_stmt = $conn->prepare($annual_sql);
if (!$annual_stmt) {
    error_log("Prepare failed for annual_plans query: " . $conn->error);
    $errors[] = "Failed to load annual plans due to a database error.";
    $annual_plans = [];
} else {
    $annual_stmt->bind_param("ii", $teacher_id, $teacher_department_id);
    $annual_stmt->execute();
    $annual_plans = $annual_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $annual_stmt->close();
}

// Fetch termly plans (Oxford format)
$termly_sql = "SELECT tp.id, c.class_name, s.subject_name, ac.term_name, tp.week, tp.created_at 
               FROM termly_plans tp
               JOIN classes c ON tp.class_id = c.id
               JOIN subjects s ON tp.subject_id = s.id
               JOIN academic_calendar ac ON tp.term_id = ac.id
               WHERE tp.teacher_id = ? AND tp.department_id = ? AND tp.format = 'oxford'
               ORDER BY tp.created_at DESC LIMIT 100";
$termly_stmt = $conn->prepare($termly_sql);
if (!$termly_stmt) {
    error_log("Prepare failed for termly_plans query: " . $conn->error);
    $errors[] = "Failed to load termly plans due to a database error.";
    $termly_plans = [];
} else {
    $termly_stmt->bind_param("ii", $teacher_id, $teacher_department_id);
    $termly_stmt->execute();
    $termly_plans = $termly_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $termly_stmt->close();
}

// Fetch weekly plans (Oxford format)
$weekly_sql = "SELECT tn.id, c.class_name, s.subject_name, ac.term_name, tn.week, tn.created_at 
              FROM teacher_notes tn 
              JOIN classes c ON tn.class_id = c.id
              LEFT JOIN subjects s ON tn.subject_id = s.id
              JOIN academic_calendar ac ON tn.term_id = ac.id 
              WHERE tn.teacher_id = ? AND tn.format = 'oxford'
              ORDER BY tn.created_at DESC LIMIT 100";
$weekly_stmt = $conn->prepare($weekly_sql);
if (!$weekly_stmt) {
    error_log("Prepare failed for teacher_notes query: " . $conn->error);
    $errors[] = "Failed to load weekly plans due to a database error.";
    $weekly_plans = [];
} else {
    $weekly_stmt->bind_param("i", $teacher_id);
    $weekly_stmt->execute();
    $weekly_plans = $weekly_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $weekly_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en" class="dark" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Oxford Lesson plan dashboard for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, lesson plan, teacher portal, Oxford format">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Oxford Lesson Plan Dashboard">
    <meta property="og:description" content="Teacher interface for managing lesson plans in Oxford format at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/lesson_plan_oxford.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Oxford Lesson Plan Dashboard">
    <meta name="twitter:description" content="Teacher interface for managing lesson plans in Oxford format at QueensPa School.">
    <title>Oxford Lesson Plan Dashboard - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(to bottom right, #111827, #000000);
            color: white;
            min-height: 100vh;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dark {
            --bg-color: #111827;
            --text-color: #ffffff;
            --card-bg: rgba(31, 41, 55, 0.8);
        }
        .light {
            --bg-color: #f3f4f6;
            --text-color: #000000;
            --card-bg: rgba(255, 255, 255, 0.9);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-900 to-black text-white min-h-screen">
    <div id="loading-overlay" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
        <i class="fas fa-spinner fa-spin text-orange-500 text-4xl"></i>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <main class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Header Section -->
        <header class="mb-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center space-y-4 md:space-y-0">
                <div>
                    <h1 class="text-3xl font-bold font-orbitron">Oxford Lesson Plan Dashboard</h1>
                    <p class="text-gray-400 mt-1">Manage your annual, termly, and weekly lesson plans in Oxford format</p>
                </div>
                <div class="flex items-center space-x-4">
                    <button id="theme-toggle" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-xl flex items-center transition-colors duration-300">
                        <i class="fas fa-moon mr-2"></i> Toggle Theme
                    </button>
                </div>
            </div>
        </header>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-900/50 border border-red-500 text-red-200 px-4 py-3 rounded-xl mb-6 animate-fade-in-up" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="bg-green-900/50 border border-green-500 text-green-200 px-4 py-3 rounded-xl mb-6 animate-fade-in-up" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <!-- Annual Plans Section -->
        <section class="mb-8 animate-fade-in-up">
            <div class="glassmorphism rounded-2xl overflow-hidden">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 p-4">
                    <h2 class="text-xl font-semibold font-orbitron">Annual Lesson Plans (Oxford)</h2>
                </div>
                <div class="p-6">
                    <a href="/teacher/annual_plan_oxford.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-6 py-3 rounded-xl font-medium inline-block mb-4 transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                        <i class="fas fa-plus mr-2"></i> Create New Annual Plan
                    </a>
                    <?php if (empty($annual_plans)): ?>
                        <div class="bg-gray-800/50 p-4 rounded-xl text-gray-300">No annual plans created yet.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead class="bg-gray-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">Class</th>
                                        <th class="px-4 py-3 text-left font-semibold">Subject</th>
                                        <th class="px-4 py-3 text-left font-semibold">Week</th>
                                        <th class="px-4 py-3 text-left font-semibold">Created At</th>
                                        <th class="px-4 py-3 text-left font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <?php foreach ($annual_plans as $plan): ?>
                                        <tr class="hover:bg-gray-700 transition-colors duration-200">
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['class_name']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['subject_name']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['week']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></td>
                                            <td class="px-4 py-3">
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this annual plan?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="delete_annual">
                                                    <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-lg transition-colors duration-300">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Termly Plans Section -->
        <section class="mb-8 animate-fade-in-up">
            <div class="glassmorphism rounded-2xl overflow-hidden">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 p-4">
                    <h2 class="text-xl font-semibold font-orbitron">Termly Lesson Plans (Oxford)</h2>
                </div>
                <div class="p-6">
                    <a href="/teacher/termly_plan_oxford.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-6 py-3 rounded-xl font-medium inline-block mb-4 transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                        <i class="fas fa-plus mr-2"></i> Create New Termly Plan
                    </a>
                    <?php if (empty($termly_plans)): ?>
                        <div class="bg-gray-800/50 p-4 rounded-xl text-gray-300">No termly plans created yet.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead class="bg-gray-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">Class</th>
                                        <th class="px-4 py-3 text-left font-semibold">Subject</th>
                                        <th class="px-4 py-3 text-left font-semibold">Term</th>
                                        <th class="px-4 py-3 text-left font-semibold">Week</th>
                                        <th class="px-4 py-3 text-left font-semibold">Created At</th>
                                        <th class="px-4 py-3 text-left font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <?php foreach ($termly_plans as $plan): ?>
                                        <tr class="hover:bg-gray-700 transition-colors duration-200">
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['class_name']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['subject_name']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['term_name']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['week']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></td>
                                            <td class="px-4 py-3">
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this termly plan?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="delete_termly">
                                                    <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-lg transition-colors duration-300">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Weekly Plans Section -->
        <section class="mb-8 animate-fade-in-up">
            <div class="glassmorphism rounded-2xl overflow-hidden">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 p-4">
                    <h2 class="text-xl font-semibold font-orbitron">Weekly Lesson Plans (Oxford)</h2>
                </div>
                <div class="p-6">
                    <a href="/teacher/weekly_plan_oxford.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-6 py-3 rounded-xl font-medium inline-block mb-4 transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                        <i class="fas fa-plus mr-2"></i> Create New Weekly Plan
                    </a>
                    <?php if (empty($weekly_plans)): ?>
                        <div class="bg-gray-800/50 p-4 rounded-xl text-gray-300">No weekly plans created yet.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead class="bg-gray-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">Class</th>
                                        <th class="px-4 py-3 text-left font-semibold">Subject</th>
                                        <th class="px-4 py-3 text-left font-semibold">Term</th>
                                        <th class="px-4 py-3 text-left font-semibold">Week</th>
                                        <th class="px-4 py-3 text-left font-semibold">Created At</th>
                                        <th class="px-4 py-3 text-left font-semibold">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-700">
                                    <?php foreach ($weekly_plans as $plan): ?>
                                        <tr class="hover:bg-gray-700 transition-colors duration-200">
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['class_name']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['term_name']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars($plan['week']); ?></td>
                                            <td class="px-4 py-3"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></td>
                                            <td class="px-4 py-3">
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this weekly plan?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="delete_weekly">
                                                    <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded-lg transition-colors duration-300">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    </main>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loadingOverlay = document.getElementById('loading-overlay');
            loadingOverlay.classList.add('hidden');

            // Dark mode toggle
            const themeToggle = document.getElementById('theme-toggle');
            if (localStorage.getItem('theme') === 'light') {
                document.documentElement.classList.remove('dark');
                document.body.classList.remove('bg-gradient-to-br', 'from-gray-900', 'to-black', 'text-white');
                document.body.classList.add('bg-gradient-to-br', 'from-gray-100', 'to-white', 'text-black');
                themeToggle.innerHTML = '<i class="fas fa-moon mr-2"></i> Toggle Theme';
            }
            themeToggle.addEventListener('click', () => {
                const html = document.documentElement;
                html.classList.toggle('dark');
                const isDark = html.classList.contains('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                if (isDark) {
                    document.body.classList.add('bg-gradient-to-br', 'from-gray-900', 'to-black', 'text-white');
                    document.body.classList.remove('bg-gradient-to-br', 'from-gray-100', 'to-white', 'text-black');
                    themeToggle.innerHTML = '<i class="fas fa-sun mr-2"></i> Toggle Theme';
                } else {
                    document.body.classList.add('bg-gradient-to-br', 'from-gray-100', 'to-white', 'text-black');
                    document.body.classList.remove('bg-gradient-to-br', 'from-gray-900', 'to-black', 'text-white');
                    themeToggle.innerHTML = '<i class="fas fa-moon mr-2"></i> Toggle Theme';
                }
            });

            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
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