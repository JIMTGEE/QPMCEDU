<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
error_reporting(E_ALL);
ini_set('display_errors', 0);

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
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        $sql = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iss", $user_id, $action, $description);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log("Activity: [$user_id] $action - $description");
        }
    }
} else {
    require_once($log_path);
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
$user_check_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher' AND status = 'active'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $teacher_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid teacher_id in session: $teacher_id");
        $_SESSION['error_msg'] = "Invalid user session. Please contact administrator.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $teacher_name = $user['name'];
    $teacher_department_id = $user['department_id'];
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
logActivity($conn, $teacher_id, "Accessed Manage Assignments Page", "Teacher accessed manage assignments page");

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// =============================================================================
// FETCH ACADEMIC YEARS AND TERMS FOR SELECTOR
// =============================================================================

// Get all academic years for dropdown
$academic_years_sql = "SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC";
$academic_years_result = $conn->query($academic_years_sql);
$academic_years = [];
if ($academic_years_result) {
    while ($row = $academic_years_result->fetch_assoc()) {
        $academic_years[] = $row;
    }
}

// Get selected academic year from GET or default to null (all years)
$selected_academic_year_id = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);
if ($selected_academic_year_id === false || $selected_academic_year_id === null) {
    $selected_academic_year_id = null;
}
$selected_academic_year_label = null;
if ($selected_academic_year_id) {
    foreach ($academic_years as $ay) {
        if ($ay['id'] == $selected_academic_year_id) {
            $selected_academic_year_label = $ay['year_label'];
            break;
        }
    }
}

// Get terms for selected academic year (if a year is selected), else get all terms
$terms = [];
if ($selected_academic_year_id) {
    $terms_sql = "SELECT id, term_name, term_number, is_active FROM academic_terms WHERE academic_year_id = ? ORDER BY term_number";
    $terms_stmt = $conn->prepare($terms_sql);
    if ($terms_stmt) {
        $terms_stmt->bind_param("i", $selected_academic_year_id);
        $terms_stmt->execute();
        $terms_result = $terms_stmt->get_result();
        while ($row = $terms_result->fetch_assoc()) {
            $terms[] = $row;
        }
        $terms_stmt->close();
    }
} else {
    // If no year selected, get all terms (for "All Years" view)
    $terms_sql = "SELECT id, term_name, term_number, is_active, academic_year_id FROM academic_terms ORDER BY academic_year_id DESC, term_number";
    $terms_result = $conn->query($terms_sql);
    if ($terms_result) {
        while ($row = $terms_result->fetch_assoc()) {
            $terms[] = $row;
        }
    }
}

// Get selected term from GET or default to null (all terms)
$selected_term_id = filter_input(INPUT_GET, 'term_id', FILTER_VALIDATE_INT);
if ($selected_term_id === false || $selected_term_id === null) {
    $selected_term_id = null;
}

$selected_term_name = null;
if ($selected_term_id) {
    foreach ($terms as $term) {
        if ($term['id'] == $selected_term_id) {
            $selected_term_name = $term['term_name'];
            break;
        }
    }
}

// =============================================================================
// FETCH DEPARTMENTS
// =============================================================================

$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_result = $conn->query($departments_sql);
$departments = [];
if ($departments_result) {
    while ($row = $departments_result->fetch_assoc()) {
        $departments[] = $row;
    }
} else {
    error_log("Failed to fetch departments: " . $conn->error);
}

// =============================================================================
// FETCH TEACHER'S CLASSES (from class_schedules)
// =============================================================================

$teacher_classes_sql = "
    SELECT DISTINCT c.id, c.class_name, c.department_id, d.name as department_name
    FROM classes c
    INNER JOIN class_schedules cs ON c.id = cs.class_id
    LEFT JOIN departments d ON c.department_id = d.id
    WHERE cs.teacher_id = ? 
    ORDER BY c.class_name";
$teacher_classes_stmt = $conn->prepare($teacher_classes_sql);
$teacher_classes = [];
if ($teacher_classes_stmt) {
    $teacher_classes_stmt->bind_param("i", $teacher_id);
    $teacher_classes_stmt->execute();
    $teacher_classes_result = $teacher_classes_stmt->get_result();
    while ($row = $teacher_classes_result->fetch_assoc()) {
        $teacher_classes[] = $row;
    }
    $teacher_classes_stmt->close();
}

// =============================================================================
// FETCH TEACHER'S SUBJECTS (from class_schedules)
// =============================================================================

$teacher_subjects_sql = "
    SELECT DISTINCT s.id, s.subject_name, s.subject_code, s.department_id, d.name as department_name
    FROM subjects s
    INNER JOIN class_schedules cs ON s.id = cs.subject_id
    LEFT JOIN departments d ON s.department_id = d.id
    WHERE cs.teacher_id = ? 
    ORDER BY s.subject_name";
$teacher_subjects_stmt = $conn->prepare($teacher_subjects_sql);
$teacher_subjects = [];
if ($teacher_subjects_stmt) {
    $teacher_subjects_stmt->bind_param("i", $teacher_id);
    $teacher_subjects_stmt->execute();
    $teacher_subjects_result = $teacher_subjects_stmt->get_result();
    while ($row = $teacher_subjects_result->fetch_assoc()) {
        $teacher_subjects[] = $row;
    }
    $teacher_subjects_stmt->close();
}

// =============================================================================
// HANDLE AJAX REQUESTS
// =============================================================================

// Handle AJAX requests for status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid CSRF token.");
        }
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        $status = trim(filter_input(INPUT_POST, 'status', FILTER_UNSAFE_RAW));
        $valid_statuses = ['draft', 'published', 'closed'];
        if (!$assignment_id || !in_array($status, $valid_statuses)) {
            throw new Exception("Invalid assignment ID or status.");
        }
        
        // Check if assignment belongs to this teacher
        $check_sql = "SELECT id, title, class_id, term_id FROM assignments WHERE id = ? AND teacher_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $assignment_id, $teacher_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            throw new Exception("Assignment not found or unauthorized.");
        }
        $assignment_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        $update_sql = "UPDATE assignments SET status = ?, updated_at = NOW() WHERE id = ? AND teacher_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $update_stmt->bind_param("sii", $status, $assignment_id, $teacher_id);
        $update_stmt->execute();
        
        if ($update_stmt->affected_rows > 0) {
            logActivity($conn, $teacher_id, "Updated Assignment Status", 
                "Changed status of assignment '{$assignment_data['title']}' (ID: $assignment_id) to $status");
            
            // Create notification for students if assignment is published
            if ($status === 'published') {
                // Get academic_year_id from term
                $term_year_sql = "SELECT academic_year_id FROM academic_terms WHERE id = ?";
                $term_year_stmt = $conn->prepare($term_year_sql);
                if ($term_year_stmt) {
                    $term_year_stmt->bind_param("i", $assignment_data['term_id']);
                    $term_year_stmt->execute();
                    $term_year_result = $term_year_stmt->get_result();
                    $academic_year_id = null;
                    if ($term_year_row = $term_year_result->fetch_assoc()) {
                        $academic_year_id = $term_year_row['academic_year_id'];
                    }
                    $term_year_stmt->close();
                }
                
                if ($academic_year_id) {
                    $students_sql = "SELECT DISTINCT sc.user_id FROM student_classes sc 
                                     WHERE sc.class_id = ? AND sc.academic_year_id = ?";
                    $students_stmt = $conn->prepare($students_sql);
                    if ($students_stmt) {
                        $students_stmt->bind_param("ii", $assignment_data['class_id'], $academic_year_id);
                        $students_stmt->execute();
                        $students_result = $students_stmt->get_result();
                        
                        while ($student = $students_result->fetch_assoc()) {
                            $notification_sql = "INSERT INTO notifications (user_id, target_role, title, message, type, link, created_at) 
                                                 VALUES (?, 'student', ?, ?, 'assignment', ?, NOW())";
                            $notification_stmt = $conn->prepare($notification_sql);
                            if ($notification_stmt) {
                                $title = "New Assignment: {$assignment_data['title']}";
                                $message = "A new assignment '{$assignment_data['title']}' has been published. Please check and submit before the due date.";
                                $link = "/student/view_assignments.php?assignment_id=$assignment_id";
                                $notification_stmt->bind_param("isss", $student['user_id'], $title, $message, $link);
                                $notification_stmt->execute();
                                $notification_stmt->close();
                            }
                        }
                        $students_stmt->close();
                    }
                }
            }
            
            // Regenerate CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $response['success'] = true;
            $response['message'] = "Status updated to $status successfully.";
        } else {
            throw new Exception("No changes made to assignment status.");
        }
        $update_stmt->close();
    } catch (Exception $e) {
        error_log("Status update error: " . $e->getMessage());
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// Handle AJAX request for assignment deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_assignment') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid CSRF token.");
        }
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        
        if (!$assignment_id) {
            throw new Exception("Invalid assignment ID.");
        }
        
        // Check if assignment has submissions
        $check_submissions_sql = "SELECT COUNT(*) as count FROM assignment_submissions WHERE assignment_id = ?";
        $check_sub_stmt = $conn->prepare($check_submissions_sql);
        $check_sub_stmt->bind_param("i", $assignment_id);
        $check_sub_stmt->execute();
        $submission_count = $check_sub_stmt->get_result()->fetch_assoc()['count'];
        $check_sub_stmt->close();
        
        if ($submission_count > 0) {
            throw new Exception("Cannot delete assignment with existing submissions. Please archive it instead.");
        }
        
        // Get assignment title before deletion
        $title_sql = "SELECT title FROM assignments WHERE id = ? AND teacher_id = ?";
        $title_stmt = $conn->prepare($title_sql);
        $title_stmt->bind_param("ii", $assignment_id, $teacher_id);
        $title_stmt->execute();
        $title_result = $title_stmt->get_result();
        if ($title_result->num_rows === 0) {
            throw new Exception("Assignment not found or unauthorized.");
        }
        $assignment_title = $title_result->fetch_assoc()['title'];
        $title_stmt->close();
        
        $delete_sql = "DELETE FROM assignments WHERE id = ? AND teacher_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $assignment_id, $teacher_id);
        $delete_stmt->execute();
        
        if ($delete_stmt->affected_rows > 0) {
            logActivity($conn, $teacher_id, "Deleted Assignment", 
                "Deleted assignment '{$assignment_title}' (ID: $assignment_id)");
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $response['success'] = true;
            $response['message'] = "Assignment deleted successfully.";
        } else {
            throw new Exception("Failed to delete assignment.");
        }
        $delete_stmt->close();
    } catch (Exception $e) {
        error_log("Assignment deletion error: " . $e->getMessage());
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// =============================================================================
// FILTERS AND PAGINATION
// =============================================================================

$department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT) ?: null;
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: null;
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: null;
$status_filter = filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW) ?: null;
$search = trim(filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW) ?? '');
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$per_page = 10;

// Build query for assignments - JOIN with academic_terms and classes
$assignments_sql = "
    SELECT 
        a.id, 
        a.title, 
        a.type, 
        a.due_date, 
        a.status, 
        a.max_points, 
        a.is_group_assignment,
        a.created_at,
        a.term_id,
        a.teacher_id,
        c.id as class_id,
        c.class_name, 
        c.department_id,
        s.id as subject_id,
        s.subject_name,
        s.department_id as subject_department_id,
        d.name as department_name,
        at.academic_year_id,
        ay.year_label as academic_year_label,
        (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.id) as submission_count,
        (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.id AND subm.status = 'graded') as graded_count,
        (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.id AND subm.score IS NOT NULL) as graded_submissions_count
    FROM assignments a
    INNER JOIN classes c ON a.class_id = c.id
    INNER JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN departments d ON c.department_id = d.id
    LEFT JOIN academic_terms at ON a.term_id = at.id
    LEFT JOIN academic_years ay ON at.academic_year_id = ay.id
    WHERE a.teacher_id = ?";

$conditions = [];
$params = [$teacher_id];
$param_types = "i";

// Academic Year Filter - only if a specific year is selected
if ($selected_academic_year_id) {
    $conditions[] = "at.academic_year_id = ?";
    $params[] = $selected_academic_year_id;
    $param_types .= "i";
}

// Term Filter - only if a specific term is selected
if ($selected_term_id) {
    $conditions[] = "a.term_id = ?";
    $params[] = $selected_term_id;
    $param_types .= "i";
}

// Department filter (via class department)
if ($department_id) {
    $conditions[] = "c.department_id = ?";
    $params[] = $department_id;
    $param_types .= "i";
}

// Class filter
if ($class_id) {
    $conditions[] = "a.class_id = ?";
    $params[] = $class_id;
    $param_types .= "i";
}

// Subject filter
if ($subject_id) {
    $conditions[] = "a.subject_id = ?";
    $params[] = $subject_id;
    $param_types .= "i";
}

// Status filter
if ($status_filter && in_array($status_filter, ['draft', 'published', 'closed'])) {
    $conditions[] = "a.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Search filter
if ($search) {
    $conditions[] = "(a.title LIKE ? OR a.tags LIKE ? OR a.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $param_types .= "sss";
}

if (!empty($conditions)) {
    $assignments_sql .= " AND " . implode(" AND ", $conditions);
}

$assignments_sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = ($page - 1) * $per_page;
$param_types .= "ii";

$assignments_stmt = $conn->prepare($assignments_sql);
$assignments = [];
if ($assignments_stmt) {
    $assignments_stmt->bind_param($param_types, ...$params);
    $assignments_stmt->execute();
    $assignments_result = $assignments_stmt->get_result();
    $assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
    $assignments_stmt->close();
} else {
    error_log("Prepare failed for assignments query: " . $conn->error);
    $assignments = [];
}

// Count total assignments for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM assignments a 
    INNER JOIN classes c ON a.class_id = c.id 
    LEFT JOIN academic_terms at ON a.term_id = at.id
    WHERE a.teacher_id = ?";
$count_params = [$teacher_id];
$count_param_types = "i";

if ($selected_academic_year_id) {
    $count_sql .= " AND at.academic_year_id = ?";
    $count_params[] = $selected_academic_year_id;
    $count_param_types .= "i";
}
if ($selected_term_id) {
    $count_sql .= " AND a.term_id = ?";
    $count_params[] = $selected_term_id;
    $count_param_types .= "i";
}
if ($department_id) {
    $count_sql .= " AND c.department_id = ?";
    $count_params[] = $department_id;
    $count_param_types .= "i";
}
if ($class_id) {
    $count_sql .= " AND a.class_id = ?";
    $count_params[] = $class_id;
    $count_param_types .= "i";
}
if ($subject_id) {
    $count_sql .= " AND a.subject_id = ?";
    $count_params[] = $subject_id;
    $count_param_types .= "i";
}
if ($status_filter && in_array($status_filter, ['draft', 'published', 'closed'])) {
    $count_sql .= " AND a.status = ?";
    $count_params[] = $status_filter;
    $count_param_types .= "s";
}
if ($search) {
    $count_sql .= " AND (a.title LIKE ? OR a.tags LIKE ? OR a.description LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_param_types .= "sss";
}

$count_stmt = $conn->prepare($count_sql);
$total_assignments = 0;
if ($count_stmt) {
    $count_stmt->bind_param($count_param_types, ...$count_params);
    $count_stmt->execute();
    $total_assignments = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    error_log("Prepare failed for count query: " . $conn->error);
    $total_assignments = 0;
}
$total_pages = ceil($total_assignments / $per_page);

// =============================================================================
// SUMMARY METRICS (based on teacher's assignments)
// =============================================================================

$metrics_sql = "
    SELECT 
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = ?) as total_assignments,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = ? AND status = 'published') as published_assignments,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = ? AND status = 'draft') as draft_assignments,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = ? AND status = 'closed') as closed_assignments,
        (SELECT COUNT(*) FROM assignment_submissions subm 
         INNER JOIN assignments a ON subm.assignment_id = a.id 
         WHERE a.teacher_id = ?) as total_submissions,
        (SELECT COUNT(*) FROM assignments 
         WHERE teacher_id = ? AND due_date < CURDATE() AND status != 'closed') as overdue_assignments";
$metrics_stmt = $conn->prepare($metrics_sql);
$metrics = [
    'total_assignments' => 0, 
    'published_assignments' => 0,
    'draft_assignments' => 0,
    'closed_assignments' => 0,
    'total_submissions' => 0, 
    'overdue_assignments' => 0
];
if ($metrics_stmt) {
    $metrics_stmt->bind_param("iiiiii", $teacher_id, $teacher_id, $teacher_id, $teacher_id, $teacher_id, $teacher_id);
    $metrics_stmt->execute();
    $metrics_result = $metrics_stmt->get_result()->fetch_assoc();
    if ($metrics_result) {
        $metrics = array_merge($metrics, $metrics_result);
    }
    $metrics_stmt->close();
} else {
    error_log("Prepare failed for metrics query: " . $conn->error);
}

// Get submission trends for the last 7 days for this teacher
$submission_trends_sql = "
    SELECT 
        DATE(submission_date) as date,
        COUNT(*) as count
    FROM assignment_submissions subm
    INNER JOIN assignments a ON subm.assignment_id = a.id
    WHERE a.teacher_id = ? 
    AND submission_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(submission_date)
    ORDER BY date ASC";
$submission_trends_stmt = $conn->prepare($submission_trends_sql);
$submission_trends = [];
if ($submission_trends_stmt) {
    $submission_trends_stmt->bind_param("i", $teacher_id);
    $submission_trends_stmt->execute();
    $trends_result = $submission_trends_stmt->get_result();
    while ($row = $trends_result->fetch_assoc()) {
        $submission_trends[$row['date']] = $row['count'];
    }
    $submission_trends_stmt->close();
}

// Generate last 7 days labels
$last_7_days = [];
$submission_counts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $last_7_days[] = date('M d', strtotime($date));
    $submission_counts[] = $submission_trends[$date] ?? 0;
}

// Get top performing assignments for this teacher
$top_assignments_sql = "
    SELECT 
        a.id,
        a.title,
        AVG(subm.score / a.max_points * 100) as avg_score,
        COUNT(subm.id) as submission_count
    FROM assignments a
    LEFT JOIN assignment_submissions subm ON a.id = subm.assignment_id AND subm.status = 'graded'
    WHERE a.teacher_id = ?
    GROUP BY a.id
    HAVING submission_count > 0
    ORDER BY avg_score DESC
    LIMIT 5";
$top_assignments_stmt = $conn->prepare($top_assignments_sql);
$top_assignments = [];
if ($top_assignments_stmt) {
    $top_assignments_stmt->bind_param("i", $teacher_id);
    $top_assignments_stmt->execute();
    $top_assignments_result = $top_assignments_stmt->get_result();
    while ($row = $top_assignments_result->fetch_assoc()) {
        $top_assignments[] = $row;
    }
    $top_assignments_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en" class="<?= isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage assignments for QueensPa School, view submissions, edit, and update statuses.">
    <meta name="keywords" content="QueensPa School, manage assignments, teacher dashboard, assignment management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Assignments - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        success: {
                            500: '#10b981',
                            600: '#059669',
                        },
                        warning: {
                            500: '#f59e0b',
                            600: '#d97706',
                        },
                        danger: {
                            500: '#ef4444',
                            600: '#dc2626',
                        },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'slide-up': 'slideUp 0.4s ease-out',
                        'scale-in': 'scaleIn 0.3s ease-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        scaleIn: {
                            '0%': { transform: 'scale(0.95)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                    },
                },
            },
        };
    </script>

    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .status-badge {
            @apply inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium;
        }
        .status-published { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200; }
        .status-draft { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200; }
        .status-closed { @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300; }
        
        .assignment-card {
            transition: all 0.3s ease;
        }
        .assignment-card:hover {
            transform: translateY(-2px);
            @apply shadow-lg;
        }
        
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            @apply bg-gray-100 dark:bg-gray-800 rounded;
        }
        ::-webkit-scrollbar-thumb {
            @apply bg-gray-400 dark:bg-gray-600 rounded;
        }
        ::-webkit-scrollbar-thumb:hover {
            @apply bg-gray-500 dark:bg-gray-500;
        }
        
        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .container {
                max-width: 100%;
                padding: 0;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 flex flex-col items-center space-y-3">
            <i class="fas fa-spinner fa-spin text-primary-500 text-4xl"></i>
            <p class="text-gray-700 dark:text-gray-300">Processing...</p>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="status-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full animate-scale-in">
            <div class="bg-primary-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold">
                    <i class="fas fa-sync-alt mr-2"></i> Confirm Status Change
                </h3>
                <button id="close-modal" class="text-white hover:text-gray-200 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <p id="modal-message" class="text-gray-700 dark:text-gray-300"></p>
                <input type="hidden" id="modal-assignment-id">
                <input type="hidden" id="modal-status">
                <div class="flex justify-end space-x-3">
                    <button id="cancel-modal" 
                            class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button id="confirm-modal" 
                            class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Assignment Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full animate-scale-in">
            <div class="bg-danger-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold">
                    <i class="fas fa-trash-alt mr-2"></i> Delete Assignment
                </h3>
                <button id="close-delete-modal" class="text-white hover:text-gray-200 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <p id="delete-modal-message" class="text-gray-700 dark:text-gray-300"></p>
                <input type="hidden" id="delete-assignment-id">
                <div class="flex justify-end space-x-3">
                    <button id="cancel-delete" 
                            class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button id="confirm-delete" 
                            class="px-4 py-2 bg-danger-600 hover:bg-danger-700 text-white rounded-lg transition-colors">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 animate-fade-in">
            <div>
                <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 dark:text-white">
                    <i class="fas fa-tasks text-primary-500 mr-3"></i> Manage Assignments
                </h1>
                <p class="text-gray-600 dark:text-gray-400 mt-2">
                    Create, edit, and manage your assignments
                </p>
            </div>
            <div class="flex space-x-3 mt-4 lg:mt-0">
                <button id="dark-mode-toggle" 
                        class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center shadow-sm hover:shadow">
                    <i class="fas fa-moon mr-2"></i> <span id="dark-mode-text">Dark Mode</span>
                </button>
                <button id="export-csv" 
                        class="bg-success-500 hover:bg-success-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center shadow-sm hover:shadow">
                    <i class="fas fa-download mr-2"></i> Export CSV
                </button>
                <a href="/teacher/create_assignment.php" 
                   class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center shadow-sm hover:shadow">
                    <i class="fas fa-plus mr-2"></i> Create Assignment
                </a>
            </div>
        </div>

        <!-- Academic Year and Term Selector -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 mb-6 animate-slide-up">
            <form id="year-term-form" method="GET" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Academic Year</label>
                    <select name="academic_year_id" id="academic_year_select" 
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600">
                        <option value="">All Years</option>
                        <?php foreach ($academic_years as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $selected_academic_year_id == $ay['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ay['year_label']) ?> <?= $ay['is_active'] ? '(Active)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Term</label>
                    <select name="term_id" id="term_select" 
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600">
                        <option value="">All Terms</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= $term['id'] ?>" <?= $selected_term_id == $term['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name']) ?> <?= $term['is_active'] ? '(Active)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all">
                        <i class="fas fa-filter mr-1"></i> Apply
                    </button>
                    <a href="?" class="ml-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-sm">
                        <i class="fas fa-times"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-slide-up dark:bg-red-900 dark:border-red-700 dark:text-red-200">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?= htmlspecialchars($_SESSION['error_msg']) ?></span>
                </div>
                <button class="close-alert text-red-500 hover:text-red-700 dark:text-red-300">&times;</button>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-slide-up dark:bg-green-900 dark:border-green-700 dark:text-green-200">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span><?= htmlspecialchars($_SESSION['success_msg']) ?></span>
                </div>
                <button class="close-alert text-green-500 hover:text-green-700">&times;</button>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Summary Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 text-center hover:shadow-lg transition-shadow">
                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400"><?= $metrics['total_assignments'] ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 text-center hover:shadow-lg transition-shadow">
                <div class="text-2xl font-bold text-success-600"><?= $metrics['published_assignments'] ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Published</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 text-center hover:shadow-lg transition-shadow">
                <div class="text-2xl font-bold text-warning-600"><?= $metrics['draft_assignments'] ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Draft</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 text-center hover:shadow-lg transition-shadow">
                <div class="text-2xl font-bold text-gray-600"><?= $metrics['closed_assignments'] ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Closed</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 text-center hover:shadow-lg transition-shadow">
                <div class="text-2xl font-bold text-primary-600"><?= $metrics['total_submissions'] ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Submissions</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 text-center hover:shadow-lg transition-shadow">
                <div class="text-2xl font-bold text-danger-600"><?= $metrics['overdue_assignments'] ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Overdue</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Submission Trends Chart -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-primary-500 to-primary-700 px-6 py-3">
                    <h3 class="text-white font-semibold flex items-center">
                        <i class="fas fa-chart-line mr-2"></i> Submission Trends (Last 7 Days)
                    </h3>
                </div>
                <div class="p-4">
                    <div class="chart-container">
                        <canvas id="submission-trends-chart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Performing Assignments -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-success-500 to-success-700 px-6 py-3">
                    <h3 class="text-white font-semibold flex items-center">
                        <i class="fas fa-trophy mr-2"></i> Top Performing Assignments
                    </h3>
                </div>
                <div class="p-4">
                    <?php if (empty($top_assignments)): ?>
                        <p class="text-gray-500 dark:text-gray-400 text-center py-8">
                            <i class="fas fa-chart-simple text-3xl mb-2 block"></i>
                            No graded submissions yet.
                        </p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($top_assignments as $index => $assignment): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <span class="text-2xl font-bold <?= $index === 0 ? 'text-yellow-500' : ($index === 1 ? 'text-gray-400' : ($index === 2 ? 'text-amber-600' : 'text-gray-500')) ?>">
                                            <?= $index + 1 ?>
                                        </span>
                                        <div>
                                            <p class="font-medium text-gray-800 dark:text-white"><?= htmlspecialchars($assignment['title']) ?></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= $assignment['submission_count'] ?> submissions</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-lg font-bold text-success-600"><?= round($assignment['avg_score'], 1) ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-8 animate-slide-up">
            <div class="bg-gray-50 dark:bg-gray-700 px-6 py-3 border-b border-gray-200 dark:border-gray-600">
                <h3 class="font-semibold text-gray-700 dark:text-gray-200">
                    <i class="fas fa-filter mr-2 text-primary-500"></i> Filter Assignments
                </h3>
            </div>
            <div class="p-6">
                <form id="filter-form" method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <input type="hidden" name="academic_year_id" value="<?= $selected_academic_year_id ?>">
                    <input type="hidden" name="term_id" value="<?= $selected_term_id ?>">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department</label>
                        <select name="department_id" id="department_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= $department['id'] ?>" <?= $department_id == $department['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($department['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Class</label>
                        <select name="class_id" id="class_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600">
                            <option value="">All Classes</option>
                            <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?> <?= $class['department_name'] ? '(' . htmlspecialchars($class['department_name']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject</label>
                        <select name="subject_id" id="subject_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600">
                            <option value="">All Subjects</option>
                            <?php foreach ($teacher_subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $subject_id == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['subject_name']) ?> <?= $subject['department_name'] ? '(' . htmlspecialchars($subject['department_name']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                        <select name="status" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600">
                            <option value="">All Statuses</option>
                            <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= $status_filter === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="closed" <?= $status_filter === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600"
                               placeholder="Search by title, tags, or description...">
                    </div>
                </form>
                <div class="mt-4 flex justify-end">
                    <a href="?" 
                       class="text-primary-500 hover:text-primary-600 text-sm">
                        <i class="fas fa-sync-alt mr-1"></i> Reset Filters
                    </a>
                </div>
            </div>
        </div>

        <!-- Assignments Table -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden animate-slide-up">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Title</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submissions</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($assignments)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-folder-open text-3xl mb-2 block"></i>
                                    No assignments found matching your filters.
                                    <div class="mt-2">
                                        <a href="/teacher/create_assignment.php" class="text-primary-500 hover:text-primary-600">
                                            <i class="fas fa-plus mr-1"></i> Create your first assignment
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="flex flex-col">
                                            <span class="font-medium text-gray-800 dark:text-white"><?= htmlspecialchars($assignment['title']) ?></span>
                                            <?php if ($assignment['is_group_assignment']): ?>
                                                <span class="text-xs text-primary-500"><i class="fas fa-users"></i> Group Assignment</span>
                                            <?php endif; ?>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Max: <?= $assignment['max_points'] ?> pts</span>
                                            <?php if ($assignment['academic_year_label']): ?>
                                                <span class="text-xs text-gray-400 dark:text-gray-500"><?= htmlspecialchars($assignment['academic_year_label']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-col">
                                            <span class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($assignment['class_name']) ?></span>
                                            <?php if ($assignment['department_name']): ?>
                                                <span class="text-xs text-gray-400 dark:text-gray-500"><?= htmlspecialchars($assignment['department_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-col">
                                            <span class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($assignment['subject_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $assignment['type']))) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                        <?php if ($assignment['due_date']): ?>
                                            <?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                                            <?php if (strtotime($assignment['due_date']) < time() && $assignment['status'] !== 'closed'): ?>
                                                <span class="ml-1 text-danger-500 text-xs"><i class="fas fa-exclamation-triangle"></i> Overdue</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <div class="flex items-center space-x-1">
                                            <span class="font-semibold text-gray-800 dark:text-white"><?= $assignment['submission_count'] ?></span>
                                            <?php if ($assignment['graded_submissions_count'] > 0): ?>
                                                <span class="text-xs text-success-500">(<?= $assignment['graded_submissions_count'] ?> graded)</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="status-badge status-<?= $assignment['status'] ?>">
                                            <?= ucfirst($assignment['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex space-x-2">
                                            <a href="/teacher/view_submissions.php?assignment_id=<?= $assignment['id'] ?>" 
                                               class="text-primary-500 hover:text-primary-700 p-1 rounded transition-colors" 
                                               title="View Submissions">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="/teacher/edit_assignment.php?assignment_id=<?= $assignment['id'] ?>" 
                                               class="text-success-500 hover:text-success-700 p-1 rounded transition-colors" 
                                               title="Edit Assignment">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="status-toggle text-gray-500 hover:text-gray-700 p-1 rounded transition-colors" 
                                                    data-assignment-id="<?= $assignment['id'] ?>" 
                                                    data-current-status="<?= $assignment['status'] ?>" 
                                                    data-title="<?= htmlspecialchars($assignment['title']) ?>"
                                                    title="Change Status">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                            <button class="delete-assignment text-danger-500 hover:text-danger-700 p-1 rounded transition-colors" 
                                                    data-assignment-id="<?= $assignment['id'] ?>" 
                                                    data-title="<?= htmlspecialchars($assignment['title']) ?>"
                                                    data-submission-count="<?= $assignment['submission_count'] ?>"
                                                    title="Delete Assignment">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-4 py-3 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600 flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        Showing <?= count($assignments) ?> of <?= $total_assignments ?> assignments
                    </div>
                    <div class="flex space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                               class="px-3 py-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-100 dark:hover:bg-gray-700">
                                Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                               class="px-3 py-1 rounded-lg text-sm <?= $i == $page ? 'bg-primary-600 text-white' : 'bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                               class="px-3 py-1 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-100 dark:hover:bg-gray-700">
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ?>
    <?php ob_end_flush(); ?>

    <script>
        $(document).ready(function() {
            const loadingOverlay = $('#loading-overlay');
            const statusModal = $('#status-modal');
            const deleteModal = $('#delete-modal');
            const closeModal = $('#close-modal');
            const cancelModal = $('#cancel-modal');
            const confirmModal = $('#confirm-modal');
            const closeDeleteModal = $('#close-delete-modal');
            const cancelDelete = $('#cancel-delete');
            const confirmDelete = $('#confirm-delete');
            const modalMessage = $('#modal-message');
            const modalAssignmentId = $('#modal-assignment-id');
            const modalStatus = $('#modal-status');
            const deleteAssignmentId = $('#delete-assignment-id');
            const deleteModalMessage = $('#delete-modal-message');
            const filterForm = $('#filter-form');
            const searchInput = $('input[name="search"]');
            const exportCsvButton = $('#export-csv');
            const darkModeToggle = $('#dark-mode-toggle');
            const academicYearSelect = $('#academic_year_select');
            const termSelect = $('#term_select');
            const yearTermForm = $('#year-term-form');
            
            // Dark mode handling with localStorage
            function initDarkMode() {
                const saved = localStorage.getItem('darkMode');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const isDark = saved === 'enabled' || (!saved && prefersDark);
                
                if (isDark) {
                    $('html').addClass('dark');
                    darkModeToggle.find('i').removeClass('fa-moon').addClass('fa-sun');
                    darkModeToggle.find('#dark-mode-text').text('Light Mode');
                } else {
                    $('html').removeClass('dark');
                    darkModeToggle.find('i').removeClass('fa-sun').addClass('fa-moon');
                    darkModeToggle.find('#dark-mode-text').text('Dark Mode');
                }
            }
            
            function toggleDarkMode() {
                if ($('html').hasClass('dark')) {
                    $('html').removeClass('dark');
                    localStorage.setItem('darkMode', 'disabled');
                    darkModeToggle.find('i').removeClass('fa-sun').addClass('fa-moon');
                    darkModeToggle.find('#dark-mode-text').text('Dark Mode');
                } else {
                    $('html').addClass('dark');
                    localStorage.setItem('darkMode', 'enabled');
                    darkModeToggle.find('i').removeClass('fa-moon').addClass('fa-sun');
                    darkModeToggle.find('#dark-mode-text').text('Light Mode');
                }
            }
            
            initDarkMode();
            darkModeToggle.on('click', toggleDarkMode);
            
            // Auto-dismiss alerts
            $('.close-alert').on('click', function() {
                $(this).closest('[class*="bg-"]').fadeOut(300);
            });
            
            setTimeout(function() {
                $('[class*="bg-"]:has(.close-alert)').fadeOut(500);
            }, 5000);
            
            // Handle academic year/term change
            academicYearSelect.on('change', function() {
                yearTermForm.submit();
            });
            
            termSelect.on('change', function() {
                yearTermForm.submit();
            });
            
            // Submit filters on change
            filterForm.find('select, input[name="search"]').on('change', function() {
                filterForm.submit();
            });
            
            // Debounced search
            let searchTimeout;
            searchInput.on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    filterForm.submit();
                }, 500);
            });
            
            // Status toggle
            $('.status-toggle').on('click', function() {
                const assignmentId = $(this).data('assignment-id');
                const currentStatus = $(this).data('current-status');
                const title = $(this).data('title');
                let nextStatus = '';
                
                if (currentStatus === 'draft') {
                    nextStatus = 'published';
                } else if (currentStatus === 'published') {
                    nextStatus = 'closed';
                } else if (currentStatus === 'closed') {
                    nextStatus = 'draft';
                }
                
                modalAssignmentId.val(assignmentId);
                modalStatus.val(nextStatus);
                modalMessage.text(`Are you sure you want to change the status of "${title}" to "${nextStatus.toUpperCase()}"?`);
                statusModal.removeClass('hidden');
            });
            
            // Close modal
            closeModal.on('click', () => statusModal.addClass('hidden'));
            cancelModal.on('click', () => statusModal.addClass('hidden'));
            
            // Confirm status change
            confirmModal.on('click', function() {
                const assignmentId = modalAssignmentId.val();
                const status = modalStatus.val();
                
                loadingOverlay.removeClass('hidden');
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'update_status',
                        assignment_id: assignmentId,
                        status: status,
                        csrf_token: '<?= $csrf_token ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        loadingOverlay.addClass('hidden');
                        statusModal.addClass('hidden');
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(`Error: ${response.message}`);
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingOverlay.addClass('hidden');
                        console.error('Status update error:', status, error);
                        alert('Failed to update status. Please try again.');
                    }
                });
            });
            
            // Delete assignment
            $('.delete-assignment').on('click', function() {
                const assignmentId = $(this).data('assignment-id');
                const title = $(this).data('title');
                const submissionCount = $(this).data('submission-count');
                
                if (submissionCount > 0) {
                    alert(`Cannot delete "${title}" because it has ${submissionCount} submission(s). Please archive it instead.`);
                    return;
                }
                
                deleteAssignmentId.val(assignmentId);
                deleteModalMessage.text(`Are you sure you want to delete "${title}"? This action cannot be undone.`);
                deleteModal.removeClass('hidden');
            });
            
            // Close delete modal
            closeDeleteModal.on('click', () => deleteModal.addClass('hidden'));
            cancelDelete.on('click', () => deleteModal.addClass('hidden'));
            
            // Confirm delete
            confirmDelete.on('click', function() {
                const assignmentId = deleteAssignmentId.val();
                
                loadingOverlay.removeClass('hidden');
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'delete_assignment',
                        assignment_id: assignmentId,
                        csrf_token: '<?= $csrf_token ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        loadingOverlay.addClass('hidden');
                        deleteModal.addClass('hidden');
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(`Error: ${response.message}`);
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingOverlay.addClass('hidden');
                        console.error('Delete error:', status, error);
                        alert('Failed to delete assignment. Please try again.');
                    }
                });
            });
            
            // Export CSV
            exportCsvButton.on('click', function() {
                const rows = [];
                
                // Header row
                rows.push(['Title', 'Class', 'Department', 'Subject', 'Type', 'Due Date', 'Submissions', 'Graded', 'Max Points', 'Status', 'Group Assignment', 'Academic Year', 'Created At']);
                
                <?php foreach ($assignments as $assignment): ?>
                    rows.push([
                        `"<?= addslashes($assignment['title']) ?>"`,
                        `"<?= addslashes($assignment['class_name']) ?>"`,
                        `"<?= addslashes($assignment['department_name'] ?? '') ?>"`,
                        `"<?= addslashes($assignment['subject_name']) ?>"`,
                        `"<?= $assignment['type'] ?>"`,
                        `"<?= $assignment['due_date'] ? date('Y-m-d', strtotime($assignment['due_date'])) : '' ?>"`,
                        `"<?= $assignment['submission_count'] ?>"`,
                        `"<?= $assignment['graded_submissions_count'] ?>"`,
                        `"<?= $assignment['max_points'] ?>"`,
                        `"<?= $assignment['status'] ?>"`,
                        `"<?= $assignment['is_group_assignment'] ? 'Yes' : 'No' ?>"`,
                        `"<?= addslashes($assignment['academic_year_label'] ?? '') ?>"`,
                        `"<?= date('Y-m-d H:i:s', strtotime($assignment['created_at'])) ?>"`
                    ]);
                <?php endforeach; ?>
                
                const csvContent = rows.map(row => row.join(',')).join('\n');
                const blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'assignments_<?= date('Y-m-d') ?>.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
            
            // Helper function to escape HTML
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            // Create submission trends chart
            const submissionTrendsData = <?= json_encode($submission_counts) ?>;
            const last7DaysLabels = <?= json_encode($last_7_days) ?>;
            
            const trendsCtx = document.getElementById('submission-trends-chart');
            if (trendsCtx) {
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: last7DaysLabels,
                        datasets: [{
                            label: 'Submissions',
                            data: submissionTrendsData,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            pointBackgroundColor: '#3b82f6',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            tension: 0.3,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 6
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `${context.raw} submission(s)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Submissions'
                                },
                                ticks: {
                                    stepSize: 1
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Date'
                                }
                            }
                        }
                    }
                });
            }
            
            // Hide loading overlay after page load
            setTimeout(() => loadingOverlay.addClass('hidden'), 300);
        });
    </script>
</body>
</html>