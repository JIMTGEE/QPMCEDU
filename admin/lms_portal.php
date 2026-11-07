<?php
// public/admin/dashboard_teacher.php

// Include the new header
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header1.php';
if (!file_exists($header_path)) {
    error_log("Header file missing: $header_path");
    $_SESSION['error_msg'] = "System error: Unable to load page header.";
    header("Location: /login.php");
    exit();
}
require_once $header_path;

// Determine current academic year and term
$today = new DateTime('2025-05-23');
$current_academic_year = '2024/2025';
$session_term_key = "current_term_$user_id";
if (!isset($_SESSION[$session_term_key])) {
    $current_term_sql = "SELECT id, year, term_name, start_date, end_date 
                         FROM academic_calendar 
                         WHERE start_date <= CURDATE() AND end_date >= CURDATE() 
                         LIMIT 1";
    $current_term_stmt = $conn->prepare($current_term_sql);
    if ($current_term_stmt) {
        $current_term_stmt->execute();
        $term_result = $current_term_stmt->get_result()->fetch_assoc();
        $_SESSION[$session_term_key] = $term_result ?: ['id' => 1, 'year' => '2024/2025', 'term_name' => 'Unknown', 'start_date' => '2025-05-06', 'end_date' => '2025-07-25'];
        logActivity($conn, $user_id, "Fetched Term", "Current term fetched: " . json_encode($_SESSION[$session_term_key]));
        $current_term_stmt->close();
    } else {
        logActivity($conn, $user_id, "Term Query Failed", "Prepare failed for current term query: " . $conn->error);
        $_SESSION[$session_term_key] = ['id' => 1, 'year' => '2024/2025', 'term_name' => 'Unknown', 'start_date' => '2025-05-06', 'end_date' => '2025-07-25'];
    }
}
$current_term = $_SESSION[$session_term_key];
$current_term_id = $current_term['id'] ?? 1;

// Calculate term progress
$term_progress = 0;
if ($current_term && isset($current_term['start_date'], $current_term['end_date'])) {
    $start_date = new DateTime($current_term['start_date']);
    $end_date = new DateTime($current_term['end_date']);
    $total_days = $start_date->diff($end_date)->days;
    $days_passed = $start_date->diff($today)->days;
    $term_progress = $total_days > 0 ? min(100, round(($days_passed / $total_days) * 100)) : 0;
}

// Fetch all terms
$terms_sql = "SELECT id, term_name, year FROM academic_calendar ORDER BY start_date DESC";
$terms = [];
if ($terms_stmt = $conn->prepare($terms_sql)) {
    $terms_stmt->execute();
    $terms_result = $terms_stmt->get_result();
    while ($row = $terms_result->fetch_assoc()) $terms[] = $row;
    $terms_stmt->close();
} else {
    logActivity($conn, $user_id, "Terms Query Failed", "Prepare failed for terms query: " . $conn->error);
}

// Fetch departments
$session_dept_key = "departments_$user_id";
if (!isset($_SESSION[$session_dept_key])) {
    $departments_sql = "SELECT id, name FROM departments ORDER BY name";
    $_SESSION[$session_dept_key] = [];
    if ($dept_stmt = $conn->prepare($departments_sql)) {
        $dept_stmt->execute();
        $dept_result = $dept_stmt->get_result();
        while ($row = $dept_result->fetch_assoc()) {
            $_SESSION[$session_dept_key][$row['id']] = $row['name'];
        }
        $dept_stmt->close();
    } else {
        logActivity($conn, $user_id, "Departments Query Failed", "Prepare failed for departments query: " . $conn->error);
    }
}
$departments = $_SESSION[$session_dept_key];

// Fetch key metrics
$metrics = [
    'class_count' => 0,
    'subject_count' => 0,
    'teaching_hours' => 0,
    'assignment_count' => 0,
    'pending_submissions' => 0,
    'attendance_percentage' => 0,
    'evaluations' => 0,
    'notification_count' => 0
];

// Class count
$class_count_sql = "SELECT COUNT(DISTINCT c.id) as class_count 
                    FROM classes c 
                    JOIN class_schedules cs ON c.id = cs.class_id 
                    WHERE cs.teacher_id = ? AND cs.term_id = ?";
if ($stmt = $conn->prepare($class_count_sql)) {
    $stmt->bind_param("ii", $user_id, $current_term_id);
    $stmt->execute();
    $metrics['class_count'] = $stmt->get_result()->fetch_assoc()['class_count'] ?? 0;
    logActivity($conn, $user_id, "Fetched Class Count", "Class count: " . $metrics['class_count']);
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Class Count Query Failed", "Prepare failed for class count query: " . $conn->error);
}

// Subject count
$subject_count_sql = "SELECT COUNT(DISTINCT s.id) as subject_count 
                      FROM subjects s 
                      JOIN class_schedules cs ON s.id = cs.subject_id 
                      WHERE cs.teacher_id = ? AND cs.term_id = ?";
if ($stmt = $conn->prepare($subject_count_sql)) {
    $stmt->bind_param("ii", $user_id, $current_term_id);
    $stmt->execute();
    $metrics['subject_count'] = $stmt->get_result()->fetch_assoc()['subject_count'] ?? 0;
    logActivity($conn, $user_id, "Fetched Subject Count", "Subject count: " . $metrics['subject_count']);
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Subject Count Query Failed", "Prepare failed for subject count query: " . $conn->error);
}

// Teaching hours
$teaching_hours_sql = "SELECT SUM(TIMESTAMPDIFF(MINUTE, cs.start_time, cs.end_time) / 60.0) as teaching_hours 
                       FROM class_schedules cs 
                       WHERE cs.teacher_id = ? AND cs.term_id = ? 
                       AND cs.start_time IS NOT NULL AND cs.end_time IS NOT NULL";
if ($stmt = $conn->prepare($teaching_hours_sql)) {
    $stmt->bind_param("ii", $user_id, $current_term_id);
    $stmt->execute();
    $metrics['teaching_hours'] = round($stmt->get_result()->fetch_assoc()['teaching_hours'] ?? 0, 1);
    logActivity($conn, $user_id, "Fetched Teaching Hours", "Teaching hours: " . $metrics['teaching_hours']);
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Teaching Hours Query Failed", "Prepare failed for teaching hours query: " . $conn->error);
}

// Assignments given
$start_date = $current_term['start_date'] ?? '2025-05-06';
$end_date = $current_term['end_date'] ?? '2025-07-25';
$assignment_count_sql = "SELECT COUNT(a.id) as assignment_count 
                         FROM assignments a 
                         JOIN class_schedules cs ON a.class_id = cs.class_id 
                         WHERE cs.teacher_id = ? AND cs.term_id = ? 
                         AND a.created_at BETWEEN ? AND ?";
if ($stmt = $conn->prepare($assignment_count_sql)) {
    $stmt->bind_param("iiss", $user_id, $current_term_id, $start_date, $end_date);
    $stmt->execute();
    $metrics['assignment_count'] = $stmt->get_result()->fetch_assoc()['assignment_count'] ?? 0;
    logActivity($conn, $user_id, "Fetched Assignment Count", "Assignment count: " . $metrics['assignment_count']);
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Assignment Count Query Failed", "Prepare failed for assignment count query: " . $conn->error);
}

// Pending submissions
$pending_submissions_sql = "SELECT COUNT(DISTINCT ca.id) as pending_submissions 
                            FROM class_assignments ca 
                            JOIN assignments a ON ca.assignment_id = a.id 
                            JOIN class_schedules cs ON a.class_id = cs.class_id 
                            LEFT JOIN assignment_submissions sub ON ca.assignment_id = sub.assignment_id AND ca.student_id = sub.user_id 
                            WHERE cs.teacher_id = ? AND cs.term_id = ? 
                            AND a.created_at BETWEEN ? AND ? 
                            AND sub.id IS NULL";
if ($stmt = $conn->prepare($pending_submissions_sql)) {
    $stmt->bind_param("iiss", $user_id, $current_term_id, $start_date, $end_date);
    $stmt->execute();
    $metrics['pending_submissions'] = $stmt->get_result()->fetch_assoc()['pending_submissions'] ?? 0;
    logActivity($conn, $user_id, "Fetched Pending Submissions", "Pending submissions: " . $metrics['pending_submissions']);
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Pending Submissions Query Failed", "Prepare failed for pending submissions query: " . $conn->error);
}

// Attendance percentage
$attendance_sql = "SELECT AVG(CASE WHEN a.status = 'present' THEN 100 ELSE 0 END) as attendance_percentage 
                   FROM attendance a 
                   JOIN class_schedules cs ON a.class_id = cs.class_id 
                   WHERE cs.teacher_id = ? AND cs.term_id = ? 
                   AND a.date BETWEEN ? AND ?";
if ($stmt = $conn->prepare($attendance_sql)) {
    $stmt->bind_param("iiss", $user_id, $current_term_id, $start_date, $end_date);
    $stmt->execute();
    $metrics['attendance_percentage'] = round($stmt->get_result()->fetch_assoc()['attendance_percentage'] ?? 0, 1);
    logActivity($conn, $user_id, "Fetched Attendance Percentage", "Attendance percentage: " . $metrics['attendance_percentage']);
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Attendance Query Failed", "Prepare failed for attendance query: " . $conn->error);
}

// Evaluations
$evaluation_count_sql = "SELECT COUNT(te.id) as evaluations 
                         FROM teacher_evaluations te 
                         WHERE te.teacher_id = ? AND te.term_id = ?";
if ($stmt = $conn->prepare($evaluation_count_sql)) {
    $stmt->bind_param("ii", $user_id, $current_term_id);
    $stmt->execute();
    $metrics['evaluations'] = $stmt->get_result()->fetch_assoc()['evaluations'] ?? 0;
    logActivity($conn, $user_id, "Fetched Evaluations", "Evaluations: " . $metrics['evaluations']);
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Evaluation Count Query Failed", "Prepare failed for evaluation count query: " . $conn->error);
}

// Notification count
$metrics['notification_count'] = $unread_count; // Use count from header

// Fetch classes
$classes_sql = "SELECT c.id, c.class_name, c.department_id, 
                       GROUP_CONCAT(DISTINCT s.subject_name ORDER BY s.subject_name) as subjects, 
                       GROUP_CONCAT(DISTINCT cs.day_of_week ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')) as days, 
                       MIN(cs.start_time) as first_start_time, 
                       MAX(cs.end_time) as last_end_time 
                FROM classes c 
                JOIN class_schedules cs ON c.id = cs.class_id 
                LEFT JOIN subjects s ON cs.subject_id = s.id 
                WHERE cs.teacher_id = ? AND cs.term_id = ? 
                GROUP BY c.id 
                ORDER BY c.department_id, c.class_name";
$classes = [];
if ($stmt = $conn->prepare($classes_sql)) {
    $stmt->bind_param("ii", $user_id, $current_term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $classes[$row['department_id']][] = $row;
    }
    logActivity($conn, $user_id, "Fetched Classes", "Classes fetched: " . count($classes));
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Classes Query Failed", "Prepare failed for classes query: " . $conn->error);
}

// Fetch weekly teaching hours by day
$teaching_hours_by_day_sql = "SELECT cs.day_of_week, SUM(TIMESTAMPDIFF(MINUTE, cs.start_time, cs.end_time) / 60.0) as hours 
                              FROM class_schedules cs 
                              WHERE cs.teacher_id = ? AND cs.term_id = ? 
                              GROUP BY cs.day_of_week 
                              ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')";
$teaching_hours_by_day = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0);
if ($stmt = $conn->prepare($teaching_hours_by_day_sql)) {
    $stmt->bind_param("ii", $user_id, $current_term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $teaching_hours_by_day[$row['day_of_week']] = round($row['hours'] ?? 0, 1);
    }
    logActivity($conn, $user_id, "Fetched Teaching Hours by Day", "Teaching hours by day: " . json_encode($teaching_hours_by_day));
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Teaching Hours by Day Query Failed", "Prepare failed for teaching hours by day query: " . $conn->error);
}

// Fetch upcoming assignments
$assignments_sql = "SELECT a.id, a.title, a.due_date, c.class_name 
                    FROM assignments a 
                    JOIN class_schedules cs ON a.class_id = cs.class_id 
                    JOIN classes c ON a.class_id = c.id 
                    WHERE cs.teacher_id = ? AND cs.term_id = ? 
                    AND a.due_date >= CURDATE() 
                    ORDER BY a.due_date 
                    LIMIT 5";
$assignments = [];
if ($stmt = $conn->prepare($assignments_sql)) {
    $stmt->bind_param("ii", $user_id, $current_term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $assignments[] = $row;
    logActivity($conn, $user_id, "Fetched Assignments", "Upcoming assignments: " . count($assignments));
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Assignments Query Failed", "Prepare failed for assignments query: " . $conn->error);
}

// Fetch unread notifications
$notifications_sql = "SELECT id, title, message, created_at 
                      FROM notifications 
                      WHERE (target_role IN ('admin', 'teacher') OR user_id = ?) AND is_read = 0 
                      ORDER BY created_at DESC 
                      LIMIT 5";
$notifications = [];
if ($stmt = $conn->prepare($notifications_sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $notifications[] = $row;
    logActivity($conn, $user_id, "Fetched Notifications", "Notifications fetched: " . count($notifications));
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Notifications Query Failed", "Prepare failed for notifications query: " . $conn->error);
}

// Fetch recent activity
$recent_logs_sql = "SELECT action, description, created_at 
                    FROM user_logs 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 5";
$recent_logs = [];
if ($stmt = $conn->prepare($recent_logs_sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $recent_logs[] = $row;
    logActivity($conn, $user_id, "Fetched Recent Logs", "Recent logs fetched: " . count($recent_logs));
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Recent Logs Query Failed", "Prepare failed for recent logs query: " . $conn->error);
}

// Fetch submission status
$submission_status_sql = "SELECT 
                            COUNT(DISTINCT ass.id) as submitted, 
                            COUNT(DISTINCT ca.id) - COUNT(DISTINCT ass.id) as pending 
                          FROM assignments a 
                          JOIN class_schedules cs ON a.class_id = cs.class_id 
                          JOIN class_assignments ca ON a.id = ca.assignment_id 
                          LEFT JOIN assignment_submissions ass ON ca.assignment_id = ass.assignment_id AND ca.student_id = ass.user_id 
                          WHERE cs.teacher_id = ? AND cs.term_id = ? 
                          AND a.created_at BETWEEN ? AND ?";
$submission_status = ['submitted' => 0, 'pending' => 0];
if ($stmt = $conn->prepare($submission_status_sql)) {
    $stmt->bind_param("iiss", $user_id, $current_term_id, $start_date, $end_date);
    $stmt->execute();
    $submission_status = $stmt->get_result()->fetch_assoc() ?: ['submitted' => 0, 'pending' => 0];
    logActivity($conn, $user_id, "Fetched Submission Status", "Submission status: " . json_encode($submission_status));
    $stmt->close();
} else {
    logActivity($conn, $user_id, "Submission Status Query Failed", "Prepare failed for submission status query: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher dashboard for admins at QueensPa School, managing classes, subjects, assignments, and analytics.">
    <meta name="keywords" content="QueensPa School, teacher dashboard, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Admin Teacher Dashboard">
    <meta property="og:description" content="Manage classes, subjects, assignments, and analytics for admins with teaching roles.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/dashboard_teacher.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Admin Teacher Dashboard">
    <meta name="twitter:description" content="Manage classes, subjects, assignments, and analytics for admins with teaching roles.">
    <title>Admin Teacher Dashboard - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --card-bg: #ffffff;
            --dark-bg: #1a1a1a;
            --dark-card-bg: #2c2c2c;
            --dark-text: #e0e0e0;
        }
        body {
            background: linear-gradient(135deg, var(--bg-color), #fff3e0);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }
        body.dark-mode {
            background: linear-gradient(135deg, #121212, #1a1a1a);
            color: var(--dark-text);
        }
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, background-color 0.3s;
        }
        .dark-mode .card {
            background: var(--dark-card-bg);
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-primary, .btn-print, .btn-action, .btn-export {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            font-size: 0.875rem;
        }
        .btn-primary:hover, .btn-print:hover, .btn-action:hover, .btn-export:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
        }
        .table-container {
            max-height: 250px;
            overflow-y: auto;
            border-radius: 8px;
        }
        .table thead {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .dark-mode .table-hover tbody tr:hover {
            background-color: #3a3a3a;
        }
        .quick-links .btn {
            min-width: 150px;
            margin: 0.25rem;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin: 0 auto;
        }
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .alert {
            border-radius: 8px;
            background-color: #fff3e0;
            color: var(--text-color);
        }
        .dark-mode .alert {
            background-color: #3a3a3a;
            color: var(--dark-text);
        }
        .search-bar {
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        .filter-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .progress {
            height: 1.5rem;
            border-radius: 8px;
        }
        .print-section {
            visibility: hidden;
            position: absolute;
        }
        @media print {
            body * { visibility: hidden; }
            .print-section, .print-section * { visibility: visible; display: block; }
            .print-section { position: static; font-size: 12pt; }
            .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #ff4500; }
            .print-table { width: 100%; border-collapse: collapse; }
            .print-table th, .print-table td { border: 1px solid #ddd; padding: 8px; }
            .print-table th { background: linear-gradient(to right, #ff4500, #ff6b00); color: white; }
            .print-footer { text-align: center; margin-top: 20px; font-size: 10pt; color: #555; }
            @page { size: A4; margin: 20mm; }
        }
        @media (max-width: 767.98px) {
            .quick-links { flex-direction: column; }
            .quick-links .btn { width: 100%; margin-bottom: 0.5rem; }
            .chart-container { height: 200px; }
            .table-responsive { font-size: 0.85rem; }
            .filter-group { flex-direction: column; }
            .search-bar { max-width: 100%; }
        }
    </style>
</head>
<body>
    <div id="loading-overlay">
        <i class="fas fa-spinner fa-spin" id="loading-spinner"></i>
    </div>
    <div class="content" id="content">
        <main class="container my-4">
            <div class="d-flex justify-content-between align-items-center mb-4 animate__animated animate__fadeIn">
                <h1 class="text-center flex-grow-1">📚 Admin Teacher Dashboard</h1>
                <button class="btn btn-secondary btn-sm" id="theme-toggle">
                    <i class="fas fa-moon"></i> Toggle Theme
                </button>
            </div>

            <!-- Academic Term -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Academic Term</h3>
                    <select class="form-select form-select-sm w-auto" id="term-filter" data-csrf-token="<?= htmlspecialchars($csrf_token) ?>">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= $term['id'] ?>" <?= $term['id'] == $current_term_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="card-body">
                    <?php if ($current_term && isset($current_term['term_name'])): ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p><strong>Year:</strong> <?= htmlspecialchars($current_term['year']) ?></p>
                                <p><strong>Term:</strong> <?= htmlspecialchars($current_term['term_name']) ?></p>
                                <p><strong>Start Date:</strong> <?= htmlspecialchars($current_term['start_date']) ?></p>
                                <p><strong>End Date:</strong> <?= htmlspecialchars($current_term['end_date']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Term Progress:</strong> <?= $term_progress ?>%</p>
                                <div class="progress" role="progressbar" aria-valuenow="<?= $term_progress ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar bg-success" style="width: <?= $term_progress ?>%;">
                                        <?= $term_progress ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">No active term found. Please contact the administrator.</div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Key Metrics -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Your Metrics (<?= htmlspecialchars($current_academic_year) ?>)</h3>
                    <button class="btn btn-action btn-sm" id="refresh-metrics" data-csrf-token="<?= htmlspecialchars($csrf_token) ?>">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <?php
                        $metric_items = [
                            ['icon' => 'chalkboard', 'title' => 'Classes', 'value' => $metrics['class_count'], 'tooltip' => 'Your classes this term'],
                            ['icon' => 'book', 'title' => 'Subjects', 'value' => $metrics['subject_count'], 'tooltip' => 'Subjects you teach this term'],
                            ['icon' => 'clock', 'title' => 'Teaching Hours', 'value' => $metrics['teaching_hours'] . ' hrs', 'tooltip' => 'Your weekly teaching hours'],
                            ['icon' => 'tasks', 'title' => 'Assignments', 'value' => $metrics['assignment_count'], 'tooltip' => 'Assignments you gave this term'],
                            ['icon' => 'exclamation-triangle', 'title' => 'Pending Submissions', 'value' => $metrics['pending_submissions'], 'tooltip' => 'Assignments not yet submitted', 'color' => 'danger'],
                            ['icon' => 'check-circle', 'title' => 'Attendance', 'value' => $metrics['attendance_percentage'] . '%', 'tooltip' => 'Average attendance in your classes'],
                            ['icon' => 'star', 'title' => 'Evaluations', 'value' => $metrics['evaluations'], 'tooltip' => 'Evaluations you received this term'],
                            ['icon' => 'bell', 'title' => 'Notifications', 'value' => $metrics['notification_count'], 'tooltip' => 'Your unread notifications', 'class' => 'notification-count']
                        ];
                        foreach ($metric_items as $item): ?>
                            <div class="col-md-4 col-lg-3">
                                <div class="card h-100 text-center">
                                    <div class="card-body">
                                        <i class="fas fa-<?= $item['icon'] ?> fa-2x mb-2 text-<?= $item['color'] ?? 'primary' ?>"></i>
                                        <h5><?= $item['title'] ?></h5>
                                        <p class="fw-bold <?= $item['class'] ?? '' ?>"><?= $item['value'] ?></p>
                                        <small data-bs-toggle="tooltip" title="<?= $item['tooltip'] ?>">Total</small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-end mt-3">
                        <button class="btn btn-print" onclick="printMetrics()">Print</button>
                        <button class="btn btn-export" onclick="exportMetrics()">Export</button>
                    </div>
                    <div class="print-section" id="metrics-print">
                        <div class="print-header">
                            <img src="/assets/images/logo.png" alt="QueensPa School Logo" onerror="this.src='/assets/images/default_logo.png'">
                            <h2>QueensPa School</h2>
                            <p>P.O. Box 123, Accra, Ghana | info@qpmsedu.com</p>
                            <p>Admin Teacher Dashboard Metrics - <?= date('F j, Y') ?></p>
                        </div>
                        <table class="print-table">
                            <tr><th>Metric</th><th>Value</th></tr>
                            <?php foreach ($metric_items as $item): ?>
                                <tr><td><?= $item['title'] ?></td><td><?= $item['value'] ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                        <div class="print-footer">Generated by QueensPa School Management System</div>
                    </div>
                </div>
            </section>

            <!-- Classes and Subjects -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Your Classes and Subjects</h3>
                </div>
                <div class="card-body">
                    <div class="search-bar mb-3">
                        <input type="text" class="form-control" id="class-search" placeholder="Search classes or subjects...">
                    </div>
                    <div class="filter-group mb-3">
                        <select class="form-select" id="department-filter">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" id="day-filter">
                            <option value="">All Days</option>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                        </select>
                    </div>
                    <?php if (empty($classes)): ?>
                        <div class="alert alert-info">No classes assigned for this term. Contact the administrator.</div>
                    <?php else: ?>
                        <?php foreach ($classes as $dept_id => $dept_classes): ?>
                            <div class="collapsible-card mb-3" data-department="<?= $dept_id ?>">
                                <div class="card-header" data-bs-toggle="collapse" data-bs-target="#dept-<?= $dept_id ?>">
                                    <h5 class="mb-0"><?= htmlspecialchars($departments[$dept_id] ?? 'Unknown') ?> 
                                        <span class="badge bg-secondary"><?= count($dept_classes) ?> Classes</span>
                                    </h5>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div id="dept-<?= $dept_id ?>" class="collapse show">
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover table-bordered">
                                                <thead>
                                                    <tr>
                                                        <th>Class Name</th>
                                                        <th>Subjects</th>
                                                        <th>Days</th>
                                                        <th>Time</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($dept_classes as $class): ?>
                                                        <tr class="class-row" 
                                                            data-class-name="<?= htmlspecialchars($class['class_name']) ?>" 
                                                            data-subjects="<?= htmlspecialchars($class['subjects'] ?? '') ?>"
                                                            data-days="<?= htmlspecialchars($class['days'] ?? '') ?>">
                                                            <td><?= htmlspecialchars($class['class_name']) ?></td>
                                                            <td><?= htmlspecialchars($class['subjects'] ?: 'N/A') ?></td>
                                                            <td><?= htmlspecialchars($class['days'] ?: 'N/A') ?></td>
                                                            <td>
                                                                <?= htmlspecialchars($class['first_start_time'] && $class['last_end_time'] 
                                                                    ? substr($class['first_start_time'], 0, 5) . ' - ' . substr($class['last_end_time'], 0, 5) 
                                                                    : 'N/A') ?>
                                                            </td>
                                                            <td>
                                                                <a href="/admin/tview_classes.php?class_id=<?= $class['id'] ?>" class="btn btn-action btn-sm" data-bs-toggle="tooltip" title="View class details">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                                <a href="/admin/create_assignment.php?class_id=<?= $class['id'] ?>" class="btn btn-action btn-sm" data-bs-toggle="tooltip" title="Create assignment">
                                                                    <i class="fas fa-plus"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="text-end mt-3">
                        <button class="btn btn-print" onclick="printClasses()">Print</button>
                        <button class="btn btn-export" onclick="exportClasses()">Export</button>
                    </div>
                    <div class="print-section" id="classes-print">
                        <div class="print-header">
                            <img src="/assets/images/logo.png" alt="QueensPa School Logo" onerror="this.src='/assets/images/default_logo.png'">
                            <h2>QueensPa School</h2>
                            <p>P.O. Box 123, Accra, Ghana | info@qpmsedu.com</p>
                            <p>Class Schedule - <?= date('F j, Y') ?></p>
                        </div>
                        <?php foreach ($classes as $dept_id => $dept_classes): ?>
                            <h4><?= htmlspecialchars($departments[$dept_id] ?? 'Unknown') ?></h4>
                            <table class="print-table">
                                <tr><th>Class Name</th><th>Subjects</th><th>Days</th><th>Time</th></tr>
                                <?php foreach ($dept_classes as $class): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($class['class_name']) ?></td>
                                        <td><?= htmlspecialchars($class['subjects'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($class['days'] ?: 'N/A') ?></td>
                                        <td>
                                            <?= htmlspecialchars($class['first_start_time'] && $class['last_end_time'] 
                                                ? substr($class['first_start_time'], 0, 5) . ' - ' . substr($class['last_end_time'], 0, 5) 
                                                : 'N/A') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endforeach; ?>
                        <div class="print-footer">Generated by QueensPa School Management System</div>
                    </div>
                </div>
            </section>

            <!-- Teaching Load Visualization -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Your Weekly Teaching Load</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="teachingLoadChart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Upcoming Assignments -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Your Upcoming Assignments</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <div class="table-container">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Class</th>
                                        <th>Due Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($assignments)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center">
                                                No upcoming assignments. <a href="/admin/assignments.php">Manage assignments</a>.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($assignments as $assignment): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($assignment['title']) ?></td>
                                                <td><?= htmlspecialchars($assignment['class_name']) ?></td>
                                                <td><?= htmlspecialchars($assignment['due_date']) ?></td>
                                                <td>
                                                    <a href="/admin/assignment_details.php?id=<?= $assignment['id'] ?>" class="btn btn-action btn-sm" data-bs-toggle="tooltip" title="View assignment">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Notifications -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Your Unread Notifications</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <div class="table-container">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Message</th>
                                        <th>Received</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($notifications)): ?>
                                        <tr><td colspan="4" class="text-center">No unread notifications.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($notifications as $notification): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($notification['title']) ?></td>
                                                <td><?= htmlspecialchars($notification['message']) ?></td>
                                                <td><?= htmlspecialchars($notification['created_at']) ?></td>
                                                <td>
                                                    <button class="btn btn-action btn-sm mark-read" 
                                                            data-notification-id="<?= $notification['id'] ?>" 
                                                            data-csrf-token="<?= htmlspecialchars($csrf_token) ?>" 
                                                            data-bs-toggle="tooltip" title="Mark as read">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Assignment Submission Status -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Your Assignment Submission Status</h3>
                    <select class="form-select form-select-sm w-auto" id="submission-filter">
                        <option value="all">All</option>
                        <option value="submitted">Submitted</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="submissionStatusChart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Recent Activity -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Your Recent Activity</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <div class="table-container">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Action</th>
                                        <th>Description</th>
                                        <th>Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_logs)): ?>
                                        <tr><td colspan="3" class="text-center">No recent activity found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_logs as $log): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($log['action']) ?></td>
                                                <td><?= htmlspecialchars($log['description']) ?></td>
                                                <td><?= htmlspecialchars($log['created_at']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="d-flex flex-wrap quick-links gap-2 mb-4 animate__animated animate__fadeInUp">
                <a href="/admin/class_schedules.php" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="View your class schedules">
                    <i class="fas fa-calendar"></i> View Schedules
                </a>
                <a href="/admin/manage_attendance.php" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Manage attendance">
                    <i class="fas fa-check-circle"></i> Manage Attendance
                </a>
                <a href="/admin/lesson_schedule.php" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Manage lesson plans">
                    <i class="fas fa-book"></i> Manage Lesson Plans
                </a>
                <a href="/admin/assignments.php" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Manage assignments">
                    <i class="fas fa-tasks"></i> Manage Assignments
                </a>
            </section>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        </main>
    </div>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        logActivity($conn, $user_id, "Footer Missing", "Footer file missing: $footer_path");
        $_SESSION['error_msg'] = "System error: Unable to load page footer.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loadingOverlay = document.getElementById('loading-overlay');
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('animate__fadeOut');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            const themeToggle = document.getElementById('theme-toggle');
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark-mode');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i> Toggle Theme';
            }
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                const isDark = document.body.classList.contains('dark-mode');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i> Toggle Theme' : '<i class="fas fa-moon"></i> Toggle Theme';
                updateCharts();
            });

            const searchInput = document.getElementById('class-search');
            const deptFilter = document.getElementById('department-filter');
            const dayFilter = document.getElementById('day-filter');
            const classRows = document.querySelectorAll('.class-row');
            const deptCards = document.querySelectorAll('.collapsible-card');

            function filterClasses() {
                const searchTerm = searchInput.value.toLowerCase();
                const deptValue = deptFilter.value;
                const dayValue = dayFilter.value;

                deptCards.forEach(card => {
                    const deptId = card.getAttribute('data-department');
                    card.style.display = deptValue && deptId !== deptValue ? 'none' : 'block';
                });

                classRows.forEach(row => {
                    const className = row.getAttribute('data-class-name').toLowerCase();
                    const subjects = row.getAttribute('data-subjects').toLowerCase();
                    const days = row.getAttribute('data-days').toLowerCase();
                    const matchesSearch = className.includes(searchTerm) || subjects.includes(searchTerm);
                    const matchesDay = !dayValue || days.includes(dayValue.toLowerCase());
                    row.style.display = matchesSearch && matchesDay ? '' : 'none';
                });
            }

            searchInput.addEventListener('input', filterClasses);
            deptFilter.addEventListener('change', filterClasses);
            dayFilter.addEventListener('change', filterClasses);

            const termFilter = document.getElementById('term-filter');
            termFilter.addEventListener('change', async () => {
                loadingOverlay.style.display = 'flex';
                const termId = termFilter.value;
                const csrfToken = termFilter.getAttribute('data-csrf-token');
                try {
                    const response = await fetch('/api/update_term.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ term_id: termId, csrf_token: csrfToken })
                    });
                    if (response.ok) window.location.reload();
                    else alert('Failed to update term.');
                } catch (error) {
                    console.error('Error updating term:', error);
                    alert('An error occurred.');
                } finally {
                    loadingOverlay.style.display = 'none';
                }
            });

            const refreshMetrics = document.getElementById('refresh-metrics');
            refreshMetrics.addEventListener('click', async () => {
                loadingOverlay.style.display = 'flex';
                const csrfToken = refreshMetrics.getAttribute('data-csrf-token');
                try {
                    const response = await fetch('/api/refresh_metrics.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ csrf_token: csrfToken })
                    });
                    if (response.ok) window.location.reload();
                    else alert('Failed to refresh metrics.');
                } catch (error) {
                    console.error('Error refreshing metrics:', error);
                    alert('An error occurred.');
                } finally {
                    loadingOverlay.style.display = 'none';
                }
            });

            document.querySelectorAll('.mark-read').forEach(button => {
                button.addEventListener('click', async () => {
                    loadingOverlay.style.display = 'flex';
                    const notificationId = button.getAttribute('data-notification-id');
                    const csrfToken = button.getAttribute('data-csrf-token');
                    try {
                        const response = await fetch('/api/mark_notification_read.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ notification_id: notificationId, csrf_token: csrfToken })
                        });
                        if (response.ok) {
                            button.closest('tr').remove();
                            const notifCountEl = document.querySelector('.notification-count');
                            if (notifCountEl) {
                                const count = parseInt(notifCountEl.textContent) - 1;
                                notifCountEl.textContent = count >= 0 ? count : 0;
                            }
                        } else {
                            alert('Failed to mark notification as read.');
                        }
                    } catch (error) {
                        console.error('Error marking notification:', error);
                        alert('An error occurred.');
                    } finally {
                        loadingOverlay.style.display = 'none';
                    }
                });
            });

            const submissionFilter = document.getElementById('submission-filter');
            submissionFilter.addEventListener('change', updateSubmissionChart);

            loadingOverlay.style.display = 'none';
        });

        let teachingLoadChart;
        function updateTeachingLoadChart() {
            if (teachingLoadChart) teachingLoadChart.destroy();
            teachingLoadChart = new Chart(document.getElementById('teachingLoadChart'), {
                type: 'bar',
                data: {
                    labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                    datasets: [{
                        label: 'Teaching Hours',
                        data: [
                            <?= $teaching_hours_by_day['Monday'] ?>,
                            <?= $teaching_hours_by_day['Tuesday'] ?>,
                            <?= $teaching_hours_by_day['Wednesday'] ?>,
                            <?= $teaching_hours_by_day['Thursday'] ?>,
                            <?= $teaching_hours_by_day['Friday'] ?>
                        ],
                        backgroundColor: 'rgba(255, 69, 0, 0.6)',
                        borderColor: 'rgba(255, 69, 0, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'Your Weekly Teaching Hours by Day' },
                        tooltip: { callbacks: { label: context => `${context.raw} hours` } }
                    },
                    scales: { y: { beginAtZero: true, title: { display: true, text: 'Hours' } } }
                }
            });
        }

        let submissionStatusChart;
        function updateSubmissionChart() {
            if (submissionStatusChart) submissionStatusChart.destroy();
            const filter = document.getElementById('submission-filter').value;
            let data = [<?= $submission_status['submitted'] ?>, <?= $submission_status['pending'] ?>];
            let labels = ['Submitted', 'Pending'];
            if (filter === 'submitted') data = [<?= $submission_status['submitted'] ?>, 0];
            else if (filter === 'pending') data = [0, <?= $submission_status['pending'] ?>];
            submissionStatusChart = new Chart(document.getElementById('submissionStatusChart'), {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{ data: data, backgroundColor: ['#28a745', '#dc3545'], borderColor: '#fff', borderWidth: 2 }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' },
                        title: { display: true, text: 'Your Assignment Submission Status' },
                        tooltip: {
                            callbacks: {
                                label: context => {
                                    let label = context.label || '';
                                    let value = context.raw || 0;
                                    let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    let percentage = total ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        function updateCharts() {
            updateTeachingLoadChart();
            updateSubmissionChart();
        }

        updateCharts();

        function printMetrics() {
            const printSection = document.getElementById('metrics-print');
            printSection.style.visibility = 'visible';
            printSection.style.display = 'block';
            window.print();
            printSection.style.visibility = 'hidden';
            printSection.style.display = 'none';
        }

        function printClasses() {
            const printSection = document.getElementById('classes-print');
            printSection.style.visibility = 'visible';
            printSection.style.display = 'block';
            window.print();
            printSection.style.visibility = 'hidden';
            printSection.style.display = 'none';
        }

        function exportMetrics() {
            const data = [
                ['Metric', 'Value'],
                ['Classes', '<?= $metrics['class_count'] ?>'],
                ['Subjects', '<?= $metrics['subject_count'] ?>'],
                ['Teaching Hours (Weekly)', '<?= $metrics['teaching_hours'] ?> hrs'],
                ['Assignments (Given)', '<?= $metrics['assignment_count'] ?>'],
                ['Pending Submissions', '<?= $metrics['pending_submissions'] ?>'],
                ['Attendance Rate', '<?= $metrics['attendance_percentage'] ?>%'],
                ['Evaluations (Received)', '<?= $metrics['evaluations'] ?>'],
                ['Notifications (Unread)', '<?= $metrics['notification_count'] ?>']
            ];
            const csv = data.map(row => row.join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'admin_teacher_dashboard_metrics_<?= date('Ymd') ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        function exportClasses() {
            const data = [['Department', 'Class Name', 'Subjects', 'Days', 'Time']];
            <?php foreach ($classes as $dept_id => $dept_classes): ?>
                <?php foreach ($dept_classes as $class): ?>
                    data.push([
                        '<?= addslashes($departments[$dept_id] ?? 'Unknown') ?>',
                        '<?= addslashes($class['class_name']) ?>',
                        '<?= addslashes($class['subjects'] ?: 'N/A') ?>',
                        '<?= addslashes($class['days'] ?: 'N/A') ?>',
                        '<?= addslashes($class['first_start_time'] && $class['last_end_time'] 
                            ? substr($class['first_start_time'], 0, 5) . ' - ' . substr($class['last_end_time'], 0, 5) 
                            : 'N/A') ?>'
                    ]);
                <?php endforeach; ?>
            <?php endforeach; ?>
            const csv = data.map(row => row.join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'admin_teacher_dashboard_classes_<?= date('Ymd') ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>