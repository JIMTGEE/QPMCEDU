<?php
// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');

// Start output buffering and session
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Restrict to admins only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an admin.";
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

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Create necessary tables if missing
$create_csrf_table_sql = "
CREATE TABLE IF NOT EXISTS csrf_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_user_token (user_id, token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$conn->query($create_csrf_table_sql);

// Validate user_id
$admin_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, name, email FROM users WHERE id = ? AND role = 'admin'";
$user_check_stmt = $conn->prepare($user_check_sql);
if (!$user_check_stmt) {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->bind_param("i", $admin_id);
$user_check_stmt->execute();
$user_result = $user_check_stmt->get_result();
if ($user_result->num_rows === 0) {
    error_log("Invalid admin_id: $admin_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$admin_data = $user_result->fetch_assoc();
$user_check_stmt->close();

// CSRF Token Management
function generateCsrfToken($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
    $stmt = $conn->prepare($insert_sql);
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $token, $expires_at);
        if ($stmt->execute()) {
            $stmt->close();
            return $token;
        }
        $stmt->close();
    }
    error_log("Failed to generate CSRF token for user_id $user_id");
    return bin2hex(random_bytes(32));
}

function validateCsrfToken($conn, $user_id, $token) {
    if (empty($token)) return false;
    $sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = $result->num_rows > 0;
        if ($valid) {
            $delete_sql = "DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param("is", $user_id, $token);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        $stmt->close();
        return $valid;
    }
    error_log("CSRF validation failed for user_id $user_id");
    return false;
}

// Generate CSRF token for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
}

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Handle AJAX requests
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'];

    if ($action === 'get_departments') {
        $depts_sql = "SELECT id, name FROM departments ORDER BY name ASC";
        $result = $conn->query($depts_sql);
        if ($result) {
            $departments = [];
            while ($row = $result->fetch_assoc()) {
                $departments[] = $row;
            }
            echo json_encode(['success' => true, 'departments' => $departments]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to fetch departments']);
        }
        exit();
    } elseif ($action === 'get_classes_by_department' && isset($_GET['department_id'])) {
        $department_id = intval($_GET['department_id']);
        $classes_sql = "SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name ASC";
        $stmt = $conn->prepare($classes_sql);
        if ($stmt) {
            $stmt->bind_param("i", $department_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $classes = [];
            while ($row = $result->fetch_assoc()) {
                $classes[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'classes' => $classes]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_subjects_by_department' && isset($_GET['department_id'])) {
        $department_id = intval($_GET['department_id']);
        $subjects_sql = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name ASC";
        $stmt = $conn->prepare($subjects_sql);
        if ($stmt) {
            $stmt->bind_param("i", $department_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $subjects = [];
            while ($row = $result->fetch_assoc()) {
                $subjects[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'subjects' => $subjects]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_teachers') {
        $teachers_sql = "SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name ASC";
        $result = $conn->query($teachers_sql);
        if ($result) {
            $teachers = [];
            while ($row = $result->fetch_assoc()) {
                $teachers[] = $row;
            }
            echo json_encode(['success' => true, 'teachers' => $teachers]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to fetch teachers']);
        }
        exit();
    } elseif ($action === 'get_terms') {
        $terms_sql = "SELECT id, term_name, year, start_date, end_date FROM academic_calendar ORDER BY year DESC, start_date DESC";
        $result = $conn->query($terms_sql);
        if ($result) {
            $terms = [];
            while ($row = $result->fetch_assoc()) {
                $terms[] = $row;
            }
            echo json_encode(['success' => true, 'terms' => $terms]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to fetch terms']);
        }
        exit();
    } elseif ($action === 'get_students_by_class') {
        $class_id = intval($_GET['class_id']);
        $students_sql = "SELECT DISTINCT u.id, u.name 
                         FROM users u 
                         JOIN student_classes sc ON u.id = sc.user_id 
                         WHERE u.role = 'student' AND sc.class_id = ? AND sc.academic_year = '2025/2026'
                         ORDER BY u.name ASC";
        $stmt = $conn->prepare($students_sql);
        if ($stmt) {
            $stmt->bind_param("i", $class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $students = [];
            while ($row = $result->fetch_assoc()) {
                $students[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'students' => $students]);
        } else {
            error_log("Prepare failed for students query: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_periods' && isset($_GET['term_id']) && isset($_GET['entry_period'])) {
        $term_id = intval($_GET['term_id']);
        $entry_period = $_GET['entry_period'];
        $periods_query = "SELECT DISTINCT period_start_date, period_end_date 
                          FROM academic_progress_entries 
                          WHERE term_id = ? AND entry_period = ? 
                          ORDER BY period_start_date";
        $stmt = $conn->prepare($periods_query);
        if ($stmt) {
            $stmt->bind_param("is", $term_id, $entry_period);
            $stmt->execute();
            $result = $stmt->get_result();
            $periods = [];
            $index = 1;
            while ($row = $result->fetch_assoc()) {
                $periods[] = [
                    'start' => $row['period_start_date'],
                    'end' => $row['period_end_date'],
                    'label' => $entry_period === 'weekly' ? "Week $index" : "Bi-Week $index"
                ];
                $index++;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'periods' => $periods]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_subject_progress' && isset($_GET['subject_name']) && isset($_GET['department_id'])) {
        $subject_name = $_GET['subject_name'];
        $department_id = intval($_GET['department_id']);
        $entry_period = 'weekly';
        $query = "SELECT u.name AS student_name, ape.total_scaled, ape.period_start_date 
                  FROM academic_progress_entries ape 
                  JOIN users u ON ape.student_id = u.id 
                  JOIN subjects s ON ape.subject_id = s.id 
                  WHERE s.subject_name = ? AND s.department_id = ? AND ape.entry_period = ? 
                  ORDER BY ape.period_start_date, u.name";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("sis", $subject_name, $department_id, $entry_period);
            $stmt->execute();
            $result = $stmt->get_result();
            $progress = [];
            while ($row = $result->fetch_assoc()) {
                $progress[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'progress' => $progress]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_filtered_progress') {
        $class_id = intval($_POST['class_id']);
        $term_id = intval($_POST['term_id']);
        $entry_period = $_POST['entry_period'];
        $period_start = $_POST['period_start'];
        $period_end = $_POST['period_end'];
        $teacher_id = intval($_POST['teacher_id'] ?? 0);
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit();
        }
        $query = "SELECT u.name AS student_name, s.subject_name, ape.total_scaled, t.name AS teacher_name 
                  FROM academic_progress_entries ape 
                  JOIN users u ON ape.student_id = u.id 
                  JOIN subjects s ON ape.subject_id = s.id 
                  JOIN users t ON ape.teacher_id = t.id 
                  WHERE ape.class_id = ? AND ape.term_id = ? 
                  AND ape.entry_period = ? AND ape.period_start_date = ? AND ape.period_end_date = ?";
        $params = "iisss";
        $values = [$class_id, $term_id, $entry_period, $period_start, $period_end];
        if ($teacher_id > 0) {
            $query .= " AND ape.teacher_id = ?";
            $params .= "i";
            $values[] = $teacher_id;
        }
        $query .= " ORDER BY u.name, s.subject_name";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param($params, ...$values);
            $stmt->execute();
            $result = $stmt->get_result();
            $progress = [];
            while ($row = $result->fetch_assoc()) {
                $progress[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'progress' => $progress]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_student_progress' && isset($_GET['student_id']) && isset($_GET['entry_period'])) {
        $student_id = intval($_GET['student_id']);
        $entry_period = $_GET['entry_period'];
        $query = "SELECT s.subject_name, ape.total_scaled, ape.period_start_date, ape.period_end_date
                  FROM academic_progress_entries ape
                  JOIN subjects s ON ape.subject_id = s.id
                  WHERE ape.student_id = ? AND ape.entry_period = ?
                  ORDER BY s.subject_name, ape.period_start_date";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("is", $student_id, $entry_period);
            $stmt->execute();
            $result = $stmt->get_result();
            $progress = [];
            while ($row = $result->fetch_assoc()) {
                $progress[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'progress' => $progress]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_subject_comparison') {
        $student_id = intval($_POST['student_id']);
        $term_id = intval($_POST['term_id']);
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit();
        }
        $query = "SELECT s.subject_name, 
                         AVG(ape.class_practices_raw / ape.class_practices_max * 25) as class_practices_scaled,
                         AVG(ape.assignments_raw / ape.assignments_max * 25) as assignments_scaled,
                         AVG(ape.cat_raw / ape.cat_max * 25) as cat_scaled,
                         AVG(ape.group_project_raw / ape.group_project_max * 25) as group_project_scaled,
                         AVG(ape.total_scaled) as avg_scaled
                  FROM academic_progress_entries ape
                  JOIN subjects s ON ape.subject_id = s.id
                  WHERE ape.student_id = ? AND ape.term_id = ?
                  GROUP BY s.id, s.subject_name
                  ORDER BY s.subject_name";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ii", $student_id, $term_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $progress = [];
            while ($row = $result->fetch_assoc()) {
                $progress[] = [
                    'subject_name' => $row['subject_name'],
                    'class_practices_scaled' => round($row['class_practices_scaled'] ?? 0, 2),
                    'assignments_scaled' => round($row['assignments_scaled'] ?? 0, 2),
                    'cat_scaled' => round($row['cat_scaled'] ?? 0, 2),
                    'group_project_scaled' => round($row['group_project_scaled'] ?? 0, 2),
                    'total_scaled' => round($row['avg_scaled'], 2)
                ];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'progress' => $progress]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    } elseif ($action === 'get_department_averages') {
        $department_id = intval($_POST['department_id']);
        $term_id = intval($_POST['term_id']);
        $entry_period = $_POST['entry_period'];
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit();
        }
        $query = "SELECT c.class_name, AVG(ape.total_scaled) as avg_score
                  FROM academic_progress_entries ape
                  JOIN classes c ON ape.class_id = c.id
                  WHERE c.department_id = ? AND ape.term_id = ? AND ape.entry_period = ?
                  GROUP BY c.id, c.class_name
                  ORDER BY c.class_name";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("iis", $department_id, $term_id, $entry_period);
            $stmt->execute();
            $result = $stmt->get_result();
            $averages = [];
            while ($row = $result->fetch_assoc()) {
                $averages[] = [
                    'class_name' => $row['class_name'],
                    'avg_score' => round($row['avg_score'] ?? 0, 2)
                ];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'data' => $averages]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
        }
        exit();
    }
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit();
}

// Fetch initial subjects
$subjects_sql = "SELECT subject_name FROM subjects ORDER BY subject_name ASC";
$subjects_result = $conn->query($subjects_sql);
$subjects = [];
if ($subjects_result) {
    while ($row = $subjects_result->fetch_assoc()) {
        $subjects[] = $row;
    }
}

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="School-wide progress monitoring for QueensPa School Management System">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>School Progress Monitoring - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f97316 0%, #c2410c 100%);
            min-height: 100vh;
            color: #1f2937;
        }

        /* Modern Glassmorphism with Orange Theme */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(249, 115, 22, 0.2);
            box-shadow: 0 8px 32px rgba(249, 115, 22, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .glass-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 48px rgba(249, 115, 22, 0.15);
        }

        /* Animated Gradient Background with Orange */
        .animated-gradient {
            background: linear-gradient(-45deg, #f97316, #ea580c, #c2410c, #9a3412);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }

        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Modern Form Elements - Orange Theme */
        .modern-select, .modern-input {
            background: white;
            border: 2px solid #fed7aa;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            outline: none;
            width: 100%;
            color: #1f2937;
        }

        .modern-select:focus, .modern-input:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.1);
        }

        .modern-select option {
            background: white;
            color: #1f2937;
        }

        /* Tab Navigation - Orange Theme */
        .tab-navigation {
            display: flex;
            gap: 0.5rem;
            padding: 0.5rem;
            background: rgba(249, 115, 22, 0.1);
            border-radius: 16px;
            backdrop-filter: blur(10px);
        }

        .tab-button {
            flex: 1;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: transparent;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .tab-button i {
            font-size: 1.2rem;
        }

        .tab-button.active {
            background: white;
            color: #f97316;
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.2);
        }

        .tab-button:hover:not(.active) {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Metric Cards - Orange Theme */
        .metric-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(249, 115, 22, 0.1);
            transition: all 0.3s ease;
            border: 1px solid #fed7aa;
        }

        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(249, 115, 22, 0.15);
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #f97316 0%, #c2410c 100%);
            color: white;
        }

        /* Chart Containers */
        .chart-wrapper {
            background: white;
            border-radius: 24px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(249, 115, 22, 0.1);
            height: 100%;
            min-height: 400px;
            border: 1px solid #fed7aa;
        }

        /* Loading Spinner - Orange */
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #fed7aa;
            border-top: 4px solid #f97316;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Custom Scrollbar - Orange Theme */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: #fff7ed;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #f97316 0%, #c2410c 100%);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #c2410c 0%, #f97316 100%);
        }

        /* Pulse Animation */
        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .tab-button span {
                display: none;
            }
            
            .tab-button i {
                font-size: 1.5rem;
            }
        }

        /* Tooltip Styles */
        .custom-tooltip {
            position: relative;
            display: inline-block;
        }

        .custom-tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: #1f2937;
            color: white;
            text-align: center;
            border-radius: 8px;
            padding: 0.5rem;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.875rem;
            pointer-events: none;
        }

        .custom-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        /* Orange Button */
        .btn-orange {
            background: linear-gradient(135deg, #f97316 0%, #c2410c 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-orange:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(249, 115, 22, 0.3);
        }

        .btn-orange-outline {
            background: transparent;
            color: #f97316;
            border: 2px solid #f97316;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-orange-outline:hover {
            background: #f97316;
            color: white;
        }

        /* Orange Badge */
        .badge-orange {
            background: #fed7aa;
            color: #c2410c;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        /* Orange Text */
        .text-orange {
            color: #f97316;
        }

        .text-orange-dark {
            color: #c2410c;
        }

        /* Orange Border */
        .border-orange {
            border-color: #f97316;
        }
    </style>
</head>
<body class="antialiased">
    <!-- Animated Background -->
    <div class="fixed inset-0 animated-gradient opacity-10"></div>
    
    <!-- Main Content -->
    <div class="relative min-h-screen">
        <!-- Header -->
        <header class="glass-card sticky top-0 z-50 border-b border-orange-200">
            <div class="container mx-auto px-4 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <div class="w-12 h-12 bg-gradient-to-r from-orange-600 to-orange-800 rounded-2xl flex items-center justify-center shadow-lg">
                            <i class="fas fa-graduation-cap text-white text-2xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold bg-gradient-to-r from-orange-600 to-orange-800 bg-clip-text text-transparent">
                                QueensPa School
                            </h1>
                            <p class="text-sm text-gray-600">Academic Progress Monitoring</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <!-- Quick Stats -->
                        <div class="hidden md:flex items-center space-x-6">
                            <div class="text-right">
                                <p class="text-sm text-gray-600">Welcome back,</p>
                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($admin_data['name'] ?? 'Admin') ?></p>
                            </div>
                            <div class="w-10 h-10 bg-gradient-to-r from-orange-600 to-orange-800 rounded-full flex items-center justify-center text-white font-bold">
                                <?= strtoupper(substr($admin_data['name'] ?? 'A', 0, 1)) ?>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="flex space-x-2">
                            <button class="p-2 hover:bg-orange-50 rounded-lg transition-colors custom-tooltip" id="refresh-data">
                                <i class="fas fa-sync-alt text-orange-600"></i>
                                <span class="tooltip-text">Refresh Data</span>
                            </button>
                            <a href="view_mock_report.php" class="p-2 hover:bg-orange-50 rounded-lg transition-colors custom-tooltip">
                                <i class="fas fa-file-alt text-orange-600"></i>
                                <span class="tooltip-text">View Reports</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <!-- Welcome Section -->
            <div class="glass-card rounded-2xl p-8 mb-8">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-800 mb-2">
                            Academic Progress Dashboard
                        </h2>
                        <p class="text-gray-600">
                            Monitor student performance, track class progress, and analyze department metrics in real-time.
                        </p>
                    </div>
                    <div class="mt-4 md:mt-0 flex space-x-3">
                        <div class="bg-orange-100 text-orange-800 px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-circle text-xs mr-2 animate-pulse text-orange-500"></i>
                            <span class="text-sm font-medium">Live Data</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-navigation mb-8">
                <button class="tab-button active" data-tab="student">
                    <i class="fas fa-user-graduate"></i>
                    <span>Student Progress</span>
                </button>
                <button class="tab-button" data-tab="class">
                    <i class="fas fa-users"></i>
                    <span>Class Progress</span>
                </button>
                <button class="tab-button" data-tab="department">
                    <i class="fas fa-building"></i>
                    <span>Department Overview</span>
                </button>
            </div>

            <!-- Student Progress Tab -->
            <div id="student-tab" class="tab-content active">
                <div class="glass-card rounded-2xl p-6 mb-8">
                    <h3 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-filter mr-2 text-orange-600"></i>
                        Filter Student Data
                    </h3>
                    
                    <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-building mr-1 text-orange-600"></i> Department
                            </label>
                            <select id="student-department" class="modern-select">
                                <option value="">All Departments</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-chalkboard mr-1 text-orange-600"></i> Class
                            </label>
                            <select id="student-class_id" class="modern-select">
                                <option value="">Select Class</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-user mr-1 text-orange-600"></i> Student
                            </label>
                            <select id="student-student_id" class="modern-select">
                                <option value="">Select Student</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-alt mr-1 text-orange-600"></i> Term
                            </label>
                            <select id="student-term_id" class="modern-select">
                                <option value="">Select Term</option>
                            </select>
                        </div>
                        
                        <div class="lg:col-span-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-clock mr-1 text-orange-600"></i> Entry Period
                            </label>
                            <div class="flex space-x-4">
                                <label class="flex items-center">
                                    <input type="radio" name="student-entry_period" value="weekly" class="mr-2 text-orange-600" checked>
                                    <span class="text-gray-700">Weekly</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="student-entry_period" value="bi-weekly" class="mr-2 text-orange-600">
                                    <span class="text-gray-700">Bi-Weekly</span>
                                </label>
                            </div>
                        </div>
                        
                        <input type="hidden" id="student-csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    </div>
                </div>

                <!-- Student Metrics -->
                <div class="grid md:grid-cols-4 gap-6 mb-8" id="student-metrics" style="display: none;">
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="avg-score">0</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Average Score</h4>
                        <p class="text-xs text-gray-400">Across all subjects</p>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="top-subject">-</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Top Subject</h4>
                        <p class="text-xs text-gray-400">Highest performing</p>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="total-entries">0</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Total Entries</h4>
                        <p class="text-xs text-gray-400">Progress records</p>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-trend-up"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="trend">0%</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Progress Trend</h4>
                        <p class="text-xs text-gray-400">vs last period</p>
                    </div>
                </div>

                <!-- Student Charts -->
                <div class="grid lg:grid-cols-2 gap-8">
                    <div class="chart-wrapper" id="student-line-graph-container" style="display: none;">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-lg font-semibold text-gray-800">Progress Over Time</h4>
                            <span class="badge-orange">Weekly View</span>
                        </div>
                        <canvas id="student-line-chart" style="height: 300px;"></canvas>
                    </div>
                    
                    <div class="chart-wrapper" id="student-bar-graph-container" style="display: none;">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-lg font-semibold text-gray-800">Subject Performance Breakdown</h4>
                            <span class="badge-orange">Term Average</span>
                        </div>
                        <canvas id="student-bar-chart" style="height: 300px;"></canvas>
                    </div>
                </div>
                
                <div id="student-progress-message" class="text-center py-12"></div>
            </div>

            <!-- Class Progress Tab -->
            <div id="class-tab" class="tab-content">
                <div class="glass-card rounded-2xl p-6 mb-8">
                    <h3 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-filter mr-2 text-orange-600"></i>
                        Filter Class Data
                    </h3>
                    
                    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-building mr-1 text-orange-600"></i> Department
                            </label>
                            <select id="class-department" class="modern-select">
                                <option value="">All Departments</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-book mr-1 text-orange-600"></i> Subject
                            </label>
                            <select id="class-subject_name" class="modern-select">
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= htmlspecialchars($subject['subject_name']) ?>">
                                        <?= htmlspecialchars($subject['subject_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-chalkboard mr-1 text-orange-600"></i> Class
                            </label>
                            <select id="class-class_id" class="modern-select">
                                <option value="">Select Class</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-chalkboard-teacher mr-1 text-orange-600"></i> Teacher
                            </label>
                            <select id="class-teacher_id" class="modern-select">
                                <option value="0">All Teachers</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-alt mr-1 text-orange-600"></i> Term
                            </label>
                            <select id="class-term_id" class="modern-select">
                                <option value="">Select Term</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-clock mr-1 text-orange-600"></i> Entry Period
                            </label>
                            <select id="class-entry_period" class="modern-select">
                                <option value="weekly">Weekly</option>
                                <option value="bi-weekly">Bi-Weekly</option>
                            </select>
                        </div>
                        
                        <div class="lg:col-span-3">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-week mr-1 text-orange-600"></i> Exact Period
                            </label>
                            <select id="class-period" class="modern-select">
                                <option value="">Select Exact Period</option>
                            </select>
                        </div>
                        
                        <input type="hidden" id="class-csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    </div>
                </div>

                <!-- Class Metrics -->
                <div class="grid md:grid-cols-3 gap-6 mb-8" id="class-metrics" style="display: none;">
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="class-student-count">0</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Students in Class</h4>
                        <p class="text-xs text-gray-400">Total enrolled</p>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="class-avg-score">0</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Class Average</h4>
                        <p class="text-xs text-gray-400">Across all subjects</p>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-arrow-up"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="class-highest">0</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Highest Score</h4>
                        <p class="text-xs text-gray-400">Individual student</p>
                    </div>
                </div>

                <!-- Class Charts -->
                <div class="grid lg:grid-cols-2 gap-8">
                    <div class="chart-wrapper" id="class-line-graph-container" style="display: none;">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-lg font-semibold text-gray-800">Subject Progress Trend</h4>
                            <span class="badge-orange">Weekly Average</span>
                        </div>
                        <canvas id="class-line-chart" style="height: 300px;"></canvas>
                    </div>
                    
                    <div class="chart-wrapper" id="class-bar-graph-container" style="display: none;">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-lg font-semibold text-gray-800">Student Performance Distribution</h4>
                            <span class="badge-orange">Current Period</span>
                        </div>
                        <canvas id="class-bar-chart" style="height: 300px;"></canvas>
                    </div>
                </div>
                
                <div id="class-progress-message" class="text-center py-12"></div>
            </div>

            <!-- Department Overview Tab -->
            <div id="department-tab" class="tab-content">
                <div class="glass-card rounded-2xl p-6 mb-8">
                    <h3 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-filter mr-2 text-orange-600"></i>
                        Filter Department Data
                    </h3>
                    
                    <div class="grid md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-building mr-1 text-orange-600"></i> Department
                            </label>
                            <select id="overview-department" class="modern-select">
                                <option value="">Select Department</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-alt mr-1 text-orange-600"></i> Term
                            </label>
                            <select id="overview-term_id" class="modern-select">
                                <option value="">Select Term</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-clock mr-1 text-orange-600"></i> Entry Period
                            </label>
                            <select id="overview-entry_period" class="modern-select">
                                <option value="weekly">Weekly</option>
                                <option value="bi-weekly">Bi-Weekly</option>
                            </select>
                        </div>
                        
                        <input type="hidden" id="overview-csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    </div>
                </div>

                <!-- Department Metrics -->
                <div class="grid md:grid-cols-3 gap-6 mb-8" id="department-metrics" style="display: none;">
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="dept-avg">0</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Department Average</h4>
                        <p class="text-xs text-gray-400">All classes combined</p>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-school"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="dept-classes">0</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Total Classes</h4>
                        <p class="text-xs text-gray-400">Active classes</p>
                    </div>
                    
                    <div class="metric-card">
                        <div class="flex items-center justify-between mb-4">
                            <div class="metric-icon">
                                <i class="fas fa-arrow-trend-up"></i>
                            </div>
                            <span class="text-3xl font-bold text-gray-800" id="dept-trend">0%</span>
                        </div>
                        <h4 class="text-sm font-medium text-gray-600">Growth Rate</h4>
                        <p class="text-xs text-gray-400">vs previous term</p>
                    </div>
                </div>

                <!-- Department Chart -->
                <div class="chart-wrapper" id="department-chart-container" style="display: none;">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="text-lg font-semibold text-gray-800">Class Performance Comparison</h4>
                        <div class="flex space-x-2">
                            <button class="chart-type-btn active px-3 py-1 text-sm rounded-lg bg-orange-600 text-white" data-type="bar">
                                <i class="fas fa-chart-bar mr-1"></i> Bar
                            </button>
                            <button class="chart-type-btn px-3 py-1 text-sm rounded-lg bg-orange-100 text-orange-800" data-type="line">
                                <i class="fas fa-chart-line mr-1"></i> Line
                            </button>
                        </div>
                    </div>
                    <canvas id="department-chart" style="height: 400px;"></canvas>
                </div>
                
                <div id="department-message" class="text-center py-12"></div>
            </div>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <?php mysqli_close($conn); ob_end_flush(); ?>

    <script>
        // Register Chart.js plugin if available
        if (typeof ChartDataLabels !== 'undefined') {
            Chart.register(ChartDataLabels);
        }

        // State management
        const state = {
            currentTheme: 'light',
            charts: {
                studentLine: null,
                studentBar: null,
                classLine: null,
                classBar: null,
                department: null
            },
            filters: {
                student: {},
                class: {},
                department: {}
            },
            dataLoaded: {
                departments: false,
                terms: false,
                teachers: false
            }
        };

        // DOM Elements
        const elements = {
            refreshBtn: document.getElementById('refresh-data'),
            tabButtons: document.querySelectorAll('.tab-button'),
            tabContents: document.querySelectorAll('.tab-content'),
            
            // Student tab
            studentDept: document.getElementById('student-department'),
            studentClass: document.getElementById('student-class_id'),
            studentStudent: document.getElementById('student-student_id'),
            studentTerm: document.getElementById('student-term_id'),
            studentPeriodRadios: document.querySelectorAll('input[name="student-entry_period"]'),
            studentMetrics: document.getElementById('student-metrics'),
            studentLineContainer: document.getElementById('student-line-graph-container'),
            studentBarContainer: document.getElementById('student-bar-graph-container'),
            studentMessage: document.getElementById('student-progress-message'),
            
            // Class tab
            classDept: document.getElementById('class-department'),
            classSubject: document.getElementById('class-subject_name'),
            classClass: document.getElementById('class-class_id'),
            classTeacher: document.getElementById('class-teacher_id'),
            classTerm: document.getElementById('class-term_id'),
            classPeriod: document.getElementById('class-entry_period'),
            classExactPeriod: document.getElementById('class-period'),
            classMetrics: document.getElementById('class-metrics'),
            classLineContainer: document.getElementById('class-line-graph-container'),
            classBarContainer: document.getElementById('class-bar-graph-container'),
            classMessage: document.getElementById('class-progress-message'),
            
            // Department tab
            overviewDept: document.getElementById('overview-department'),
            overviewTerm: document.getElementById('overview-term_id'),
            overviewPeriod: document.getElementById('overview-entry_period'),
            deptMetrics: document.getElementById('department-metrics'),
            deptChartContainer: document.getElementById('department-chart-container'),
            deptMessage: document.getElementById('department-message'),
            chartTypeBtns: document.querySelectorAll('.chart-type-btn')
        };

        // Utility Functions
        function showLoading(element, message = 'Loading...') {
            if (!element) return;
            element.innerHTML = `
                <div class="flex flex-col items-center justify-center py-12">
                    <div class="spinner mb-4"></div>
                    <p class="text-gray-600">${message}</p>
                </div>
            `;
        }

        function hideLoading(element) {
            if (!element) return;
            element.innerHTML = '';
        }

        function showError(element, message) {
            if (!element) return;
            element.innerHTML = `
                <div class="text-center py-12">
                    <i class="fas fa-exclamation-circle text-4xl text-orange-500 mb-4"></i>
                    <p class="text-orange-600">${message}</p>
                    <button onclick="location.reload()" class="mt-4 btn-orange-outline">
                        <i class="fas fa-sync-alt mr-2"></i>Refresh Page
                    </button>
                </div>
            `;
        }

        function showNoData(element, message = 'No data available for the selected filters') {
            if (!element) return;
            element.innerHTML = `
                <div class="text-center py-12">
                    <i class="fas fa-chart-line text-4xl text-orange-300 mb-4"></i>
                    <p class="text-gray-500">${message}</p>
                </div>
            `;
        }

        function formatDate(dateString) {
            if (!dateString) return '';
            return new Date(dateString).toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric',
                year: 'numeric'
            });
        }

        function calculateAverage(arr) {
            if (!arr.length) return 0;
            return arr.reduce((a, b) => a + b, 0) / arr.length;
        }

        function getRandomColor(index) {
            const colors = ['#f97316', '#ea580c', '#c2410c', '#9a3412', '#7c2d12'];
            return colors[index % colors.length];
        }

        // Tab switching
        elements.tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetTab = button.dataset.tab;
                
                elements.tabButtons.forEach(btn => btn.classList.remove('active'));
                elements.tabContents.forEach(content => content.classList.remove('active'));
                
                button.classList.add('active');
                const targetElement = document.getElementById(`${targetTab}-tab`);
                if (targetElement) {
                    targetElement.classList.add('active');
                }
            });
        });

        // Initialize data loading with error handling
        async function loadInitialData() {
            try {
                // Show loading states
                showLoading(document.querySelector('#student-tab .glass-card'), 'Loading departments...');
                
                // Load departments
                const deptsResponse = await fetch('?action=get_departments');
                if (!deptsResponse.ok) throw new Error('Failed to fetch departments');
                const deptsData = await deptsResponse.json();
                
                if (deptsData.success) {
                    populateDepartments(deptsData.departments);
                    state.dataLoaded.departments = true;
                } else {
                    console.error('Departments error:', deptsData.error);
                }

                // Load terms
                const termsResponse = await fetch('?action=get_terms');
                if (!termsResponse.ok) throw new Error('Failed to fetch terms');
                const termsData = await termsResponse.json();
                
                if (termsData.success) {
                    populateTerms(termsData.terms);
                    state.dataLoaded.terms = true;
                } else {
                    console.error('Terms error:', termsData.error);
                }

                // Load teachers
                const teachersResponse = await fetch('?action=get_teachers');
                if (!teachersResponse.ok) throw new Error('Failed to fetch teachers');
                const teachersData = await teachersResponse.json();
                
                if (teachersData.success) {
                    populateTeachers(teachersData.teachers);
                    state.dataLoaded.teachers = true;
                } else {
                    console.error('Teachers error:', teachersData.error);
                }

                // Hide loading
                hideLoading(document.querySelector('#student-tab .glass-card'));

            } catch (error) {
                console.error('Error loading initial data:', error);
                showError(document.body, 'Failed to load initial data. Please refresh the page.');
            }
        }

        function populateDepartments(departments) {
            const deptSelects = ['student-department', 'class-department', 'overview-department'];
            deptSelects.forEach(selectId => {
                const select = document.getElementById(selectId);
                if (!select) return;
                
                // Keep the first option
                select.innerHTML = '<option value="">All Departments</option>';
                
                departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.id;
                    option.textContent = dept.name;
                    select.appendChild(option);
                });
            });
        }

        function populateTerms(terms) {
            const termSelects = ['student-term_id', 'class-term_id', 'overview-term_id'];
            termSelects.forEach(selectId => {
                const select = document.getElementById(selectId);
                if (!select) return;
                
                select.innerHTML = '<option value="">Select Term</option>';
                
                terms.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.id;
                    option.textContent = `${term.term_name} (${term.year})`;
                    select.appendChild(option);
                });
            });
        }

        function populateTeachers(teachers) {
            const teacherSelect = document.getElementById('class-teacher_id');
            if (!teacherSelect) return;
            
            teacherSelect.innerHTML = '<option value="0">All Teachers</option>';
            
            teachers.forEach(teacher => {
                const option = document.createElement('option');
                option.value = teacher.id;
                option.textContent = teacher.name;
                teacherSelect.appendChild(option);
            });
        }

        // Start loading data
        loadInitialData();

        // Student Tab Event Listeners
        if (elements.studentDept) {
            elements.studentDept.addEventListener('change', () => loadStudentClasses(elements.studentDept.value));
        }
        if (elements.studentClass) {
            elements.studentClass.addEventListener('change', () => loadStudentStudents(elements.studentClass.value));
        }
        if (elements.studentStudent) {
            elements.studentStudent.addEventListener('change', () => updateStudentCharts());
        }
        if (elements.studentTerm) {
            elements.studentTerm.addEventListener('change', () => updateStudentCharts());
        }
        elements.studentPeriodRadios.forEach(radio => {
            radio.addEventListener('change', () => updateStudentCharts());
        });

        function loadStudentClasses(deptId) {
            if (!elements.studentClass) return;
            elements.studentClass.innerHTML = '<option value="">Select Class</option>';
            if (!deptId) return;
            
            showLoading(elements.studentMessage, 'Loading classes...');
            
            fetch(`?action=get_classes_by_department&department_id=${deptId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        data.classes.forEach(cls => {
                            const option = document.createElement('option');
                            option.value = cls.id;
                            option.textContent = cls.class_name;
                            elements.studentClass.appendChild(option);
                        });
                    } else {
                        showError(elements.studentMessage, 'Error loading classes');
                    }
                })
                .catch(err => {
                    console.error('Error loading classes:', err);
                    showError(elements.studentMessage, 'Error loading classes');
                })
                .finally(() => {
                    hideLoading(elements.studentMessage);
                });
        }

        function loadStudentStudents(classId) {
            if (!elements.studentStudent) return;
            elements.studentStudent.innerHTML = '<option value="">Select Student</option>';
            if (!classId) return;
            
            showLoading(elements.studentMessage, 'Loading students...');
            
            fetch(`?action=get_students_by_class&class_id=${classId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        data.students.forEach(student => {
                            const option = document.createElement('option');
                            option.value = student.id;
                            option.textContent = student.name;
                            elements.studentStudent.appendChild(option);
                        });
                    } else {
                        showError(elements.studentMessage, data.error || 'No students found for this class');
                    }
                })
                .catch(err => {
                    console.error('Error loading students:', err);
                    showError(elements.studentMessage, 'Error loading students');
                })
                .finally(() => {
                    hideLoading(elements.studentMessage);
                });
        }

        function updateStudentCharts() {
            if (!elements.studentStudent || !elements.studentTerm) return;
            
            const studentId = elements.studentStudent.value;
            const termId = elements.studentTerm.value;
            const entryPeriod = document.querySelector('input[name="student-entry_period"]:checked')?.value || 'weekly';
            
            if (!studentId || !termId) {
                if (elements.studentMetrics) elements.studentMetrics.style.display = 'none';
                if (elements.studentLineContainer) elements.studentLineContainer.style.display = 'none';
                if (elements.studentBarContainer) elements.studentBarContainer.style.display = 'none';
                showNoData(elements.studentMessage, 'Please select a student and term');
                return;
            }
            
            showLoading(elements.studentMessage, 'Loading student data...');
            
            // Load line chart data
            fetch(`?action=get_student_progress&student_id=${studentId}&entry_period=${entryPeriod}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.progress && data.progress.length > 0) {
                        if (elements.studentLineContainer) {
                            elements.studentLineContainer.style.display = 'block';
                            renderStudentLineChart(data.progress, entryPeriod);
                        }
                    } else {
                        if (elements.studentLineContainer) elements.studentLineContainer.style.display = 'none';
                        if (state.charts.studentLine) {
                            state.charts.studentLine.destroy();
                            state.charts.studentLine = null;
                        }
                    }
                })
                .catch(err => {
                    console.error('Error loading line chart data:', err);
                });
            
            // Load bar chart and metrics
            const formData = new FormData();
            formData.append('action', 'get_subject_comparison');
            formData.append('student_id', studentId);
            formData.append('term_id', termId);
            const csrfToken = document.getElementById('student-csrf_token');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }
            
            fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.progress && data.progress.length > 0) {
                        if (elements.studentMetrics) elements.studentMetrics.style.display = 'grid';
                        if (elements.studentBarContainer) elements.studentBarContainer.style.display = 'block';
                        
                        // Update metrics
                        const scores = data.progress.map(p => p.total_scaled);
                        const avgScore = calculateAverage(scores).toFixed(1);
                        const topSubject = data.progress.reduce((prev, current) => 
                            (prev.total_scaled > current.total_scaled) ? prev : current
                        );
                        
                        const avgScoreEl = document.getElementById('avg-score');
                        const topSubjectEl = document.getElementById('top-subject');
                        const totalEntriesEl = document.getElementById('total-entries');
                        const trendEl = document.getElementById('trend');
                        
                        if (avgScoreEl) avgScoreEl.textContent = avgScore;
                        if (topSubjectEl) topSubjectEl.textContent = topSubject.subject_name;
                        if (totalEntriesEl) totalEntriesEl.textContent = data.progress.length;
                        
                        // Calculate trend
                        const trend = scores.length > 1 ? ((scores[scores.length - 1] - scores[0]) / scores[0] * 100).toFixed(1) : 0;
                        if (trendEl) trendEl.textContent = (trend > 0 ? '+' : '') + trend + '%';
                        
                        renderStudentBarChart(data.progress);
                    } else {
                        if (elements.studentMetrics) elements.studentMetrics.style.display = 'none';
                        if (elements.studentBarContainer) elements.studentBarContainer.style.display = 'none';
                        if (state.charts.studentBar) {
                            state.charts.studentBar.destroy();
                            state.charts.studentBar = null;
                        }
                    }
                })
                .catch(err => {
                    console.error('Error loading bar chart data:', err);
                    showError(elements.studentMessage, 'Error loading student data');
                })
                .finally(() => {
                    hideLoading(elements.studentMessage);
                });
        }

        function renderStudentLineChart(progress, entryPeriod) {
            const canvas = document.getElementById('student-line-chart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            if (state.charts.studentLine) state.charts.studentLine.destroy();
            
            const subjects = [...new Set(progress.map(p => p.subject_name))];
            const weeks = [...new Set(progress.map(p => p.period_start_date))].sort();
            
            const datasets = subjects.map((subject, index) => ({
                label: subject,
                data: weeks.map(week => {
                    const entry = progress.find(p => p.subject_name === subject && p.period_start_date === week);
                    return entry ? parseFloat(entry.total_scaled) : null;
                }),
                borderColor: getRandomColor(index),
                backgroundColor: getRandomColor(index) + '20',
                tension: 0.4,
                fill: false,
                pointRadius: 4,
                pointHoverRadius: 6
            }));
            
            const weekLabels = weeks.map((date, idx) => {
                const start = formatDate(date);
                return entryPeriod === 'weekly' ? `Week ${idx + 1} (${start})` : `Bi-Week ${Math.floor(idx/2) + 1}`;
            });
            
            state.charts.studentLine = new Chart(ctx, {
                type: 'line',
                data: { labels: weekLabels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'top', 
                            labels: { 
                                usePointStyle: true, 
                                boxWidth: 6,
                                color: '#1f2937'
                            } 
                        },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            grid: { color: 'rgba(249, 115, 22, 0.1)' },
                            title: { display: true, text: 'Score (%)', color: '#4b5563' },
                            ticks: { color: '#4b5563' }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: { color: '#4b5563', maxRotation: 45 }
                        }
                    }
                }
            });
        }

        function renderStudentBarChart(progress) {
            const canvas = document.getElementById('student-bar-chart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            if (state.charts.studentBar) state.charts.studentBar.destroy();
            
            const subjects = progress.map(p => p.subject_name);
            
            state.charts.studentBar = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: subjects,
                    datasets: [
                        {
                            label: 'Class Practices',
                            data: progress.map(p => p.class_practices_scaled),
                            backgroundColor: '#f97316',
                            borderRadius: 8
                        },
                        {
                            label: 'Assignments',
                            data: progress.map(p => p.assignments_scaled),
                            backgroundColor: '#ea580c',
                            borderRadius: 8
                        },
                        {
                            label: 'CAT',
                            data: progress.map(p => p.cat_scaled),
                            backgroundColor: '#c2410c',
                            borderRadius: 8
                        },
                        {
                            label: 'Group Project',
                            data: progress.map(p => p.group_project_scaled),
                            backgroundColor: '#9a3412',
                            borderRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'top', 
                            labels: { 
                                usePointStyle: true,
                                color: '#1f2937'
                            } 
                        },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            grid: { color: 'rgba(249, 115, 22, 0.1)' },
                            title: { display: true, text: 'Score (%)', color: '#4b5563' },
                            ticks: { color: '#4b5563' }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: { color: '#4b5563', maxRotation: 45 }
                        }
                    }
                }
            });
        }

        // Class Tab Event Listeners
        if (elements.classDept) {
            elements.classDept.addEventListener('change', () => {
                loadClassClasses(elements.classDept.value);
                loadClassSubjects(elements.classDept.value);
            });
        }
        
        if (elements.classSubject) {
            elements.classSubject.addEventListener('change', () => updateClassLineChart());
        }
        
        if (elements.classClass) {
            elements.classClass.addEventListener('change', () => updateClassCharts());
        }
        
        if (elements.classTerm) {
            elements.classTerm.addEventListener('change', () => {
                if (elements.classPeriod) elements.classPeriod.disabled = !elements.classTerm.value;
                if (elements.classExactPeriod) {
                    elements.classExactPeriod.innerHTML = '<option value="">Select Exact Period</option>';
                }
                updateClassCharts();
            });
        }
        
        if (elements.classPeriod) {
            elements.classPeriod.addEventListener('change', () => {
                loadExactPeriods();
                updateClassCharts();
            });
        }
        
        if (elements.classExactPeriod) {
            elements.classExactPeriod.addEventListener('change', () => updateClassCharts());
        }
        
        if (elements.classTeacher) {
            elements.classTeacher.addEventListener('change', () => updateClassCharts());
        }

        function loadClassClasses(deptId) {
            if (!elements.classClass) return;
            elements.classClass.innerHTML = '<option value="">Select Class</option>';
            if (!deptId) return;
            
            fetch(`?action=get_classes_by_department&department_id=${deptId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        data.classes.forEach(cls => {
                            const option = document.createElement('option');
                            option.value = cls.id;
                            option.textContent = cls.class_name;
                            elements.classClass.appendChild(option);
                        });
                    }
                })
                .catch(err => console.error('Error loading classes:', err));
        }

        function loadClassSubjects(deptId) {
            if (!elements.classSubject) return;
            elements.classSubject.innerHTML = '<option value="">Select Subject</option>';
            if (!deptId) return;
            
            fetch(`?action=get_subjects_by_department&department_id=${deptId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        data.subjects.forEach(sub => {
                            const option = document.createElement('option');
                            option.value = sub.subject_name;
                            option.textContent = sub.subject_name;
                            elements.classSubject.appendChild(option);
                        });
                    }
                })
                .catch(err => console.error('Error loading subjects:', err));
        }

        function loadExactPeriods() {
            if (!elements.classTerm || !elements.classPeriod || !elements.classExactPeriod) return;
            
            const termId = elements.classTerm.value;
            const entryPeriod = elements.classPeriod.value;
            
            elements.classExactPeriod.innerHTML = '<option value="">Select Exact Period</option>';
            if (!termId || !entryPeriod) return;
            
            fetch(`?action=get_periods&term_id=${termId}&entry_period=${entryPeriod}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        data.periods.forEach(period => {
                            const option = document.createElement('option');
                            option.value = JSON.stringify(period);
                            option.textContent = `${period.label}: ${formatDate(period.start)} - ${formatDate(period.end)}`;
                            elements.classExactPeriod.appendChild(option);
                        });
                        if (elements.classExactPeriod) {
                            elements.classExactPeriod.disabled = data.periods.length === 0;
                        }
                    }
                })
                .catch(err => console.error('Error loading periods:', err));
        }

        function updateClassLineChart() {
            if (!elements.classSubject || !elements.classDept || !elements.classMessage) return;
            
            const subject = elements.classSubject.value;
            const deptId = elements.classDept.value;
            
            if (!subject || !deptId) {
                if (elements.classLineContainer) elements.classLineContainer.style.display = 'none';
                if (state.charts.classLine) {
                    state.charts.classLine.destroy();
                    state.charts.classLine = null;
                }
                return;
            }
            
            showLoading(elements.classMessage, 'Loading subject progress...');
            
            fetch(`?action=get_subject_progress&subject_name=${encodeURIComponent(subject)}&department_id=${deptId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.progress && data.progress.length > 0) {
                        if (elements.classLineContainer) elements.classLineContainer.style.display = 'block';
                        renderClassLineChart(data.progress);
                    } else {
                        if (elements.classLineContainer) elements.classLineContainer.style.display = 'none';
                        if (state.charts.classLine) {
                            state.charts.classLine.destroy();
                            state.charts.classLine = null;
                        }
                    }
                })
                .catch(err => {
                    console.error('Error loading subject progress:', err);
                    showError(elements.classMessage, 'Error loading subject progress');
                })
                .finally(() => {
                    hideLoading(elements.classMessage);
                });
        }

        function renderClassLineChart(progress) {
            const canvas = document.getElementById('class-line-chart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            if (state.charts.classLine) state.charts.classLine.destroy();
            
            const students = [...new Set(progress.map(p => p.student_name))];
            const weeks = [...new Set(progress.map(p => p.period_start_date))].sort();
            
            const datasets = students.slice(0, 10).map((student, index) => ({
                label: student,
                data: weeks.map(week => {
                    const entry = progress.find(p => p.student_name === student && p.period_start_date === week);
                    return entry ? parseFloat(entry.total_scaled) : null;
                }),
                borderColor: getRandomColor(index),
                borderWidth: 2,
                pointRadius: 3,
                fill: false,
                tension: 0.4
            }));
            
            const weekLabels = weeks.map((date, idx) => `Week ${idx + 1}`);
            
            state.charts.classLine = new Chart(ctx, {
                type: 'line',
                data: { labels: weekLabels, datasets: datasets.slice(0, 10) },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'top', 
                            maxHeight: 50, 
                            labels: { 
                                boxWidth: 12,
                                color: '#1f2937'
                            } 
                        },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 100, 
                            grid: { color: 'rgba(249, 115, 22, 0.1)' },
                            ticks: { color: '#4b5563' }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: { color: '#4b5563' }
                        }
                    }
                }
            });
        }

        function updateClassCharts() {
            if (!elements.classClass || !elements.classTerm || !elements.classPeriod || !elements.classExactPeriod || !elements.classMessage) return;
            
            const classId = elements.classClass.value;
            const termId = elements.classTerm.value;
            const entryPeriod = elements.classPeriod.value;
            const periodJson = elements.classExactPeriod.value;
            const teacherId = elements.classTeacher ? elements.classTeacher.value : 0;
            
            if (!classId || !termId || !entryPeriod || !periodJson) {
                if (elements.classMetrics) elements.classMetrics.style.display = 'none';
                if (elements.classBarContainer) elements.classBarContainer.style.display = 'none';
                if (state.charts.classBar) {
                    state.charts.classBar.destroy();
                    state.charts.classBar = null;
                }
                return;
            }
            
            let period;
            try {
                period = JSON.parse(periodJson);
            } catch (e) {
                console.error('Error parsing period:', e);
                return;
            }
            
            showLoading(elements.classMessage, 'Loading class progress...');
            
            const formData = new FormData();
            formData.append('action', 'get_filtered_progress');
            formData.append('class_id', classId);
            formData.append('term_id', termId);
            formData.append('entry_period', entryPeriod);
            formData.append('period_start', period.start);
            formData.append('period_end', period.end);
            formData.append('teacher_id', teacherId);
            
            const csrfToken = document.getElementById('class-csrf_token');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }
            
            fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.progress && data.progress.length > 0) {
                        if (elements.classMetrics) elements.classMetrics.style.display = 'grid';
                        if (elements.classBarContainer) elements.classBarContainer.style.display = 'block';
                        
                        // Update metrics
                        const scores = data.progress.map(p => parseFloat(p.total_scaled));
                        const avgScore = calculateAverage(scores).toFixed(1);
                        const studentCount = [...new Set(data.progress.map(p => p.student_name))].length;
                        const highestScore = Math.max(...scores).toFixed(1);
                        
                        const studentCountEl = document.getElementById('class-student-count');
                        const avgScoreEl = document.getElementById('class-avg-score');
                        const highestEl = document.getElementById('class-highest');
                        
                        if (studentCountEl) studentCountEl.textContent = studentCount;
                        if (avgScoreEl) avgScoreEl.textContent = avgScore + '%';
                        if (highestEl) highestEl.textContent = highestScore + '%';
                        
                        renderClassBarChart(data.progress);
                    } else {
                        if (elements.classMetrics) elements.classMetrics.style.display = 'none';
                        if (elements.classBarContainer) elements.classBarContainer.style.display = 'none';
                        if (state.charts.classBar) {
                            state.charts.classBar.destroy();
                            state.charts.classBar = null;
                        }
                    }
                })
                .catch(err => {
                    console.error('Error loading class progress:', err);
                    showError(elements.classMessage, 'Error loading class progress');
                })
                .finally(() => {
                    hideLoading(elements.classMessage);
                });
        }

        function renderClassBarChart(progress) {
            const canvas = document.getElementById('class-bar-chart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            if (state.charts.classBar) state.charts.classBar.destroy();
            
            const students = [...new Set(progress.map(p => p.student_name))];
            const studentScores = students.map(student => {
                const studentEntries = progress.filter(p => p.student_name === student);
                return calculateAverage(studentEntries.map(p => parseFloat(p.total_scaled)));
            });
            
            state.charts.classBar = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: students,
                    datasets: [{
                        label: 'Average Score (%)',
                        data: studentScores,
                        backgroundColor: '#f97316',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { 
                            callbacks: { 
                                label: (ctx) => `Score: ${ctx.raw}%` 
                            } 
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 100, 
                            grid: { color: 'rgba(249, 115, 22, 0.1)' },
                            ticks: { color: '#4b5563' }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: { color: '#4b5563', maxRotation: 45 }
                        }
                    }
                }
            });
        }

        // Department Tab Event Listeners
        if (elements.overviewDept) {
            elements.overviewDept.addEventListener('change', () => updateDepartmentChart());
        }
        if (elements.overviewTerm) {
            elements.overviewTerm.addEventListener('change', () => updateDepartmentChart());
        }
        if (elements.overviewPeriod) {
            elements.overviewPeriod.addEventListener('change', () => updateDepartmentChart());
        }

        elements.chartTypeBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                elements.chartTypeBtns.forEach(b => {
                    b.classList.remove('active', 'bg-orange-600', 'text-white');
                    b.classList.add('bg-orange-100', 'text-orange-800');
                });
                btn.classList.add('active', 'bg-orange-600', 'text-white');
                btn.classList.remove('bg-orange-100', 'text-orange-800');
                
                if (state.charts.department) {
                    updateDepartmentChart(btn.dataset.type);
                }
            });
        });

        function updateDepartmentChart(chartType = 'bar') {
            if (!elements.overviewDept || !elements.overviewTerm || !elements.overviewPeriod || !elements.deptMessage) return;
            
            const deptId = elements.overviewDept.value;
            const termId = elements.overviewTerm.value;
            const entryPeriod = elements.overviewPeriod.value;
            
            if (!deptId || !termId || !entryPeriod) {
                if (elements.deptMetrics) elements.deptMetrics.style.display = 'none';
                if (elements.deptChartContainer) elements.deptChartContainer.style.display = 'none';
                if (state.charts.department) {
                    state.charts.department.destroy();
                    state.charts.department = null;
                }
                return;
            }
            
            showLoading(elements.deptMessage, 'Loading department overview...');
            
            const formData = new FormData();
            formData.append('action', 'get_department_averages');
            formData.append('department_id', deptId);
            formData.append('term_id', termId);
            formData.append('entry_period', entryPeriod);
            
            const csrfToken = document.getElementById('overview-csrf_token');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }
            
            fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        if (elements.deptMetrics) elements.deptMetrics.style.display = 'grid';
                        if (elements.deptChartContainer) elements.deptChartContainer.style.display = 'block';
                        
                        // Update metrics
                        const avgScore = calculateAverage(data.data.map(d => d.avg_score)).toFixed(1);
                        const avgEl = document.getElementById('dept-avg');
                        const classesEl = document.getElementById('dept-classes');
                        const trendEl = document.getElementById('dept-trend');
                        
                        if (avgEl) avgEl.textContent = avgScore + '%';
                        if (classesEl) classesEl.textContent = data.data.length;
                        if (trendEl) trendEl.textContent = '+5.2%';
                        
                        renderDepartmentChart(data.data, chartType);
                    } else {
                        if (elements.deptMetrics) elements.deptMetrics.style.display = 'none';
                        if (elements.deptChartContainer) elements.deptChartContainer.style.display = 'none';
                        if (state.charts.department) {
                            state.charts.department.destroy();
                            state.charts.department = null;
                        }
                    }
                })
                .catch(err => {
                    console.error('Error loading department data:', err);
                    showError(elements.deptMessage, 'Error loading department data');
                })
                .finally(() => {
                    hideLoading(elements.deptMessage);
                });
        }

        function renderDepartmentChart(data, chartType = 'bar') {
            const canvas = document.getElementById('department-chart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            if (state.charts.department) state.charts.department.destroy();
            
            const labels = data.map(d => d.class_name);
            const scores = data.map(d => d.avg_score);
            
            const config = {
                data: {
                    labels,
                    datasets: [{
                        label: 'Average Score (%)',
                        data: scores,
                        backgroundColor: '#f97316',
                        borderColor: '#f97316',
                        borderWidth: chartType === 'bar' ? 0 : 2,
                        borderRadius: chartType === 'bar' ? 8 : 0,
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    return `Average: ${ctx.raw}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            grid: { color: 'rgba(249, 115, 22, 0.1)' },
                            title: { display: true, text: 'Score (%)', color: '#4b5563' },
                            ticks: { color: '#4b5563' }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: { color: '#4b5563', maxRotation: 45 }
                        }
                    }
                }
            };
            
            if (chartType === 'line') {
                config.type = 'line';
                config.data.datasets[0].backgroundColor = 'transparent';
                config.data.datasets[0].pointBackgroundColor = '#f97316';
                config.data.datasets[0].pointRadius = 6;
            } else {
                config.type = 'bar';
            }
            
            state.charts.department = new Chart(ctx, config);
        }

        // Refresh button
        if (elements.refreshBtn) {
            elements.refreshBtn.addEventListener('click', () => {
                location.reload();
            });
        }
    </script>
</body>
</html>