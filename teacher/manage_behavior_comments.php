<?php
// manage_behavior_comments.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Try to find the database configuration file
$db_found = false;
$db_paths = [
    '../config/database.php',
    '../config/db.php',
    '../config/connection.php',
    '../includes/db.php',
    '../includes/database.php',
    '../includes/connection.php',
    '../functions/db.php',
    '../db.php',
    'db.php',
];

foreach ($db_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $db_found = true;
        break;
    }
}

if (!$db_found) {
    $config_dir = dirname(__DIR__) . '/config';
    if (is_dir($config_dir)) {
        $files = scandir($config_dir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) == 'php' && 
                (strpos(strtolower($file), 'db') !== false || 
                 strpos(strtolower($file), 'database') !== false)) {
                require_once $config_dir . '/' . $file;
                $db_found = true;
                break;
            }
        }
    }
    
    if (!$db_found) {
        $includes_dir = dirname(__DIR__) . '/includes';
        if (is_dir($includes_dir)) {
            $files = scandir($includes_dir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) == 'php' && 
                    (strpos(strtolower($file), 'db') !== false || 
                     strpos(strtolower($file), 'database') !== false)) {
                    require_once $includes_dir . '/' . $file;
                    $db_found = true;
                    break;
                }
            }
        }
    }
}

if (!$db_found) {
    die("Could not find database configuration file.");
}

// Check if connection variable exists
if (!isset($conn) && isset($connection)) {
    $conn = $connection;
}
if (!isset($conn) && isset($db)) {
    $conn = $db;
}
if (!isset($conn) && isset($db_connection)) {
    $conn = $db_connection;
}

if (!isset($conn) || !$conn) {
    if (isset($db_host) && isset($db_user) && isset($db_pass) && isset($db_name)) {
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            die("Database connection failed: " . $conn->connect_error);
        }
    } else {
        die("Database connection not established.");
    }
}

// Check if user is logged in and has teacher role
if (!isset($_SESSION['user_id'])) {
    die("Please login as a teacher to access this page. <a href='../login.php'>Login here</a>");
}

// Get user role from database instead of session
$user_id = $_SESSION['user_id'];
$role_query = "SELECT role FROM users WHERE id = '$user_id'";
$role_result = mysqli_query($conn, $role_query);
if ($role_result && mysqli_num_rows($role_result) > 0) {
    $user_data = mysqli_fetch_assoc($role_result);
    $user_role = $user_data['role'];
    $_SESSION['role'] = $user_role;
} else {
    $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
}

if ($user_role !== 'teacher' && $user_role !== 'admin') {
    die("Access denied. This page is for teachers only.");
}

$teacher_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle AJAX requests
if (isset($_POST['ajax_action'])) {
    if ($_POST['ajax_action'] === 'get_students') {
        $class_id = mysqli_real_escape_string($conn, $_POST['class_id']);
        
        $query = "SELECT u.id, u.name 
                  FROM users u
                  INNER JOIN student_classes sc ON u.id = sc.user_id
                  WHERE sc.class_id = '$class_id' 
                  AND u.role = 'student'
                  ORDER BY u.name";
        
        $result = mysqli_query($conn, $query);
        
        if ($result && mysqli_num_rows($result) > 0) {
            echo '<option value="">Select student...</option>';
            while ($student = mysqli_fetch_assoc($result)) {
                echo "<option value='{$student['id']}'>" . htmlspecialchars($student['name']) . "</option>";
            }
        } else {
            echo '<option value="">No students found in this class</option>';
        }
        exit();
    }
    
    if ($_POST['ajax_action'] === 'get_comment_stats') {
        $teacher_id = mysqli_real_escape_string($conn, $teacher_id);
        
        $stats_query = "SELECT 
                            COUNT(*) as total_comments,
                            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count,
                            SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count
                        FROM behavior_comments 
                        WHERE created_by = '$teacher_id'";
        
        $stats_result = mysqli_query($conn, $stats_query);
        
        if ($stats_result) {
            $stats = mysqli_fetch_assoc($stats_result);
            echo json_encode($stats);
        } else {
            echo json_encode(['total_comments' => 0, 'unread_count' => 0, 'read_count' => 0]);
        }
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_comment') {
            $class_id = mysqli_real_escape_string($conn, $_POST['class_id']);
            $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);
            $term_id = mysqli_real_escape_string($conn, $_POST['term_id']);
            $comment = mysqli_real_escape_string($conn, $_POST['comment']);
            
            // Get term name
            $term_query = "SELECT term_name FROM academic_terms WHERE id = '$term_id'";
            $term_result = mysqli_query($conn, $term_query);
            $term = mysqli_fetch_assoc($term_result);
            $term_name = $term ? $term['term_name'] : 'Current Term';
            
            // Get teacher name
            $teacher_query = "SELECT name FROM users WHERE id = '$teacher_id'";
            $teacher_result = mysqli_query($conn, $teacher_query);
            $teacher = mysqli_fetch_assoc($teacher_result);
            $teacher_name = $teacher ? $teacher['name'] : 'Teacher';
            
            // Insert new comment
            $insert_query = "INSERT INTO behavior_comments 
                            (class_id, user_id, term, comment, created_by, is_read, created_at) 
                            VALUES ('$class_id', '$student_id', '$term_name', '$comment', 
                                    '$teacher_id', 0, NOW())";
            
            if (mysqli_query($conn, $insert_query)) {
                $comment_id = mysqli_insert_id($conn);
                $success_message = "Behavior comment sent successfully to parents!";
                
                // Get all parents linked to this student
                $parent_query = "SELECT p.user_id, p.parent_name, u.name as parent_user_name
                                FROM parents p 
                                INNER JOIN parent_student_mapping psm ON p.id = psm.parent_id 
                                INNER JOIN users u ON p.user_id = u.id
                                WHERE psm.student_id = '$student_id'";
                $parent_result = mysqli_query($conn, $parent_query);
                
                if ($parent_result && mysqli_num_rows($parent_result) > 0) {
                    while ($parent = mysqli_fetch_assoc($parent_result)) {
                        $parent_user_id = $parent['user_id'];
                        $parent_name = $parent['parent_name'] ?: $parent['parent_user_name'];
                        
                        // Get student name
                        $student_query = "SELECT name FROM users WHERE id = '$student_id'";
                        $student_result = mysqli_query($conn, $student_query);
                        $student = mysqli_fetch_assoc($student_result);
                        $student_name = $student ? $student['name'] : 'Your child';
                        
                        // Create notification for parent
                        $notification_message = "Teacher {$teacher_name} has added a behavior comment for {$student_name} regarding their behavior in {$term_name} term.";
                        
                        $notification_query = "INSERT INTO notifications 
                                              (user_id, title, message, type, category, link, created_at) 
                                              VALUES ('$parent_user_id', 
                                                     'New Behavior Comment', 
                                                     '$notification_message', 
                                                     'behavior', 
                                                     'academic', 
                                                     'behavior_comments.php', 
                                                     NOW())";
                        mysqli_query($conn, $notification_query);
                        
                        // Also log the activity
                        $activity_log = "INSERT INTO activity_logs 
                                        (user_id, action, description, created_at) 
                                        VALUES ('$parent_user_id', 
                                               'behavior_comment_received', 
                                               'Received behavior comment from {$teacher_name} for {$student_name}', 
                                               NOW())";
                        mysqli_query($conn, $activity_log);
                    }
                }
                
                // Also create notification for the student (optional)
                $student_notification = "INSERT INTO notifications 
                                        (user_id, title, message, type, category, link, created_at) 
                                        VALUES ('$student_id', 
                                               'Behavior Comment Added', 
                                               'Your teacher {$teacher_name} has added a behavior comment for you.', 
                                               'behavior', 
                                               'academic', 
                                               'my_behavior.php', 
                                               NOW())";
                mysqli_query($conn, $student_notification);
                
            } else {
                $error_message = "Error sending comment: " . mysqli_error($conn);
            }
        } elseif ($_POST['action'] === 'update_comment') {
            $comment_id = mysqli_real_escape_string($conn, $_POST['comment_id']);
            $comment = mysqli_real_escape_string($conn, $_POST['comment']);
            
            // Get original comment to check if changed
            $original_query = "SELECT comment, user_id FROM behavior_comments WHERE id = '$comment_id' AND created_by = '$teacher_id'";
            $original_result = mysqli_query($conn, $original_query);
            
            if ($original_result && $original_comment = mysqli_fetch_assoc($original_result)) {
                $original_text = $original_comment['comment'];
                $student_id = $original_comment['user_id'];
                
                $update_query = "UPDATE behavior_comments 
                                SET comment = '$comment', updated_at = NOW() 
                                WHERE id = '$comment_id' AND created_by = '$teacher_id'";
                
                if (mysqli_query($conn, $update_query)) {
                    $success_message = "Comment updated successfully!";
                    
                    // Notify parent about update if comment changed
                    if ($original_text !== $comment) {
                        $teacher_query = "SELECT name FROM users WHERE id = '$teacher_id'";
                        $teacher_result = mysqli_query($conn, $teacher_query);
                        $teacher = mysqli_fetch_assoc($teacher_result);
                        $teacher_name = $teacher ? $teacher['name'] : 'Teacher';
                        
                        $student_query = "SELECT name FROM users WHERE id = '$student_id'";
                        $student_result = mysqli_query($conn, $student_query);
                        $student = mysqli_fetch_assoc($student_result);
                        $student_name = $student ? $student['name'] : 'Your child';
                        
                        $parent_query = "SELECT p.user_id FROM parents p 
                                        INNER JOIN parent_student_mapping psm ON p.id = psm.parent_id 
                                        WHERE psm.student_id = '$student_id'";
                        $parent_result = mysqli_query($conn, $parent_query);
                        
                        if ($parent_result) {
                            while ($parent = mysqli_fetch_assoc($parent_result)) {
                                $notification_query = "INSERT INTO notifications 
                                                      (user_id, title, message, type, category, link, created_at) 
                                                      VALUES ('{$parent['user_id']}', 
                                                             'Behavior Comment Updated', 
                                                             'Teacher {$teacher_name} has updated a behavior comment for {$student_name}', 
                                                             'behavior', 
                                                             'academic', 
                                                             'behavior_comments.php', 
                                                             NOW())";
                                mysqli_query($conn, $notification_query);
                            }
                        }
                    }
                } else {
                    $error_message = "Error updating comment: " . mysqli_error($conn);
                }
            } else {
                $error_message = "Comment not found or you don't have permission to edit it.";
            }
        } elseif ($_POST['action'] === 'delete_comment') {
            $comment_id = mysqli_real_escape_string($conn, $_POST['comment_id']);
            
            // Get student info before deleting
            $comment_query = "SELECT user_id FROM behavior_comments WHERE id = '$comment_id' AND created_by = '$teacher_id'";
            $comment_result = mysqli_query($conn, $comment_query);
            
            if ($comment_result && $comment_data = mysqli_fetch_assoc($comment_result)) {
                $student_id = $comment_data['user_id'];
                
                $delete_query = "DELETE FROM behavior_comments 
                                WHERE id = '$comment_id' AND created_by = '$teacher_id'";
                
                if (mysqli_query($conn, $delete_query)) {
                    $success_message = "Comment deleted successfully!";
                    
                    // Notify parent about deletion
                    $teacher_query = "SELECT name FROM users WHERE id = '$teacher_id'";
                    $teacher_result = mysqli_query($conn, $teacher_query);
                    $teacher = mysqli_fetch_assoc($teacher_result);
                    $teacher_name = $teacher ? $teacher['name'] : 'Teacher';
                    
                    $student_query = "SELECT name FROM users WHERE id = '$student_id'";
                    $student_result = mysqli_query($conn, $student_query);
                    $student = mysqli_fetch_assoc($student_result);
                    $student_name = $student ? $student['name'] : 'Your child';
                    
                    $parent_query = "SELECT p.user_id FROM parents p 
                                    INNER JOIN parent_student_mapping psm ON p.id = psm.parent_id 
                                    WHERE psm.student_id = '$student_id'";
                    $parent_result = mysqli_query($conn, $parent_query);
                    
                    if ($parent_result) {
                        while ($parent = mysqli_fetch_assoc($parent_result)) {
                            $notification_query = "INSERT INTO notifications 
                                                  (user_id, title, message, type, category, link, created_at) 
                                                  VALUES ('{$parent['user_id']}', 
                                                         'Behavior Comment Removed', 
                                                         'Teacher {$teacher_name} has removed a behavior comment for {$student_name}', 
                                                         'behavior', 
                                                         'academic', 
                                                         'behavior_comments.php', 
                                                         NOW())";
                            mysqli_query($conn, $notification_query);
                        }
                    }
                } else {
                    $error_message = "Error deleting comment: " . mysqli_error($conn);
                }
            } else {
                $error_message = "Comment not found or you don't have permission to delete it.";
            }
        } elseif ($_POST['action'] === 'mark_as_read') {
            $comment_id = mysqli_real_escape_string($conn, $_POST['comment_id']);
            $update_query = "UPDATE behavior_comments SET is_read = 1 WHERE id = '$comment_id'";
            mysqli_query($conn, $update_query);
            echo json_encode(['success' => true]);
            exit();
        }
    }
}

// Get teacher's classes
$classes_query = "SELECT DISTINCT c.id, c.class_name 
                 FROM classes c
                 LEFT JOIN class_schedules cs ON c.id = cs.class_id
                 WHERE c.teacher_id = '$teacher_id' 
                 OR c.class_teacher_id = '$teacher_id'
                 OR cs.teacher_id = '$teacher_id'
                 ORDER BY c.class_name";
$classes_result = mysqli_query($conn, $classes_query);

// Get active terms
$terms_query = "SELECT id, term_name, term_number FROM academic_terms 
                WHERE is_active = 1 
                ORDER BY term_number";
$terms_result = mysqli_query($conn, $terms_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Behavior Comments - Teacher Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Theme Variables */
        :root {
            --bg-primary: #f5f7fb;
            --bg-secondary: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --card-shadow: 0 2px 10px rgba(0,0,0,0.05);
            --sidebar-bg: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            --accent-color: #f97316;
            --accent-hover: #ea580c;
            --stat-bg: #ffffff;
        }
        
        body.dark-mode {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --border-color: #334155;
            --card-shadow: 0 2px 10px rgba(0,0,0,0.3);
            --stat-bg: #1e293b;
        }
        
        body {
            background-color: var(--bg-primary);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-primary);
            transition: background-color 0.3s, color 0.3s;
        }
        
        /* Sidebar */
        .sidebar {
            background: var(--sidebar-bg);
            min-height: 100vh;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.9);
            padding: 12px 20px;
            margin: 5px 15px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover {
            background: rgba(255,255,255,0.1);
            transform: translateX(5px);
        }
        
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
        }
        
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.2);
            font-weight: bold;
        }
        
        /* Main Content */
        .main-content {
            padding: 20px;
        }
        
        /* Theme Toggle Button */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--accent-color);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .theme-toggle:hover {
            transform: scale(1.1);
            background: var(--accent-hover);
        }
        
        /* Stat Cards */
        .stat-card {
            background: var(--stat-bg);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s, background-color 0.3s;
            border: 1px solid var(--border-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: var(--accent-color);
        }
        
        /* Behavior Cards */
        .behavior-card {
            border-left: 4px solid var(--accent-color);
            margin-bottom: 20px;
            transition: all 0.3s;
            box-shadow: var(--card-shadow);
            background: var(--bg-secondary);
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }
        
        .behavior-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .card-title {
            color: var(--text-primary);
        }
        
        .text-muted {
            color: var(--text-secondary) !important;
        }
        
        .comment-box {
            background: rgba(249, 115, 22, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
            border-left: 3px solid var(--accent-color);
        }
        
        /* Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-read {
            background: #10b98120;
            color: #10b981;
        }
        
        .status-unread {
            background: #f9731620;
            color: var(--accent-color);
        }
        
        /* Buttons */
        .btn-gradient {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: white;
            border: none;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.4);
            color: white;
        }
        
        .btn-outline-accent {
            border-color: var(--accent-color);
            color: var(--accent-color);
        }
        
        .btn-outline-accent:hover {
            background: var(--accent-color);
            color: white;
        }
        
        /* Filter Section */
        .filter-section {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
        }
        
        /* Form Controls */
        .form-control, .form-select {
            background-color: var(--bg-secondary);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--bg-secondary);
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.2rem rgba(249, 115, 22, 0.25);
        }
        
        /* Modal */
        .modal-content {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
        }
        
        .modal-header {
            border-bottom-color: var(--border-color);
        }
        
        .modal-footer {
            border-top-color: var(--border-color);
        }
        
        /* Alert */
        .alert {
            border-radius: 10px;
        }
        
        /* Action Buttons */
        .action-buttons {
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .behavior-card:hover .action-buttons {
            opacity: 1;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                margin-bottom: 20px;
            }
            .action-buttons {
                opacity: 1;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .behavior-card {
            animation: fadeIn 0.5s ease-out;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-secondary);
            border-radius: 15px;
            border: 1px solid var(--border-color);
        }
        
        .empty-state i {
            font-size: 64px;
            color: var(--accent-color);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 d-none d-md-block sidebar">
                <div class="position-sticky pt-4">
                    <div class="text-center mb-4">
                        <i class="fas fa-chalkboard-teacher fa-3x text-white mb-2"></i>
                        <h4 class="text-white">Teacher Portal</h4>
                        <hr class="bg-white">
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="manage_behavior_comments.php">
                                <i class="fas fa-comment-dots"></i> Behavior Comments
                            </a>
                        </li>
                       
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../logout.php">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-10 ms-sm-auto px-md-4 main-content">
                <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-comment-dots" style="color: var(--accent-color);"></i> Manage Behavior Comments
                    </h1>
                    <div>
                        <button type="button" class="btn btn-gradient me-2" data-bs-toggle="modal" data-bs-target="#addCommentModal">
                            <i class="fas fa-plus"></i> New Comment
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <i class="fas fa-comments fa-3x" style="color: var(--accent-color); margin-bottom: 10px;"></i>
                            <div class="stat-number" id="total-comments">0</div>
                            <div class="text-muted">Total Comments</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <i class="fas fa-envelope fa-3x" style="color: var(--accent-color); margin-bottom: 10px;"></i>
                            <div class="stat-number" id="unread-comments">0</div>
                            <div class="text-muted">Unread Comments</div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <i class="fas fa-check-circle fa-3x" style="color: var(--accent-color); margin-bottom: 10px;"></i>
                            <div class="stat-number" id="read-comments">0</div>
                            <div class="text-muted">Read by Parents</div>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h5><i class="fas fa-filter" style="color: var(--accent-color);"></i> Filter Comments</h5>
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="class_filter" class="form-label">Class</label>
                            <select name="class_id" id="class_filter" class="form-select">
                                <option value="">All Classes</option>
                                <?php 
                                if ($classes_result && mysqli_num_rows($classes_result) > 0) {
                                    mysqli_data_seek($classes_result, 0);
                                    while ($class = mysqli_fetch_assoc($classes_result)) {
                                        $selected = (isset($_GET['class_id']) && $_GET['class_id'] == $class['id']) ? 'selected' : '';
                                        echo "<option value='{$class['id']}' $selected>" . htmlspecialchars($class['class_name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="student_filter" class="form-label">Student</label>
                            <select name="student_id" id="student_filter" class="form-select">
                                <option value="">All Students</option>
                                <?php
                                if (isset($_GET['class_id']) && !empty($_GET['class_id'])) {
                                    $class_id = mysqli_real_escape_string($conn, $_GET['class_id']);
                                    $students_query = "SELECT u.id, u.name 
                                                     FROM users u
                                                     INNER JOIN student_classes sc ON u.id = sc.user_id
                                                     WHERE sc.class_id = '$class_id' 
                                                     AND u.role = 'student'
                                                     ORDER BY u.name";
                                    $students_result = mysqli_query($conn, $students_query);
                                    if ($students_result) {
                                        while ($student = mysqli_fetch_assoc($students_result)) {
                                            $selected = (isset($_GET['student_id']) && $_GET['student_id'] == $student['id']) ? 'selected' : '';
                                            echo "<option value='{$student['id']}' $selected>" . htmlspecialchars($student['name']) . "</option>";
                                        }
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="status_filter" class="form-label">Status</label>
                            <select name="status" id="status_filter" class="form-select">
                                <option value="">All</option>
                                <option value="0" <?php echo (isset($_GET['status']) && $_GET['status'] === '0') ? 'selected' : ''; ?>>Unread</option>
                                <option value="1" <?php echo (isset($_GET['status']) && $_GET['status'] === '1') ? 'selected' : ''; ?>>Read</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-gradient">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <a href="manage_behavior_comments.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-sync"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Comments List -->
                <div class="row">
                    <?php
                    // Build query based on filters
                    $where_clauses = ["bc.created_by = '$teacher_id'"];
                    
                    if (isset($_GET['class_id']) && !empty($_GET['class_id'])) {
                        $class_id = mysqli_real_escape_string($conn, $_GET['class_id']);
                        $where_clauses[] = "bc.class_id = '$class_id'";
                    }
                    
                    if (isset($_GET['student_id']) && !empty($_GET['student_id'])) {
                        $student_id = mysqli_real_escape_string($conn, $_GET['student_id']);
                        $where_clauses[] = "bc.user_id = '$student_id'";
                    }
                    
                    if (isset($_GET['status']) && $_GET['status'] !== '') {
                        $status = mysqli_real_escape_string($conn, $_GET['status']);
                        $where_clauses[] = "bc.is_read = '$status'";
                    }
                    
                    $where_sql = implode(" AND ", $where_clauses);
                    
                    $comments_query = "SELECT bc.*, u.name as student_name, c.class_name,
                                              CASE WHEN bc.is_read = 1 THEN 'Read' ELSE 'Unread' END as read_status,
                                              DATE_FORMAT(bc.created_at, '%M %d, %Y %h:%i %p') as formatted_date
                                       FROM behavior_comments bc
                                       INNER JOIN users u ON bc.user_id = u.id
                                       INNER JOIN classes c ON bc.class_id = c.id
                                       WHERE $where_sql
                                       ORDER BY bc.is_read ASC, bc.created_at DESC";
                    
                    $comments_result = mysqli_query($conn, $comments_query);
                    
                    if ($comments_result && mysqli_num_rows($comments_result) > 0) {
                        while ($comment = mysqli_fetch_assoc($comments_result)) {
                            ?>
                            <div class="col-md-6">
                                <div class="card behavior-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="card-title mb-1">
                                                    <i class="fas fa-user-graduate" style="color: var(--accent-color);"></i> 
                                                    <?php echo htmlspecialchars($comment['student_name']); ?>
                                                </h5>
                                                <p class="text-muted mb-0">
                                                    <i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($comment['class_name']); ?>
                                                    <br>
                                                    <small><i class="fas fa-tag"></i> <?php echo htmlspecialchars($comment['term']); ?></small>
                                                </p>
                                            </div>
                                            <span class="status-badge <?php echo $comment['is_read'] ? 'status-read' : 'status-unread'; ?>">
                                                <i class="fas <?php echo $comment['is_read'] ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                                <?php echo $comment['read_status']; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="comment-box">
                                            <p class="card-text mb-0"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                        </div>
                                        
                                        <div class="mt-3 d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <i class="far fa-calendar-alt"></i> <?php echo $comment['formatted_date']; ?>
                                            </small>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-outline-accent edit-comment" 
                                                        data-id="<?php echo $comment['id']; ?>" 
                                                        data-comment="<?php echo htmlspecialchars($comment['comment']); ?>">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger delete-comment" 
                                                        data-id="<?php echo $comment['id']; ?>"
                                                        data-student="<?php echo htmlspecialchars($comment['student_name']); ?>">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        ?>
                        <div class="col-12">
                            <div class="empty-state">
                                <i class="fas fa-comment-slash"></i>
                                <h4>No Behavior Comments Found</h4>
                                <p class="text-muted">Click the "New Comment" button to send your first behavior comment to parents.</p>
                                <button type="button" class="btn btn-gradient mt-3" data-bs-toggle="modal" data-bs-target="#addCommentModal">
                                    <i class="fas fa-plus"></i> Create Your First Comment
                                </button>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Theme Toggle Button -->
    <button class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon"></i>
    </button>

    <!-- Add Comment Modal -->
    <div class="modal fade" id="addCommentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-comment-medical"></i> Add New Behavior Comment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_comment">
                        
                        <div class="mb-3">
                            <label for="modal_class_id" class="form-label">Select Class <span class="text-danger">*</span></label>
                            <select name="class_id" id="modal_class_id" class="form-select" required>
                                <option value="">Choose class...</option>
                                <?php
                                if ($classes_result && mysqli_num_rows($classes_result) > 0) {
                                    mysqli_data_seek($classes_result, 0);
                                    while ($class = mysqli_fetch_assoc($classes_result)) {
                                        echo "<option value='{$class['id']}'>" . htmlspecialchars($class['class_name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="modal_student_id" class="form-label">Select Student <span class="text-danger">*</span></label>
                            <select name="student_id" id="modal_student_id" class="form-select" required disabled>
                                <option value="">First select a class</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="modal_term_id" class="form-label">Select Term <span class="text-danger">*</span></label>
                            <select name="term_id" id="modal_term_id" class="form-select" required>
                                <option value="">Select term...</option>
                                <?php
                                if ($terms_result && mysqli_num_rows($terms_result) > 0) {
                                    mysqli_data_seek($terms_result, 0);
                                    while ($term = mysqli_fetch_assoc($terms_result)) {
                                        echo "<option value='{$term['id']}'>" . htmlspecialchars($term['term_name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="comment" class="form-label">Behavior Comment <span class="text-danger">*</span></label>
                            <textarea name="comment" id="comment" rows="5" class="form-control" 
                                      placeholder="Describe the student's behavior in detail. Be specific and constructive. Include both positive behaviors and areas for improvement." required></textarea>
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                This comment will be visible to parents and the student.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient">
                            <i class="fas fa-paper-plane"></i> Send Comment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Comment Modal -->
    <div class="modal fade" id="editCommentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Edit Comment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_comment">
                        <input type="hidden" name="comment_id" id="edit_comment_id">
                        <textarea name="comment" id="edit_comment" rows="5" class="form-control" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient">Update Comment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteCommentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle"></i> Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_comment">
                        <input type="hidden" name="comment_id" id="delete_comment_id">
                        <p>Are you sure you want to delete this behavior comment?</p>
                        <p class="text-danger">This action cannot be undone. Parents will be notified of the deletion.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Comment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Theme Toggle
            function initTheme() {
                const savedTheme = localStorage.getItem('theme');
                if (savedTheme === 'dark') {
                    document.body.classList.add('dark-mode');
                    $('#themeToggle i').removeClass('fa-moon').addClass('fa-sun');
                }
            }
            
            initTheme();
            
            $('#themeToggle').click(function() {
                document.body.classList.toggle('dark-mode');
                if (document.body.classList.contains('dark-mode')) {
                    localStorage.setItem('theme', 'dark');
                    $(this).find('i').removeClass('fa-moon').addClass('fa-sun');
                } else {
                    localStorage.setItem('theme', 'light');
                    $(this).find('i').removeClass('fa-sun').addClass('fa-moon');
                }
            });
            
            // Load statistics
            function loadStats() {
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: { ajax_action: 'get_comment_stats' },
                    dataType: 'json',
                    success: function(stats) {
                        $('#total-comments').text(stats.total_comments || 0);
                        $('#unread-comments').text(stats.unread_count || 0);
                        $('#read-comments').text(stats.read_count || 0);
                    },
                    error: function() {
                        console.log('Error loading statistics');
                    }
                });
            }
            loadStats();
            
            // Load students when class is selected
            $('#modal_class_id').change(function() {
                var classId = $(this).val();
                if (classId) {
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'get_students',
                            class_id: classId
                        },
                        success: function(data) {
                            $('#modal_student_id').html(data);
                            $('#modal_student_id').prop('disabled', false);
                        }
                    });
                } else {
                    $('#modal_student_id').html('<option value="">First select a class</option>');
                    $('#modal_student_id').prop('disabled', true);
                }
            });
            
            // Load students for filter
            $('#class_filter').change(function() {
                var classId = $(this).val();
                if (classId) {
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'get_students',
                            class_id: classId
                        },
                        success: function(data) {
                            $('#student_filter').html('<option value="">All Students</option>' + data);
                        }
                    });
                } else {
                    $('#student_filter').html('<option value="">All Students</option>');
                }
            });
            
            // Edit comment
            $(document).on('click', '.edit-comment', function() {
                $('#edit_comment_id').val($(this).data('id'));
                $('#edit_comment').val($(this).data('comment'));
                $('#editCommentModal').modal('show');
            });
            
            // Delete comment
            $(document).on('click', '.delete-comment', function() {
                $('#delete_comment_id').val($(this).data('id'));
                $('#deleteCommentModal').modal('show');
            });
            
            // Auto refresh stats every 30 seconds
            setInterval(loadStats, 30000);
            
            // Trigger class filter change if class is preselected
            if ($('#class_filter').val()) {
                $('#class_filter').trigger('change');
            }
        });
    </script>
</body>
</html>