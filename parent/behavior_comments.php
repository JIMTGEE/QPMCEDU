<?php
// behavior_comments.php - Parent Portal Behavior Comments
// Updated to align with parent_header.php sidebar and QPMCEDU.com styling
// Fixed: Properly handle term field (string vs ID)

session_start();

// Restrict to parents only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    header("Location: /parent/login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!isset($conn) || !$conn) {
    error_log("Database connection failed");
    http_response_code(500);
    die("System error: Database connection failed.");
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$parent_id = (int)$_SESSION['user_id'];

// Fetch current academic year
$current_year_query = $conn->query("SELECT academic_year FROM student_classes GROUP BY academic_year ORDER BY academic_year DESC LIMIT 1");
$current_academic_year = $current_year_query->fetch_assoc()['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);

// Handle mark as read (AJAX and POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_read']) && isset($_POST['comment_id']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token) {
        $comment_id = (int)$_POST['comment_id'];
        $stmt = $conn->prepare("UPDATE behavior_comments SET is_read = 1 WHERE id = ? AND user_id IN (SELECT student_id FROM parent_student_mapping WHERE parent_id = ?)");
        $stmt->bind_param("ii", $comment_id, $parent_id);
        if ($stmt->execute()) {
            logActivity($conn, $parent_id, "Marked Comment as Read", "Comment ID: $comment_id");
            $success_message = "Comment marked as read successfully!";
        } else {
            error_log("Mark read failed: " . $stmt->error);
            $error_message = "Failed to mark comment as read.";
        }
        $stmt->close();
    }
    
    // Handle AJAX mark as read
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'mark_as_read' && isset($_POST['comment_id']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token) {
        $comment_id = (int)$_POST['comment_id'];
        $stmt = $conn->prepare("UPDATE behavior_comments SET is_read = 1 WHERE id = ? AND user_id IN (SELECT student_id FROM parent_student_mapping WHERE parent_id = ?)");
        $stmt->bind_param("ii", $comment_id, $parent_id);
        $success = $stmt->execute();
        $stmt->close();
        header('Content-Type: application/json');
        echo json_encode(['success' => $success]);
        exit();
    }
}

// Fetch terms - get term names from academic_calendar
$terms = [];
$stmt = $conn->prepare("SELECT id, term_name, year FROM academic_calendar ORDER BY start_date DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $terms[] = $row;
}
$stmt->close();

// Current term - get term name
$current_term_name = '';
$current_term_year = $current_academic_year;
$stmt = $conn->prepare("SELECT term_name, year FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $current_term_name = $row['term_name'];
    $current_term_year = $row['year'];
} else {
    // If no active term, get the latest term
    $stmt2 = $conn->prepare("SELECT term_name, year FROM academic_calendar ORDER BY start_date DESC LIMIT 1");
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    if ($row2 = $result2->fetch_assoc()) {
        $current_term_name = $row2['term_name'];
        $current_term_year = $row2['year'];
    }
    $stmt2->close();
}
$stmt->close();

// Handle term selection - store as term name (string)
$selected_term_name = isset($_GET['term']) && !empty($_GET['term']) 
    ? $_GET['term'] 
    : (isset($_POST['term_name']) && !empty($_POST['term_name']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token 
        ? $_POST['term_name'] 
        : $current_term_name);

$selected_term_year = $current_term_year;
// If term name is selected, get its year
if (!empty($selected_term_name)) {
    $stmt = $conn->prepare("SELECT year FROM academic_calendar WHERE term_name = ? LIMIT 1");
    $stmt->bind_param("s", $selected_term_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $selected_term_year = $row['year'];
    }
    $stmt->close();
}

// Handle student filter (GET and POST)
$selected_student_id = 0;
if (isset($_GET['student']) && is_numeric($_GET['student'])) {
    $selected_student_id = (int)$_GET['student'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token && isset($_POST['student_id']) && is_numeric($_POST['student_id'])) {
    $selected_student_id = (int)$_POST['student_id'];
}

// Fetch students
$students = [];
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.name, c.class_name
    FROM users u
    JOIN parent_student_mapping psm ON u.id = psm.student_id
    JOIN student_classes sc ON u.id = sc.user_id
    JOIN classes c ON sc.class_id = c.id
    WHERE psm.parent_id = ? AND sc.academic_year = ?
    ORDER BY u.name
");
$stmt->bind_param("is", $parent_id, $selected_term_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

$valid_student_ids = array_column($students, 'id');
if ($selected_student_id > 0 && !in_array($selected_student_id, $valid_student_ids)) {
    $selected_student_id = 0;
}

// Fetch comments for each student - term is stored as string (term name)
$comments = [];
$total_comments = 0;
$unread_comments = 0;

foreach ($students as $student) {
    $sid = $student['id'];
    try {
        $sql = "SELECT bc.id, bc.comment, bc.created_at, bc.term, bc.is_read, 
                       u.name AS student_name, c.class_name, t.name AS teacher_name
                FROM behavior_comments bc
                JOIN users u ON bc.user_id = u.id
                JOIN classes c ON bc.class_id = c.id
                JOIN users t ON bc.created_by = t.id
                WHERE bc.user_id = ?
                AND bc.term = ?
                ORDER BY bc.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $sid, $selected_term_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $student_comments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
      
        $student_total = count($student_comments);
        $total_comments += $student_total;
        $unread_comments += count(array_filter($student_comments, fn($c) => !$c['is_read']));
      
        $comments[$sid] = $student_comments;
    } catch (Exception $e) {
        error_log("Error fetching comments for student $sid: " . $e->getMessage());
        $comments[$sid] = [];
    }
}

// Log access
logActivity($conn, $parent_id, "Accessed Behavior Comments", "Viewed for term: {$selected_term_name} {$selected_term_year}" . ($selected_student_id > 0 ? ", student: $selected_student_id" : ""));
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Behavior Comments | QPMCEDU Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Additional page-specific styles that complement the shared navbar */
        :root {
            --qpms-orange: #ff6b00;
            --qpms-orange-dark: #e65c00;
            --qpms-orange-light: #fff3e0;
            --qpms-gray: #212529;
        }
        body {
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
            color: var(--qpms-gray);
            overflow-x: hidden;
        }
        /* Override any potential conflicts with navbar margins */
        #qpmc-content {
            padding: 1.5rem 2rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        @media (max-width: 768px) {
            #qpmc-content {
                padding: 1rem;
            }
        }
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: var(--qpms-orange);
            color: white;
            font-weight: 600;
            padding: 1rem 1.5rem;
            border-bottom: none;
        }
        .card-header h3, .card-header h5 {
            margin: 0;
        }
        .btn-orange {
            background: var(--qpms-orange);
            border: none;
            color: white;
            border-radius: 0.75rem;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-orange:hover {
            background: var(--qpms-orange-dark);
            color: white;
            transform: translateY(-1px);
        }
        .btn-outline-orange {
            border: 1px solid var(--qpms-orange);
            color: var(--qpms-orange);
            background: transparent;
            border-radius: 0.75rem;
            padding: 0.5rem 1.25rem;
            transition: all 0.2s;
        }
        .btn-outline-orange:hover {
            background: var(--qpms-orange);
            color: white;
        }
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: transform 0.2s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-card i {
            font-size: 2rem;
            color: var(--qpms-orange);
            margin-bottom: 0.75rem;
        }
        .stat-card h6 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0.5rem 0;
            color: var(--qpms-gray);
        }
        .table {
            font-size: 0.9rem;
        }
        .table thead th {
            background: #fff3e6;
            color: var(--qpms-orange);
            font-weight: 600;
            border-bottom: 2px solid var(--qpms-orange);
        }
        .unread-row {
            background-color: #fff8f0;
            border-left: 3px solid var(--qpms-orange);
        }
        .badge-read {
            background: #10b981;
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
        }
        .badge-unread {
            background: var(--qpms-orange);
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
        }
        .form-select:focus, .form-control:focus {
            border-color: var(--qpms-orange);
            box-shadow: 0 0 0 0.2rem rgba(255,107,0,0.25);
        }
        .student-section {
            margin-bottom: 2rem;
        }
        .student-section:last-child {
            margin-bottom: 0;
        }
        .comment-text {
            max-width: 400px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        footer {
            text-align: center;
            padding: 1.5rem;
            color: #6c757d;
            font-size: 0.85rem;
            border-top: 1px solid #dee2e6;
            margin-top: 2rem;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 1rem;
        }
        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        @media print {
            #qpmc-sidebar, #qpmc-topbar, #qpmc-overlay, .no-print, .btn, .card-header .btn, form, .stat-card .btn {
                display: none !important;
            }
            #qpmc-content {
                margin: 0 !important;
                padding: 0 !important;
            }
            body {
                background: white;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }
            .stat-card {
                border: 1px solid #ddd;
                box-shadow: none;
            }
            .unread-row {
                background: #f5f5f5;
            }
        }
        .teacher-info {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .teacher-info i {
            margin-right: 0.25rem;
        }
    </style>
</head>
<body>

<?php 
// Include the shared parent sidebar/navbar
// IMPORTANT: This file opens <div id="qpmc-content"> which we will close at the end of THIS file.
require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php'; 
?>

<!-- MAIN CONTENT AREA (inside #qpmc-content which is opened by navbar.php) -->
<div class="container-fluid px-0">
    
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1 fw-semibold" style="color: var(--qpms-gray);">
                <i class="fas fa-comment-dots me-2" style="color: var(--qpms-orange);"></i>Behavior Comments
            </h2>
            <p class="text-muted mb-0">
                <i class="fas fa-calendar-alt me-1"></i> Track your child's behavior and conduct reports from teachers
            </p>
        </div>
        <div class="mt-2 mt-sm-0">
            <button onclick="window.print()" class="btn btn-outline-orange no-print me-2">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="/parent/dashboard.php" class="btn btn-orange no-print">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end" id="filterForm">
                <div class="col-md-4">
                    <label for="term" class="form-label fw-semibold">
                        <i class="fas fa-calendar-alt me-1"></i> Select Term
                    </label>
                    <select name="term" id="term" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= htmlspecialchars($term['term_name']) ?>" <?= $selected_term_name == $term['term_name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name'] . ' ' . $term['year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="student" class="form-label fw-semibold">
                        <i class="fas fa-user-graduate me-1"></i> Filter by Student
                    </label>
                    <select name="student" id="student" class="form-select" onchange="this.form.submit()">
                        <option value="0" <?= $selected_student_id == 0 ? 'selected' : '' ?>>-- All Students --</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $selected_student_id == $s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['class_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <a href="behavior_comments.php" class="btn btn-outline-orange w-100">
                        <i class="fas fa-sync-alt"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h6><?= count($students) ?></h6>
                <small class="text-muted">Total Students</small>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-comments"></i>
                <h6><?= $total_comments ?></h6>
                <small class="text-muted">Total Comments</small>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-envelope"></i>
                <h6 style="color: var(--qpms-orange);"><?= $unread_comments ?></h6>
                <small class="text-muted">Unread Comments</small>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h6 style="color: #10b981;"><?= $total_comments - $unread_comments ?></h6>
                <small class="text-muted">Read Comments</small>
            </div>
        </div>
    </div>

    <!-- Comments by Student -->
    <?php 
    $display_students = $students;
    if ($selected_student_id > 0) {
        $display_students = array_filter($students, fn($s) => $s['id'] == $selected_student_id);
    }
    
    if (empty($display_students)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <h5>No Students Found</h5>
                    <p class="text-muted">No students are linked to your account for the selected term.</p>
                    <?php if ($selected_student_id > 0): ?>
                        <a href="behavior_comments.php" class="btn btn-orange mt-3">
                            <i class="fas fa-sync-alt"></i> Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($display_students as $student): 
            $student_comments = $comments[$student['id']] ?? [];
        ?>
            <div class="card student-section">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0">
                            <i class="fas fa-user-graduate me-2"></i>
                            <?= htmlspecialchars($student['name']) ?>
                        </h5>
                        <small class="opacity-75">
                            <i class="fas fa-graduation-cap me-1"></i>
                            <?= htmlspecialchars($student['class_name'] ?? 'Class Not Assigned') ?>
                        </small>
                    </div>
                    <span class="badge bg-light text-dark">
                        <i class="fas fa-calendar me-1"></i>
                        <?= htmlspecialchars($selected_term_name) ?> <?= htmlspecialchars($selected_term_year) ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (empty($student_comments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-comment-slash"></i>
                            <h5>No Behavior Comments</h5>
                            <p class="text-muted">No behavior comments have been added for <?= htmlspecialchars($student['name']) ?> during this term.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 45%">Comment / Feedback</th>
                                        <th>Teacher</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th class="no-print">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($student_comments as $comment): ?>
                                        <tr class="<?= !$comment['is_read'] ? 'unread-row' : '' ?>">
                                            <td class="comment-text">
                                                <i class="fas fa-quote-left me-2 text-muted" style="font-size: 0.8rem;"></i>
                                                <?= nl2br(htmlspecialchars($comment['comment'])) ?>
                                            </td>
                                            <td>
                                                <div class="teacher-info">
                                                    <i class="fas fa-chalkboard-user"></i>
                                                    <?= htmlspecialchars($comment['teacher_name']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <i class="far fa-calendar-alt me-1"></i>
                                                <?= date('M d, Y', strtotime($comment['created_at'])) ?>
                                                <br>
                                                <small class="text-muted"><?= date('h:i A', strtotime($comment['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <span class="<?= $comment['is_read'] ? 'badge-read' : 'badge-unread' ?>">
                                                    <i class="fas <?= $comment['is_read'] ? 'fa-check-circle' : 'fa-clock' ?> me-1"></i>
                                                    <?= $comment['is_read'] ? 'Read' : 'Unread' ?>
                                                </span>
                                            </td>
                                            <td class="no-print">
                                                <?php if (!$comment['is_read']): ?>
                                                    <form method="POST" style="display:inline;" class="mark-read-form">
                                                        <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                        <button type="submit" name="mark_read" class="btn btn-sm btn-orange">
                                                            <i class="fas fa-check"></i> Mark Read
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled>
                                                        <i class="fas fa-check-circle"></i> Already Read
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="no-print">
        <p class="mb-0">&copy; <?= date('Y') ?> QueensPa School. Behavior comments are recorded by teachers to help track student conduct and progress.</p>
        <p class="small text-muted mt-1">For any concerns regarding behavior reports, please contact your child's class teacher.</p>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Close sidebar when a nav link is clicked on mobile
        const sidebar = document.getElementById('qpmc-sidebar');
        const overlay = document.getElementById('qpmc-overlay');
        if (sidebar && overlay) {
            const navLinks = sidebar.querySelectorAll('a');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (window.innerWidth <= 991) {
                        if (typeof closeSidebar === 'function') {
                            closeSidebar();
                        }
                    }
                });
            });
        }
        
        // AJAX mark as read functionality
        const markReadForms = document.querySelectorAll('.mark-read-form');
        markReadForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const commentId = this.querySelector('input[name="comment_id"]').value;
                const csrfToken = this.querySelector('input[name="csrf_token"]').value;
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'ajax_action': 'mark_as_read',
                        'comment_id': commentId,
                        'csrf_token': csrfToken
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const row = this.closest('tr');
                        row.classList.remove('unread-row');
                        
                        // Update status badge
                        const statusCell = row.querySelector('td:nth-child(4)');
                        statusCell.innerHTML = '<span class="badge-read"><i class="fas fa-check-circle me-1"></i> Read</span>';
                        
                        // Replace button with disabled version
                        const actionCell = this.closest('td');
                        actionCell.innerHTML = '<button class="btn btn-sm btn-outline-secondary" disabled><i class="fas fa-check-circle"></i> Already Read</button>';
                        
                        // Update stats
                        const unreadElem = document.querySelector('.stat-card:nth-child(3) h6');
                        const readElem = document.querySelector('.stat-card:nth-child(4) h6');
                        if (unreadElem && readElem) {
                            let unread = parseInt(unreadElem.innerText);
                            let read = parseInt(readElem.innerText);
                            if (!isNaN(unread) && unread > 0) {
                                unreadElem.innerText = unread - 1;
                                readElem.innerText = read + 1;
                            }
                        }
                    }
                })
                .catch(error => console.error('Error:', error));
            });
        });
        
        // Auto-dismiss alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                const closeBtn = alert.querySelector('.btn-close');
                if (closeBtn) {
                    closeBtn.click();
                }
            }, 5000);
        });
    });
</script>

<?php
// Close the #qpmc-content div that was opened by navbar.php
// This is required as per the navbar.php documentation.
?>
</div> <!-- end #qpmc-content -->
</body>
</html>