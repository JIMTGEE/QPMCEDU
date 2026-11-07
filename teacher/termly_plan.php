<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt to termly_plan.php: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
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
            VALUES (?, 'Accessed Termly Plan Page', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed termly plan page";
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

// Handle AJAX requests for dynamic dropdowns
if (isset($_GET['action']) && $_GET['action'] === 'get_classes') {
    $department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
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

if (isset($_GET['action']) && $_GET['action'] === 'get_subjects') {
    $department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
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

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments = $departments_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$departments_stmt->close();

// Fetch academic years and terms
$terms_sql = "SELECT id, term_name, year FROM academic_calendar ORDER BY year DESC, term_name";
$terms_stmt = $conn->prepare($terms_sql);
$terms = [];
if ($terms_stmt) {
    $terms_stmt->execute();
    $terms_result = $terms_stmt->get_result();
    while ($row = $terms_result->fetch_assoc()) {
        $terms[] = $row;
    }
    $terms_stmt->close();
} else {
    error_log("Prepare failed for terms: " . $conn->error);
    $terms = [];
}

// Load existing plans for editing
$edit_mode = false;
$existing_plans = [];
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: 0;
$department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT) ?: 0;
$term_id = filter_input(INPUT_GET, 'term_id', FILTER_VALIDATE_INT) ?: 0;

if ($class_id > 0 && $subject_id > 0 && $department_id > 0 && $term_id > 0) {
    $plan_sql = "SELECT id, week, unit, topic, lesson, term_id, term_number, class_id, subject_id, annual_plan_id
                 FROM termly_plans
                 WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND department_id = ? AND term_id = ?";
    $plan_stmt = $conn->prepare($plan_sql);
    $plan_stmt->bind_param("iiiii", $teacher_id, $class_id, $subject_id, $department_id, $term_id);
    $plan_stmt->execute();
    $result = $plan_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existing_plans[$row['week']] = $row;
    }
    $plan_stmt->close();
    $edit_mode = !empty($existing_plans);
}

// Fetch existing termly plans
$termly_plans_sql = "SELECT tp.id, tp.week, tp.unit, tp.topic, tp.lesson, tp.term_number, tp.class_id, tp.subject_id, tp.department_id, tp.term_id, ac.term_name, c.class_name, s.subject_name, d.name AS department_name, tp.created_at
                     FROM termly_plans tp
                     JOIN academic_calendar ac ON tp.term_id = ac.id
                     JOIN classes c ON tp.class_id = c.id
                     JOIN subjects s ON tp.subject_id = s.id
                     JOIN departments d ON tp.department_id = d.id
                     WHERE tp.teacher_id = ?
                     ORDER BY ac.year DESC, tp.term_number, tp.week DESC LIMIT 100";
$termly_plans_stmt = $conn->prepare($termly_plans_sql);
$termly_plans_stmt->bind_param("i", $teacher_id);
$termly_plans_stmt->execute();
$termly_plans = $termly_plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$termly_plans_stmt->close();

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
            $annual_plan_id = filter_input(INPUT_POST, 'annual_plan_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $term_id = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT);
            $term_number = filter_input(INPUT_POST, 'term_number', FILTER_VALIDATE_INT);
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
            if (!$term_id || $term_id <= 0) {
                $errors[] = "Invalid term selected.";
            }
            if (!$term_number || $term_number < 1 || $term_number > 3) {
                $errors[] = "Invalid term number.";
            }
            if (empty($weeks) || !is_array($weeks)) {
                $errors[] = "No valid week data provided.";
            }
            // Validate annual_plan_id if provided
            if ($annual_plan_id !== null && $annual_plan_id <= 0) {
                $errors[] = "Invalid annual plan selected.";
            } elseif ($annual_plan_id !== null) {
                $check_annual_sql = "SELECT id FROM annual_plans WHERE id = ? AND teacher_id = ?";
                $check_annual_stmt = $conn->prepare($check_annual_sql);
                $check_annual_stmt->bind_param("ii", $annual_plan_id, $teacher_id);
                $check_annual_stmt->execute();
                if ($check_annual_stmt->get_result()->num_rows === 0) {
                    $errors[] = "Selected annual plan does not exist or you lack permission.";
                }
                $check_annual_stmt->close();
            }

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    foreach ($weeks as $week => $data) {
                        $week_num = (int)$week;
                        if ($week_num < 1 || $week_num > 16) {
                            $errors[] = "Invalid week number for week $week_num.";
                            continue;
                        }

                        $unit = trim(filter_var($data['unit'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
                        $topic = trim(filter_var($data['topic'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
                        $lesson = trim(filter_var($data['lesson'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
                        $plan_id = isset($data['plan_id']) && is_numeric($data['plan_id']) ? (int)$data['plan_id'] : 0;

                        if (strlen($unit) > 255 || strlen($topic) > 255 || strlen($lesson) > 65535) {
                            $errors[] = "Data too long for week $week_num.";
                            continue;
                        }

                        if (empty($unit) && empty($topic) && empty($lesson) && $plan_id === 0) {
                            continue;
                        }

                        // Check if plan_id is valid for update
                        $is_update = false;
                        if ($plan_id > 0) {
                            $check_sql = "SELECT id FROM termly_plans WHERE id = ? AND teacher_id = ? AND week = ? AND class_id = ? AND subject_id = ? AND department_id = ? AND term_id = ?";
                            $check_stmt = $conn->prepare($check_sql);
                            $check_stmt->bind_param("iiiiiii", $plan_id, $teacher_id, $week_num, $class_id, $subject_id, $department_id, $term_id);
                            $check_stmt->execute();
                            $plan_exists = $check_stmt->get_result()->num_rows > 0;
                            $check_stmt->close();

                            if ($plan_exists) {
                                $is_update = true;
                            } else {
                                // Invalid plan_id, treat as new plan
                                $plan_id = 0;
                                error_log("Invalid plan_id $plan_id for week $week_num, treating as new plan.");
                            }
                        }

                        if ($is_update) {
                            // Update existing plan
                            $update_sql = "UPDATE termly_plans SET unit = ?, topic = ?, lesson = ?, annual_plan_id = ?, updated_at = NOW()
                                           WHERE id = ? AND teacher_id = ? AND week = ? AND class_id = ? AND subject_id = ? AND department_id = ? AND term_id = ?";
                            $update_stmt = $conn->prepare($update_sql);
                            $update_stmt->bind_param(
                                "sssiiiiiii",
                                $unit,
                                $topic,
                                $lesson,
                                $annual_plan_id,
                                $plan_id,
                                $teacher_id,
                                $week_num,
                                $class_id,
                                $subject_id,
                                $department_id,
                                $term_id
                            );
                            if (!$update_stmt->execute()) {
                                $errors[] = "Failed to update plan for week $week_num: " . $conn->error;
                                error_log("Termly plan update error for week $week_num: " . $conn->error);
                                $conn->rollback();
                                goto post_processing;
                            }
                            $update_stmt->close();
                            $log_desc = "Teacher updated termly plan ID: $plan_id for week $week_num";
                        } else {
                            // Insert new plan
                            $insert_sql = "INSERT INTO termly_plans (
                                annual_plan_id, teacher_id, class_id, subject_id, department_id, term_id, term_number, week, unit, topic, lesson, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                            $insert_stmt = $conn->prepare($insert_sql);
                            $insert_stmt->bind_param(
                                "iiiiiiissss",
                                $annual_plan_id,
                                $teacher_id,
                                $class_id,
                                $subject_id,
                                $department_id,
                                $term_id,
                                $term_number,
                                $week_num,
                                $unit,
                                $topic,
                                $lesson
                            );
                            if (!$insert_stmt->execute()) {
                                $errors[] = "Failed to save plan for week $week_num: " . $conn->error;
                                error_log("Termly plan insert error for week $week_num: " . $conn->error);
                                $conn->rollback();
                                goto post_processing;
                            }
                            $insert_stmt->close();
                            $log_desc = "Teacher created termly plan for week $week_num, class_id: $class_id, subject_id: $subject_id";
                        }

                        // Log action
                        $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at)
                                                    VALUES (?, ?, ?, ?, ?, NOW())");
                        $action_log = $is_update ? 'Updated Termly Plan' : 'Created Termly Plan';
                        $log_stmt->bind_param("issss", $teacher_id, $action_log, $log_desc, $ip_address, $user_agent);
                        if (!$log_stmt->execute()) {
                            error_log("Failed to log termly plan action for week $week_num: " . $log_stmt->error);
                        }
                        $log_stmt->close();
                    }

                    // Refresh plans list
                    $termly_plans_stmt = $conn->prepare($termly_plans_sql);
                    $termly_plans_stmt->bind_param("i", $teacher_id);
                    $termly_plans_stmt->execute();
                    $termly_plans = $termly_plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $termly_plans_stmt->close();

                    $success_msg = "Termly plans saved successfully!";
                    $conn->commit();

                    // Redirect to clear form
                    header("Location: /teacher/termly_plan.php");
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error saving termly plans: " . $e->getMessage();
                    error_log("Termly plan save exception: " . $e->getMessage());
                }
            }
        } elseif ($action === 'delete') {
            $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
            if (!$plan_id || $plan_id <= 0) {
                $errors[] = "Invalid termly plan ID.";
            } else {
                $conn->begin_transaction();
                try {
                    $check_sql = "SELECT id FROM termly_plans WHERE id = ? AND teacher_id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $plan_id, $teacher_id);
                    $check_stmt->execute();
                    $plan_exists = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();

                    if (!$plan_exists) {
                        $errors[] = "Termly plan not found or you do not have permission to delete it.";
                        $conn->rollback();
                    } else {
                        $delete_sql = "DELETE FROM termly_plans WHERE id = ? AND teacher_id = ?";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
                        if ($delete_stmt->execute()) {
                            $log_desc = "Teacher deleted termly plan ID: $plan_id";
                            $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at)
                                                        VALUES (?, 'Deleted Termly Plan', ?, ?, ?, NOW())");
                            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                            if (!$log_stmt->execute()) {
                                error_log("Failed to log termly plan deletion: " . $log_stmt->error);
                            }
                            $log_stmt->close();

                            $success_msg = "Termly plan deleted successfully!";
                            $termly_plans_stmt = $conn->prepare($termly_plans_sql);
                            $termly_plans_stmt->bind_param("i", $teacher_id);
                            $termly_plans_stmt->execute();
                            $termly_plans = $termly_plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                            $termly_plans_stmt->close();

                            $conn->commit();
                        } else {
                            $errors[] = "Failed to delete termly plan: " . $conn->error;
                            error_log("Termly plan delete error: " . $conn->error);
                            $conn->rollback();
                        }
                        $delete_stmt->close();
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error deleting termly plan: " . $e->getMessage();
                    error_log("Termly plan delete exception: " . $e->getMessage());
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
    <meta name="description" content="Termly plan creation for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, termly plan, teacher portal">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Termly Plan">
    <meta property="og:description" content="Teacher interface for creating termly lesson plans in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/termly_plan.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Termly Plan">
    <meta name="twitter:description" content="Teacher interface for creating termly lesson plans in QueensPa School.">
    <title><?php echo $edit_mode ? 'Edit Termly Plan' : 'Create Termly Plan'; ?> - QueensPa School</title>

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
        .week-table th, .week-table td { min-width: 150px; }
        select:invalid { color: #9ca3af; }
        .modal-content { background: rgba(255, 255, 255, 0.95); }
        .dark .modal-content { background: rgba(31, 41, 55, 0.95); }
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
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white text-center flex-grow mb-4 md:mb-0 animate-glow" aria-label="Termly Plan">
                    <i class="fas fa-book-open mr-2"></i> <?php echo $edit_mode ? 'Edit Termly Plan' : 'Create Termly Plan'; ?>
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
                    <h3 class="text-xl font-semibold mb-0">Existing Termly Plans</h3>
                </div>
                <div class="p-6 text-white">
                    <?php if (empty($termly_plans)): ?>
                        <div class="text-center text-gray-300 py-8">
                            <i class="fas fa-calendar-times text-4xl mb-4"></i>
                            <p>No termly plans found.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto bg-white bg-opacity-10 rounded-lg overflow-hidden" aria-label="Existing termly plans table">
                                <thead class="sticky top-0 bg-gradient-to-r from-orange-500 to-red-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Department</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Class</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Subject</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Term</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Week</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Unit</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Topic</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Created At</th>
                                        <th class="px-4 py-3 text-left font-semibold text-white">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-500">
                                    <?php foreach ($termly_plans as $plan): ?>
                                        <tr class="hover:bg-gray-700 transition-colors duration-200">
                                            <td class="px-4 py-3 font-medium text-white"><?php echo htmlspecialchars($plan['department_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['class_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['term_name'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['week']); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['unit'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars($plan['topic'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-gray-300"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></td>
                                            <td class="px-4 py-3 flex gap-2">
                                                <button type="button" 
                                                        class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#viewPlanModal" 
                                                        data-plan='<?php echo json_encode([
                                                            "id" => $plan['id'],
                                                            "department" => $plan['department_name'] ?? 'N/A',
                                                            "class" => $plan['class_name'] ?? 'N/A',
                                                            "subject" => $plan['subject_name'] ?? 'N/A',
                                                            "term" => $plan['term_name'] ?? 'N/A',
                                                            "week" => $plan['week'],
                                                            "unit" => $plan['unit'] ?? 'N/A',
                                                            "topic" => $plan['topic'] ?? 'N/A',
                                                            "lesson" => $plan['lesson'] ?? 'N/A',
                                                            "created_at" => date('Y-m-d H:i', strtotime($plan['created_at']))
                                                        ]); ?>' 
                                                        title="View Termly Plan">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this termly plan?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="plan_id" value="<?php echo htmlspecialchars($plan['id']); ?>">
                                                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" data-bs-toggle="tooltip" title="Delete Termly Plan">
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

            <!-- View Plan Modal -->
            <div class="modal fade" id="viewPlanModal" tabindex="-1" aria-labelledby="viewPlanModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content glassmorphism rounded-2xl">
                        <div class="modal-header bg-gradient-to-r from-orange-500 to-red-500">
                            <h5 class="modal-title text-white font-semibold" id="viewPlanModalLabel">Termly Plan Details</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-6 text-gray-900 dark:text-gray-200">
                            <div class="space-y-4">
                                <p><strong>Department:</strong> <span id="view-department"></span></p>
                                <p><strong>Class:</strong> <span id="view-class"></span></p>
                                <p><strong>Subject:</strong> <span id="view-subject"></span></p>
                                <p><strong>Term:</strong> <span id="view-term"></span></p>
                                <p><strong>Week:</strong> <span id="view-week"></span></p>
                                <p><strong>Unit:</strong> <span id="view-unit"></span></p>
                                <p><strong>Topic:</strong> <span id="view-topic"></span></p>
                                <p><strong>Lesson Details:</strong></p>
                                <div id="view-lesson" class="border border-gray-300 dark:border-gray-600 p-3 rounded-lg whitespace-pre-wrap"></div>
                                <p><strong>Created At:</strong> <span id="view-created-at"></span></p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-colors duration-300 glassmorphism" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create/Edit Form Section -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0"><?php echo $edit_mode ? 'Edit Termly Plan' : 'Create New Termly Plan'; ?></h3>
                </div>
                <div class="p-6 text-white">
                    <?php if (empty($departments) || empty($terms)): ?>
                        <div class="bg-yellow-500 bg-opacity-20 border border-yellow-400 text-yellow-200 px-4 py-3 rounded-lg animate-fade-in" role="alert">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php echo empty($departments) ? 'No departments available.' : 'No terms available.'; ?> Please contact your administrator.
                        </div>
                    <?php else: ?>
                        <form method="POST" action="" onsubmit="return validateForm() && showLoading()" novalidate id="termlyPlanForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="submit">
                            <input type="hidden" name="term_number" id="termNumber" value="<?php echo $edit_mode && isset($existing_plans[array_key_first($existing_plans)]) ? htmlspecialchars($existing_plans[array_key_first($existing_plans)]['term_number']) : ''; ?>">

                            <!-- Basic Information -->
                            <div class="space-y-6">
                                <div class="bg-gray-800 bg-opacity-50 p-4 rounded-lg">
                                    <h5 class="text-lg font-semibold mb-4 text-orange-300">Basic Information</h5>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="department_id">Department <span class="text-red-400">*</span></label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="department_id" name="department_id" required>
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?php echo htmlspecialchars($department['id']); ?>" <?php echo $edit_mode && $department_id == $department['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($department['name']); ?>
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
                                                    <?php
                                                    $class_sql = "SELECT class_name FROM classes WHERE id = ?";
                                                    $class_stmt = $conn->prepare($class_sql);
                                                    $class_stmt->bind_param("i", $class_id);
                                                    $class_stmt->execute();
                                                    $class_name = $class_stmt->get_result()->fetch_assoc()['class_name'] ?? 'Unknown';
                                                    $class_stmt->close();
                                                    ?>
                                                    <option value="<?php echo htmlspecialchars($class_id); ?>" selected><?php echo htmlspecialchars($class_name); ?></option>
                                                <?php endif; ?>
                                            </select>
                                            <span class="loading-spinner class-spinner hidden inline-flex items-center"><i class="fas fa-spinner fa-spin mr-1"></i>Loading...</span>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="subject_id">Subject <span class="text-red-400">*</span></label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="subject_id" name="subject_id" required>
                                                <option value="">Select Subject</option>
                                                <?php if ($edit_mode && !empty($existing_plans)): ?>
                                                    <?php
                                                    $subject_sql = "SELECT subject_name FROM subjects WHERE id = ?";
                                                    $subject_stmt = $conn->prepare($subject_sql);
                                                    $subject_stmt->bind_param("i", $subject_id);
                                                    $subject_stmt->execute();
                                                    $subject_name = $subject_stmt->get_result()->fetch_assoc()['subject_name'] ?? 'Unknown';
                                                    $subject_stmt->close();
                                                    ?>
                                                    <option value="<?php echo htmlspecialchars($subject_id); ?>" selected><?php echo htmlspecialchars($subject_name); ?></option>
                                                <?php endif; ?>
                                            </select>
                                            <span class="loading-spinner subject-spinner hidden inline-flex items-center"><i class="fas fa-spinner fa-spin mr-1"></i>Loading...</span>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="term_id">Term <span class="text-red-400">*</span></label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="term_id" name="term_id" required onchange="setTermNumber()">
                                                <option value="">Select Term</option>
                                                <?php foreach ($terms as $term): ?>
                                                    <?php
                                                    $term_number = strpos($term['term_name'], '1') !== false ? 1 : (strpos($term['term_name'], '2') !== false ? 2 : 3);
                                                    ?>
                                                    <option value="<?php echo htmlspecialchars($term['id']); ?>" data-term-number="<?php echo $term_number; ?>" <?php echo $edit_mode && $term_id == $term['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['year']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-200 mb-2" for="annual_plan_id">Link to Annual Plan (Optional)</label>
                                            <select class="w-full px-3 py-2 bg-white text-gray-900 rounded-lg focus:ring-2 focus:ring-orange-300 focus:border-orange-500 transition-all" id="annual_plan_id" name="annual_plan_id">
                                                <option value="">No Link</option>
                                                <?php
                                                $annual_sql = "SELECT ap.id, ap.week, c.class_name, s.subject_name
                                                               FROM annual_plans ap
                                                               JOIN classes c ON ap.class_id = c.id
                                                               JOIN subjects s ON ap.subject_id = s.id
                                                               WHERE ap.teacher_id = ?
                                                               ORDER BY ap.created_at DESC LIMIT 50";
                                                $annual_stmt = $conn->prepare($annual_sql);
                                                $annual_stmt->bind_param("i", $teacher_id);
                                                $annual_stmt->execute();
                                                $annuals = $annual_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                                $annual_stmt->close();
                                                foreach ($annuals as $annual): ?>
                                                    <option value="<?php echo htmlspecialchars($annual['id']); ?>" <?php echo $edit_mode && isset($existing_plans[array_key_first($existing_plans)]) && $existing_plans[array_key_first($existing_plans)]['annual_plan_id'] == $annual['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars("Week {$annual['week']} - {$annual['class_name']} - {$annual['subject_name']}"); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Weekly Plans Table -->
                                <div class="bg-gray-800 bg-opacity-50 p-4 rounded-lg">
                                    <h5 class="text-lg font-semibold mb-4 text-orange-300">Weekly Plans</h5>
                                    <div class="overflow-x-auto">
                                        <table class="w-full table-auto border-collapse border border-gray-600 bg-white bg-opacity-10 rounded-lg overflow-hidden week-table" id="plans-table">
                                            <thead class="sticky top-0 bg-gradient-to-r from-orange-500 to-red-500">
                                                <tr>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600">Week</th>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600">Unit</th>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600">Topic</th>
                                                    <th class="px-4 py-3 text-left font-semibold text-white border border-gray-600">Lesson Details</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php for ($week = 1; $week <= 16; $week++): ?>
                                                    <tr class="hover:bg-gray-700 transition-colors duration-200">
                                                        <td class="px-4 py-3 font-medium text-white border border-gray-600 align-top">Week <?php echo $week; ?></td>
                                                        <td class="px-4 py-3 border border-gray-600 align-top">
                                                            <input type="text" class="w-full px-3 py-2 bg-transparent text-white border-0 focus:ring-2 focus:ring-orange-300 rounded focus:outline-none" 
                                                                   name="weeks[<?php echo $week; ?>][unit]" maxlength="255" 
                                                                   placeholder="Enter unit for Week <?php echo $week; ?>" 
                                                                   value="<?php echo $edit_mode && isset($existing_plans[$week]) ? htmlspecialchars($existing_plans[$week]['unit'] ?? '') : ''; ?>">
                                                            <small class="character-counter text-gray-400 text-xs block mt-1" data-counter-for="weeks[<?php echo $week; ?>][unit]">0/255 characters</small>
                                                            <?php if ($edit_mode && isset($existing_plans[$week])): ?>
                                                                <input type="hidden" name="weeks[<?php echo $week; ?>][plan_id]" value="<?php echo htmlspecialchars($existing_plans[$week]['id']); ?>">
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="px-4 py-3 border border-gray-600 align-top">
                                                            <input type="text" class="w-full px-3 py-2 bg-transparent text-white border-0 focus:ring-2 focus:ring-orange-300 rounded focus:outline-none" 
                                                                   name="weeks[<?php echo $week; ?>][topic]" maxlength="255" 
                                                                   placeholder="Enter topic for Week <?php echo $week; ?>" 
                                                                   value="<?php echo $edit_mode && isset($existing_plans[$week]) ? htmlspecialchars($existing_plans[$week]['topic'] ?? '') : ''; ?>">
                                                            <small class="character-counter text-gray-400 text-xs block mt-1" data-counter-for="weeks[<?php echo $week; ?>][topic]">0/255 characters</small>
                                                        </td>
                                                        <td class="px-4 py-3 border border-gray-600 align-top">
                                                            <textarea class="w-full px-3 py-2 bg-transparent text-white border-0 focus:ring-2 focus:ring-orange-300 rounded focus:outline-none resize-none" 
                                                                      name="weeks[<?php echo $week; ?>][lesson]" rows="3" maxlength="65535" 
                                                                      placeholder="Enter lesson details for Week <?php echo $week; ?>"><?php echo $edit_mode && isset($existing_plans[$week]) ? htmlspecialchars($existing_plans[$week]['lesson'] ?? '') : ''; ?></textarea>
                                                            <small class="character-counter text-gray-400 text-xs block mt-1" data-counter-for="weeks[<?php echo $week; ?>][lesson]">0/65535 characters</small>
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
                                    <i class="fas fa-save mr-2"></i> <?php echo $edit_mode ? 'Update' : 'Submit'; ?> Plan
                                </button>
                                <button type="button" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-xl font-medium transition-all duration-300 glassmorphism" onclick="window.location.href='termly_plan.php'">
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
    <script>
        // Global variables
        function showLoading() {
            document.getElementById('loading-overlay').classList.remove('hidden');
            return true;
        }

        function validateForm() {
            const form = document.getElementById('termlyPlanForm');
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return false;
            }
            const departmentId = $('#department_id').val();
            const classId = $('#class_id').val();
            const subjectId = $('#subject_id').val();
            const termId = $('#term_id').val();
            if (!departmentId || !classId || !subjectId || !termId) {
                alert('Please select a valid department, class, subject, and term.');
                $('#department_id').focus();
                return false;
            }
            return confirm(`Are you sure you want to ${<?php echo $edit_mode ? "'update'" : "'submit'"; ?>} these termly plans?`);
        }

        function setTermNumber() {
            const termSelect = document.getElementById('term_id');
            const termNumber = termSelect.options[termSelect.selectedIndex]?.dataset.termNumber || '';
            document.getElementById('termNumber').value = termNumber;
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
            document.querySelectorAll('input[maxlength], textarea[maxlength]').forEach(input => {
                const counter = document.querySelector(`[data-counter-for="${input.name}"]`);
                if (counter) {
                    function updateCounter() {
                        const length = input.value.length;
                        const maxLength = input.getAttribute('maxlength');
                        counter.textContent = `${length}/${maxLength} characters`;
                        counter.classList.toggle('text-yellow-400', length > maxLength * 0.9);
                    }
                    input.addEventListener('input', updateCounter);
                    updateCounter();
                }
            });

            // Submit button enable/disable
            function updateSubmitButton() {
                const departmentId = $('#department_id').val();
                const classId = $('#class_id').val();
                const subjectId = $('#subject_id').val();
                const termId = $('#term_id').val();
                $('#submit-btn').prop('disabled', !departmentId || !classId || !subjectId || !termId);
            }
            $('#department_id, #class_id, #subject_id, #term_id').on('change', updateSubmitButton);

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
                $('#department_id').val(<?php echo $department_id; ?>).trigger('change');
                $('#term_id').val(<?php echo $term_id; ?>).trigger('change');
                setTimeout(() => {
                    $('#class_id').val(<?php echo $class_id; ?>);
                    $('#subject_id').val(<?php echo $subject_id; ?>);
                    updateSubmitButton();
                }, 1000);
            <?php endif; ?>

            // Auto-dismiss alerts after 5s
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);

            // View Plan Modal Handler
            document.querySelectorAll('[data-bs-target="#viewPlanModal"]').forEach(button => {
                button.addEventListener('click', function() {
                    const plan = JSON.parse(this.getAttribute('data-plan'));
                    document.getElementById('view-department').textContent = plan.department;
                    document.getElementById('view-class').textContent = plan.class;
                    document.getElementById('view-subject').textContent = plan.subject;
                    document.getElementById('view-term').textContent = plan.term;
                    document.getElementById('view-week').textContent = plan.week;
                    document.getElementById('view-unit').textContent = plan.unit;
                    document.getElementById('view-topic').textContent = plan.topic;
                    document.getElementById('view-lesson').textContent = plan.lesson;
                    document.getElementById('view-created-at').textContent = plan.created_at;
                });
            });

            // Initial term number setup
            setTermNumber();
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>