<?php
// Disable error reporting for production to prevent stray output
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');

// Start output buffering and session with explicit configuration
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Log session ID for debugging
error_log("Session ID: " . session_id());

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
} else {
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate user_id
$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
if (!$user_check_stmt) {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->bind_param("i", $teacher_id);
$user_check_stmt->execute();
if ($user_check_stmt->get_result()->num_rows === 0) {
    error_log("Invalid teacher_id: $teacher_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->close();

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed Input Academic Comments", "Teacher accessed page from IP: $ip_address, UA: $user_agent");

// CSRF Token Management using Database
function generateCsrfToken($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token valid for 1 hour
    $insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
    $stmt = $conn->prepare($insert_sql);
    if (!$stmt) {
        error_log("Failed to prepare CSRF insert: " . $conn->error);
        return false;
    }
    $stmt->bind_param("iss", $user_id, $token, $expires_at);
    if (!$stmt->execute()) {
        error_log("Failed to insert CSRF token: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $stmt->close();
    error_log("Generated CSRF token for user_id $user_id: $token, expires: $expires_at");
    return $token;
}

function validateCsrfToken($conn, $user_id, $token) {
    $sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW() AND created_at <= NOW()";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare CSRF validation: " . $conn->error);
        return false;
    }
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result->num_rows > 0;
    $stmt->close();
    if ($valid) {
        // Delete used token to prevent reuse
        $delete_sql = "DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("is", $user_id, $token);
        $delete_stmt->execute();
        $delete_stmt->close();
    }
    error_log("CSRF validation for user_id $user_id, token $token: " . ($valid ? "Valid" : "Invalid"));
    return $valid;
}

// Generate CSRF token for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $csrf_token = generateCsrfToken($conn, $teacher_id);
    if ($csrf_token === false) {
        // Fallback to session-based token
        $csrf_token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf_token;
        error_log("Fallback to session CSRF token: $csrf_token");
    } else {
        $_SESSION['csrf_token'] = $csrf_token; // Store in session for consistency
    }
} else {
    $csrf_token = $_SESSION['csrf_token'] ?? '';
    error_log("Using existing CSRF token for POST: $csrf_token");
}

// Prevent caching to avoid stale tokens
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Handle internal AJAX GET requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'get_subjects' && isset($_GET['class_id'])) {
        $class_id = intval($_GET['class_id']);
        $dept_sql = "SELECT department_id FROM classes WHERE id = ?";
        $dept_stmt = $conn->prepare($dept_sql);
        if (!$dept_stmt) {
            error_log("Prepare failed for department query: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit();
        }
        $dept_stmt->bind_param("i", $class_id);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->get_result();
        $department_id = $dept_result->fetch_assoc()['department_id'] ?? 0;
        $dept_stmt->close();

        if ($department_id === 0) {
            error_log("Invalid class_id $class_id or no department found");
            echo json_encode(['success' => false, 'error' => 'Invalid class or department']);
            exit();
        }

        $subjects_query = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name ASC";
        $stmt = $conn->prepare($subjects_query);
        if (!$stmt) {
            error_log("Prepare failed for subjects query: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit();
        }
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'subjects' => $subjects]);
        exit();
    } elseif ($_GET['action'] === 'get_students' && isset($_GET['class_id'])) {
        $class_id = intval($_GET['class_id']);
        $students_query = "SELECT u.id, u.name FROM users u JOIN student_classes sc ON u.id = sc.user_id WHERE sc.class_id = ? AND u.role = 'student' ORDER BY u.name ASC";
        $stmt = $conn->prepare($students_query);
        if (!$stmt) {
            error_log("Prepare failed for students query: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit();
        }
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'students' => $students]);
        exit();
    } elseif ($_GET['action'] === 'get_existing_scores' && isset($_GET['class_id']) && isset($_GET['subject_id']) && isset($_GET['term_id']) && isset($_GET['entry_period']) && isset($_GET['start_date']) && isset($_GET['end_date'])) {
        $class_id = intval($_GET['class_id']);
        $subject_id = intval($_GET['subject_id']);
        $term_id = intval($_GET['term_id']);
        $entry_period = $_GET['entry_period'];
        $start_date = $_GET['start_date'];
        $end_date = $_GET['end_date'];
        $academic_year = getCurrentAcademicYear();

        $scores_query = "SELECT student_id, class_practices_raw, assignments_raw, cat_raw, group_project_raw, comments, class_practices_max, assignments_max, cat_max, group_project_max 
                         FROM academic_progress_entries 
                         WHERE class_id = ? AND subject_id = ? AND term_id = ? AND entry_period = ? AND period_start_date = ? AND period_end_date = ? AND academic_year = ?";
        $stmt = $conn->prepare($scores_query);
        if (!$stmt) {
            error_log("Prepare failed for scores query: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit();
        }
        $stmt->bind_param("iiissss", $class_id, $subject_id, $term_id, $entry_period, $start_date, $end_date, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $scores = [];
        while ($row = $result->fetch_assoc()) {
            $scores[$row['student_id']] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'scores' => $scores]);
        exit();
    }
    error_log("Invalid GET action: " . ($_GET['action'] ?? 'none'));
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit();
}

// Handle POST requests for submitting scores
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Log CSRF tokens for debugging
    $sent_csrf = $_POST['csrf_token'] ?? 'none';
    $session_csrf = $_SESSION['csrf_token'] ?? 'none';
    error_log("CSRF Check - Sent: $sent_csrf, Session: $session_csrf");

    if (!isset($_POST['csrf_token']) || !validateCsrfToken($conn, $teacher_id, $_POST['csrf_token'])) {
        error_log("CSRF token validation failed - Sent: $sent_csrf, Expected: $session_csrf");
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit();
    }
    if (!isset($_POST['action']) || $_POST['action'] !== 'submit_scores') {
        error_log("Invalid POST action: " . ($_POST['action'] ?? 'none'));
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit();
    }

    // Validate inputs
    $class_id = intval($_POST['class_id'] ?? 0);
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $term_id = intval($_POST['term_id'] ?? 0);
    $academic_year = $_POST['academic_year'] ?? '';
    $entry_period = $_POST['entry_period'] ?? '';
    $period_start_date = $_POST['period_start_date'] ?? '';
    $period_end_date = $_POST['period_end_date'] ?? '';
    $max_scores = [
        'class_practices_max' => floatval($_POST['class_practices_max'] ?? 0),
        'assignments_max' => floatval($_POST['assignments_max'] ?? 0),
        'cat_max' => floatval($_POST['cat_max'] ?? 0),
        'group_project_max' => floatval($_POST['group_project_max'] ?? 0)
    ];

    // Log input data for debugging
    error_log("POST Data - class_id: $class_id, subject_id: $subject_id, term_id: $term_id, entry_period: $entry_period, students_count: " . count(json_decode($_POST['students'] ?? '[]', true)));

    // Server-side validation
    if ($class_id <= 0 || $subject_id <= 0 || $term_id <= 0 || empty($academic_year) || 
        empty($entry_period) || empty($period_start_date) || empty($period_end_date)) {
        error_log("Validation failed: Missing required fields");
        echo json_encode(['success' => false, 'error' => 'All selection fields are required']);
        exit();
    }

    if ($max_scores['class_practices_max'] <= 0 || $max_scores['assignments_max'] <= 0 || 
        $max_scores['cat_max'] <= 0 || $max_scores['group_project_max'] <= 0) {
        error_log("Validation failed: Max scores not positive");
        echo json_encode(['success' => false, 'error' => 'All max scores must be positive']);
        exit();
    }

    $students = json_decode($_POST['students'] ?? '[]', true);
    if (empty($students)) {
        error_log("Validation failed: No students provided");
        echo json_encode(['success' => false, 'error' => 'No students selected']);
        exit();
    }

    // Validate dates
    if (!DateTime::createFromFormat('Y-m-d', $period_start_date) || 
        !DateTime::createFromFormat('Y-m-d', $period_end_date)) {
        error_log("Validation failed: Invalid date format");
        echo json_encode(['success' => false, 'error' => 'Invalid date format']);
        exit();
    }

    $conn->begin_transaction();
    try {
        foreach ($students as $student) {
            $student_id = intval($student['id'] ?? 0);
            $class_practices_raw = floatval($student['class_practices_raw'] ?? 0);
            $assignments_raw = floatval($student['assignments_raw'] ?? 0);
            $cat_raw = floatval($student['cat_raw'] ?? 0);
            $group_project_raw = floatval($student['group_project_raw'] ?? 0);
            $comments = mysqli_real_escape_string($conn, $student['comments'] ?? '');

            if ($student_id <= 0) {
                throw new Exception("Invalid student ID: $student_id");
            }

            if ($class_practices_raw > $max_scores['class_practices_max'] || 
                $assignments_raw > $max_scores['assignments_max'] || 
                $cat_raw > $max_scores['cat_max'] || 
                $group_project_raw > $max_scores['group_project_max']) {
                throw new Exception("Raw scores exceed max for student_id: $student_id");
            }

            // Calculate scaled scores and total
            $scaled_cp = $max_scores['class_practices_max'] > 0 ? ($class_practices_raw / $max_scores['class_practices_max']) * 25 : 0;
            $scaled_as = $max_scores['assignments_max'] > 0 ? ($assignments_raw / $max_scores['assignments_max']) * 25 : 0;
            $scaled_cat = $max_scores['cat_max'] > 0 ? ($cat_raw / $max_scores['cat_max']) * 25 : 0;
            $scaled_gp = $max_scores['group_project_max'] > 0 ? ($group_project_raw / $max_scores['group_project_max']) * 25 : 0;
            $total_scaled = $scaled_cp + $scaled_as + $scaled_cat + $scaled_gp;
            $scaled_scores_json = json_encode([
                'class_practices' => $scaled_cp,
                'assignments' => $scaled_as,
                'cat' => $scaled_cat,
                'group_project' => $scaled_gp
            ]);

            $insert_sql = "
                INSERT INTO academic_progress_entries (
                    student_id, teacher_id, class_id, subject_id, term_id, academic_year, entry_period, 
                    period_start_date, period_end_date, class_practices_raw, class_practices_max, 
                    assignments_raw, assignments_max, cat_raw, cat_max, group_project_raw, group_project_max, 
                    comments, scaled_scores, total_scaled, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted'
                ) ON DUPLICATE KEY UPDATE
                    class_practices_raw = VALUES(class_practices_raw), class_practices_max = VALUES(class_practices_max),
                    assignments_raw = VALUES(assignments_raw), assignments_max = VALUES(assignments_max),
                    cat_raw = VALUES(cat_raw), cat_max = VALUES(cat_max),
                    group_project_raw = VALUES(group_project_raw), group_project_max = VALUES(group_project_max),
                    comments = VALUES(comments), scaled_scores = VALUES(scaled_scores), total_scaled = VALUES(total_scaled),
                    status = 'submitted', updated_at = CURRENT_TIMESTAMP";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) {
                error_log("Prepare failed for student_id $student_id: " . $conn->error);
                throw new Exception("Database error: Unable to prepare statement");
            }
            $stmt->bind_param("iiiisssssddddddddsss",
                $student_id, $teacher_id, $class_id, $subject_id, $term_id, $academic_year, $entry_period,
                $period_start_date, $period_end_date,
                $class_practices_raw, $max_scores['class_practices_max'],
                $assignments_raw, $max_scores['assignments_max'],
                $cat_raw, $max_scores['cat_max'],
                $group_project_raw, $max_scores['group_project_max'],
                $comments, $scaled_scores_json, $total_scaled
            );
            if (!$stmt->execute()) {
                error_log("Execute failed for student_id $student_id: " . $stmt->error);
                throw new Exception("Database error: Failed to execute statement");
            }
            $stmt->close();
        }
        $conn->commit();
        logActivity($conn, $teacher_id, "Submitted Comments", "Comments submitted for class_id=$class_id, subject_id=$subject_id, term_id=$term_id");
        echo json_encode(['success' => true, 'message' => 'Comments submitted successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Submit comments error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Helper function for current academic year
function getCurrentAcademicYear() {
    $today = new DateTime('2025-10-16 10:05:00 GMT');
    $month = (int)$today->format('m');
    $year = (int)$today->format('Y');
    return $month >= 9 ? "$year/" . ($year + 1) : ($year - 1) . "/$year";
}
$current_academic_year = getCurrentAcademicYear();

// Fetch classes
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name ASC";
$classes_result = $conn->query($classes_sql);
if (!$classes_result) {
    error_log("Failed to fetch classes: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch classes.";
    header("Location: /login.php");
    exit();
}
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[] = $row;
}

// Fetch terms
$terms_sql = "SELECT id, term_name, year FROM academic_calendar ORDER BY year DESC, start_date DESC";
$terms_result = $conn->query($terms_sql);
if (!$terms_result) {
    error_log("Failed to fetch terms: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch terms.";
    header("Location: /login.php");
    exit();
}
$terms = [];
while ($row = $terms_result->fetch_assoc()) {
    $terms[] = $row;
}

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Futuristic teacher page for inputting academic comments at QueensPa School.">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Input Academic Comments - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
                        success: { 500: '#10b981', 600: '#059669' },
                        error: { 500: '#ef4444', 600: '#dc2626' },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out',
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'zoom-in': 'zoomIn 0.6s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'glow': 'glow 2s ease-in-out infinite',
                        'spin-slow': 'spin 2s linear infinite',
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
            min-height: 100vh;
            background: linear-gradient(to bottom right, #1a202c, #2d3748);
            color: #e5e7eb;
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
        .form-select, .form-control, textarea {
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .form-select:focus, .form-control:focus, textarea:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 0.25rem rgba(249, 115, 22, 0.25);
            background-color: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }
        .form-select option {
            background-color: #1a202c;
            color: #ffffff;
        }
        .form-control::placeholder, textarea::placeholder {
            color: #9ca3af;
        }
        table thead {
            background: linear-gradient(to right, #f97316, #ea580c);
        }
        table tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        table td, table th {
            border-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }
        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .success-message {
            color: #10b981;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .loading-spinner {
            display: inline-block;
            width: 1.5rem;
            height: 1.5rem;
            border: 3px solid #ffffff;
            border-top-color: #f97316;
            border-radius: 50%;
            animation: spin-slow 1s linear infinite;
        }
        [disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }
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
    <div class="content" id="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <div class="flex flex-col md:flex-row justify-between items-center mb-8 animate-fade-in">
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white text-center flex-grow mb-4 md:mb-0 animate-glow" aria-label="Input Academic Comments">
                    <i class="fas fa-chart-line mr-2"></i> Input Academic Comments
                </h1>
                <button class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism" id="theme-toggle" aria-label="Toggle dark mode">
                    <i class="fas fa-moon mr-2"></i> Toggle Theme
                </button>
            </div>

            <!-- Selection Section -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in p-6" aria-labelledby="selection-heading">
                <h3 id="selection-heading" class="text-xl font-semibold mb-4 text-white">Select Criteria</h3>
                <form id="selection-form" class="grid md:grid-cols-6 gap-4">
                    <input type="hidden" id="academic_year" name="academic_year" value="<?= htmlspecialchars($current_academic_year) ?>">
                    <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="relative">
                        <label for="class_id" class="block text-sm font-medium text-gray-300">Class</label>
                        <select id="class_id" name="class_id" class="form-select w-full" aria-required="true" aria-describedby="class_id_error">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="class_id_error" class="error-message hidden" role="alert">Class is required</p>
                    </div>
                    <div class="relative">
                        <label for="subject_id" class="block text-sm font-medium text-gray-300">Subject</label>
                        <select id="subject_id" name="subject_id" class="form-select w-full" disabled aria-required="true" aria-describedby="subject_id_error">
                            <option value="">Select Subject</option>
                        </select>
                        <p id="subject_id_error" class="error-message hidden" role="alert">Subject is required</p>
                    </div>
                    <div class="relative">
                        <label for="term_id" class="block text-sm font-medium text-gray-300">Term</label>
                        <select id="term_id" name="term_id" class="form-select w-full" aria-required="true" aria-describedby="term_id_error">
                            <option value="">Select Term</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['id'] ?>"><?= htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="term_id_error" class="error-message hidden" role="alert">Term is required</p>
                    </div>
                    <div class="relative">
                        <label for="entry_period" class="block text-sm font-medium text-gray-300">Period Type</label>
                        <select id="entry_period" name="entry_period" class="form-select w-full" aria-required="true" aria-describedby="entry_period_error">
                            <option value="">Select Period</option>
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-Weekly</option>
                        </select>
                        <p id="entry_period_error" class="error-message hidden" role="alert">Period type is required</p>
                    </div>
                    <div class="relative">
                        <label for="period_start_date" class="block text-sm font-medium text-gray-300">Start Date</label>
                        <input type="date" id="period_start_date" name="period_start_date" class="form-control w-full" aria-required="true" aria-describedby="period_start_error">
                        <p id="period_start_error" class="error-message hidden" role="alert">Start date is required</p>
                    </div>
                    <div class="relative">
                        <label for="period_end_date" class="block text-sm font-medium text-gray-300">End Date</label>
                        <input type="date" id="period_end_date" name="period_end_date" class="form-control w-full" aria-required="true" aria-describedby="period_end_error">
                        <p id="period_end_error" class="error-message hidden" role="alert">End date is required</p>
                    </div>
                </form>
            </section>

            <!-- Max Scores Section -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in p-6" aria-labelledby="max-scores-heading">
                <h3 id="max-scores-heading" class="text-xl font-semibold mb-4 text-white">Set Max Scores for Period</h3>
                <form id="max-scores-form" class="grid md:grid-cols-4 gap-4">
                    <div class="relative">
                        <label for="class_practices_max" class="block text-sm font-medium text-gray-300">Class Practices Max</label>
                        <input type="number" id="class_practices_max" name="class_practices_max" min="0" step="0.01" class="form-control w-full" placeholder="e.g., 100" value="100" aria-required="true" aria-describedby="class_practices_max_error">
                        <p id="class_practices_max_error" class="error-message hidden" role="alert">Must be positive</p>
                    </div>
                    <div class="relative">
                        <label for="assignments_max" class="block text-sm font-medium text-gray-300">Assignments Max</label>
                        <input type="number" id="assignments_max" name="assignments_max" min="0" step="0.01" class="form-control w-full" placeholder="e.g., 100" value="100" aria-required="true" aria-describedby="assignments_max_error">
                        <p id="assignments_max_error" class="error-message hidden" role="alert">Must be positive</p>
                    </div>
                    <div class="relative">
                        <label for="cat_max" class="block text-sm font-medium text-gray-300">CAT Max</label>
                        <input type="number" id="cat_max" name="cat_max" min="0" step="0.01" class="form-control w-full" placeholder="e.g., 100" value="100" aria-required="true" aria-describedby="cat_max_error">
                        <p id="cat_max_error" class="error-message hidden" role="alert">Must be positive</p>
                    </div>
                    <div class="relative">
                        <label for="group_project_max" class="block text-sm font-medium text-gray-300">Group/Project Max</label>
                        <input type="number" id="group_project_max" name="group_project_max" min="0" step="0.01" class="form-control w-full" placeholder="e.g., 100" value="100" aria-required="true" aria-describedby="group_project_max_error">
                        <p id="group_project_max_error" class="error-message hidden" role="alert">Must be positive</p>
                    </div>
                </form>
                <div class="mt-4 flex justify-center">
                    <button id="apply-max-bulk" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism" aria-label="Apply max comments to all inputs">Apply Highest to All</button>
                </div>
            </section>

            <!-- Students Section -->
            <section id="students-section" class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in p-6 overflow-x-auto" aria-labelledby="students-heading">
                <h3 id="students-heading" class="text-xl font-semibold mb-4 text-white">Input Student Comments</h3>
                <div class="overflow-x-auto max-h-96">
                    <table class="w-full table-auto min-w-max" aria-label="Student comments table">
                        <thead class="sticky top-0 bg-gradient-to-r from-orange-500 to-red-500 text-white">
                            <tr role="row">
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Student</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Class Practices Level</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Scaled/25</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Assignments Level</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Scaled/25</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">CAT Level</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Scaled/25</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Group/Project Level</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Scaled/25</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Total/100</th>
                                <th class="px-4 py-3 text-left font-semibold" scope="col" role="columnheader">Comments</th>
                            </tr>
                        </thead>
                        <tbody id="students-table-body" class="divide-y divide-gray-500" role="rowgroup">
                            <tr role="row">
                                <td colspan="11" class="px-4 py-8 text-center text-gray-300">Select criteria to load students and scores</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-center mt-4">
                    <button type="button" id="submit-scores" class="bg-gradient-to-r from-success-500 to-success-600 hover:from-success-600 hover:to-success-700 text-white px-4 py-2 rounded-lg font-medium transition-all duration-300 w-full max-w-md flex items-center justify-center glassmorphism" aria-label="Submit comments">
                        <span id="submit-text">Submit Comments</span>
                        <span id="submit-spinner" class="loading-spinner hidden ml-2"></span>
                    </button>
                </div>
                <p id="submit_error" class="error-message hidden mt-2 text-center" role="alert"></p>
                <p id="submit_success" class="success-message hidden mt-2 text-center" role="alert"></p>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ob_end_flush(); ?>

    <script>
        // Theme toggle
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

        // Form elements
        const classIdInput = document.getElementById('class_id');
        const subjectIdInput = document.getElementById('subject_id');
        const termIdInput = document.getElementById('term_id');
        const entryPeriodInput = document.getElementById('entry_period');
        const startDateInput = document.getElementById('period_start_date');
        const endDateInput = document.getElementById('period_end_date');
        const classPracticesMaxInput = document.getElementById('class_practices_max');
        const assignmentsMaxInput = document.getElementById('assignments_max');
        const catMaxInput = document.getElementById('cat_max');
        const groupProjectMaxInput = document.getElementById('group_project_max');
        const applyMaxBulk = document.getElementById('apply-max-bulk');
        const submitButton = document.getElementById('submit-scores');
        const submitText = document.getElementById('submit-text');
        const submitSpinner = document.getElementById('submit-spinner');
        const submitError = document.getElementById('submit_error');
        const submitSuccess = document.getElementById('submit_success');
        const csrfTokenInput = document.getElementById('csrf_token');

        // Error handling functions
        function showError(elementId, message) {
            const errorEl = document.getElementById(elementId);
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
            errorEl.focus();
        }

        function hideError(elementId) {
            document.getElementById(elementId).classList.add('hidden');
        }

        function showSuccess(message) {
            submitSuccess.textContent = message;
            submitSuccess.classList.remove('hidden');
            submitSuccess.focus();
        }

        // Fetch subjects on class change
        classIdInput.addEventListener('change', () => {
            hideError('class_id_error');
            subjectIdInput.innerHTML = '<option value="">Select Subject</option>';
            subjectIdInput.disabled = true;
            if (classIdInput.value) {
                fetch(`?action=get_subjects&class_id=${classIdInput.value}`)
                    .then(res => {
                        if (!res.ok) throw new Error(`HTTP error! Status: ${res.status}`);
                        return res.json();
                    })
                    .then(data => {
                        if (data.success) {
                            data.subjects.forEach(subject => {
                                const option = document.createElement('option');
                                option.value = subject.id;
                                option.textContent = subject.subject_name;
                                subjectIdInput.appendChild(option);
                            });
                            subjectIdInput.disabled = false;
                        } else {
                            showError('subject_id_error', data.error || 'Failed to load subjects');
                        }
                    })
                    .catch(error => {
                        console.error('Fetch subjects error:', error);
                        showError('subject_id_error', `Network error: ${error.message}`);
                    });
            }
            loadStudentsAndScores();
        });

        // Set period dates on period type change
        entryPeriodInput.addEventListener('change', () => {
            hideError('entry_period_error');
            const periodType = entryPeriodInput.value;
            if (!periodType) {
                startDateInput.value = '';
                endDateInput.value = '';
                return;
            }
            let today = new Date('2025-10-16');
            let weekday = today.getDay();
            let currentStart = new Date(today);
            currentStart.setDate(currentStart.getDate() - weekday + 1); // Monday

            let startDate, endDate;
            endDate = new Date(currentStart);
            endDate.setDate(endDate.getDate() + 6); // Sunday

            if (periodType === 'weekly') {
                startDate = new Date(currentStart);
            } else { // bi-weekly
                startDate = new Date(currentStart);
                startDate.setDate(startDate.getDate() - 7);
            }

            startDateInput.value = startDate.toISOString().split('T')[0];
            endDateInput.value = endDate.toISOString().split('T')[0];
            loadStudentsAndScores();
        });

        // Trigger load on changes that affect existing scores
        [subjectIdInput, termIdInput, startDateInput, endDateInput].forEach(input => {
            input.addEventListener('change', () => {
                hideError(`${input.id}_error`);
                loadStudentsAndScores();
            });
        });

        // Validate max scores
        function validateMax(maxInput) {
            const value = parseFloat(maxInput.value);
            const errorId = `${maxInput.id}_error`;
            if (isNaN(value) || value <= 0) {
                showError(errorId, 'Must be a positive number');
                return false;
            }
            hideError(errorId);
            return true;
        }

        [classPracticesMaxInput, assignmentsMaxInput, catMaxInput, groupProjectMaxInput].forEach(input => {
            input.addEventListener('input', () => {
                validateMax(input);
                updateAllScaled();
            });
        });

        // Apply max bulk
        applyMaxBulk.addEventListener('click', () => {
            document.querySelectorAll('#students-table-body tr[data-student-id]').forEach(row => {
                ['class_practices', 'assignments', 'cat', 'group_project'].forEach(component => {
                    const input = row.querySelector(`.raw-input[data-component="${component}"]`);
                    if (input) {
                        input.value = '1.00';
                        updateScaled(input);
                    }
                });
            });
        });

        // Load students and existing scores
        function loadStudentsAndScores() {
            const classId = classIdInput.value;
            const subjectId = subjectIdInput.value;
            const termId = termIdInput.value;
            const entryPeriod = entryPeriodInput.value;
            const startDate = startDateInput.value;
            const endDate = endDateInput.value;

            if (!classId || !subjectId || !termId || !entryPeriod || !startDate || !endDate) {
                document.getElementById('students-table-body').innerHTML = '<tr role="row"><td colspan="11" class="px-4 py-8 text-center text-gray-300">Complete selections to load students and scores</td></tr>';
                return;
            }

            const tbody = document.getElementById('students-table-body');
            tbody.innerHTML = '<tr role="row"><td colspan="11" class="px-4 py-8 text-center text-gray-300">Loading students and scores...</td></tr>';

            fetch(`?action=get_students&class_id=${classId}`)
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP error! Status: ${res.status}`);
                    return res.json();
                })
                .then(studentData => {
                    if (!studentData.success) {
                        tbody.innerHTML = `<tr role="row"><td colspan="11" class="px-4 py-8 text-center text-error-500">${studentData.error || 'Failed to load students'}</td></tr>`;
                        return;
                    }
                    if (studentData.students.length === 0) {
                        tbody.innerHTML = '<tr role="row"><td colspan="11" class="px-4 py-8 text-center text-gray-300">No students found</td></tr>';
                        return;
                    }

                    fetch(`?action=get_existing_scores&class_id=${classId}&subject_id=${subjectId}&term_id=${termId}&entry_period=${entryPeriod}&start_date=${startDate}&end_date=${endDate}`)
                        .then(res => {
                            if (!res.ok) throw new Error(`HTTP error! Status: ${res.status}`);
                            return res.json();
                        })
                        .then(scoreData => {
                            const existingScores = scoreData.success ? scoreData.scores : {};
                            if (existingScores && Object.keys(existingScores).length > 0) {
                                const firstScore = Object.values(existingScores)[0];
                                classPracticesMaxInput.value = firstScore.class_practices_max || '100';
                                assignmentsMaxInput.value = firstScore.assignments_max || '100';
                                catMaxInput.value = firstScore.cat_max || '100';
                                groupProjectMaxInput.value = firstScore.group_project_max || '100';
                            }

                            tbody.innerHTML = '';
                            studentData.students.forEach(student => {
                                const score = existingScores[student.id] || {};
                                const row = document.createElement('tr');
                                row.role = 'row';
                                row.className = 'hover:bg-gray-700 transition-colors duration-200';
                                row.dataset.studentId = student.id;
                                row.innerHTML = `
                                    <td class="px-4 py-3 font-medium text-white">${student.name}</td>
                                    <td class="px-4 py-3">
                                        <select class="raw-input form-control bg-transparent border border-gray-500 text-white rounded-lg w-full" data-component="class_practices" aria-label="Class Practices Level for ${student.name}">
                                            <option value="">Select Level</option>
                                            <option value="1.00">Bravo</option>
                                            <option value="0.90">Well done</option>
                                            <option value="0.80">Good job</option>
                                            <option value="0.70">Very Good</option>
                                            <option value="0.60">Good</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3 scaled text-white" data-component="class_practices">0.00</td>
                                    <td class="px-4 py-3">
                                        <select class="raw-input form-control bg-transparent border border-gray-500 text-white rounded-lg w-full" data-component="assignments" aria-label="Assignments Level for ${student.name}">
                                            <option value="">Select Level</option>
                                            <option value="1.00">Bravo</option>
                                            <option value="0.90">Well done</option>
                                            <option value="0.80">Good job</option>
                                            <option value="0.70">Very Good</option>
                                            <option value="0.60">Good</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3 scaled text-white" data-component="assignments">0.00</td>
                                    <td class="px-4 py-3">
                                        <select class="raw-input form-control bg-transparent border border-gray-500 text-white rounded-lg w-full" data-component="cat" aria-label="CAT Level for ${student.name}">
                                            <option value="">Select Level</option>
                                            <option value="1.00">Bravo</option>
                                            <option value="0.90">Well done</option>
                                            <option value="0.80">Good job</option>
                                            <option value="0.70">Very Good</option>
                                            <option value="0.60">Good</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3 scaled text-white" data-component="cat">0.00</td>
                                    <td class="px-4 py-3">
                                        <select class="raw-input form-control bg-transparent border border-gray-500 text-white rounded-lg w-full" data-component="group_project" aria-label="Group/Project Level for ${student.name}">
                                            <option value="">Select Level</option>
                                            <option value="1.00">Bravo</option>
                                            <option value="0.90">Well done</option>
                                            <option value="0.80">Good job</option>
                                            <option value="0.70">Very Good</option>
                                            <option value="0.60">Good</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3 scaled text-white" data-component="group_project">0.00</td>
                                    <td class="px-4 py-3 font-bold total-scaled text-white">0.00</td>
                                    <td class="px-4 py-3"><textarea class="comments form-control bg-transparent border border-gray-500 text-white rounded-lg w-full" placeholder="Enter comments" aria-label="Comments for ${student.name}">${score.comments || ''}</textarea></td>
                                `;
                                tbody.appendChild(row);

                                // Set selected values based on existing scores
                                ['class_practices', 'assignments', 'cat', 'group_project'].forEach(component => {
                                    const select = row.querySelector(`.raw-input[data-component="${component}"]`);
                                    const raw = parseFloat(score[`${component}_raw`]) || 0;
                                    const max = parseFloat(score[`${component}_max`]) || 0;
                                    const norm = max > 0 ? (raw / max) * 100 : 0;
                                    const factor = getFactorFromNorm(norm);
                                    select.value = factor;
                                });
                            });
                            addInputListeners();
                            updateAllScaled();
                        })
                        .catch(error => {
                            console.error('Fetch scores error:', error);
                            tbody.innerHTML = `<tr role="row"><td colspan="11" class="px-4 py-8 text-center text-error-500">Network error: ${error.message}</td></tr>`;
                        });
                })
                .catch(error => {
                    console.error('Fetch students error:', error);
                    tbody.innerHTML = `<tr role="row"><td colspan="11" class="px-4 py-8 text-center text-error-500">Network error: ${error.message}</td></tr>`;
                });
        }

        // Map normalized score to factor
        function getFactorFromNorm(norm) {
            if (norm >= 91) return '1.00';
            if (norm >= 81) return '0.90';
            if (norm >= 71) return '0.80';
            if (norm >= 61) return '0.70';
            return '0.60';
        }

        // Add listeners for real-time updates
        function addInputListeners() {
            document.querySelectorAll('.raw-input').forEach(input => {
                input.addEventListener('change', (e) => {
                    updateScaled(e.target);
                });
            });
        }

        // Update scaled for a single input
        function updateScaled(input) {
            const row = input.closest('tr');
            const component = input.dataset.component;
            const factor = parseFloat(input.value) || 0;
            const scaled = (factor * 25).toFixed(2);
            row.querySelector(`.scaled[data-component="${component}"]`).textContent = scaled;
            updateTotal(row);
        }

        // Update row total
        function updateTotal(row) {
            const scaleds = Array.from(row.querySelectorAll('.scaled')).map(td => parseFloat(td.textContent) || 0);
            const totalValue = scaleds.reduce((a, b) => a + b, 0);
            const totalDisplay = totalValue.toFixed(2);
            row.querySelector('.total-scaled').textContent = totalDisplay;

            // Auto-generate comment based on total
            const commentTextarea = row.querySelector('.comments');
            let comment = '';
            if (totalValue >= 0 && totalValue <= 69) {
                comment = 'Developing';
            } else if (totalValue >= 70 && totalValue <= 89) {
                comment = 'Secure';
            } else if (totalValue >= 90 && totalValue <= 100) {
                comment = 'Extending';
            }
            commentTextarea.value = comment;
        }

        // Update all scaled in table
        function updateAllScaled() {
            document.querySelectorAll('#students-table-body tr[data-student-id]').forEach(row => {
                ['class_practices', 'assignments', 'cat', 'group_project'].forEach(component => {
                    const input = row.querySelector(`.raw-input[data-component="${component}"]`);
                    if (input) updateScaled(input);
                });
            });
        }

        // Submit scores
        submitButton.addEventListener('click', () => {
            hideError('submit_error');
            submitSuccess.classList.add('hidden');

            let valid = true;
            [classIdInput, subjectIdInput, termIdInput, entryPeriodInput, startDateInput, endDateInput].forEach(input => {
                if (!input.value) {
                    showError(`${input.id}_error`, `${input.labels[0].textContent} is required`);
                    valid = false;
                }
            });

            [classPracticesMaxInput, assignmentsMaxInput, catMaxInput, groupProjectMaxInput].forEach(input => {
                if (!validateMax(input)) valid = false;
            });

            if (!valid) {
                showError('submit_error', 'Please correct errors');
                return;
            }

            const students = [];
            document.querySelectorAll('#students-table-body tr[data-student-id]').forEach(row => {
                const class_practices_factor = parseFloat(row.querySelector('.raw-input[data-component="class_practices"]').value) || 0;
                const assignments_factor = parseFloat(row.querySelector('.raw-input[data-component="assignments"]').value) || 0;
                const cat_factor = parseFloat(row.querySelector('.raw-input[data-component="cat"]').value) || 0;
                const group_project_factor = parseFloat(row.querySelector('.raw-input[data-component="group_project"]').value) || 0;

                students.push({
                    id: row.dataset.studentId,
                    class_practices_raw: class_practices_factor * parseFloat(classPracticesMaxInput.value) || 0,
                    assignments_raw: assignments_factor * parseFloat(assignmentsMaxInput.value) || 0,
                    cat_raw: cat_factor * parseFloat(catMaxInput.value) || 0,
                    group_project_raw: group_project_factor * parseFloat(groupProjectMaxInput.value) || 0,
                    comments: row.querySelector('.comments').value || ''
                });
            });

            if (students.length === 0) {
                showError('submit_error', 'No students to submit');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'submit_scores');
            formData.append('csrf_token', csrfTokenInput.value);
            formData.append('class_id', classIdInput.value);
            formData.append('subject_id', subjectIdInput.value);
            formData.append('term_id', termIdInput.value);
            formData.append('academic_year', document.getElementById('academic_year').value);
            formData.append('entry_period', entryPeriodInput.value);
            formData.append('period_start_date', startDateInput.value);
            formData.append('period_end_date', endDateInput.value);
            formData.append('class_practices_max', classPracticesMaxInput.value);
            formData.append('assignments_max', assignmentsMaxInput.value);
            formData.append('cat_max', catMaxInput.value);
            formData.append('group_project_max', groupProjectMaxInput.value);
            formData.append('students', JSON.stringify(students));

            console.log("Submitting to:", window.location.href);
            console.log("FormData:", Object.fromEntries(formData));
            console.log("CSRF Token Sent:", csrfTokenInput.value);
            console.log("Students JSON size:", JSON.stringify(students).length);

            submitButton.disabled = true;
            submitText.textContent = 'Submitting...';
            submitSpinner.classList.remove('hidden');

            fetch(window.location.href, { 
                method: 'POST', 
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            })
                .then(res => {
                    if (!res.ok) {
                        throw new Error(`HTTP error! Status: ${res.status} ${res.statusText}`);
                    }
                    return res.json();
                })
                .then(data => {
                    submitButton.disabled = false;
                    submitText.textContent = 'Submit Comments';
                    submitSpinner.classList.add('hidden');
                    if (data.success) {
                        showSuccess(data.message);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showError('submit_error', data.error || 'Submission failed');
                    }
                })
                .catch(error => {
                    submitButton.disabled = false;
                    submitText.textContent = 'Submit Comments';
                    submitSpinner.classList.add('hidden');
                    console.error('Fetch error:', error);
                    showError('submit_error', `Network error: ${error.message}`);
                });
        });

        // Enter key submit
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && !submitButton.disabled) {
                submitButton.click();
            }
        });
    </script>
</body>
</html>