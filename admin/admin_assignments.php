<?php
/**
 * Admin Assignment Management Portal
 * 
 * Allows administrators to:
 * - View all assignments created by teachers
 * - View all student submissions across all assignments
 * - Filter by teacher, class, subject, status, date range
 * - Export data to CSV
 * - View detailed submission information
 * 
 * @package QueensPa School Management System
 * @version 1.0.0
 */

// =============================================================================
// INITIALIZATION & SECURITY
// =============================================================================

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Restrict to admin only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an administrator.";
    header("Location: /login.php");
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Database configuration
$host = 'localhost';
$dbname = 'qpmceduc_db';
$username = 'qpmceduc_wise';
$password = 'Kaokbnia.4040@1957';

// Create database connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$conn->set_charset("utf8mb4");

// Get admin info
$admin_id = (int)$_SESSION['user_id'];
$admin_sql = "SELECT id, name, email, role FROM users WHERE id = ? AND role = 'admin' AND status = 'active'";
$admin_stmt = $conn->prepare($admin_sql);
if ($admin_stmt) {
    $admin_stmt->bind_param("i", $admin_id);
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    if ($admin_result->num_rows === 0) {
        $_SESSION['error_msg'] = "Invalid admin session.";
        header("Location: /login.php");
        exit();
    }
    $admin = $admin_result->fetch_assoc();
    $admin_stmt->close();
} else {
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Include logging function if available
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
} else {
    function logActivity($conn, $user_id, $action, $description) {
        $sql = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iss", $user_id, $action, $description);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Log access
logActivity($conn, $admin_id, "Admin View Assignments", "Accessed admin assignment management portal");

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// =============================================================================
// FETCH FILTER OPTIONS
// =============================================================================

// Get all teachers for filter
$teachers_sql = "SELECT id, name, email FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name";
$teachers_result = $conn->query($teachers_sql);
$teachers = [];
if ($teachers_result) {
    while ($row = $teachers_result->fetch_assoc()) {
        $teachers[] = $row;
    }
}

// Get all classes
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_sql);
$classes = [];
if ($classes_result) {
    while ($row = $classes_result->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Get all subjects
$subjects_sql = "SELECT id, subject_name, subject_code FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_sql);
$subjects = [];
if ($subjects_result) {
    while ($row = $subjects_result->fetch_assoc()) {
        $subjects[] = $row;
    }
}

// Get academic years
$academic_years_sql = "SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC";
$academic_years_result = $conn->query($academic_years_sql);
$academic_years = [];
if ($academic_years_result) {
    while ($row = $academic_years_result->fetch_assoc()) {
        $academic_years[] = $row;
    }
}

// =============================================================================
// HANDLE AJAX REQUESTS / EXPORTS
// =============================================================================

// Handle CSV Export for Assignments
if (isset($_GET['export_assignments']) && $_GET['export_assignments'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assignments_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Title', 'Teacher', 'Class', 'Subject', 'Type', 'Due Date', 'Status', 'Max Points', 'Submissions', 'Created At']);
    
    // Get filtered assignments
    $export_sql = "SELECT a.id, a.title, a.type, a.due_date, a.status, a.max_points, a.created_at,
                   u.name as teacher_name, c.class_name, s.subject_name,
                   (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count
                   FROM assignments a
                   JOIN users u ON a.teacher_id = u.id
                   JOIN classes c ON a.class_id = c.id
                   JOIN subjects s ON a.subject_id = s.id
                   ORDER BY a.created_at DESC";
    
    $export_result = $conn->query($export_sql);
    if ($export_result) {
        while ($row = $export_result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['title'],
                $row['teacher_name'],
                $row['class_name'],
                $row['subject_name'],
                $row['type'],
                $row['due_date'],
                $row['status'],
                $row['max_points'],
                $row['submission_count'],
                $row['created_at']
            ]);
        }
    }
    fclose($output);
    exit();
}

// Handle CSV Export for Submissions
if (isset($_GET['export_submissions']) && $_GET['export_submissions'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="submissions_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Assignment', 'Student', 'Teacher', 'Class', 'Subject', 'Submission Date', 'Score', 'Status', 'Graded By', 'Graded At']);
    
    $export_sql = "SELECT s.id, s.submission_date, s.score, s.status, s.graded_at,
                   a.title as assignment_title, u.name as student_name, t.name as teacher_name,
                   c.class_name, sub.subject_name, g.name as graded_by_name
                   FROM assignment_submissions s
                   JOIN assignments a ON s.assignment_id = a.id
                   JOIN users u ON s.user_id = u.id
                   JOIN users t ON a.teacher_id = t.id
                   JOIN classes c ON a.class_id = c.id
                   JOIN subjects sub ON a.subject_id = sub.id
                   LEFT JOIN users g ON s.graded_by = g.id
                   ORDER BY s.submission_date DESC";
    
    $export_result = $conn->query($export_sql);
    if ($export_result) {
        while ($row = $export_result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['assignment_title'],
                $row['student_name'],
                $row['teacher_name'],
                $row['class_name'],
                $row['subject_name'],
                $row['submission_date'],
                $row['score'],
                $row['status'],
                $row['graded_by_name'] ?? 'Not graded',
                $row['graded_at']
            ]);
        }
    }
    fclose($output);
    exit();
}

// =============================================================================
// FILTERS AND PAGINATION FOR ASSIGNMENTS
// =============================================================================

$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'submissions' ? 'submissions' : 'assignments';

// Assignment filters
$assignment_teacher_id = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT) ?: null;
$assignment_class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: null;
$assignment_subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: null;
$assignment_status = filter_input(INPUT_GET, 'assignment_status', FILTER_UNSAFE_RAW) ?: null;
$assignment_search = trim(filter_input(INPUT_GET, 'assignment_search', FILTER_UNSAFE_RAW) ?? '');
$assignment_academic_year_id = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT) ?: null;
$assignment_page = max(1, filter_input(INPUT_GET, 'assignment_page', FILTER_VALIDATE_INT) ?: 1);
$assignments_per_page = 15;

// Build assignments query
$assignments_sql = "SELECT a.*, 
                    u.name as teacher_name, u.email as teacher_email,
                    c.class_name, c.department_id,
                    s.subject_name, s.subject_code,
                    ay.year_label as academic_year,
                    t.term_name,
                    (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count,
                    (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND status = 'graded') as graded_count
                    FROM assignments a
                    JOIN users u ON a.teacher_id = u.id
                    JOIN classes c ON a.class_id = c.id
                    JOIN subjects s ON a.subject_id = s.id
                    LEFT JOIN academic_terms t ON a.term_id = t.id
                    LEFT JOIN academic_years ay ON t.academic_year_id = ay.id
                    WHERE 1=1";

$assignments_params = [];
$assignments_param_types = "";

if ($assignment_teacher_id) {
    $assignments_sql .= " AND a.teacher_id = ?";
    $assignments_params[] = $assignment_teacher_id;
    $assignments_param_types .= "i";
}
if ($assignment_class_id) {
    $assignments_sql .= " AND a.class_id = ?";
    $assignments_params[] = $assignment_class_id;
    $assignments_param_types .= "i";
}
if ($assignment_subject_id) {
    $assignments_sql .= " AND a.subject_id = ?";
    $assignments_params[] = $assignment_subject_id;
    $assignments_param_types .= "i";
}
if ($assignment_status && in_array($assignment_status, ['draft', 'published', 'closed'])) {
    $assignments_sql .= " AND a.status = ?";
    $assignments_params[] = $assignment_status;
    $assignments_param_types .= "s";
}
if ($assignment_academic_year_id) {
    $assignments_sql .= " AND ay.id = ?";
    $assignments_params[] = $assignment_academic_year_id;
    $assignments_param_types .= "i";
}
if ($assignment_search) {
    $assignments_sql .= " AND (a.title LIKE ? OR a.description LIKE ? OR a.tags LIKE ? OR u.name LIKE ?)";
    $search_param = "%$assignment_search%";
    $assignments_params[] = $search_param;
    $assignments_params[] = $search_param;
    $assignments_params[] = $search_param;
    $assignments_params[] = $search_param;
    $assignments_param_types .= "ssss";
}

// Count total assignments
$count_sql = str_replace("SELECT a.*, u.name as teacher_name...", "SELECT COUNT(*) as total", $assignments_sql);
$count_stmt = $conn->prepare($count_sql);
$total_assignments = 0;
if ($count_stmt && !empty($assignments_params)) {
    $count_stmt->bind_param($assignments_param_types, ...$assignments_params);
    $count_stmt->execute();
    $total_assignments = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} elseif ($count_stmt) {
    $count_stmt->execute();
    $total_assignments = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
}

// Add pagination
$assignments_sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$assignments_params[] = $assignments_per_page;
$assignments_params[] = ($assignment_page - 1) * $assignments_per_page;
$assignments_param_types .= "ii";

$assignments_stmt = $conn->prepare($assignments_sql);
$assignments = [];
if ($assignments_stmt) {
    if (!empty($assignments_params)) {
        $assignments_stmt->bind_param($assignments_param_types, ...$assignments_params);
    }
    $assignments_stmt->execute();
    $assignments_result = $assignments_stmt->get_result();
    while ($row = $assignments_result->fetch_assoc()) {
        // Parse questions if needed
        if ($row['questions']) {
            $questions = json_decode($row['questions'], true);
            $row['question_count'] = is_array($questions) ? count($questions) : 0;
        } else {
            $row['question_count'] = 0;
        }
        $assignments[] = $row;
    }
    $assignments_stmt->close();
}
$total_assignment_pages = ceil($total_assignments / $assignments_per_page);

// =============================================================================
// FILTERS AND PAGINATION FOR SUBMISSIONS
// =============================================================================

// Submission filters
$submission_teacher_id = filter_input(INPUT_GET, 'sub_teacher_id', FILTER_VALIDATE_INT) ?: null;
$submission_class_id = filter_input(INPUT_GET, 'sub_class_id', FILTER_VALIDATE_INT) ?: null;
$submission_status = filter_input(INPUT_GET, 'sub_status', FILTER_UNSAFE_RAW) ?: null;
$submission_search = trim(filter_input(INPUT_GET, 'sub_search', FILTER_UNSAFE_RAW) ?? '');
$submission_date_from = filter_input(INPUT_GET, 'date_from', FILTER_UNSAFE_RAW) ?: null;
$submission_date_to = filter_input(INPUT_GET, 'date_to', FILTER_UNSAFE_RAW) ?: null;
$submission_score_min = filter_input(INPUT_GET, 'score_min', FILTER_VALIDATE_FLOAT) ?: null;
$submission_score_max = filter_input(INPUT_GET, 'score_max', FILTER_VALIDATE_FLOAT) ?: null;
$submission_page = max(1, filter_input(INPUT_GET, 'sub_page', FILTER_VALIDATE_INT) ?: 1);
$submissions_per_page = 20;

// Build submissions query
$submissions_sql = "SELECT s.id, s.assignment_id, s.user_id, s.submission_date, s.score, s.feedback, 
                    s.status, s.late_submission_reason, s.graded_at, s.graded_by,
                    a.title as assignment_title, a.max_points, a.type as assignment_type,
                    u.name as student_name, u.email as student_email,
                    t.name as teacher_name, t.id as teacher_id,
                    c.class_name, c.id as class_id,
                    sub.subject_name, sub.id as subject_id,
                    g.name as graded_by_name
                    FROM assignment_submissions s
                    JOIN assignments a ON s.assignment_id = a.id
                    JOIN users u ON s.user_id = u.id
                    JOIN users t ON a.teacher_id = t.id
                    JOIN classes c ON a.class_id = c.id
                    JOIN subjects sub ON a.subject_id = sub.id
                    LEFT JOIN users g ON s.graded_by = g.id
                    WHERE 1=1";

$submissions_params = [];
$submissions_param_types = "";

if ($submission_teacher_id) {
    $submissions_sql .= " AND a.teacher_id = ?";
    $submissions_params[] = $submission_teacher_id;
    $submissions_param_types .= "i";
}
if ($submission_class_id) {
    $submissions_sql .= " AND a.class_id = ?";
    $submissions_params[] = $submission_class_id;
    $submissions_param_types .= "i";
}
if ($submission_status && in_array($submission_status, ['pending', 'submitted', 'graded', 'late', 'returned'])) {
    $submissions_sql .= " AND s.status = ?";
    $submissions_params[] = $submission_status;
    $submissions_param_types .= "s";
}
if ($submission_search) {
    $submissions_sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR a.title LIKE ?)";
    $search_param = "%$submission_search%";
    $submissions_params[] = $search_param;
    $submissions_params[] = $search_param;
    $submissions_params[] = $search_param;
    $submissions_param_types .= "sss";
}
if ($submission_date_from) {
    $submissions_sql .= " AND DATE(s.submission_date) >= ?";
    $submissions_params[] = $submission_date_from;
    $submissions_param_types .= "s";
}
if ($submission_date_to) {
    $submissions_sql .= " AND DATE(s.submission_date) <= ?";
    $submissions_params[] = $submission_date_to;
    $submissions_param_types .= "s";
}
if ($submission_score_min !== null) {
    $submissions_sql .= " AND s.score >= ?";
    $submissions_params[] = $submission_score_min;
    $submissions_param_types .= "d";
}
if ($submission_score_max !== null) {
    $submissions_sql .= " AND s.score <= ?";
    $submissions_params[] = $submission_score_max;
    $submissions_param_types .= "d";
}

// Count total submissions
$count_sub_sql = str_replace("SELECT s.id, s.assignment_id...", "SELECT COUNT(*) as total", $submissions_sql);
$count_sub_stmt = $conn->prepare($count_sub_sql);
$total_submissions = 0;
if ($count_sub_stmt && !empty($submissions_params)) {
    $count_sub_stmt->bind_param($submissions_param_types, ...$submissions_params);
    $count_sub_stmt->execute();
    $total_submissions = $count_sub_stmt->get_result()->fetch_assoc()['total'];
    $count_sub_stmt->close();
} elseif ($count_sub_stmt) {
    $count_sub_stmt->execute();
    $total_submissions = $count_sub_stmt->get_result()->fetch_assoc()['total'];
    $count_sub_stmt->close();
}

// Add pagination
$submissions_sql .= " ORDER BY s.submission_date DESC LIMIT ? OFFSET ?";
$submissions_params[] = $submissions_per_page;
$submissions_params[] = ($submission_page - 1) * $submissions_per_page;
$submissions_param_types .= "ii";

$submissions_stmt = $conn->prepare($submissions_sql);
$submissions = [];
if ($submissions_stmt) {
    if (!empty($submissions_params)) {
        $submissions_stmt->bind_param($submissions_param_types, ...$submissions_params);
    }
    $submissions_stmt->execute();
    $submissions_result = $submissions_stmt->get_result();
    while ($row = $submissions_result->fetch_assoc()) {
        // Parse answers if needed
        $row['answers_parsed'] = [];
        if ($row['answers']) {
            $answers = json_decode($row['answers'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['answers_parsed'] = $answers;
            } else {
                $row['answers_parsed'] = [['answer' => $row['answers']]];
            }
        }
        // Parse feedback
        $row['feedback_parsed'] = [];
        if ($row['feedback']) {
            $feedback = json_decode($row['feedback'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['feedback_parsed'] = $feedback;
            } else {
                $row['feedback_parsed'] = ['overall' => $row['feedback']];
            }
        }
        $submissions[] = $row;
    }
    $submissions_stmt->close();
}
$total_submission_pages = ceil($total_submissions / $submissions_per_page);

// =============================================================================
// DASHBOARD STATISTICS
// =============================================================================

$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM assignments) as total_assignments,
    (SELECT COUNT(*) FROM assignments WHERE status = 'published') as published_assignments,
    (SELECT COUNT(*) FROM assignments WHERE status = 'draft') as draft_assignments,
    (SELECT COUNT(*) FROM assignments WHERE status = 'closed') as closed_assignments,
    (SELECT COUNT(*) FROM assignment_submissions) as total_submissions,
    (SELECT COUNT(*) FROM assignment_submissions WHERE status = 'submitted') as pending_submissions,
    (SELECT COUNT(*) FROM assignment_submissions WHERE status = 'graded') as graded_submissions,
    (SELECT COUNT(*) FROM assignment_submissions WHERE status = 'late') as late_submissions,
    (SELECT COUNT(*) FROM assignment_submissions WHERE status = 'returned') as returned_submissions,
    (SELECT COUNT(DISTINCT teacher_id) FROM assignments) as active_teachers,
    (SELECT AVG(score) FROM assignment_submissions WHERE score IS NOT NULL) as avg_score,
    (SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active') as total_students";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];
$stats['avg_score'] = $stats['avg_score'] ? round($stats['avg_score'], 2) : 0;

// Get submission trends (last 7 days)
$trends_sql = "SELECT DATE(submission_date) as date, COUNT(*) as count 
               FROM assignment_submissions 
               WHERE submission_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
               GROUP BY DATE(submission_date)
               ORDER BY date ASC";
$trends_result = $conn->query($trends_sql);
$trends_data = [];
$trends_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $trends_labels[] = date('M d', strtotime($date));
    $trends_data[] = 0;
}
if ($trends_result) {
    while ($row = $trends_result->fetch_assoc()) {
        $index = array_search(date('M d', strtotime($row['date'])), $trends_labels);
        if ($index !== false) {
            $trends_data[$index] = $row['count'];
        }
    }
}

// Top teachers by assignments
$top_teachers_sql = "SELECT u.name, COUNT(a.id) as assignment_count 
                     FROM users u 
                     JOIN assignments a ON u.id = a.teacher_id 
                     GROUP BY u.id 
                     ORDER BY assignment_count DESC 
                     LIMIT 5";
$top_teachers_result = $conn->query($top_teachers_sql);
$top_teachers = [];
if ($top_teachers_result) {
    while ($row = $top_teachers_result->fetch_assoc()) {
        $top_teachers[] = $row;
    }
}

// Recent activity (last 10 submissions)
$recent_activity_sql = "SELECT s.id, s.submission_date, s.score, s.status, 
                         a.title as assignment_title, u.name as student_name, t.name as teacher_name
                         FROM assignment_submissions s
                         JOIN assignments a ON s.assignment_id = a.id
                         JOIN users u ON s.user_id = u.id
                         JOIN users t ON a.teacher_id = t.id
                         ORDER BY s.submission_date DESC
                         LIMIT 10";
$recent_activity_result = $conn->query($recent_activity_sql);
$recent_activity = [];
if ($recent_activity_result) {
    while ($row = $recent_activity_result->fetch_assoc()) {
        $recent_activity[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assignment Management Portal - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        * { font-family: 'Inter', system-ui, sans-serif; }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-published { background: #d1fae5; color: #065f46; }
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-closed { background: #f3e8ff; color: #6b21a5; }
        .status-graded { background: #d1fae5; color: #065f46; }
        .status-submitted { background: #dbeafe; color: #1e40af; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-late { background: #fee2e2; color: #991b1b; }
        .status-returned { background: #f3e8ff; color: #6b21a5; }
        
        .stats-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        
        .table-row:hover {
            background-color: #f9fafb;
        }
        
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 1rem;
            max-width: 900px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .score-high { background: #d1fae5; color: #065f46; }
        .score-mid { background: #fef3c7; color: #92400e; }
        .score-low { background: #fee2e2; color: #991b1b; }
        
        .tab-active {
            border-bottom: 2px solid #3b82f6;
            color: #3b82f6;
        }
        
        @media print {
            .no-print { display: none !important; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- ===================== SUBMISSION DETAIL MODAL ===================== -->
<div id="submissionModal" class="modal no-print">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 class="text-lg font-semibold"><i class="fas fa-file-alt mr-2"></i>Submission Details</h3>
            <button onclick="closeModal('submissionModal')" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
        </div>
        <div class="p-6" id="submissionModalContent">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<!-- ===================== ASSIGNMENT DETAIL MODAL ===================== -->
<div id="assignmentModal" class="modal no-print">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-green-600 to-teal-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 class="text-lg font-semibold"><i class="fas fa-tasks mr-2"></i>Assignment Details</h3>
            <button onclick="closeModal('assignmentModal')" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
        </div>
        <div class="p-6" id="assignmentModalContent">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<!-- ===================== HEADER ===================== -->
<div class="bg-white shadow-sm border-b border-gray-200">
    <div class="container mx-auto px-4 py-4">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-chalkboard-user text-blue-500 text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">Admin Assignment Portal</h1>
                    <p class="text-xs text-gray-500">Monitor all assignments and student submissions</p>
                </div>
            </div>
            <div class="flex gap-3">
                <button onclick="window.print()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm flex items-center gap-2">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="/admin/dashboard.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container mx-auto px-4 py-6 max-w-7xl">

    <!-- Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-5 mb-6 border border-blue-100">
        <div class="flex justify-between items-start flex-wrap gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">Welcome, <?= htmlspecialchars($admin['name']) ?>!</h2>
                <p class="text-sm text-gray-600 mt-1">Monitor all assignments, student submissions, and grading progress across the school.</p>
            </div>
            <div class="flex gap-2">
                <a href="?export_assignments=csv" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2">
                    <i class="fas fa-download"></i> Export Assignments
                </a>
                <a href="?export_submissions=csv" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2">
                    <i class="fas fa-download"></i> Export Submissions
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm p-4 text-center stats-card border border-gray-100">
            <i class="fas fa-tasks text-blue-500 text-xl mb-2 block"></i>
            <div class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_assignments'] ?? 0) ?></div>
            <div class="text-xs text-gray-500">Total Assignments</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center stats-card border border-gray-100">
            <i class="fas fa-check-circle text-green-500 text-xl mb-2 block"></i>
            <div class="text-2xl font-bold text-green-600"><?= number_format($stats['published_assignments'] ?? 0) ?></div>
            <div class="text-xs text-gray-500">Published</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center stats-card border border-gray-100">
            <i class="fas fa-clock text-yellow-500 text-xl mb-2 block"></i>
            <div class="text-2xl font-bold text-yellow-600"><?= number_format($stats['pending_submissions'] ?? 0) ?></div>
            <div class="text-xs text-gray-500">Pending Grading</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center stats-card border border-gray-100">
            <i class="fas fa-check-double text-purple-500 text-xl mb-2 block"></i>
            <div class="text-2xl font-bold text-purple-600"><?= number_format($stats['graded_submissions'] ?? 0) ?></div>
            <div class="text-xs text-gray-500">Graded</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center stats-card border border-gray-100">
            <i class="fas fa-chart-line text-blue-500 text-xl mb-2 block"></i>
            <div class="text-2xl font-bold text-blue-600"><?= number_format($stats['avg_score'] ?? 0, 1) ?>%</div>
            <div class="text-xs text-gray-500">Avg Score</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center stats-card border border-gray-100">
            <i class="fas fa-users text-indigo-500 text-xl mb-2 block"></i>
            <div class="text-2xl font-bold text-indigo-600"><?= number_format($stats['total_students'] ?? 0) ?></div>
            <div class="text-xs text-gray-500">Students</div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Submission Trends Chart -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-chart-line mr-2 text-blue-500"></i>Submission Trends (Last 7 Days)</h3>
            </div>
            <div class="p-4">
                <canvas id="trendsChart" height="200"></canvas>
            </div>
        </div>
        
        <!-- Top Teachers -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-chalkboard-user mr-2 text-green-500"></i>Top Teachers by Assignments</h3>
            </div>
            <div class="p-4">
                <?php if (empty($top_teachers)): ?>
                    <p class="text-gray-500 text-center py-4">No assignments found</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($top_teachers as $index => $teacher): ?>
                            <div class="flex justify-between items-center p-2 hover:bg-gray-50 rounded-lg">
                                <div class="flex items-center gap-3">
                                    <span class="w-7 h-7 rounded-full <?= $index === 0 ? 'bg-yellow-100 text-yellow-700' : ($index === 1 ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-600') ?> flex items-center justify-center text-sm font-bold">
                                        <?= $index + 1 ?>
                                    </span>
                                    <span class="font-medium text-gray-800"><?= htmlspecialchars($teacher['name']) ?></span>
                                </div>
                                <span class="text-sm font-semibold text-blue-600"><?= $teacher['assignment_count'] ?> assignments</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="flex border-b border-gray-200 mb-6">
        <button onclick="switchTab('assignments')" id="tab-assignments-btn" class="px-6 py-3 text-sm font-medium border-b-2 transition-colors <?= $active_tab === 'assignments' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-tasks mr-2"></i>All Assignments
            <span class="ml-1 text-xs bg-gray-100 px-2 py-0.5 rounded-full"><?= number_format($stats['total_assignments'] ?? 0) ?></span>
        </button>
        <button onclick="switchTab('submissions')" id="tab-submissions-btn" class="px-6 py-3 text-sm font-medium border-b-2 transition-colors <?= $active_tab === 'submissions' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-upload mr-2"></i>Student Submissions
            <span class="ml-1 text-xs bg-gray-100 px-2 py-0.5 rounded-full"><?= number_format($stats['total_submissions'] ?? 0) ?></span>
        </button>
    </div>

    <!-- ===================== ASSIGNMENTS TAB ===================== -->
    <div id="assignments-tab" class="<?= $active_tab !== 'assignments' ? 'hidden' : '' ?>">
        <!-- Assignment Filters -->
        <div class="bg-white rounded-xl shadow-sm mb-6 border border-gray-100">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-filter mr-2 text-blue-500"></i>Filter Assignments</h3>
            </div>
            <div class="p-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <input type="hidden" name="tab" value="assignments">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Teacher</label>
                        <select name="teacher_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Teachers</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= $teacher['id'] ?>" <?= $assignment_teacher_id == $teacher['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($teacher['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                        <select name="class_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $assignment_class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                        <select name="subject_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $assignment_subject_id == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="assignment_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Statuses</option>
                            <option value="draft" <?= $assignment_status === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= $assignment_status === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="closed" <?= $assignment_status === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" name="assignment_search" value="<?= htmlspecialchars($assignment_search) ?>" placeholder="Title, description, teacher..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-all">
                            <i class="fas fa-search mr-2"></i>Apply
                        </button>
                        <a href="?tab=assignments" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded-lg transition-all">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assignments Table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Title</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Teacher</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Class</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Subject</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Due Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Submissions</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($assignments)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-gray-500">
                                    <i class="fas fa-folder-open text-4xl mb-3 block text-gray-300"></i>
                                    <p>No assignments found matching your filters.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr class="table-row transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars($assignment['title']) ?></div>
                                        <div class="text-xs text-gray-500"><?= $assignment['question_count'] ?> questions • <?= $assignment['max_points'] ?> pts</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm text-gray-600"><?= htmlspecialchars($assignment['teacher_name']) ?></div>
                                        <div class="text-xs text-gray-400"><?= htmlspecialchars($assignment['teacher_email']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($assignment['class_name']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($assignment['subject_name']) ?></td>
                                    <td class="px-4 py-3 text-sm">
                                        <?php if ($assignment['due_date']): ?>
                                            <?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                                            <?php if (strtotime($assignment['due_date']) < time() && $assignment['status'] === 'published'): ?>
                                                <span class="ml-1 text-red-500 text-xs"><i class="fas fa-exclamation-triangle"></i></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-semibold text-gray-700"><?= $assignment['submission_count'] ?></div>
                                        <div class="text-xs text-green-600"><?= $assignment['graded_count'] ?> graded</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="status-badge status-<?= $assignment['status'] ?>">
                                            <?= ucfirst($assignment['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex gap-2">
                                            <!-- View Details Button Only -->
                                            <button onclick="viewAssignment(<?= htmlspecialchars(json_encode($assignment)) ?>)" 
                                                    class="text-blue-500 hover:text-blue-700 p-1 rounded" title="View Details">
                                                <i class="fas fa-info-circle"></i>
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
            <?php if ($total_assignment_pages > 1): ?>
                <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex justify-between items-center">
                    <div class="text-sm text-gray-600">Page <?= $assignment_page ?> of <?= $total_assignment_pages ?></div>
                    <div class="flex gap-1">
                        <?php if ($assignment_page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['assignment_page' => $assignment_page - 1, 'tab' => 'assignments'])) ?>" 
                               class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Prev</a>
                        <?php endif; ?>
                        <?php if ($assignment_page < $total_assignment_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['assignment_page' => $assignment_page + 1, 'tab' => 'assignments'])) ?>" 
                               class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================== SUBMISSIONS TAB ===================== -->
    <div id="submissions-tab" class="<?= $active_tab !== 'submissions' ? 'hidden' : '' ?>">
        <!-- Submission Filters -->
        <div class="bg-white rounded-xl shadow-sm mb-6 border border-gray-100">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-filter mr-2 text-blue-500"></i>Filter Submissions</h3>
            </div>
            <div class="p-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <input type="hidden" name="tab" value="submissions">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Teacher</label>
                        <select name="sub_teacher_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Teachers</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= $teacher['id'] ?>" <?= $submission_teacher_id == $teacher['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($teacher['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                        <select name="sub_class_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $submission_class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="sub_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Statuses</option>
                            <option value="pending" <?= $submission_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="submitted" <?= $submission_status === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                            <option value="graded" <?= $submission_status === 'graded' ? 'selected' : '' ?>>Graded</option>
                            <option value="late" <?= $submission_status === 'late' ? 'selected' : '' ?>>Late</option>
                            <option value="returned" <?= $submission_status === 'returned' ? 'selected' : '' ?>>Returned</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" name="sub_search" value="<?= htmlspecialchars($submission_search) ?>" placeholder="Student, assignment..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($submission_date_from ?? '') ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($submission_date_to ?? '') ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Min Score</label>
                        <input type="number" step="0.5" name="score_min" value="<?= htmlspecialchars($submission_score_min ?? '') ?>" placeholder="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Max Score</label>
                        <input type="number" step="0.5" name="score_max" value="<?= htmlspecialchars($submission_score_max ?? '') ?>" placeholder="100" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-all">
                            <i class="fas fa-search mr-2"></i>Apply
                        </button>
                        <a href="?tab=submissions" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded-lg transition-all">
                            <i class="fas fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Submissions Table -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Student</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Assignment</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Teacher</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Submitted</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Score</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($submissions)): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                                    <i class="fas fa-inbox text-4xl mb-3 block text-gray-300"></i>
                                    <p>No submissions found matching your filters.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($submissions as $sub): ?>
                                <tr class="table-row transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars($sub['student_name']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($sub['student_email']) ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm text-gray-800"><?= htmlspecialchars($sub['assignment_title']) ?></div>
                                        <div class="text-xs text-gray-400"><?= htmlspecialchars($sub['class_name']) ?> • <?= htmlspecialchars($sub['subject_name']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($sub['teacher_name']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?= $sub['submission_date'] ? date('M j, Y g:i A', strtotime($sub['submission_date'])) : '—' ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($sub['score'] !== null): ?>
                                            <?php 
                                                $percentage = ($sub['score'] / max(1, $sub['max_points'])) * 100;
                                                $class = $percentage >= 70 ? 'score-high' : ($percentage >= 50 ? 'score-mid' : 'score-low');
                                            ?>
                                            <span class="inline-block px-2 py-1 rounded text-xs font-semibold <?= $class ?>">
                                                <?= number_format($sub['score'], 1) ?> / <?= $sub['max_points'] ?>
                                            </span>
                                            <div class="text-xs text-gray-400 mt-0.5"><?= round($percentage, 1) ?>%</div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">Not graded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="status-badge status-<?= $sub['status'] ?>">
                                            <?= ucfirst($sub['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <button onclick="viewSubmission(<?= htmlspecialchars(json_encode($sub)) ?>)" 
                                                class="text-blue-500 hover:text-blue-700 p-1 rounded" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_submission_pages > 1): ?>
                <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex justify-between items-center">
                    <div class="text-sm text-gray-600">Page <?= $submission_page ?> of <?= $total_submission_pages ?> (<?= $total_submissions ?> total)</div>
                    <div class="flex gap-1">
                        <?php if ($submission_page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['sub_page' => $submission_page - 1, 'tab' => 'submissions'])) ?>" 
                               class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Prev</a>
                        <?php endif; ?>
                        <?php if ($submission_page < $total_submission_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['sub_page' => $submission_page + 1, 'tab' => 'submissions'])) ?>" 
                               class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity Section -->
    <div class="mt-8 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-history mr-2 text-blue-500"></i>Recent Submissions</h3>
        </div>
        <div class="divide-y divide-gray-100">
            <?php if (empty($recent_activity)): ?>
                <div class="px-6 py-8 text-center text-gray-500">No recent submissions</div>
            <?php else: ?>
                <?php foreach ($recent_activity as $activity): ?>
                    <div class="px-6 py-3 flex justify-between items-center hover:bg-gray-50">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-gray-800"><?= htmlspecialchars($activity['student_name']) ?></span>
                                <span class="text-gray-400">submitted</span>
                                <span class="font-medium text-blue-600"><?= htmlspecialchars($activity['assignment_title']) ?></span>
                                <span class="text-gray-400">to</span>
                                <span class="text-gray-600"><?= htmlspecialchars($activity['teacher_name']) ?></span>
                            </div>
                            <div class="text-xs text-gray-400 mt-1"><?= date('M j, Y g:i A', strtotime($activity['submission_date'])) ?></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <?php if ($activity['score'] !== null): ?>
                                <span class="text-sm font-semibold text-green-600">Score: <?= $activity['score'] ?></span>
                            <?php endif; ?>
                            <span class="status-badge status-<?= $activity['status'] ?>"><?= ucfirst($activity['status']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="text-center text-gray-400 text-sm py-6 mt-8 border-t border-gray-200">
    QueensPa School Management System &copy; <?= date('Y') ?> | Admin Assignment Portal
</div>

<script>
// Chart initialization
const trendsCtx = document.getElementById('trendsChart');
if (trendsCtx) {
    new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trends_labels) ?>,
            datasets: [{
                label: 'Submissions',
                data: <?= json_encode($trends_data) ?>,
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
            maintainAspectRatio: true,
            plugins: {
                legend: { display: true, position: 'top' },
                tooltip: { callbacks: { label: (ctx) => `${ctx.raw} submission(s)` } }
            },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Submissions' } } }
        }
    });
}

// Tab switching
function switchTab(tab) {
    const assignmentsTab = document.getElementById('assignments-tab');
    const submissionsTab = document.getElementById('submissions-tab');
    const assignmentsBtn = document.getElementById('tab-assignments-btn');
    const submissionsBtn = document.getElementById('tab-submissions-btn');
    
    if (tab === 'assignments') {
        assignmentsTab.classList.remove('hidden');
        submissionsTab.classList.add('hidden');
        assignmentsBtn.classList.add('border-blue-500', 'text-blue-600');
        assignmentsBtn.classList.remove('border-transparent', 'text-gray-500');
        submissionsBtn.classList.remove('border-blue-500', 'text-blue-600');
        submissionsBtn.classList.add('border-transparent', 'text-gray-500');
        // Update URL without reload
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'assignments');
        window.history.pushState({}, '', url);
    } else {
        assignmentsTab.classList.add('hidden');
        submissionsTab.classList.remove('hidden');
        submissionsBtn.classList.add('border-blue-500', 'text-blue-600');
        submissionsBtn.classList.remove('border-transparent', 'text-gray-500');
        assignmentsBtn.classList.remove('border-blue-500', 'text-blue-600');
        assignmentsBtn.classList.add('border-transparent', 'text-gray-500');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'submissions');
        window.history.pushState({}, '', url);
    }
}

// Modal functions
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function viewAssignment(assignment) {
    const modal = document.getElementById('assignmentModal');
    const content = document.getElementById('assignmentModalContent');
    
    let questionsHtml = '';
    if (assignment.questions) {
        try {
            const questions = JSON.parse(assignment.questions);
            if (Array.isArray(questions)) {
                questionsHtml = '<div class="mt-4"><h4 class="font-semibold text-gray-700 mb-2">Questions:</h4><div class="space-y-2">';
                questions.forEach((q, i) => {
                    questionsHtml += `<div class="p-3 bg-gray-50 rounded-lg">
                        <div class="font-medium text-sm">${i+1}. ${escapeHtml(q.question_text || 'Question')}</div>
                        <div class="text-xs text-gray-500 mt-1">Type: ${q.type || 'text'} | Points: ${q.points || 0}</div>
                    </div>`;
                });
                questionsHtml += '</div></div>';
            }
        } catch(e) {}
    }
    
    content.innerHTML = `
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="text-xs text-gray-500">Title</label><p class="font-medium">${escapeHtml(assignment.title)}</p></div>
                <div><label class="text-xs text-gray-500">Type</label><p class="font-medium capitalize">${escapeHtml(assignment.type)}</p></div>
                <div><label class="text-xs text-gray-500">Teacher</label><p>${escapeHtml(assignment.teacher_name)} (${escapeHtml(assignment.teacher_email)})</p></div>
                <div><label class="text-xs text-gray-500">Class</label><p>${escapeHtml(assignment.class_name)}</p></div>
                <div><label class="text-xs text-gray-500">Subject</label><p>${escapeHtml(assignment.subject_name)}</p></div>
                <div><label class="text-xs text-gray-500">Due Date</label><p>${assignment.due_date ? new Date(assignment.due_date).toLocaleDateString() : 'Not set'}</p></div>
                <div><label class="text-xs text-gray-500">Max Points</label><p>${assignment.max_points}</p></div>
                <div><label class="text-xs text-gray-500">Status</label><p><span class="status-badge status-${assignment.status}">${assignment.status.toUpperCase()}</span></p></div>
                <div><label class="text-xs text-gray-500">Submissions</label><p>${assignment.submission_count} total (${assignment.graded_count} graded)</p></div>
                <div><label class="text-xs text-gray-500">Created</label><p>${new Date(assignment.created_at).toLocaleString()}</p></div>
            </div>
            ${assignment.description ? `<div><label class="text-xs text-gray-500">Description</label><p class="text-sm text-gray-700 mt-1 p-3 bg-gray-50 rounded-lg">${escapeHtml(assignment.description)}</p></div>` : ''}
            ${questionsHtml}
            <div class="flex justify-end gap-3 pt-4 border-t">
                <button onclick="closeModal('assignmentModal')" class="btn-secondary px-4 py-2 text-sm">Close</button>
            </div>
        </div>
    `;
    modal.classList.add('active');
}

function viewSubmission(submission) {
    const modal = document.getElementById('submissionModal');
    const content = document.getElementById('submissionModalContent');
    
    let answersHtml = '';
    if (submission.answers_parsed && submission.answers_parsed.length > 0) {
        answersHtml = '<div class="mt-4"><h4 class="font-semibold text-gray-700 mb-2">Student Answers:</h4><div class="space-y-3">';
        submission.answers_parsed.forEach((ans, i) => {
            const answerText = typeof ans === 'string' ? ans : (ans.answer || ans.text || JSON.stringify(ans));
            answersHtml += `<div class="p-3 bg-gray-50 rounded-lg">
                <div class="font-medium text-sm">Question ${i+1}</div>
                <div class="text-gray-700 mt-1 whitespace-pre-wrap">${escapeHtml(answerText)}</div>
            </div>`;
        });
        answersHtml += '</div></div>';
    } else if (submission.submission_text) {
        answersHtml = `<div class="mt-4"><h4 class="font-semibold text-gray-700 mb-2">Submission Text:</h4><div class="p-3 bg-gray-50 rounded-lg whitespace-pre-wrap">${escapeHtml(submission.submission_text)}</div></div>`;
    }
    
    let feedbackHtml = '';
    if (submission.feedback_parsed && submission.feedback_parsed.overall) {
        feedbackHtml = `<div class="mt-4"><h4 class="font-semibold text-gray-700 mb-2">Feedback:</h4><div class="p-3 bg-blue-50 rounded-lg">${escapeHtml(submission.feedback_parsed.overall)}</div></div>`;
        if (submission.feedback_parsed.per_question && submission.feedback_parsed.per_question.length) {
            feedbackHtml += '<div class="mt-3"><h5 class="text-sm font-medium text-gray-600 mb-1">Per Question Feedback:</h5><div class="space-y-2">';
            submission.feedback_parsed.per_question.forEach((fb, i) => {
                feedbackHtml += `<div class="text-sm"><span class="font-medium">Q${i+1}:</span> ${escapeHtml(fb)}</div>`;
            });
            feedbackHtml += '</div></div>';
        }
    }
    
    const percentage = submission.score ? (submission.score / submission.max_points * 100).toFixed(1) : 0;
    const scoreClass = percentage >= 70 ? 'score-high' : (percentage >= 50 ? 'score-mid' : 'score-low');
    
    content.innerHTML = `
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="text-xs text-gray-500">Student</label><p class="font-medium">${escapeHtml(submission.student_name)}</p><p class="text-xs text-gray-400">${escapeHtml(submission.student_email)}</p></div>
                <div><label class="text-xs text-gray-500">Assignment</label><p>${escapeHtml(submission.assignment_title)}</p></div>
                <div><label class="text-xs text-gray-500">Teacher</label><p>${escapeHtml(submission.teacher_name)}</p></div>
                <div><label class="text-xs text-gray-500">Class / Subject</label><p>${escapeHtml(submission.class_name)} / ${escapeHtml(submission.subject_name)}</p></div>
                <div><label class="text-xs text-gray-500">Submission Date</label><p>${submission.submission_date ? new Date(submission.submission_date).toLocaleString() : '—'}</p></div>
                <div><label class="text-xs text-gray-500">Status</label><p><span class="status-badge status-${submission.status}">${submission.status.toUpperCase()}</span></p></div>
                <div><label class="text-xs text-gray-500">Score</label><p><span class="inline-block px-2 py-1 rounded text-sm font-semibold ${scoreClass}">${submission.score ? submission.score : '—'} / ${submission.max_points} (${percentage}%)</span></p></div>
                ${submission.graded_by_name ? `<div><label class="text-xs text-gray-500">Graded By</label><p>${escapeHtml(submission.graded_by_name)}</p><p class="text-xs text-gray-400">${submission.graded_at ? new Date(submission.graded_at).toLocaleString() : ''}</p></div>` : ''}
                ${submission.late_submission_reason ? `<div class="col-span-2"><label class="text-xs text-gray-500">Late Submission Reason</label><p class="text-sm text-yellow-600">${escapeHtml(submission.late_submission_reason)}</p></div>` : ''}
            </div>
            ${answersHtml}
            ${feedbackHtml}
            <div class="flex justify-end gap-3 pt-4 border-t">
                <button onclick="closeModal('submissionModal')" class="btn-secondary px-4 py-2 text-sm">Close</button>
            </div>
        </div>
    `;
    modal.classList.add('active');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});

// Close modal on backdrop click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});
</script>

<?php
$conn->close();
ob_end_flush();
?>