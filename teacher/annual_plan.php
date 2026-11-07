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

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Annual Plan Page', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed annual plan page";
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

// Handle AJAX request for department classes
if (isset($_GET['action']) && $_GET['action'] === 'get_classes') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['classes' => [], 'error' => '', 'debug' => ''];

    if ($department_id <= 0) {
        $response['error'] = 'Invalid department ID.';
        $response['debug'] = 'Department ID: ' . $department_id;
    } else {
        $classes_sql = "SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name";
        $classes_stmt = $conn->prepare($classes_sql);
        $classes_stmt->bind_param("i", $department_id);
        if (!$classes_stmt->execute()) {
            $response['error'] = 'Query execution failed.';
            $response['debug'] = 'SQL Error: ' . $classes_stmt->error;
            error_log("Classes query execution error: " . $classes_stmt->error);
        } else {
            $classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $response['classes'] = $classes;
            $response['debug'] = 'Classes found for department ID: ' . $department_id;
        }
        $classes_stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle AJAX request for class subjects
if (isset($_GET['action']) && $_GET['action'] === 'get_subjects') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['subjects' => [], 'error' => '', 'debug' => ''];

    if ($department_id <= 0) {
        $response['error'] = 'Invalid department ID.';
        $response['debug'] = 'Department ID: ' . $department_id;
    } else {
        $subjects_sql = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name";
        $subjects_stmt = $conn->prepare($subjects_sql);
        $subjects_stmt->bind_param("i", $department_id);
        if (!$subjects_stmt->execute()) {
            $response['error'] = 'Query execution failed.';
            $response['debug'] = 'SQL Error: ' . $subjects_stmt->error;
            error_log("Subjects query execution error: " . $subjects_stmt->error);
        } else {
            $subjects = $subjects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $response['subjects'] = $subjects;
            $response['debug'] = 'Subjects found for department ID: ' . $department_id;
        }
        $subjects_stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Fetch academic years from academic_calendar
$years_sql = "SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC";
$years_stmt = $conn->prepare($years_sql);
$academic_years = [];
if ($years_stmt) {
    $years_stmt->execute();
    $years_result = $years_stmt->get_result();
    while ($row = $years_result->fetch_assoc()) {
        $academic_years[] = $row['year'];
    }
    $years_stmt->close();
} else {
    error_log("Prepare failed for academic years: " . $conn->error);
    $academic_years = [date('Y') . '/' . (date('Y') + 1)]; // Fallback
}

// Load existing plans for editing
$edit_mode = false;
$existing_plans = [];
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

if ($class_id > 0 && $subject_id > 0 && $department_id > 0) {
    $plan_sql = "SELECT id, week, term_1, term_2, term_3, class_id, subject_id
                 FROM annual_plans
                 WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND department_id = ?";
    $plan_stmt = $conn->prepare($plan_sql);
    $plan_stmt->bind_param("iiii", $teacher_id, $class_id, $subject_id, $department_id);
    $plan_stmt->execute();
    $result = $plan_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existing_plans[$row['week']] = $row;
    }
    $plan_stmt->close();
    $edit_mode = !empty($existing_plans);
}

// Fetch all departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments = $departments_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$departments_stmt->close();

// Fetch existing annual plans
$plans_sql = "SELECT ap.id, ap.week, ap.class_id, ap.subject_id, ap.department_id, c.class_name, s.subject_name, ap.created_at 
              FROM annual_plans ap 
              JOIN classes c ON ap.class_id = c.id 
              JOIN subjects s ON ap.subject_id = s.id 
              WHERE ap.teacher_id = ?
              ORDER BY ap.created_at DESC LIMIT 100";
$plans_stmt = $conn->prepare($plans_sql);
$plans_stmt->bind_param("i", $teacher_id);
$plans_stmt->execute();
$plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$plans_stmt->close();

// Handle form submission
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
        if ($action === 'submit') {
            $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $weeks = filter_input(INPUT_POST, 'weeks', FILTER_DEFAULT, FILTER_FORCE_ARRAY) ?? [];

            // Validate inputs
            if (!$department_id || $department_id <= 0) {
                $errors[] = "Invalid department selected.";
            }
            if (!$class_id || $class_id <= 0) {
                $errors[] = "Invalid class selected.";
            }
            if (!$subject_id || $subject_id <= 0) {
                $errors[] = "Invalid subject selected.";
            }
            if (empty($weeks) || !is_array($weeks)) {
                $errors[] = "No valid week data provided.";
            }

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    foreach ($weeks as $week => $data) {
                        $week = (int)$week;
                        if ($week < 1 || $week > 16) {
                            $errors[] = "Invalid week number for week $week.";
                            continue;
                        }

                        // Sanitize and validate week data
                        $term_1 = isset($data['term_1']) ? trim(filter_var($data['term_1'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : '';
                        $term_2 = isset($data['term_2']) ? trim(filter_var($data['term_2'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : '';
                        $term_3 = isset($data['term_3']) ? trim(filter_var($data['term_3'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : '';
                        $plan_id = isset($data['plan_id']) && is_numeric($data['plan_id']) ? (int)$data['plan_id'] : 0;

                        if (strlen($term_1) > 5000 || strlen($term_2) > 5000 || strlen($term_3) > 5000) {
                            $errors[] = "Term plan for week $week cannot exceed 5000 characters.";
                            continue;
                        }

                        // Skip empty rows for new plans
                        if (empty($term_1) && empty($term_2) && empty($term_3) && $plan_id === 0) {
                            continue;
                        }

                        if ($plan_id > 0) {
                            // Verify plan exists
                            $check_sql = "SELECT id FROM annual_plans WHERE id = ? AND teacher_id = ? AND week = ? AND class_id = ? AND subject_id = ? AND department_id = ?";
                            $check_stmt = $conn->prepare($check_sql);
                            $check_stmt->bind_param("iiiiii", $plan_id, $teacher_id, $week, $class_id, $subject_id, $department_id);
                            $check_stmt->execute();
                            $plan_exists = $check_stmt->get_result()->num_rows > 0;
                            $check_stmt->close();

                            if (!$plan_exists) {
                                $errors[] = "Plan ID $plan_id for week $week is invalid or you lack permission.";
                                continue;
                            }

                            // Update existing plan
                            $update_sql = "UPDATE annual_plans SET term_1 = ?, term_2 = ?, term_3 = ?, updated_at = NOW()
                                           WHERE id = ? AND teacher_id = ? AND week = ? AND class_id = ? AND subject_id = ? AND department_id = ?";
                            $update_stmt = $conn->prepare($update_sql);
                            $update_stmt->bind_param(
                                "sssiiiiii",
                                $term_1, $term_2, $term_3, $plan_id, $teacher_id, $week, $class_id, $subject_id, $department_id
                            );
                            if (!$update_stmt->execute()) {
                                $errors[] = "Failed to update plan for week $week: " . $conn->error;
                                error_log("Annual plan update error for week $week: " . $conn->error);
                                $conn->rollback();
                                goto post_processing;
                            }
                            $update_stmt->close();
                            $log_desc = "Teacher updated annual plan ID: $plan_id for week $week";
                        } else {
                            // Insert new plan
                            $insert_sql = "INSERT INTO annual_plans (
                                teacher_id, week, class_id, subject_id, department_id, term_1, term_2, term_3, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                            $insert_stmt = $conn->prepare($insert_sql);
                            $insert_stmt->bind_param(
                                "iiiissss",
                                $teacher_id, $week, $class_id, $subject_id, $department_id, $term_1, $term_2, $term_3
                            );
                            if (!$insert_stmt->execute()) {
                                $errors[] = "Failed to save plan for week $week: " . $conn->error;
                                error_log("Annual plan insert error for week $week: " . $conn->error);
                                $conn->rollback();
                                goto post_processing;
                            }
                            $insert_stmt->close();
                            $log_desc = "Teacher created annual plan for week $week, class_id: $class_id, subject_id: $subject_id";
                        }

                        // Log action
                        $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                                                    VALUES (?, ?, ?, ?, ?, NOW())");
                        $action_log = $plan_id ? 'Updated Annual Plan' : 'Created Annual Plan';
                        $log_stmt->bind_param("issss", $teacher_id, $action_log, $log_desc, $ip_address, $user_agent);
                        if (!$log_stmt->execute()) {
                            error_log("Failed to log annual plan action for week $week: " . $log_stmt->error);
                        }
                        $log_stmt->close();
                    }

                    // Refresh plans list
                    $plans_stmt = $conn->prepare($plans_sql);
                    $plans_stmt->bind_param("i", $teacher_id);
                    $plans_stmt->execute();
                    $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $plans_stmt->close();

                    $success_msg = "Annual plans saved successfully!";
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error saving annual plans: " . $e->getMessage();
                    error_log("Annual plan save exception: " . $e->getMessage());
                }
            }
        } elseif ($action === 'cancel') {
            $success_msg = "Annual plan creation cancelled.";
        } elseif ($action === 'delete') {
            $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
            if (!$plan_id || $plan_id <= 0) {
                $errors[] = "Invalid annual plan ID.";
            } else {
                $conn->begin_transaction();
                try {
                    $check_sql = "SELECT id FROM annual_plans WHERE id = ? AND teacher_id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $plan_id, $teacher_id);
                    $check_stmt->execute();
                    $plan_exists = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();

                    if (!$plan_exists) {
                        $errors[] = "Annual plan not found or you do not have permission to delete it.";
                        $conn->rollback();
                    } else {
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
                            $plans_stmt = $conn->prepare($plans_sql);
                            $plans_stmt->bind_param("i", $teacher_id);
                            $plans_stmt->execute();
                            $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                            $plans_stmt->close();

                            $conn->commit();
                        } else {
                            $errors[] = "Failed to delete annual plan: " . $conn->error;
                            error_log("Annual plan delete error: " . $conn->error);
                            $conn->rollback();
                        }
                        $delete_stmt->close();
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error deleting annual plan: " . $e->getMessage();
                    error_log("Annual plan delete exception: " . $e->getMessage());
                }
            }
        }
    }
}

post_processing:
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Annual plan creation for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, annual plan, teacher portal">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Annual Plan">
    <meta property="og:description" content="Teacher interface for creating annual lesson plans in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/annual_plan.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Annual Plan">
    <meta name="twitter:description" content="Teacher interface for creating annual lesson plans in QueensPa School.">
    <title><?php echo $edit_mode ? 'Edit Annual Plan' : 'Create Annual Plan'; ?> - QueensPa School</title>

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
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Orbitron', sans-serif; }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin: 0 auto;
        }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        .dark ::-webkit-scrollbar-track { background: #1f2937; }
        .dark ::-webkit-scrollbar-thumb { background: #f97316; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        .term-col { display: none; }
        .term-col.active { display: table-cell; }
        .week-table th.term-header, .week-table td.term-col { min-width: 300px; }
        /* Button Enhancements for Existing Annual Plans */
        .action-btn {
            min-width: 80px;
            text-align: center;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        .bg-blue-500, .bg-green-500, .bg-purple-500, .bg-orange-500, .bg-red-500 {
            color: #ffffff !important;
        }
        .dark .bg-blue-500, .dark .bg-green-500, .dark .bg-purple-500, .dark .bg-orange-500, .dark .bg-red-500 {
            color: #ffffff !important;
        }
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
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white text-center flex-grow mb-4 md:mb-0 animate-glow" aria-label="Annual Plan">
                    <i class="fas fa-calendar-alt mr-2"></i> <?php echo $edit_mode ? 'Edit Annual Plan' : 'Create Annual Plan'; ?>
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

            <!-- Existing Plans Section -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl flex justify-between items-center">
                    <h3 class="text-xl font-semibold mb-0">Existing Annual Plans</h3>
                </div>
                <div class="p-6 text-white">
                    <?php if (empty($plans)): ?>
                        <div class="text-center text-gray-300 py-8">
                            <i class="fas fa-calendar-times text-4xl mb-4"></i>
                            <p>No annual plans found.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto bg-white bg-opacity-10 rounded-lg overflow-hidden" aria-label="Existing annual plans table">
                                <thead class="sticky top-0 bg-gradient-to-r from-orange-500 to-red-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Class</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Subject</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Week</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Created At</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-500">
                                    <?php foreach ($plans as $plan): ?>
                                        <tr class="hover:bg-gray-700 transition-colors duration-200">
                                            <td class="px-4 py-3 font-medium text-white"><?php echo htmlspecialchars($plan['class_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['week']); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></td>
                                            <td class="px-4 py-3 flex gap-2">
                                                <a href="/teacher/view_annual_plan.php?id=<?php echo htmlspecialchars($plan['id']); ?>&term=1" 
                                                   class="bg-blue-500 text-white px-3 py-1 rounded-lg text-sm transition-all duration-300 glassmorphism action-btn hover:bg-blue-600 hover:shadow-lg" 
                                                   data-bs-toggle="tooltip" title="View Term 1 Plan">
                                                    <i class="fas fa-eye"></i> Term 1
                                                </a>
                                                <a href="/teacher/view_annual_plan.php?id=<?php echo htmlspecialchars($plan['id']); ?>&term=2" 
                                                   class="bg-green-500 text-white px-3 py-1 rounded-lg text-sm transition-all duration-300 glassmorphism action-btn hover:bg-green-600 hover:shadow-lg" 
                                                   data-bs-toggle="tooltip" title="View Term 2 Plan">
                                                    <i class="fas fa-eye"></i> Term 2
                                                </a>
                                                <a href="/teacher/view_annual_plan.php?id=<?php echo htmlspecialchars($plan['id']); ?>&term=3" 
                                                   class="bg-purple-500 text-white px-3 py-1 rounded-lg text-sm transition-all duration-300 glassmorphism action-btn hover:bg-purple-600 hover:shadow-lg" 
                                                   data-bs-toggle="tooltip" title="View Term 3 Plan">
                                                    <i class="fas fa-eye"></i> Term 3
                                                </a>
                                                <a href="/teacher/annual_plan.php?class_id=<?php echo htmlspecialchars($plan['class_id']); ?>&subject_id=<?php echo htmlspecialchars($plan['subject_id']); ?>&department_id=<?php echo htmlspecialchars($plan['department_id']); ?>" 
                                                   class="bg-orange-500 text-white px-3 py-1 rounded-lg text-sm transition-all duration-300 glassmorphism action-btn hover:bg-orange-600 hover:shadow-lg" 
                                                   data-bs-toggle="tooltip" title="Edit Annual Plan">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this annual plan?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="plan_id" value="<?php echo htmlspecialchars($plan['id']); ?>">
                                                    <button type="submit" class="bg-red-500 text-white px-3 py-1 rounded-lg text-sm transition-all duration-300 glassmorphism action-btn hover:bg-red-600 hover:shadow-lg" 
                                                            data-bs-toggle="tooltip" title="Delete Annual Plan">
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
            </section>

            <!-- Create/Edit Form Section -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0"><?php echo $edit_mode ? 'Edit Annual Plan' : 'Create New Annual Plan'; ?></h3>
                </div>
                <div class="p-6 text-white">
                    <?php if (empty($departments)): ?>
                        <div class="bg-yellow-500 bg-opacity-20 border border-yellow-400 text-yellow-200 px-4 py-3 rounded-lg animate-fade-in" role="alert">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            No departments available. Please contact your administrator.
                        </div>
                    <?php else: ?>
                        <form method="POST" action="" onsubmit="return validateForm() && showLoading()" novalidate id="annualPlanForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="submit">
                            <!-- Basic Information -->
                            <div class="space-y-6">
                                <div class="bg-gray-800 bg-opacity-50 p-4 rounded-lg">
                                    <h5 class="text-lg font-semibold mb-4 text-orange-300">Basic Information</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="department_id">Department <span class="text-red-400">*</span></label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="department_id" name="department_id" required>
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?= htmlspecialchars($department['id']) ?>" <?= $edit_mode && $department_id == $department['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($department['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="loading-spinner department-spinner hidden inline-flex items-center"><i class="fas fa-spinner fa-spin mr-1"></i>Loading...</span>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="class_id">Class <span class="text-red-400">*</span></label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="class_id" name="class_id" required>
                                                <option value="">Select Class</option>
                                                <?php if ($edit_mode && !empty($existing_plans)): ?>
                                                    <option value="<?= htmlspecialchars($class_id) ?>" selected><?= htmlspecialchars($existing_plans[array_key_first($existing_plans)]['class_name'] ?? '') ?></option>
                                                <?php endif; ?>
                                            </select>
                                            <span class="loading-spinner class-spinner hidden inline-flex items-center"><i class="fas fa-spinner fa-spin mr-1"></i>Loading...</span>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="subject_id">Subject <span class="text-red-400">*</span></label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="subject_id" name="subject_id" required>
                                                <option value="">Select Subject</option>
                                                <?php if ($edit_mode && !empty($existing_plans)): ?>
                                                    <option value="<?= htmlspecialchars($subject_id) ?>" selected><?= htmlspecialchars($existing_plans[array_key_first($existing_plans)]['subject_name'] ?? '') ?></option>
                                                <?php endif; ?>
                                            </select>
                                            <span class="loading-spinner subject-spinner hidden inline-flex items-center"><i class="fas fa-spinner fa-spin mr-1"></i>Loading...</span>
                                        </div>
                                        <div class="flex items-end">
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="term-filter-form">View Term <span class="text-red-400">*</span></label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="term-filter-form" onchange="filterTableByTerm()">
                                                <option value="all">All Terms</option>
                                                <option value="1">Term 1</option>
                                                <option value="2">Term 2</option>
                                                <option value="3">Term 3</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Weekly Plans Table -->
                                <div class="bg-gray-800 bg-opacity-50 p-4 rounded-lg">
                                    <h5 class="text-lg font-semibold mb-4 text-orange-300">Weekly Plans (Term View)</h5>
                                    <div class="overflow-x-auto">
                                        <table class="w-full table-auto border-collapse border border-gray-600 bg-white bg-opacity-10 rounded-lg overflow-hidden week-table" id="plans-table">
                                            <thead class="sticky top-0 bg-gradient-to-r from-orange-500 to-red-500">
                                                <tr>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600">Week</th>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600 term-header active" data-term="1">Term 1 Plan (Units)</th>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600 term-header" data-term="2">Term 2 Plan (Units)</th>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600 term-header" data-term="3">Term 3 Plan (Units)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php for ($week = 1; $week <= 16; $week++): ?>
                                                    <tr class="hover:bg-gray-700 transition-colors duration-200">
                                                        <td class="px-4 py-3 font-medium text-white border border-gray-600 align-top">Week <?= $week ?></td>
                                                        <td class="px-4 py-3 border border-gray-600 term-col active" data-term="1">
                                                            <textarea class="w-full px-3 py-2 bg-transparent text-white border-0 focus:ring-2 focus:ring-orange-300 rounded focus:outline-none resize-none" 
                                                                      name="weeks[<?= $week ?>][term_1]" rows="3" maxlength="5000" 
                                                                      placeholder="Enter Term 1 plan for Week <?= $week ?>"><?= $edit_mode && isset($existing_plans[$week]) ? htmlspecialchars($existing_plans[$week]['term_1'] ?? '') : '' ?></textarea>
                                                            <small class="character-counter text-gray-400 text-xs block mt-1" data-counter-for="weeks[<?= $week ?>][term_1]">0/5000 characters</small>
                                                            <?php if ($edit_mode && isset($existing_plans[$week])): ?>
                                                                <input type="hidden" name="weeks[<?= $week ?>][plan_id]" value="<?= htmlspecialchars($existing_plans[$week]['id']) ?>">
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="px-4 py-3 border border-gray-600 term-col" data-term="2">
                                                            <textarea class="w-full px-3 py-2 bg-transparent text-white border-0 focus:ring-2 focus:ring-orange-300 rounded focus:outline-none resize-none" 
                                                                      name="weeks[<?= $week ?>][term_2]" rows="3" maxlength="5000" 
                                                                      placeholder="Enter Term 2 plan for Week <?= $week ?>"><?= $edit_mode && isset($existing_plans[$week]) ? htmlspecialchars($existing_plans[$week]['term_2'] ?? '') : '' ?></textarea>
                                                            <small class="character-counter text-gray-400 text-xs block mt-1" data-counter-for="weeks[<?= $week ?>][term_2]">0/5000 characters</small>
                                                        </td>
                                                        <td class="px-4 py-3 border border-gray-600 term-col" data-term="3">
                                                            <textarea class="w-full px-3 py-2 bg-transparent text-white border-0 focus:ring-2 focus:ring-orange-300 rounded focus:outline-none resize-none" 
                                                                      name="weeks[<?= $week ?>][term_3]" rows="3" maxlength="5000" 
                                                                      placeholder="Enter Term 3 plan for Week <?= $week ?>"><?= $edit_mode && isset($existing_plans[$week]) ? htmlspecialchars($existing_plans[$week]['term_3'] ?? '') : '' ?></textarea>
                                                            <small class="character-counter text-gray-400 text-xs block mt-1" data-counter-for="weeks[<?= $week ?>][term_3]">0/5000 characters</small>
                                                        </td>
                                                    </tr>
                                                <?php endfor; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="flex gap-3 mt-6">
                                <button type="submit" class="bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white px-6 py-3 rounded-xl font-medium transition-all duration-300 glassmorphism" id="submit-btn" disabled>
                                    <i class="fas fa-save mr-2"></i> <?= $edit_mode ? 'Update' : 'Submit' ?> Plan
                                </button>
                                <button type="button" name="action" value="cancel" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-xl font-medium transition-all duration-300 glassmorphism" onclick="window.location.href='annual_plan.php'">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Global variables
        function showLoading() {
            document.getElementById('loading-overlay').classList.remove('hidden');
            return true;
        }

        function validateForm() {
            const form = document.getElementById('annualPlanForm');
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return false;
            }
            const departmentId = $('#department_id').val();
            const classId = $('#class_id').val();
            const subjectId = $('#subject_id').val();
            if (!departmentId || !classId || !subjectId) {
                alert('Please select a valid department, class, and subject.');
                $('#department_id').focus();
                return false;
            }
            return confirm(`Are you sure you want to ${<?= $edit_mode ? "'update'" : "'submit'" ?>} these annual plans?`);
        }

        function filterTableByTerm() {
            const selectedTerm = document.getElementById('term-filter-form').value;
            const headers = document.querySelectorAll('.term-header');
            const cols = document.querySelectorAll('.term-col');

            headers.forEach(header => header.classList.remove('active'));
            cols.forEach(col => col.classList.remove('active'));

            if (selectedTerm === 'all') {
                headers.forEach(header => header.classList.add('active'));
                cols.forEach(col => col.classList.add('active'));
            } else {
                const termNum = parseInt(selectedTerm);
                headers.forEach(header => {
                    if (parseInt(header.dataset.term) === termNum) header.classList.add('active');
                });
                cols.forEach(col => {
                    if (parseInt(col.dataset.term) === termNum) col.classList.add('active');
                });
            }
        }

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

            // Character counters
            document.querySelectorAll('textarea').forEach(textarea => {
                const counter = document.querySelector(`[data-counter-for="${textarea.name}"]`);
                if (counter) {
                    function updateCounter() {
                        const length = textarea.value.length;
                        counter.textContent = `${length}/5000 characters`;
                        counter.classList.toggle('text-yellow-400', length > 4500);
                    }
                    textarea.addEventListener('input', updateCounter);
                    updateCounter();
                }
            });

            // Initial term filter
            filterTableByTerm();

            // Submit button enable/disable
            function updateSubmitButton() {
                const departmentId = $('#department_id').val();
                const classId = $('#class_id').val();
                const subjectId = $('#subject_id').val();
                $('#submit-btn').prop('disabled', !departmentId || !classId || !subjectId);
            }
            $('#department_id, #class_id, #subject_id').on('change', updateSubmitButton);

            // AJAX for department change
            $('#department_id').on('change', function() {
                const departmentId = $(this).val();
                $('#class_id, #subject_id').html('<option value="">Select...</option>');
                $('#submit-btn').prop('disabled', true);

                if (departmentId) {
                    $('.department-spinner').removeClass('hidden');
                    $.ajax({
                        url: window.location.pathname + '?action=get_classes',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        success: function(response) {
                            $('.department-spinner').addClass('hidden');
                            if (response.error) {
                                alert(response.error);
                            } else if (response.classes && response.classes.length) {
                                response.classes.forEach(cls => {
                                    $('#class_id').append(`<option value="${cls.id}">${cls.class_name}</option>`);
                                });
                            } else {
                                alert('No classes available for this department.');
                            }
                        },
                        error: function() {
                            $('.department-spinner').addClass('hidden');
                            alert('Error fetching classes.');
                        }
                    });

                    // Subjects
                    $.ajax({
                        url: window.location.pathname + '?action=get_subjects',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.error) {
                                alert(response.error);
                            } else if (response.subjects && response.subjects.length) {
                                response.subjects.forEach(sub => {
                                    $('#subject_id').append(`<option value="${sub.id}">${sub.subject_name}</option>`);
                                });
                            } else {
                                alert('No subjects available for this department.');
                            }
                        },
                        error: function() {
                            alert('Error fetching subjects.');
                        }
                    });
                }
                updateSubmitButton();
            });

            // Edit mode pre-load
            <?php if ($edit_mode): ?>
                $('#department_id').val(<?= $department_id ?>).trigger('change');
            <?php endif; ?>

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