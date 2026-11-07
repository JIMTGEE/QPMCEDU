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
        } else {
            error_log("Failed to prepare clean CSRF tokens query: " . $conn->error);
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
            error_log("Failed to insert CSRF token for user_id $user_id: " . $stmt->error);
            $stmt->close();
        } else {
            error_log("Prepare failed for CSRF token insert: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("Error generating CSRF token: " . $e->getMessage());
    }
    // Fallback token (log this to detect issues)
    $fallback = bin2hex(random_bytes(32));
    error_log("Using fallback CSRF token for user_id $user_id");
    return $fallback;
}

function validateCsrfToken($conn, $user_id, $token) {
    if (empty($token)) {
        error_log("CSRF token is empty for user_id $user_id");
        return false;
    }
    
    $sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for CSRF validation: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("is", $user_id, $token);
    if (!$stmt->execute()) {
        error_log("Execute failed for CSRF validation: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $valid = $result->num_rows > 0;
    $stmt->close();
    
    if ($valid) {
        // Delete the used token
        $delete_sql = "DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        if ($delete_stmt) {
            $delete_stmt->bind_param("is", $user_id, $token);
            $delete_stmt->execute();
            $delete_stmt->close();
            error_log("CSRF token validated and deleted for user_id $user_id");
        } else {
            error_log("Failed to prepare CSRF token deletion: " . $conn->error);
        }
    } else {
        error_log("CSRF token invalid or expired for user_id $user_id, token: $token");
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
                    error_log("Failed to fetch classes: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch classes']);
                }
                break;

            case 'get_students':
                $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
                $students_sql = "SELECT DISTINCT u.id, u.name 
                                FROM users u 
                                JOIN academic_progress_entries ape ON u.id = ape.student_id 
                                WHERE u.role = 'student'";
                $students = [];
                
                if ($class_id > 0) {
                    $students_sql .= " AND ape.class_id = ?";
                    $stmt = $conn->prepare($students_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $class_id);
                        if ($stmt->execute()) {
                            $result = $stmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                $students[] = $row;
                            }
                        } else {
                            error_log("Failed to execute students query: " . $stmt->error);
                            echo json_encode(['success' => false, 'error' => 'Failed to fetch students']);
                            exit();
                        }
                        $stmt->close();
                    } else {
                        error_log("Prepare failed for students query: " . $conn->error);
                        echo json_encode(['success' => false, 'error' => 'Failed to fetch students']);
                        exit();
                    }
                } else {
                    $result = $conn->query($students_sql);
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $students[] = $row;
                        }
                    } else {
                        error_log("Failed to fetch students: " . $conn->error);
                        echo json_encode(['success' => false, 'error' => 'Failed to fetch students']);
                        exit();
                    }
                }
                
                echo json_encode(['success' => true, 'students' => $students]);
                break;

            case 'get_terms':
                $terms_sql = "SELECT id, term_name, year, start_date, end_date FROM academic_calendar ORDER BY year DESC, start_date DESC";
                $result = $conn->query($terms_sql);
                if ($result) {
                    $terms = [];
                    while ($row = $result->fetch_assoc()) {
                        $terms[] = $row;
                    }
                    echo json_encode(['success' => true, 'terms' => $terms]);
                } else {
                    error_log("Failed to fetch terms: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch terms']);
                }
                break;

            case 'get_student_progress':
                if (!isset($_GET['student_id']) || !isset($_GET['entry_period'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
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
                if ($stmt) {
                    $stmt->bind_param("is", $student_id, $entry_period);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $progress = [];
                        while ($row = $result->fetch_assoc()) {
                            $progress[] = $row;
                        }
                        $stmt->close();
                        echo json_encode(['success' => true, 'progress' => $progress]);
                    } else {
                        error_log("Failed to execute student progress query: " . $stmt->error);
                        echo json_encode(['success' => false, 'error' => 'Failed to fetch progress']);
                    }
                } else {
                    error_log("Prepare failed for student progress query: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch progress']);
                }
                break;

            case 'get_subject_comparison':
                if (!isset($_POST['student_id']) || !isset($_POST['term_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
                    break;
                }
                
                $student_id = intval($_POST['student_id']);
                $term_id = intval($_POST['term_id']);
                $csrf_token = $_POST['csrf_token'] ?? '';
                
                error_log("Received CSRF token for get_subject_comparison: $csrf_token for user_id: $admin_id");
                if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
                    error_log("CSRF validation failed for get_subject_comparison, student_id: $student_id");
                    // Generate a new token for the next request
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
                if ($stmt) {
                    $stmt->bind_param("ii", $student_id, $term_id);
                    if ($stmt->execute()) {
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
                        // Generate a new token for the next request
                        $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
                        echo json_encode(['success' => true, 'progress' => $progress, 'new_csrf_token' => $_SESSION['csrf_token']]);
                    } else {
                        error_log("Failed to execute subject comparison query: " . $stmt->error);
                        echo json_encode(['success' => false, 'error' => 'Failed to fetch subject comparison']);
                    }
                } else {
                    error_log("Prepare failed for subject comparison query: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch subject comparison']);
                }
                break;

            case 'generate_report':
                if (!isset($_POST['student_id']) || !isset($_POST['term_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
                    break;
                }
                
                $student_id = intval($_POST['student_id']);
                $term_id = intval($_POST['term_id']);
                $csrf_token = $_POST['csrf_token'] ?? '';
                
                error_log("Received CSRF token for generate_report: $csrf_token for user_id: $admin_id");
                if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
                    error_log("CSRF validation failed for generate_report, student_id: $student_id");
                    // Generate a new token for the next request
                    $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
                    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token', 'new_csrf_token' => $_SESSION['csrf_token']]);
                    break;
                }
                
                // Clear output buffer
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                // Fetch student name and class
                $student_query = "SELECT u.name, c.class_name 
                                 FROM users u 
                                 LEFT JOIN classes c ON u.class_id = c.id 
                                 WHERE u.id = ? AND u.role = 'student'";
                $student_stmt = $conn->prepare($student_query);
                if (!$student_stmt) {
                    error_log("Prepare failed for student query: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch student data']);
                    exit();
                }
                
                $student_stmt->bind_param("i", $student_id);
                if (!$student_stmt->execute()) {
                    error_log("Failed to execute student query: " . $student_stmt->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch student data']);
                    $student_stmt->close();
                    exit();
                }
                
                $student_result = $student_stmt->get_result();
                $student_data = $student_result->num_rows > 0 ? $student_result->fetch_assoc() : ['name' => 'Unknown', 'class_name' => 'Unknown'];
                $student_name = $student_data['name'];
                $class_name = $student_data['class_name'] ?: 'N/A';
                $student_stmt->close();
                
                // Fetch term details
                $term_query = "SELECT term_name, year FROM academic_calendar WHERE id = ?";
                $term_stmt = $conn->prepare($term_query);
                if (!$term_stmt) {
                    error_log("Prepare failed for term query: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch term data']);
                    exit();
                }
                
                $term_stmt->bind_param("i", $term_id);
                if (!$term_stmt->execute()) {
                    error_log("Failed to execute term query: " . $term_stmt->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch term data']);
                    $term_stmt->close();
                    exit();
                }
                
                $term_result = $term_stmt->get_result();
                $term_data = $term_result->num_rows > 0 ? $term_result->fetch_assoc() : ['term_name' => 'Unknown', 'year' => ''];
                $term_stmt->close();
                
                // Fetch progress data
                $progress_query = "SELECT s.subject_name, ape.total_scaled, ape.entry_period, ape.period_start_date, ape.period_end_date,
                                   ape.class_practices_raw, ape.class_practices_max, ape.assignments_raw, ape.assignments_max,
                                   ape.cat_raw, ape.cat_max, ape.group_project_raw, ape.group_project_max, ape.comments
                                   FROM academic_progress_entries ape
                                   JOIN subjects s ON ape.subject_id = s.id
                                   WHERE ape.student_id = ? AND ape.term_id = ?
                                   ORDER BY ape.period_start_date, s.subject_name";
                $progress_stmt = $conn->prepare($progress_query);
                if (!$progress_stmt) {
                    error_log("Prepare failed for progress query: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch progress data']);
                    exit();
                }
                
                $progress_stmt->bind_param("ii", $student_id, $term_id);
                if (!$progress_stmt->execute()) {
                    error_log("Failed to execute progress query: " . $progress_stmt->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch progress data']);
                    $progress_stmt->close();
                    exit();
                }
                
                $progress_result = $progress_stmt->get_result();
                $progress_data = [];
                while ($row = $progress_result->fetch_assoc()) {
                    $progress_data[] = $row;
                }
                $progress_stmt->close();
                
                // Fetch activities
                $activities_query = "SELECT activity_name, participation_date, details
                                     FROM extracurricular_activities
                                     WHERE student_id = ? AND term_id = ?
                                     ORDER BY participation_date DESC";
                $activities_stmt = $conn->prepare($activities_query);
                if (!$activities_stmt) {
                    error_log("Prepare failed for activities query: " . $conn->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch activities data']);
                    exit();
                }
                
                $activities_stmt->bind_param("ii", $student_id, $term_id);
                if (!$activities_stmt->execute()) {
                    error_log("Failed to execute activities query: " . $activities_stmt->error);
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch activities data']);
                    $activities_stmt->close();
                    exit();
                }
                
                $activities_result = $activities_stmt->get_result();
                $activities_data = [];
                while ($row = $activities_result->fetch_assoc()) {
                    $activities_data[] = $row;
                }
                $activities_stmt->close();
                
                // Set CSV headers
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="student_report_' . $student_id . '_term_' . $term_id . '.csv"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                
                // Generate CSV
                $output = fopen('php://output', 'w');
                if (!$output) {
                    error_log("Failed to open php://output for CSV generation");
                    echo json_encode(['success' => false, 'error' => 'Failed to generate CSV']);
                    exit();
                }
                
                // Add BOM for UTF-8 encoding
                fwrite($output, "\xEF\xBB\xBF");
                
                fputcsv($output, ['Student Progress Report']);
                fputcsv($output, ['Student', $student_name]);
                fputcsv($output, ['Class', $class_name]);
                fputcsv($output, ['Term', $term_data['term_name'] . ' (' . $term_data['year'] . ')']);
                fputcsv($output, []);
                
                fputcsv($output, ['Academic Progress']);
                fputcsv($output, ['Subject', 'Class Practices', 'Assignments', 'CAT', 'Group Project', 'Total Score (%)', 'Period Type', 'Start Date', 'End Date', 'Comments']);
                if (empty($progress_data)) {
                    fputcsv($output, ['No academic progress data available']);
                } else {
                    foreach ($progress_data as $row) {
                        $class_practices = ($row['class_practices_max'] > 0) ? round(($row['class_practices_raw'] / $row['class_practices_max']) * 25, 2) : 0;
                        $assignments = ($row['assignments_max'] > 0) ? round(($row['assignments_raw'] / $row['assignments_max']) * 25, 2) : 0;
                        $cat = ($row['cat_max'] > 0) ? round(($row['cat_raw'] / $row['cat_max']) * 25, 2) : 0;
                        $group_project = ($row['group_project_max'] > 0) ? round(($row['group_project_raw'] / $row['group_project_max']) * 25, 2) : 0;
                        fputcsv($output, [
                            $row['subject_name'],
                            $class_practices,
                            $assignments,
                            $cat,
                            $group_project,
                            $row['total_scaled'] ?? 'N/A',
                            $row['entry_period'] ?? 'N/A',
                            $row['period_start_date'] ?? 'N/A',
                            $row['period_end_date'] ?? 'N/A',
                            $row['comments'] ?? 'N/A'
                        ]);
                    }
                }
                
                fputcsv($output, []);
                fputcsv($output, ['Extracurricular Activities']);
                fputcsv($output, ['Activity Name', 'Participation Date', 'Details']);
                if (empty($activities_data)) {
                    fputcsv($output, ['No extracurricular activities recorded']);
                } else {
                    foreach ($activities_data as $row) {
                        fputcsv($output, [
                            $row['activity_name'] ?? 'N/A',
                            $row['participation_date'] ?? 'N/A',
                            $row['details'] ?? 'N/A'
                        ]);
                    }
                }
                
                fclose($output);
                error_log("Successfully generated report for student_id: $student_id, term_id: $term_id");
                // Generate a new token for the next request
                $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
                exit();
                
            default:
                error_log("Invalid action requested: $action");
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(to bottom right, #1a202c, #2d3748);
            color: #e5e7eb;
            min-height: 100vh;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        h1, h2, h3 { font-family: 'Orbitron', sans-serif; }
        .form-select {
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            transition: border-color 0.3s, box-shadow 0.3s;
            padding: 0.5rem;
            border-radius: 0.375rem;
        }
        .form-select:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 0.25rem rgba(249, 115, 22, 0.25);
            background-color: rgba(255, 255, 255, 0.15);
        }
        .form-select option {
            background-color: #1a202c;
            color: #ffffff;
        }
        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
            min-height: 400px;
            margin-bottom: 1.5rem;
        }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #1f2937; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #f97316;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 to-orange-900 dark:bg-gradient-to-br dark:from-gray-900 dark:to-orange-900">
    <div class="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white"><i class="fas fa-chart-line mr-2"></i> Student Progress Monitoring</h1>
                <button class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg glassmorphism" id="theme-toggle">
                    <i class="fas fa-moon mr-2"></i> Toggle Theme
                </button>
            </div>
            
            <!-- Filter Section -->
            <section class="glassmorphism rounded-2xl p-6 mb-8" aria-labelledby="filter-heading">
                <h3 id="filter-heading" class="text-xl font-semibold mb-4 text-white">Filters</h3>
                <form id="filter-form" class="grid md:grid-cols-4 gap-4">
                    <div>
                        <label for="class_id" class="block text-sm font-medium text-gray-300">Class</label>
                        <select id="class_id" class="form-select w-full" aria-describedby="class_id_error">
                            <option value="">Select Class</option>
                        </select>
                        <p id="class_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="student_id" class="block text-sm font-medium text-gray-300">Student</label>
                        <select id="student_id" class="form-select w-full" disabled aria-describedby="student_id_error">
                            <option value="">Select Student</option>
                        </select>
                        <p id="student_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="term_id" class="block text-sm font-medium text-gray-300">Term</label>
                        <select id="term_id" class="form-select w-full" disabled aria-describedby="term_id_error">
                            <option value="">Select Term</option>
                        </select>
                        <p id="term_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="entry_period" class="block text-sm font-medium text-gray-300">Period Type</label>
                        <select id="entry_period" class="form-select w-full" disabled aria-describedby="entry_period_error">
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-Weekly</option>
                        </select>
                        <p id="entry_period_error" class="error-message hidden"></p>
                    </div>
                    <input type="hidden" id="csrf_token" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                </form>
                <div class="mt-4">
                    <button id="generate-report" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg glassmorphism" disabled>
                        <i class="fas fa-download mr-2"></i> Generate Report
                    </button>
                </div>
            </section>

            <!-- Graphs Section -->
            <section class="glassmorphism rounded-2xl p-6 mb-8" aria-labelledby="graphs-heading">
                <h3 id="graphs-heading" class="text-xl font-semibold mb-4 text-white">Progress Graphs</h3>
                <div class="grid md:grid-cols-2 gap-6">
                    <div class="chart-container" id="line-graph-container">
                        <canvas id="line-chart" aria-label="Student progress over time"></canvas>
                    </div>
                    <div class="chart-container" id="bar-graph-container">
                        <canvas id="bar-chart" aria-label="Subject comparison"></canvas>
                    </div>
                </div>
                <div id="progress-message" class="text-center text-gray-300"></div>
            </section>

            <!-- Activities Section -->
            <section class="glassmorphism rounded-2xl p-6 mb-8" aria-labelledby="activities-heading">
                <h3 id="activities-heading" class="text-xl font-semibold mb-4 text-white">Extracurricular Activities</h3>
                <div id="activities-container" class="overflow-x-auto">
                    <table class="w-full text-left text-gray-300">
                        <thead>
                            <tr class="bg-gray-800">
                                <th class="px-4 py-2">Activity Name</th>
                                <th class="px-4 py-2">Participation Date</th>
                                <th class="px-4 py-2">Details</th>
                            </tr>
                        </thead>
                        <tbody id="activities-table"></tbody>
                    </table>
                </div>
                <div id="activities-message" class="text-center text-gray-300 mt-4"></div>
            </section>

            <!-- Insights Section -->
            <section class="glassmorphism rounded-2xl p-6" aria-labelledby="insights-heading">
                <h3 id="insights-heading" class="text-xl font-semibold mb-4 text-white">Insights</h3>
                <div id="insights-container" class="text-gray-300"></div>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
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
            const element = document.getElementById(elementId);
            element.innerHTML = `<div class="flex justify-center items-center py-4"><span class="loading-spinner mr-2"></span>${message}</div>`;
        }

        function hideLoading(elementId) {
            const element = document.getElementById(elementId);
            if (element.innerHTML.includes('loading-spinner')) {
                element.innerHTML = '';
            }
        }

        function showError(elementId, message) {
            const element = document.getElementById(elementId);
            element.innerHTML = `<p class="text-red-400 text-center py-4">${message}</p>`;
        }

        function clearError(elementId) {
            const element = document.getElementById(elementId);
            element.innerHTML = '';
        }

        function getRandomColor(index) {
            const colors = ['#f97316', '#1e3a8a', '#dc2626', '#16a34a', '#a21caf', '#eab308', '#0284c7', '#7c2d12', '#4b5563', '#059669'];
            return colors[index % colors.length];
        }

        // Update CSRF token in form
        function updateCsrfToken(newToken) {
            if (newToken) {
                csrfTokenInput.value = newToken;
                console.log('Updated CSRF token:', newToken);
            }
        }

        // Load initial data
        function loadInitialData() {
            showLoading('progress-message', 'Loading classes and terms...');
            
            Promise.all([
                fetch('?action=get_classes')
                    .then(res => {
                        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                        return res.json();
                    }),
                fetch('?action=get_terms')
                    .then(res => {
                        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                        return res.json();
                    })
            ])
            .then(([classesData, termsData]) => {
                clearError('progress-message');
                
                // Load classes
                if (classesData.success) {
                    classIdInput.innerHTML = '<option value="">Select Class</option>';
                    classesData.classes.forEach(cls => {
                        const option = document.createElement('option');
                        option.value = cls.id;
                        option.textContent = cls.class_name;
                        classIdInput.appendChild(option);
                    });
                } else {
                    showError('progress-message', classesData.error || 'Failed to load classes');
                }
                
                // Load terms
                if (termsData.success) {
                    termIdInput.innerHTML = '<option value="">Select Term</option>';
                    termsData.terms.forEach(term => {
                        const option = document.createElement('option');
                        option.value = term.id;
                        option.textContent = `${term.term_name} (${term.year})`;
                        termIdInput.appendChild(option);
                    });
                    // Default to first term
                    if (termIdInput.options.length > 1) {
                        termIdInput.value = termIdInput.options[1].value;
                        termIdInput.disabled = false;
                    }
                } else {
                    showError('progress-message', termsData.error || 'Failed to load terms');
                }
                
                // Auto-select first class if available
                if (classesData.success && classesData.classes.length > 0) {
                    classIdInput.value = classesData.classes[0].id;
                    loadStudents(classIdInput.value);
                }
                
                hideLoading('progress-message');
            })
            .catch(err => {
                console.error('Error loading initial data:', err);
                showError('progress-message', 'Error loading initial data: ' + err.message);
                hideLoading('progress-message');
            });
        }

        // Load students for selected class
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
            studentIdInput.innerHTML = '<option value="">Loading students...</option>';
            studentIdInput.disabled = true;
            
            fetch(`?action=get_students&class_id=${encodeURIComponent(classId)}`)
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                    return res.json();
                })
                .then(data => {
                    studentIdInput.innerHTML = '<option value="">Select Student</option>';
                    if (data.success) {
                        if (data.students.length > 0) {
                            data.students.forEach(student => {
                                const option = document.createElement('option');
                                option.value = student.id;
                                option.textContent = student.name;
                                studentIdInput.appendChild(option);
                            });
                            studentIdInput.disabled = false;
                            termIdInput.disabled = false;
                            entryPeriodInput.disabled = false;
                            // Auto-select first student
                            if (studentIdInput.options.length > 1) {
                                studentIdInput.value = studentIdInput.options[1].value;
                                updateLineChart();
                                updateBarChart();
                                updateActivities();
                                updateInsights();
                                generateReportButton.disabled = !studentIdInput.value || !termIdInput.value;
                            }
                        } else {
                            showError('progress-message', 'No students found for selected class');
                            studentIdInput.disabled = true;
                            termIdInput.disabled = true;
                            entryPeriodInput.disabled = true;
                            generateReportButton.disabled = true;
                            lineGraphContainer.style.display = 'none';
                            barGraphContainer.style.display = 'none';
                        }
                    } else {
                        showError('progress-message', data.error || 'Failed to load students');
                        studentIdInput.disabled = true;
                        lineGraphContainer.style.display = 'none';
                        barGraphContainer.style.display = 'none';
                    }
                })
                .catch(err => {
                    console.error('Error loading students:', err);
                    showError('progress-message', 'Error loading students: ' + err.message);
                    studentIdInput.innerHTML = '<option value="">Select Student</option>';
                    studentIdInput.disabled = true;
                    termIdInput.disabled = true;
                    entryPeriodInput.disabled = true;
                    generateReportButton.disabled = true;
                    lineGraphContainer.style.display = 'none';
                    barGraphContainer.style.display = 'none';
                })
                .finally(() => {
                    hideLoading('progress-message');
                });
        }

        // Event listeners
        classIdInput.addEventListener('change', () => {
            const classId = classIdInput.value;
            loadStudents(classId);
            
            // Reset other fields
            studentIdInput.value = '';
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
            const studentId = studentIdInput.value;
            termIdInput.disabled = !studentId;
            entryPeriodInput.disabled = !studentId;
            
            if (studentId) {
                updateLineChart();
                updateBarChart();
                updateActivities();
                updateInsights();
            } else {
                lineGraphContainer.style.display = 'none';
                barGraphContainer.style.display = 'none';
                if (lineChart) lineChart.destroy();
                if (barChart) barChart.destroy();
                activitiesTable.innerHTML = '';
                insightsContainer.innerHTML = '';
            }
            
            generateReportButton.disabled = !studentId || !termIdInput.value;
        });

        termIdInput.addEventListener('change', () => {
            const termId = termIdInput.value;
            entryPeriodInput.disabled = !termId;
            
            if (studentIdInput.value && termId) {
                updateBarChart();
                updateActivities();
                updateInsights();
            }
            
            generateReportButton.disabled = !studentIdInput.value || !termId;
        });

        entryPeriodInput.addEventListener('change', () => {
            if (studentIdInput.value) {
                updateLineChart();
            }
        });

        // Generate report
        generateReportButton.addEventListener('click', () => {
            if (!studentIdInput.value || !termIdInput.value) {
                showError('progress-message', 'Please select a student and term to generate a report');
                return;
            }
            
            showLoading('progress-message', 'Generating report...');
            
            const formData = new FormData();
            formData.append('action', 'generate_report');
            formData.append('student_id', studentIdInput.value);
            formData.append('term_id', termIdInput.value);
            formData.append('csrf_token', csrfTokenInput.value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(data => {
                        throw new Error(data.error || `HTTP error! Status: ${res.status}`);
                    });
                }
                return res.blob();
            })
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `student_report_${studentIdInput.value}_term_${termIdInput.value}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                clearError('progress-message');
            })
            .catch(err => {
                console.error('Error generating report:', err);
                showError('progress-message', 'Error generating report: ' + err.message);
                // Check for CSRF token error and update token if provided
                if (err.message.includes('Invalid CSRF token')) {
                    fetch('?action=get_classes') // Trigger a GET request to refresh CSRF token
                        .then(res => res.json())
                        .then(data => {
                            if (data.new_csrf_token) {
                                updateCsrfToken(data.new_csrf_token);
                            }
                        });
                }
            })
            .finally(() => {
                hideLoading('progress-message');
            });
        });

        // Update bar chart
        function updateBarChart() {
            const studentId = studentIdInput.value;
            const termId = termIdInput.value;
            
            if (!studentId || !termId) {
                barGraphContainer.style.display = 'none';
                if (barChart) barChart.destroy();
                showError('progress-message', 'Please select a student and term');
                return;
            }
            
            showLoading('progress-message', 'Loading subject comparison...');
            
            const formData = new FormData();
            formData.append('action', 'get_subject_comparison');
            formData.append('student_id', studentId);
            formData.append('term_id', termId);
            formData.append('csrf_token', csrfTokenInput.value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(data => {
                        throw new Error(data.error || `HTTP ${res.status}: ${res.statusText}`);
                    });
                }
                return res.json();
            })
            .then(data => {
                if (data.new_csrf_token) {
                    updateCsrfToken(data.new_csrf_token);
                }
                if (data.success && data.progress.length > 0) {
                    barGraphContainer.style.display = 'block';
                    renderBarChart(data.progress);
                    clearError('progress-message');
                } else {
                    barGraphContainer.style.display = 'none';
                    if (barChart) barChart.destroy();
                    showError('progress-message', data.error || 'No progress data for selected term');
                }
            })
            .catch(err => {
                console.error('Error loading bar chart:', err);
                barGraphContainer.style.display = 'none';
                if (barChart) barChart.destroy();
                showError('progress-message', 'Error loading subject comparison: ' + err.message);
                if (err.message.includes('Invalid CSRF token')) {
                    fetch('?action=get_classes') // Trigger a GET request to refresh CSRF token
                        .then(res => res.json())
                        .then(data => {
                            if (data.new_csrf_token) {
                                updateCsrfToken(data.new_csrf_token);
                            }
                        });
                }
            })
            .finally(() => hideLoading('progress-message'));
        }

        // Render bar chart
        function renderBarChart(progress) {
            const ctx = document.getElementById('bar-chart').getContext('2d');
            if (!ctx) {
                console.error('Bar chart canvas context not found');
                showError('progress-message', 'Error initializing bar chart');
                return;
            }
            if (barChart) barChart.destroy();
            
            const subjects = progress.map(p => p.subject_name);
            const datasets = [
                {
                    label: 'Class Practices',
                    data: progress.map(p => p.class_practices_scaled || 0),
                    backgroundColor: '#f97316' + '99',
                    borderColor: '#f97316',
                    borderWidth: 1
                },
                {
                    label: 'Assignments',
                    data: progress.map(p => p.assignments_scaled || 0),
                    backgroundColor: '#1e3a8a' + '99',
                    borderColor: '#1e3a8a',
                    borderWidth: 1
                },
                {
                    label: 'CAT',
                    data: progress.map(p => p.cat_scaled || 0),
                    backgroundColor: '#dc2626' + '99',
                    borderColor: '#dc2626',
                    borderWidth: 1
                },
                {
                    label: 'Group Project',
                    data: progress.map(p => p.group_project_scaled || 0),
                    backgroundColor: '#16a34a' + '99',
                    borderColor: '#16a34a',
                    borderWidth: 1
                }
            ];
            
            barChart = new Chart(ctx, {
                type: 'bar',
                data: { 
                    labels: subjects, 
                    datasets 
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'top',
                            labels: { 
                                color: '#ffffff',
                                padding: 20
                            }
                        },
                        title: { 
                            display: true, 
                            text: 'Subject Performance Breakdown (Term Average)', 
                            color: '#ffffff',
                            font: { size: 16, weight: 'bold' },
                            padding: { top: 20, bottom: 20 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.raw.toFixed(2)}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 25, 
                            title: { display: true, text: 'Scaled Score (%)', color: '#ffffff', padding: 10 },
                            ticks: { 
                                color: '#ffffff',
                                stepSize: 5,
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        x: { 
                            title: { display: true, text: 'Subjects', color: '#ffffff', padding: 10 },
                            ticks: { 
                                color: '#ffffff',
                                maxRotation: 45,
                                minRotation: 45
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    },
                    layout: {
                        padding: {
                            left: 10,
                            right: 10,
                            top: 10,
                            bottom: 10
                        }
                    }
                }
            });
        }

        // Update line chart
        function updateLineChart() {
            const studentId = studentIdInput.value;
            const entryPeriod = entryPeriodInput.value;
            
            if (!studentId || !entryPeriod) {
                lineGraphContainer.style.display = 'none';
                if (lineChart) lineChart.destroy();
                showError('progress-message', 'Please select a student and period type');
                return;
            }
            
            showLoading('progress-message', 'Loading progress chart...');
            
            fetch(`?action=get_student_progress&student_id=${encodeURIComponent(studentId)}&entry_period=${encodeURIComponent(entryPeriod)}`)
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                    return res.json();
                })
                .then(data => {
                    if (data.success && data.progress.length > 0) {
                        lineGraphContainer.style.display = 'block';
                        renderLineChart(data.progress, entryPeriod);
                        clearError('progress-message');
                    } else {
                        lineGraphContainer.style.display = 'none';
                        if (lineChart) lineChart.destroy();
                        showError('progress-message', data.error || 'No progress data available for selected period');
                    }
                })
                .catch(err => {
                    console.error('Error loading line chart:', err);
                    lineGraphContainer.style.display = 'none';
                    if (lineChart) lineChart.destroy();
                    showError('progress-message', 'Error loading progress chart: ' + err.message);
                })
                .finally(() => hideLoading('progress-message'));
        }

        // Render line chart
        function renderLineChart(progress, entryPeriod) {
            const ctx = document.getElementById('line-chart').getContext('2d');
            if (!ctx) {
                console.error('Line chart canvas context not found');
                showError('progress-message', 'Error initializing line chart');
                return;
            }
            if (lineChart) lineChart.destroy();
            
            const subjects = [...new Set(progress.map(p => p.subject_name))];
            const periods = [...new Set(progress.map(p => p.period_start_date))].sort();
            
            const datasets = subjects.map((subject, index) => ({
                label: subject,
                data: periods.map(period => {
                    const entry = progress.find(p => p.subject_name === subject && p.period_start_date === period);
                    return entry ? parseFloat(entry.total_scaled) || 0 : null;
                }),
                borderColor: getRandomColor(index),
                backgroundColor: getRandomColor(index) + '33',
                fill: false,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6
            }));
            
            const periodLabels = periods.map((date, idx) => {
                const start = new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                if (entryPeriod === 'bi-weekly') {
                    const entry = progress.find(p => p.period_start_date === date);
                    const end = entry ? new Date(entry.period_end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '';
                    return `Bi-Week ${Math.floor(idx/2) + 1} (${start} - ${end})`;
                }
                return `Week ${idx + 1} (${start})`;
            });
            
            lineChart = new Chart(ctx, {
                type: 'line',
                data: { 
                    labels: periodLabels, 
                    datasets 
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'top',
                            labels: { 
                                color: '#ffffff',
                                padding: 20,
                                usePointStyle: true
                            }
                        },
                        title: { 
                            display: true, 
                            text: `Progress Over Time (${entryPeriod.charAt(0).toUpperCase() + entryPeriod.slice(1)})`, 
                            color: '#ffffff',
                            font: { size: 16, weight: 'bold' },
                            padding: 20
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 100, 
                            title: { display: true, text: 'Scaled Score (%)', color: '#ffffff' },
                            ticks: { 
                                color: '#ffffff',
                                stepSize: 10
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        x: { 
                            title: { display: true, text: 'Time Period', color: '#ffffff' },
                            ticks: { 
                                color: '#ffffff', 
                                maxRotation: 45,
                                minRotation: 45
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }

        // Update activities
        function updateActivities() {
            const studentId = studentIdInput.value;
            const termId = termIdInput.value;
            
            if (!studentId || !termId) {
                activitiesTable.innerHTML = '';
                activitiesMessage.textContent = 'Select a student and term to view activities';
                return;
            }
            
            showLoading('activities-message', 'Loading activities...');
            
            fetch(`?action=get_activities&student_id=${encodeURIComponent(studentId)}&term_id=${encodeURIComponent(termId)}`)
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                    return res.json();
                })
                .then(data => {
                    activitiesTable.innerHTML = '';
                    if (data.success && data.activities.length > 0) {
                        data.activities.forEach(activity => {
                            const row = document.createElement('tr');
                            row.className = 'hover:bg-gray-800';
                            row.innerHTML = `
                                <td class="px-4 py-2 border-b border-gray-700">${activity.activity_name || 'N/A'}</td>
                                <td class="px-4 py-2 border-b border-gray-700">${activity.participation_date || 'N/A'}</td>
                                <td class="px-4 py-2 border-b border-gray-700">${activity.details || 'N/A'}</td>
                            `;
                            activitiesTable.appendChild(row);
                        });
                        activitiesMessage.textContent = '';
                    } else {
                        activitiesMessage.textContent = data.error || 'No activities found for selected student and term';
                    }
                })
                .catch(err => {
                    console.error('Error loading activities:', err);
                    showError('activities-message', 'Error loading activities: ' + err.message);
                })
                .finally(() => hideLoading('activities-message'));
        }

        // Update insights
        function updateInsights() {
            const studentId = studentIdInput.value;
            const termId = termIdInput.value;
            
            if (!studentId || !termId) {
                insightsContainer.innerHTML = '<p class="text-gray-400">Select a student and term to view insights</p>';
                return;
            }
            
            showLoading('insights-container', 'Loading insights...');
            
            Promise.all([
                fetch(`?action=get_student_progress&student_id=${encodeURIComponent(studentId)}&entry_period=${encodeURIComponent(entryPeriodInput.value || 'weekly')}`)
                    .then(res => {
                        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                        return res.json();
                    }),
                fetch(`?action=get_activities&student_id=${encodeURIComponent(studentId)}&term_id=${encodeURIComponent(termId)}`)
                    .then(res => {
                        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                        return res.json();
                    })
            ])
            .then(([progressData, activitiesData]) => {
                let insights = '<div class="space-y-2">';
                
                if (progressData.success && progressData.progress.length > 0) {
                    const scores = progressData.progress
                        .map(p => parseFloat(p.total_scaled))
                        .filter(score => !isNaN(score));
                    
                    if (scores.length > 0) {
                        const averageScore = scores.reduce((sum, score) => sum + score, 0) / scores.length;
                        const topSubject = progressData.progress.reduce((max, p) => 
                            parseFloat(p.total_scaled) > parseFloat(max.total_scaled) ? p : max
                        );
                        const bottomSubject = progressData.progress.reduce((min, p) => 
                            parseFloat(p.total_scaled) < parseFloat(min.total_scaled) ? p : min
                        );
                        
                        insights += `
                            <p><strong>Average Performance:</strong> ${averageScore.toFixed(1)}%</p>
                            <p><strong>Best Subject:</strong> ${topSubject.subject_name} (${parseFloat(topSubject.total_scaled).toFixed(1)}%)</p>
                            <p><strong>Weakest Subject:</strong> ${bottomSubject.subject_name} (${parseFloat(bottomSubject.total_scaled).toFixed(1)}%)</p>
                            <p><strong>Total Assessments:</strong> ${progressData.progress.length}</p>
                        `;
                    } else {
                        insights += '<p>No valid score data available</p>';
                    }
                } else {
                    insights += '<p>No academic progress data available</p>';
                }
                
                if (activitiesData.success && activitiesData.activities.length > 0) {
                    insights += `<p><strong>Extracurricular Activities:</strong> ${activitiesData.activities.length} participated</p>`;
                } else {
                    insights += '<p>No extracurricular activities recorded</p>';
                }
                
                insights += '</div>';
                insightsContainer.innerHTML = insights;
            })
            .catch(err => {
                console.error('Error loading insights:', err);
                insightsContainer.innerHTML = `<p class="text-red-400">Error loading insights: ${err.message}</p>`;
            })
            .finally(() => hideLoading('insights-container'));
        }

        // Initialize the page
        document.addEventListener('DOMContentLoaded', () => {
            loadInitialData();
        });
    </script>
</body>
</html>