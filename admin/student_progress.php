<?php
// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/logs/php_errors.log');
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

// Create CSRF tokens table
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
if (!$conn->query($create_csrf_table_sql)) {
    error_log("Failed to create csrf_tokens table: " . $conn->error);
}

// Create classes table
$create_classes_table_sql = "
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
if (!$conn->query($create_classes_table_sql)) {
    error_log("Failed to create classes table: " . $conn->error);
}

// Populate default classes
$classes_count_sql = "SELECT COUNT(*) as count FROM classes";
$classes_count_result = $conn->query($classes_count_sql);
if ($classes_count_result && $classes_count_result->fetch_assoc()['count'] == 0) {
    $default_classes = ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    $insert_class_sql = "INSERT INTO classes (class_name) VALUES (?)";
    $stmt = $conn->prepare($insert_class_sql);
    if ($stmt) {
        foreach ($default_classes as $class_name) {
            $stmt->bind_param("s", $class_name);
            $stmt->execute();
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare default classes insert: " . $conn->error);
    }
}

// Add class_id to users table
$check_class_id_column = "SHOW COLUMNS FROM users LIKE 'class_id'";
$column_result = $conn->query($check_class_id_column);
if ($column_result->num_rows == 0) {
    $alter_users_table_sql = "
    ALTER TABLE users 
    ADD COLUMN class_id INT NULL,
    ADD CONSTRAINT fk_users_class_id FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL;
    ";
    if (!$conn->query($alter_users_table_sql)) {
        error_log("Failed to alter users table for class_id: " . $conn->error);
    }
}

// Create extracurricular_activities table
$create_activities_table_sql = "
CREATE TABLE IF NOT EXISTS extracurricular_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    term_id INT NOT NULL,
    activity_name VARCHAR(255) NOT NULL,
    participation_date DATE NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES academic_calendar(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
if (!$conn->query($create_activities_table_sql)) {
    error_log("Failed to create extracurricular_activities table: " . $conn->error);
}

// Create subjects table if not exists
$create_subjects_table_sql = "
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
if (!$conn->query($create_subjects_table_sql)) {
    error_log("Failed to create subjects table: " . $conn->error);
}

// Populate default subjects
$subjects_count_sql = "SELECT COUNT(*) as count FROM subjects";
$subjects_count_result = $conn->query($subjects_count_sql);
if ($subjects_count_result && $subjects_count_result->fetch_assoc()['count'] == 0) {
    $default_subjects = ['Mathematics', 'English', 'Science', 'History', 'Geography'];
    $insert_subject_sql = "INSERT INTO subjects (subject_name) VALUES (?)";
    $stmt = $conn->prepare($insert_subject_sql);
    if ($stmt) {
        foreach ($default_subjects as $subject_name) {
            $stmt->bind_param("s", $subject_name);
            $stmt->execute();
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare default subjects insert: " . $conn->error);
    }
}

// Validate user_id
$admin_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin'";
$user_check_stmt = $conn->prepare($user_check_sql);
if (!$user_check_stmt) {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->bind_param("i", $admin_id);
$user_check_stmt->execute();
if ($user_check_stmt->get_result()->num_rows === 0) {
    error_log("Invalid admin_id: $admin_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->close();

// CSRF Token Management
function generateCsrfToken($conn, $user_id) {
    try {
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Clean expired tokens for the user
        $clean_sql = "DELETE FROM csrf_tokens WHERE expires_at < NOW() OR user_id = ?";
        $clean_stmt = $conn->prepare($clean_sql);
        if ($clean_stmt) {
            $clean_stmt->bind_param("i", $user_id);
            $clean_stmt->execute();
            $clean_stmt->close();
        }
        
        // Insert new token
        $insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
        $stmt = $conn->prepare($insert_sql);
        if ($stmt) {
            $stmt->bind_param("iss", $user_id, $token, $expires_at);
            if ($stmt->execute()) {
                $stmt->close();
                error_log("Generated new CSRF token for user_id: $user_id");
                return $token;
            }
            error_log("Failed to insert CSRF token: " . $stmt->error);
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error generating CSRF token: " . $e->getMessage());
    }
    $fallback = bin2hex(random_bytes(32));
    error_log("Using fallback CSRF token for user_id $user_id");
    return $fallback;
}

function validateCsrfToken($conn, $user_id, $token) {
    if (empty($token)) return false;
    $sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result->num_rows > 0;
    $stmt->close();
    if ($valid) {
        $delete_sql = "DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        if ($delete_stmt) {
            $delete_stmt->bind_param("is", $user_id, $token);
            $delete_stmt->execute();
            $delete_stmt->close();
        }
    }
    return $valid;
}

// Generate CSRF token only if not already set
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_SESSION['csrf_token'])) {
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
    
    try {
        switch ($action) {
            case 'get_classes':
                $classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name ASC";
                $result = $conn->query($classes_sql);
                if ($result) {
                    $classes = [];
                    while ($row = $result->fetch_assoc()) {
                        $classes[] = $row;
                    }
                    echo json_encode(['success' => true, 'classes' => $classes]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch classes']);
                }
                break;

            case 'get_students':
                $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
                $students_sql = "SELECT DISTINCT u.id, u.name 
                                FROM users u 
                                JOIN academic_progress_entries ape ON u.id = ape.student_id 
                                WHERE u.role = 'student'";
                if ($class_id > 0) {
                    $students_sql .= " AND ape.class_id = ?";
                    $stmt = $conn->prepare($students_sql);
                    $stmt->bind_param("i", $class_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $students = [];
                    while ($row = $result->fetch_assoc()) {
                        $students[] = $row;
                    }
                    $stmt->close();
                } else {
                    $result = $conn->query($students_sql);
                    $students = [];
                    while ($row = $result->fetch_assoc()) {
                        $students[] = $row;
                    }
                }
                echo json_encode(['success' => true, 'students' => $students]);
                break;

            case 'get_terms':
                $terms_sql = "SELECT id, term_name, year, start_date, end_date FROM academic_calendar ORDER BY year DESC, start_date DESC";
                $result = $conn->query($terms_sql);
                $terms = [];
                while ($row = $result->fetch_assoc()) {
                    $terms[] = $row;
                }
                echo json_encode(['success' => true, 'terms' => $terms]);
                break;

            case 'get_student_progress':
                if (!isset($_GET['student_id']) || !isset($_GET['entry_period'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                    break;
                }
                $student_id = intval($_GET['student_id']);
                $entry_period = $_GET['entry_period'];
                $query = "SELECT s.subject_name, ape.total_scaled, ape.period_start_date, ape.period_end_date
                          FROM academic_progress_entries ape
                          JOIN subjects s ON ape.subject_id = s.id
                          WHERE ape.student_id = ? AND ape.entry_period = ?
                          ORDER BY s.subject_name, ape.period_start_date";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("is", $student_id, $entry_period);
                $stmt->execute();
                $result = $stmt->get_result();
                $progress = [];
                while ($row = $result->fetch_assoc()) {
                    $progress[] = $row;
                }
                $stmt->close();
                echo json_encode(['success' => true, 'progress' => $progress]);
                break;

            case 'get_subject_comparison':
                if (!isset($_POST['student_id']) || !isset($_POST['term_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                    break;
                }
                $student_id = intval($_POST['student_id']);
                $term_id = intval($_POST['term_id']);
                $csrf_token = $_POST['csrf_token'] ?? '';
                if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
                    $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
                    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token', 'new_csrf_token' => $_SESSION['csrf_token']]);
                    break;
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
                $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
                echo json_encode(['success' => true, 'progress' => $progress, 'new_csrf_token' => $_SESSION['csrf_token']]);
                break;

            case 'get_activities':
                if (!isset($_GET['student_id']) || !isset($_GET['term_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                    break;
                }
                $student_id = intval($_GET['student_id']);
                $term_id = intval($_GET['term_id']);
                $query = "SELECT activity_name, participation_date, details
                          FROM extracurricular_activities
                          WHERE student_id = ? AND term_id = ?
                          ORDER BY participation_date DESC";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $student_id, $term_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $activities = [];
                while ($row = $result->fetch_assoc()) {
                    $activities[] = $row;
                }
                $stmt->close();
                echo json_encode(['success' => true, 'activities' => $activities]);
                break;

            case 'generate_report':
                if (!isset($_POST['student_id']) || !isset($_POST['term_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                    break;
                }
                $student_id = intval($_POST['student_id']);
                $term_id = intval($_POST['term_id']);
                $csrf_token = $_POST['csrf_token'] ?? '';
                if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
                    $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
                    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token', 'new_csrf_token' => $_SESSION['csrf_token']]);
                    break;
                }
                
                while (ob_get_level() > 0) ob_end_clean();
                
                // Fetch student name and class
                $student_query = "SELECT u.name, c.class_name FROM users u LEFT JOIN classes c ON u.class_id = c.id WHERE u.id = ? AND u.role = 'student'";
                $stmt = $conn->prepare($student_query);
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $student_result = $stmt->get_result();
                $student_data = $student_result->num_rows > 0 ? $student_result->fetch_assoc() : ['name' => 'Unknown', 'class_name' => 'Unknown'];
                $stmt->close();
                
                // Fetch term details
                $term_query = "SELECT term_name, year FROM academic_calendar WHERE id = ?";
                $stmt = $conn->prepare($term_query);
                $stmt->bind_param("i", $term_id);
                $stmt->execute();
                $term_result = $stmt->get_result();
                $term_data = $term_result->num_rows > 0 ? $term_result->fetch_assoc() : ['term_name' => 'Unknown', 'year' => ''];
                $stmt->close();
                
                // Fetch progress data
                $progress_query = "SELECT s.subject_name, ape.total_scaled, ape.entry_period, ape.period_start_date, ape.period_end_date,
                                   ape.class_practices_raw, ape.class_practices_max, ape.assignments_raw, ape.assignments_max,
                                   ape.cat_raw, ape.cat_max, ape.group_project_raw, ape.group_project_max, ape.comments
                                   FROM academic_progress_entries ape
                                   JOIN subjects s ON ape.subject_id = s.id
                                   WHERE ape.student_id = ? AND ape.term_id = ?
                                   ORDER BY ape.period_start_date, s.subject_name";
                $stmt = $conn->prepare($progress_query);
                $stmt->bind_param("ii", $student_id, $term_id);
                $stmt->execute();
                $progress_result = $stmt->get_result();
                $progress_data = [];
                while ($row = $progress_result->fetch_assoc()) $progress_data[] = $row;
                $stmt->close();
                
                // Fetch activities
                $activities_query = "SELECT activity_name, participation_date, details
                                     FROM extracurricular_activities
                                     WHERE student_id = ? AND term_id = ?
                                     ORDER BY participation_date DESC";
                $stmt = $conn->prepare($activities_query);
                $stmt->bind_param("ii", $student_id, $term_id);
                $stmt->execute();
                $activities_result = $stmt->get_result();
                $activities_data = [];
                while ($row = $activities_result->fetch_assoc()) $activities_data[] = $row;
                $stmt->close();
                
                // CSV output
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="student_report_' . $student_id . '_term_' . $term_id . '.csv"');
                $output = fopen('php://output', 'w');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['Student Progress Report']);
                fputcsv($output, ['Student', $student_data['name']]);
                fputcsv($output, ['Class', $student_data['class_name'] ?: 'N/A']);
                fputcsv($output, ['Term', $term_data['term_name'] . ' (' . $term_data['year'] . ')']);
                fputcsv($output, []);
                fputcsv($output, ['Academic Progress']);
                fputcsv($output, ['Subject', 'Class Practices', 'Assignments', 'CAT', 'Group Project', 'Total Score (%)', 'Period Type', 'Start Date', 'End Date', 'Comments']);
                foreach ($progress_data as $row) {
                    $class_practices = ($row['class_practices_max'] > 0) ? round(($row['class_practices_raw'] / $row['class_practices_max']) * 25, 2) : 0;
                    $assignments = ($row['assignments_max'] > 0) ? round(($row['assignments_raw'] / $row['assignments_max']) * 25, 2) : 0;
                    $cat = ($row['cat_max'] > 0) ? round(($row['cat_raw'] / $row['cat_max']) * 25, 2) : 0;
                    $group_project = ($row['group_project_max'] > 0) ? round(($row['group_project_raw'] / $row['group_project_max']) * 25, 2) : 0;
                    fputcsv($output, [
                        $row['subject_name'],
                        $class_practices, $assignments, $cat, $group_project,
                        $row['total_scaled'] ?? 'N/A',
                        $row['entry_period'] ?? 'N/A',
                        $row['period_start_date'] ?? 'N/A',
                        $row['period_end_date'] ?? 'N/A',
                        $row['comments'] ?? 'N/A'
                    ]);
                }
                fputcsv($output, []);
                fputcsv($output, ['Extracurricular Activities']);
                fputcsv($output, ['Activity Name', 'Participation Date', 'Details']);
                foreach ($activities_data as $row) {
                    fputcsv($output, [$row['activity_name'] ?? 'N/A', $row['participation_date'] ?? 'N/A', $row['details'] ?? 'N/A']);
                }
                fclose($output);
                $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
                exit();
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        error_log("Exception in AJAX handler: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error occurred']);
    }
    exit();
}

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin student progress view for QueensPa School Management System">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Student Progress Monitoring - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Orbitron:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        /* ===== UPDATED UI/UX: HIGH CONTRAST & READABILITY ===== */
        * {
            box-sizing: border-box;
        }
        html {
            font-size: 16px;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #0f172a; /* Solid dark navy */
            color: #f1f5f9;
            min-height: 100vh;
            line-height: 1.6;
        }
        h1, h2, h3 {
            font-family: 'Orbitron', sans-serif;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        /* High-opacity cards */
        .glassmorphism {
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            border-radius: 1.25rem;
            padding: 1.5rem;
        }
        .dark .glassmorphism {
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        /* Labels */
        label {
            font-weight: 600;
            font-size: 0.95rem;
            color: #e2e8f0;
            margin-bottom: 0.25rem;
            display: block;
        }
        /* Select inputs – fully opaque, strong border */
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: #1e293b;
            border: 2px solid #475569;
            color: #f8fafc;
            border-radius: 0.75rem;
            font-size: 0.95rem;
            font-weight: 500;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23f97316' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 0.8rem;
            cursor: pointer;
        }
        .form-select:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.4);
            background-color: #0f172a;
        }
        .form-select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .form-select option {
            background-color: #1e293b;
            color: #f1f5f9;
        }
        /* Error messages */
        .error-message {
            color: #f87171;
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.25rem;
        }
        /* Buttons */
        #theme-toggle, #generate-report {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            border-radius: 0.75rem;
            transition: all 0.2s;
            color: #fff;
            cursor: pointer;
        }
        #theme-toggle:hover, #generate-report:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.15);
        }
        #generate-report:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        /* Chart containers */
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
            min-height: 400px;
            margin-bottom: 1.5rem;
        }
        /* Table styling */
        table {
            width: 100%;
            border-collapse: collapse;
            color: #e2e8f0;
        }
        th {
            background: #1e293b;
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #475569;
        }
        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #334155;
        }
        tr:hover td {
            background: rgba(255,255,255,0.05);
        }
        /* Scrollbar */
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 6px; }
        ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #f97316;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        /* Responsive grid */
        @media (max-width: 768px) {
            .grid.md\\:grid-cols-2, .grid.md\\:grid-cols-4 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="antialiased">
    <div class="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <div class="flex flex-wrap justify-between items-center mb-8 gap-4">
                <h1 class="text-3xl md:text-4xl font-bold text-white flex items-center gap-3">
                    <i class="fas fa-user-graduate text-orange-500"></i> Student Progress Monitoring
                </h1>
                <button id="theme-toggle" class="flex items-center gap-2">
                    <i class="fas fa-moon"></i> <span>Toggle Theme</span>
                </button>
            </div>
            
            <!-- Filter Section -->
            <section class="glassmorphism mb-8" aria-labelledby="filter-heading">
                <h3 id="filter-heading" class="text-xl font-semibold mb-4 text-white flex items-center gap-2"><i class="fas fa-sliders-h text-orange-400"></i> Filters</h3>
                <form id="filter-form" class="grid md:grid-cols-4 gap-5">
                    <div>
                        <label for="class_id">Class</label>
                        <select id="class_id" class="form-select" aria-describedby="class_id_error">
                            <option value="">Select Class</option>
                        </select>
                        <p id="class_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="student_id">Student</label>
                        <select id="student_id" class="form-select" disabled aria-describedby="student_id_error">
                            <option value="">Select Student</option>
                        </select>
                        <p id="student_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="term_id">Term</label>
                        <select id="term_id" class="form-select" disabled aria-describedby="term_id_error">
                            <option value="">Select Term</option>
                        </select>
                        <p id="term_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="entry_period">Period Type</label>
                        <select id="entry_period" class="form-select" disabled aria-describedby="entry_period_error">
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-Weekly</option>
                        </select>
                        <p id="entry_period_error" class="error-message hidden"></p>
                    </div>
                    <input type="hidden" id="csrf_token" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                </form>
                <div class="mt-4">
                    <button id="generate-report" class="flex items-center gap-2" disabled>
                        <i class="fas fa-download"></i> Generate Report
                    </button>
                </div>
            </section>

            <!-- Graphs Section -->
            <section class="glassmorphism mb-8" aria-labelledby="graphs-heading">
                <h3 id="graphs-heading" class="text-xl font-semibold mb-4 text-white flex items-center gap-2"><i class="fas fa-chart-bar text-orange-400"></i> Progress Graphs</h3>
                <div class="grid md:grid-cols-2 gap-6">
                    <div class="chart-container" id="line-graph-container">
                        <canvas id="line-chart" aria-label="Student progress over time"></canvas>
                    </div>
                    <div class="chart-container" id="bar-graph-container">
                        <canvas id="bar-chart" aria-label="Subject comparison"></canvas>
                    </div>
                </div>
                <div id="progress-message" class="text-center text-slate-300 font-medium mt-4"></div>
            </section>

            <!-- Activities Section -->
            <section class="glassmorphism mb-8" aria-labelledby="activities-heading">
                <h3 id="activities-heading" class="text-xl font-semibold mb-4 text-white flex items-center gap-2"><i class="fas fa-running text-orange-400"></i> Extracurricular Activities</h3>
                <div class="overflow-x-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Activity Name</th>
                                <th>Participation Date</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="activities-table"></tbody>
                    </table>
                </div>
                <div id="activities-message" class="text-center text-slate-300 mt-4"></div>
            </section>

            <!-- Insights Section -->
            <section class="glassmorphism" aria-labelledby="insights-heading">
                <h3 id="insights-heading" class="text-xl font-semibold mb-4 text-white flex items-center gap-2"><i class="fas fa-lightbulb text-orange-400"></i> Insights</h3>
                <div id="insights-container" class="text-slate-200"></div>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <?php mysqli_close($conn); ob_end_flush(); ?>

    <script>
        // Theme toggle
        const themeToggle = document.getElementById('theme-toggle');
        const body = document.body;
        const icon = themeToggle.querySelector('i');
        const textSpan = themeToggle.querySelector('span');

        function setTheme(isDark) {
            if (isDark) {
                body.classList.add('dark');
                icon.className = 'fas fa-sun';
                textSpan.textContent = 'Light Mode';
                localStorage.setItem('theme', 'dark');
            } else {
                body.classList.remove('dark');
                icon.className = 'fas fa-moon';
                textSpan.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            }
        }

        const savedTheme = localStorage.getItem('theme');
        setTheme(savedTheme === 'dark');
        themeToggle.addEventListener('click', () => setTheme(!body.classList.contains('dark')));

        // Form elements
        const classIdInput = document.getElementById('class_id');
        const studentIdInput = document.getElementById('student_id');
        const termIdInput = document.getElementById('term_id');
        const entryPeriodInput = document.getElementById('entry_period');
        const csrfTokenInput = document.getElementById('csrf_token');
        const generateReportButton = document.getElementById('generate-report');
        const lineGraphContainer = document.getElementById('line-graph-container');
        const barGraphContainer = document.getElementById('bar-graph-container');
        const progressMessage = document.getElementById('progress-message');
        const activitiesTable = document.getElementById('activities-table');
        const activitiesMessage = document.getElementById('activities-message');
        const insightsContainer = document.getElementById('insights-container');

        let lineChart = null;
        let barChart = null;

        // Utility functions
        function showLoading(elementId, message = 'Loading...') {
            const el = document.getElementById(elementId);
            el.innerHTML = `<div class="flex justify-center items-center py-4"><span class="loading-spinner mr-2"></span>${message}</div>`;
        }
        function hideLoading(elementId) {
            const el = document.getElementById(elementId);
            if (el.innerHTML.includes('loading-spinner')) el.innerHTML = '';
        }
        function showError(elementId, message) {
            document.getElementById(elementId).innerHTML = `<p class="text-red-400 text-center py-4"><i class="fas fa-exclamation-circle mr-1"></i>${message}</p>`;
        }
        function clearError(elementId) {
            document.getElementById(elementId).innerHTML = '';
        }
        function getRandomColor(index) {
            const colors = ['#f97316', '#3b82f6', '#ef4444', '#22c55e', '#a855f7', '#eab308', '#06b6d4', '#7c2d12', '#4b5563', '#059669'];
            return colors[index % colors.length];
        }
        function updateCsrfToken(newToken) {
            if (newToken) csrfTokenInput.value = newToken;
        }

        // Load initial data
        function loadInitialData() {
            showLoading('progress-message', 'Loading classes and terms...');
            Promise.all([
                fetch('?action=get_classes').then(r => r.json()),
                fetch('?action=get_terms').then(r => r.json())
            ]).then(([classesData, termsData]) => {
                clearError('progress-message');
                if (classesData.success) {
                    classIdInput.innerHTML = '<option value="">Select Class</option>';
                    classesData.classes.forEach(cls => {
                        classIdInput.innerHTML += `<option value="${cls.id}">${cls.class_name}</option>`;
                    });
                } else {
                    showError('progress-message', classesData.error || 'Failed to load classes');
                }
                if (termsData.success) {
                    termIdInput.innerHTML = '<option value="">Select Term</option>';
                    termsData.terms.forEach(term => {
                        termIdInput.innerHTML += `<option value="${term.id}">${term.term_name} (${term.year})</option>`;
                    });
                    if (termIdInput.options.length > 1) {
                        termIdInput.value = termIdInput.options[1].value;
                        termIdInput.disabled = false;
                    }
                } else {
                    showError('progress-message', termsData.error || 'Failed to load terms');
                }
                if (classesData.success && classesData.classes.length > 0) {
                    classIdInput.value = classesData.classes[0].id;
                    loadStudents(classIdInput.value);
                }
                hideLoading('progress-message');
            }).catch(err => showError('progress-message', 'Error loading initial data'));
        }

        function loadStudents(classId) {
            if (!classId) {
                studentIdInput.innerHTML = '<option value="">Select Student</option>';
                studentIdInput.disabled = true;
                termIdInput.disabled = true;
                entryPeriodInput.disabled = true;
                generateReportButton.disabled = true;
                lineGraphContainer.style.display = 'none';
                barGraphContainer.style.display = 'none';
                return;
            }
            showLoading('progress-message', 'Loading students...');
            fetch(`?action=get_students&class_id=${classId}`)
                .then(r => r.json())
                .then(data => {
                    studentIdInput.innerHTML = '<option value="">Select Student</option>';
                    if (data.success && data.students.length > 0) {
                        data.students.forEach(s => studentIdInput.innerHTML += `<option value="${s.id}">${s.name}</option>`);
                        studentIdInput.disabled = false;
                        termIdInput.disabled = false;
                        entryPeriodInput.disabled = false;
                        studentIdInput.value = studentIdInput.options[1].value;
                        updateLineChart();
                        updateBarChart();
                        updateActivities();
                        updateInsights();
                        generateReportButton.disabled = !studentIdInput.value || !termIdInput.value;
                    } else {
                        showError('progress-message', 'No students found');
                        studentIdInput.disabled = true;
                    }
                }).catch(err => showError('progress-message', 'Error loading students'))
                .finally(() => hideLoading('progress-message'));
        }

        // Event listeners
        classIdInput.addEventListener('change', () => {
            loadStudents(classIdInput.value);
            termIdInput.value = termIdInput.options.length > 1 ? termIdInput.options[1].value : '';
            entryPeriodInput.value = 'weekly';
            lineGraphContainer.style.display = 'none';
            barGraphContainer.style.display = 'none';
            if (lineChart) lineChart.destroy();
            if (barChart) barChart.destroy();
            activitiesTable.innerHTML = '';
            insightsContainer.innerHTML = '';
            generateReportButton.disabled = true;
        });

        studentIdInput.addEventListener('change', () => {
            const sid = studentIdInput.value;
            termIdInput.disabled = !sid;
            entryPeriodInput.disabled = !sid;
            if (sid) {
                updateLineChart();
                updateBarChart();
                updateActivities();
                updateInsights();
            }
            generateReportButton.disabled = !sid || !termIdInput.value;
        });

        termIdInput.addEventListener('change', () => {
            const tid = termIdInput.value;
            entryPeriodInput.disabled = !tid;
            if (studentIdInput.value && tid) {
                updateBarChart();
                updateActivities();
                updateInsights();
            }
            generateReportButton.disabled = !studentIdInput.value || !tid;
        });

        entryPeriodInput.addEventListener('change', () => {
            if (studentIdInput.value) updateLineChart();
        });

        // Generate report
        generateReportButton.addEventListener('click', () => {
            if (!studentIdInput.value || !termIdInput.value) {
                showError('progress-message', 'Select student and term');
                return;
            }
            showLoading('progress-message', 'Generating report...');
            const formData = new FormData();
            formData.append('action', 'generate_report');
            formData.append('student_id', studentIdInput.value);
            formData.append('term_id', termIdInput.value);
            formData.append('csrf_token', csrfTokenInput.value);
            fetch('', { method: 'POST', body: formData })
                .then(res => {
                    if (!res.ok) return res.json().then(d => { throw new Error(d.error || 'Error'); });
                    return res.blob();
                })
                .then(blob => {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `student_report_${studentIdInput.value}_term_${termIdInput.value}.csv`;
                    a.click();
                    URL.revokeObjectURL(url);
                    clearError('progress-message');
                })
                .catch(err => showError('progress-message', err.message))
                .finally(() => hideLoading('progress-message'));
        });

        // Update line chart
        function updateLineChart() {
            const sid = studentIdInput.value, ep = entryPeriodInput.value;
            if (!sid || !ep) { lineGraphContainer.style.display = 'none'; return; }
            showLoading('progress-message', 'Loading progress...');
            fetch(`?action=get_student_progress&student_id=${sid}&entry_period=${ep}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.progress.length > 0) {
                        lineGraphContainer.style.display = 'block';
                        renderLineChart(data.progress, ep);
                        clearError('progress-message');
                    } else {
                        lineGraphContainer.style.display = 'none';
                        showError('progress-message', data.error || 'No data');
                    }
                }).catch(err => showError('progress-message', err.message))
                .finally(() => hideLoading('progress-message'));
        }

        function renderLineChart(progress, entryPeriod) {
            const ctx = document.getElementById('line-chart').getContext('2d');
            if (lineChart) lineChart.destroy();
            const subjects = [...new Set(progress.map(p => p.subject_name))];
            const periods = [...new Set(progress.map(p => p.period_start_date))].sort();
            const datasets = subjects.map((sub, i) => ({
                label: sub,
                data: periods.map(p => {
                    const e = progress.find(x => x.subject_name === sub && x.period_start_date === p);
                    return e ? parseFloat(e.total_scaled) : null;
                }),
                borderColor: getRandomColor(i),
                backgroundColor: getRandomColor(i) + '33',
                fill: false,
                tension: 0.4,
                pointRadius: 4
            }));
            const labels = periods.map((d, idx) => {
                const start = new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                return entryPeriod === 'bi-weekly' ? `Bi-Week ${idx+1} (${start})` : `Week ${idx+1} (${start})`;
            });
            lineChart = new Chart(ctx, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#e2e8f0' } },
                        title: { display: true, text: `Progress Over Time (${entryPeriod})`, color: '#fff', font: { size: 16 } }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100, title: { display: true, text: 'Scaled Score (%)', color: '#e2e8f0' }, ticks: { color: '#cbd5e1' }, grid: { color: 'rgba(255,255,255,0.1)' } },
                        x: { title: { display: true, text: 'Time Period', color: '#e2e8f0' }, ticks: { color: '#cbd5e1', maxRotation: 45 } }
                    }
                }
            });
        }

        // Update bar chart
        function updateBarChart() {
            const sid = studentIdInput.value, tid = termIdInput.value;
            if (!sid || !tid) { barGraphContainer.style.display = 'none'; return; }
            showLoading('progress-message', 'Loading comparison...');
            const formData = new FormData();
            formData.append('action', 'get_subject_comparison');
            formData.append('student_id', sid);
            formData.append('term_id', tid);
            formData.append('csrf_token', csrfTokenInput.value);
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.new_csrf_token) updateCsrfToken(data.new_csrf_token);
                    if (data.success && data.progress.length > 0) {
                        barGraphContainer.style.display = 'block';
                        renderBarChart(data.progress);
                        clearError('progress-message');
                    } else {
                        barGraphContainer.style.display = 'none';
                        showError('progress-message', data.error || 'No data');
                    }
                }).catch(err => showError('progress-message', err.message))
                .finally(() => hideLoading('progress-message'));
        }

        function renderBarChart(progress) {
            const ctx = document.getElementById('bar-chart').getContext('2d');
            if (barChart) barChart.destroy();
            const subjects = progress.map(p => p.subject_name);
            const datasets = [
                { label: 'Class Practices', data: progress.map(p => p.class_practices_scaled), backgroundColor: '#f9731699' },
                { label: 'Assignments', data: progress.map(p => p.assignments_scaled), backgroundColor: '#3b82f699' },
                { label: 'CAT', data: progress.map(p => p.cat_scaled), backgroundColor: '#ef444499' },
                { label: 'Group Project', data: progress.map(p => p.group_project_scaled), backgroundColor: '#22c55e99' }
            ];
            barChart = new Chart(ctx, {
                type: 'bar',
                data: { labels: subjects, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#e2e8f0' } },
                        title: { display: true, text: 'Subject Breakdown (Term Average)', color: '#fff', font: { size: 16 } }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 25, title: { display: true, text: 'Scaled Score (%)', color: '#e2e8f0' }, ticks: { color: '#cbd5e1', stepSize: 5 } },
                        x: { ticks: { color: '#cbd5e1', maxRotation: 45 } }
                    }
                }
            });
        }

        // Activities
        function updateActivities() {
            const sid = studentIdInput.value, tid = termIdInput.value;
            if (!sid || !tid) { activitiesTable.innerHTML = ''; return; }
            showLoading('activities-message', 'Loading activities...');
            fetch(`?action=get_activities&student_id=${sid}&term_id=${tid}`)
                .then(r => r.json())
                .then(data => {
                    activitiesTable.innerHTML = '';
                    if (data.success && data.activities.length > 0) {
                        data.activities.forEach(a => {
                            activitiesTable.innerHTML += `<tr>
                                <td>${a.activity_name || 'N/A'}</td>
                                <td>${a.participation_date || 'N/A'}</td>
                                <td>${a.details || 'N/A'}</td>
                            </tr>`;
                        });
                        activitiesMessage.textContent = '';
                    } else {
                        activitiesMessage.textContent = 'No activities found';
                    }
                }).catch(err => showError('activities-message', err.message))
                .finally(() => hideLoading('activities-message'));
        }

        // Insights
        function updateInsights() {
            const sid = studentIdInput.value, tid = termIdInput.value;
            if (!sid || !tid) { insightsContainer.innerHTML = '<p class="text-gray-400">Select student and term</p>'; return; }
            showLoading('insights-container', 'Loading insights...');
            Promise.all([
                fetch(`?action=get_student_progress&student_id=${sid}&entry_period=${entryPeriodInput.value || 'weekly'}`).then(r => r.json()),
                fetch(`?action=get_activities&student_id=${sid}&term_id=${tid}`).then(r => r.json())
            ]).then(([progressData, activitiesData]) => {
                let html = '<div class="space-y-2">';
                if (progressData.success && progressData.progress.length > 0) {
                    const scores = progressData.progress.map(p => parseFloat(p.total_scaled)).filter(v => !isNaN(v));
                    if (scores.length > 0) {
                        const avg = scores.reduce((a,b) => a+b,0)/scores.length;
                        const best = progressData.progress.reduce((a,b) => parseFloat(a.total_scaled) > parseFloat(b.total_scaled) ? a : b);
                        const worst = progressData.progress.reduce((a,b) => parseFloat(a.total_scaled) < parseFloat(b.total_scaled) ? a : b);
                        html += `<p><strong>Average:</strong> ${avg.toFixed(1)}%</p>
                                <p><strong>Best Subject:</strong> ${best.subject_name} (${parseFloat(best.total_scaled).toFixed(1)}%)</p>
                                <p><strong>Weakest:</strong> ${worst.subject_name} (${parseFloat(worst.total_scaled).toFixed(1)}%)</p>
                                <p><strong>Assessments:</strong> ${progressData.progress.length}</p>`;
                    }
                } else html += '<p>No academic data</p>';
                if (activitiesData.success && activitiesData.activities.length > 0) {
                    html += `<p><strong>Activities:</strong> ${activitiesData.activities.length} recorded</p>`;
                } else html += '<p>No activities</p>';
                html += '</div>';
                insightsContainer.innerHTML = html;
            }).catch(err => insightsContainer.innerHTML = `<p class="text-red-400">Error: ${err.message}</p>`)
            .finally(() => hideLoading('insights-container'));
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', loadInitialData);
    </script>
</body>
</html>