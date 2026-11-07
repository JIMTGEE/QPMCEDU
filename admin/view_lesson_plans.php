<?php
// Suppress display errors, log them instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/logs/php_errors.log');

ob_start();
session_start();

// Restrict to admins
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . 
              ", user_role=" . ($_SESSION['user_role'] ?? 'unset') . 
              ", requested_url=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    $_SESSION['error_msg'] = "Please log in as an administrator.";
    header("Location: /admin/login.php");
    exit();
}

// Include database connection and functions
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
    require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');
} catch (Exception $e) {
    error_log("Failed to include required files: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to initialize.";
    header("Location: /admin/login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /admin/login.php");
    exit();
}

$admin_id = (int)$_SESSION['user_id'];
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$csrf_token = isset($_GET['csrf_token']) ? trim($_GET['csrf_token']) : '';

// Validate CSRF token
$csrf_valid = false;
if (!empty($csrf_token)) {
    $csrf_sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_stmt = $conn->prepare($csrf_sql)) {
        $csrf_stmt->bind_param("is", $admin_id, $csrf_token);
        $csrf_stmt->execute();
        $csrf_valid = $csrf_stmt->get_result()->num_rows > 0;
        $csrf_stmt->close();
    } else {
        error_log("CSRF query preparation failed: " . $conn->error);
    }
}

if (!$csrf_valid) {
    error_log("Invalid CSRF token for user_id=$admin_id, token=$csrf_token");
    $_SESSION['error_msg'] = "Invalid session. Please try again.";
}

// Validate teacher_id
$teacher_sql = "SELECT id, name FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Invalid teacher_id=$teacher_id for user_id=$admin_id");
    $_SESSION['error_msg'] = "Teacher not found.";
    header("Location: /admin/lesson_schedule.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Lesson Plans', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Admin viewed lesson plans for teacher ID $teacher_id";
$log_stmt->bind_param("isss", $admin_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log page access: " . $log_stmt->error);
}
$log_stmt->close();

// Handle AJAX request for lesson plans
if (isset($_GET['action']) && $_GET['action'] === 'get_lesson_plans') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    $filter_week = isset($_GET['week']) ? (int)$_GET['week'] : 0;
    $filter_term_id = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
    $filter_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;

    $response = ['lesson_plans' => [], 'total_pages' => 1, 'error' => '', 'debug' => ''];

    try {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM teacher_notes tn 
                      LEFT JOIN classes c ON tn.class_id = c.id 
                      WHERE tn.teacher_id = ?";
        $params = [$teacher_id];
        $types = "i";
        $conditions = [];

        if ($filter_week > 0) {
            $conditions[] = "tn.week = ?";
            $params[] = $filter_week;
            $types .= "i";
        }
        if ($filter_term_id > 0) {
            $conditions[] = "tn.term_id = ?";
            $params[] = $filter_term_id;
            $types .= "i";
        }
        if ($filter_class_id > 0) {
            $conditions[] = "tn.class_id = ?";
            $params[] = $filter_class_id;
            $types .= "i";
        }

        if ($conditions) {
            $count_sql .= " AND " . implode(" AND ", $conditions);
        }

        $count_stmt = $conn->prepare($count_sql);
        if (!$count_stmt) {
            throw new Exception("Count query preparation failed: " . $conn->error);
        }
        $count_stmt->bind_param($types, ...$params);
        if (!$count_stmt->execute()) {
            throw new Exception("Count query execution failed: " . $count_stmt->error);
        }
        $total = $count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();

        $total_pages = ceil($total / $per_page);
        $response['total_pages'] = $total_pages;

        $plans_sql = "SELECT tn.id, tn.week, COALESCE(c.class_name, 'N/A') as class_name, tn.term_id, 
                             tn.strand, tn.sub_strand, tn.content_standard, tn.indicator, 
                             tn.reference, tn.resources, tn.keywords, tn.starter, 
                             tn.presentation, tn.summary, tn.created_at 
                      FROM teacher_notes tn 
                      LEFT JOIN classes c ON tn.class_id = c.id 
                      WHERE tn.teacher_id = ?";
        if ($conditions) {
            $plans_sql .= " AND " . implode(" AND ", $conditions);
        }
        $plans_sql .= " ORDER BY tn.created_at DESC LIMIT ?, ?";
        $params[] = $offset;
        $params[] = $per_page;
        $types .= "ii";

        $plans_stmt = $conn->prepare($plans_sql);
        if (!$plans_stmt) {
            throw new Exception("Plans query preparation failed: " . $conn->error);
        }
        $plans_stmt->bind_param($types, ...$params);
        if (!$plans_stmt->execute()) {
            throw new Exception("Plans query execution failed: " . $plans_stmt->error);
        }
        $result = $plans_stmt->get_result();
        $lesson_plans = $result->fetch_all(MYSQLI_ASSOC);
        $plans_stmt->close();

        $response['lesson_plans'] = $lesson_plans;
        $response['debug'] = "Fetched $total lesson plans for teacher_id=$teacher_id, page=$page, filters: week=$filter_week, term_id=$filter_term_id, class_id=$filter_class_id";
        error_log($response['debug'] . ", plans_count=" . count($lesson_plans));
    } catch (Exception $e) {
        $response['error'] = "Failed to load lesson plans.";
        $response['debug'] = "Exception: " . $e->getMessage();
        error_log("Lesson plans query error for teacher_id=$teacher_id: " . $e->getMessage());
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response);
    ob_end_flush();
    exit();
}

// Handle AJAX request for sending notification
if (isset($_POST['action']) && $_POST['action'] === 'send_notification') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    $response = ['success' => false, 'error' => '', 'debug' => '', 'new_csrf_token' => ''];
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $lesson_id = (int)($_POST['lesson_id'] ?? 0);
    $new_csrf_token = trim($_POST['csrf_token'] ?? '');

    error_log("Notification attempt: admin_id=$admin_id, teacher_id=$teacher_id, lesson_id=$lesson_id, title='$title', type='$type', message_length=" . strlen($message));

    // Validate CSRF token
    $csrf_check_sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if (!$csrf_check_stmt = $conn->prepare($csrf_check_sql)) {
        $response['error'] = 'Server error: CSRF validation failed.';
        $response['debug'] = "CSRF query preparation failed: " . $conn->error;
        error_log($response['debug']);
    } elseif (!$csrf_check_stmt->bind_param("is", $admin_id, $new_csrf_token)) {
        $response['error'] = 'Server error: CSRF binding failed.';
        $response['debug'] = "CSRF bind error: " . $conn->error;
        error_log($response['debug']);
    } elseif (!$csrf_check_stmt->execute()) {
        $response['error'] = 'Server error: CSRF execution failed.';
        $response['debug'] = "CSRF execute error: " . $conn->error;
        error_log($response['debug']);
    } else {
        $csrf_valid = $csrf_check_stmt->get_result()->num_rows > 0;
        $csrf_check_stmt->close();

        if (!$csrf_valid) {
            $response['error'] = 'Invalid or expired CSRF token.';
            $response['debug'] = "CSRF token validation failed for user_id=$admin_id, token=$new_csrf_token";
            error_log($response['debug']);
        } elseif (empty($title) || strlen($title) > 100) {
            $response['error'] = 'Title is required and must be 100 characters or less.';
            $response['debug'] = "Title validation failed: length=" . strlen($title);
        } elseif (empty($message) || strlen($message) > 1000) {
            $response['error'] = 'Message is required and must be 1000 characters or less.';
            $response['debug'] = "Message validation failed: length=" . strlen($message);
        } elseif (!in_array($type, ['info', 'warning', 'alert', 'reminder'])) {
            $response['error'] = 'Invalid notification type.';
            $response['debug'] = "Type validation failed: type=$type";
        } elseif ($lesson_id <= 0) {
            $response['error'] = 'Invalid lesson plan ID.';
            $response['debug'] = "Lesson ID validation failed: lesson_id=$lesson_id";
        } else {
            // Verify lesson plan belongs to teacher
            $lesson_sql = "SELECT tn.id FROM teacher_notes tn WHERE tn.id = ? AND tn.teacher_id = ?";
            if (!$lesson_stmt = $conn->prepare($lesson_sql)) {
                $response['error'] = 'Server error: Lesson validation failed.';
                $response['debug'] = "Lesson query preparation failed: " . $conn->error;
                error_log($response['debug']);
            } elseif (!$lesson_stmt->bind_param("ii", $lesson_id, $teacher_id)) {
                $response['error'] = 'Server error: Lesson binding failed.';
                $response['debug'] = "Lesson bind error: " . $conn->error;
                error_log($response['debug']);
            } elseif (!$lesson_stmt->execute()) {
                $response['error'] = 'Server error: Lesson execution failed.';
                $response['debug'] = "Lesson execute error: " . $conn->error;
                error_log($response['debug']);
            } else {
                $lesson = $lesson_stmt->get_result()->fetch_assoc();
                $lesson_stmt->close();

                if (!$lesson) {
                    $response['error'] = 'Lesson plan not found or does not belong to this teacher.';
                    $response['debug'] = "Lesson validation failed: lesson_id=$lesson_id, teacher_id=$teacher_id";
                    error_log($response['debug']);
                } else {
                    // Insert notification
                    $insert_sql = "INSERT INTO notifications (user_id, target_role, title, message, type, is_read, created_at) 
                                   VALUES (?, 'teacher', ?, ?, ?, 0, NOW())";
                    if (!$insert_stmt = $conn->prepare($insert_sql)) {
                        $response['error'] = 'Server error: Notification insert failed.';
                        $response['debug'] = "Notification insert query preparation failed: " . $conn->error;
                        error_log($response['debug']);
                    } elseif (!$insert_stmt->bind_param("isss", $teacher_id, $title, $message, $type)) {
                        $response['error'] = 'Server error: Notification binding failed.';
                        $response['debug'] = "Notification bind error: " . $conn->error;
                        error_log($response['debug']);
                    } elseif (!$insert_stmt->execute()) {
                        $response['error'] = 'Server error: Notification execution failed.';
                        $response['debug'] = "Notification insert query execution failed: " . $insert_stmt->error;
                        error_log($response['debug']);
                    } else {
                        $notification_id = $conn->insert_id;
                        $insert_stmt->close();

                        // Log action
                        $action = "Sent Notification";
                        $description = "Notification ID: $notification_id, Title: $title, Type: $type, Target: Teacher ID $teacher_id, Lesson ID: $lesson_id";
                        logActivity($conn, $admin_id, $action, $description);

                        // Generate new CSRF token
                        $new_csrf_token = bin2hex(random_bytes(32));
                        $csrf_insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
                                            VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
                        if ($csrf_insert_stmt = $conn->prepare($csrf_insert_sql)) {
                            $csrf_insert_stmt->bind_param("is", $admin_id, $new_csrf_token);
                            $csrf_insert_stmt->execute();
                            $csrf_insert_stmt->close();
                        } else {
                            error_log("CSRF token insert failed: " . $conn->error);
                        }

                        $response['success'] = true;
                        $response['new_csrf_token'] = $new_csrf_token;
                        $response['debug'] = "Notification $notification_id sent for lesson_id=$lesson_id";
                        error_log($response['debug']);
                    }
                }
            }
        }
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response);
    ob_end_flush();
    exit();
}

// Fetch classes for filters
$classes_sql = "SELECT DISTINCT c.id, c.class_name 
                FROM classes c 
                INNER JOIN teacher_notes tn ON c.id = tn.class_id 
                WHERE tn.teacher_id = ? 
                ORDER BY c.class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $teacher_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

$terms_sql = "SELECT DISTINCT term_id 
              FROM teacher_notes 
              WHERE teacher_id = ? 
              ORDER BY term_id";
$terms_stmt = $conn->prepare($terms_sql);
$terms_stmt->bind_param("i", $teacher_id);
$terms_stmt->execute();
$terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$terms_stmt->close();

// Fetch summary stats
$stats_sql = "SELECT 
                (SELECT COUNT(*) 
                 FROM teacher_notes 
                 WHERE teacher_id = ?) as total_plans,
                (SELECT COUNT(DISTINCT term_id) 
                 FROM teacher_notes 
                 WHERE teacher_id = ?) as total_terms,
                (SELECT COUNT(DISTINCT class_id) 
                 FROM class_schedules 
                 WHERE teacher_id = ?) as total_classes";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("iii", $teacher_id, $teacher_id, $teacher_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Generate new CSRF token for notification form
$new_csrf_token = bin2hex(random_bytes(32));
$csrf_insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
                    VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_insert_stmt = $conn->prepare($csrf_insert_sql);
$csrf_insert_stmt->bind_param("is", $admin_id, $new_csrf_token);
$csrf_insert_stmt->execute();
$csrf_insert_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin view of teacher lesson plans with notification capabilities at QueensPa School.">
    <meta name="keywords" content="QueensPa School, lesson plans, admin dashboard, notifications">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Admin Lesson Plans">
    <meta property="og:description" content="Admin interface for viewing teacher lesson plans and sending notifications.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/view_lesson_plans.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Admin Lesson Plans">
    <meta name="twitter:description" content="Admin interface for viewing teacher lesson plans and sending notifications.">
    <title>View Lesson Plans - QueensPa School Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 1400px;
            padding: 2rem;
        }
        .card {
            border: none;
            border-radius: 16px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            border-radius: 16px 16px 0 0;
            padding: 1.5rem;
            color: #fff;
        }
        .card-header h2, .card-header h3 {
            margin: 0;
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            animation: animate__pulse 0.5s;
        }
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
            border-radius: 10px;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .form-select, .form-control {
            border-radius: 10px;
            border: 1px solid #ced4da;
            padding: 0.75rem;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.2);
            outline: none;
        }
        .table-responsive {
            border-radius: 10px;
            overflow-x: auto;
        }
        .table thead {
            background: var(--primary-color);
            color: #fff;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .pagination .page-link {
            color: var(--primary-color);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .stats-card {
            background: #f9f9f9;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.3s ease;
        }
        .stats-card:hover {
            transform: scale(1.05);
        }
        .stats-card h5 {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        .stats-card p {
            font-size: 1.2rem;
            color: var(--text-color);
        }
        .loading-spinner {
            display: none;
            color: var(--primary-color);
            margin-left: 10px;
        }
        .modal-content {
            border-radius: 16px;
            box-shadow: var(--shadow);
        }
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            color: #fff;
            border-radius: 16px 16px 0 0;
        }
        #loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        #loading-spinner {
            color: var(--primary-color);
            font-size: 2rem;
        }
        .character-counter {
            font-size: 0.85rem;
            color: #666;
        }
        .character-counter.warning {
            color: var(--primary-color);
        }
        @media (max-width: 991.98px) {
            .container {
                padding: 1rem;
            }
            .stats-card {
                margin-bottom: 1rem;
            }
        }
        @media (max-width: 575.98px) {
            .card-header h2, .card-header h3 {
                font-size: 1.5rem;
            }
            .stats-card h5 {
                font-size: 1.2rem;
            }
            .stats-card p {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div id="loading-overlay" role="status" aria-label="Loading">
        <i class="fas fa-spinner fa-spin" id="loading-spinner" aria-hidden="true"></i>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>
    <div class="container my-5">
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>
        <section class="card mb-5 animate__animated animate__fadeIn">
            <div class="card-header">
                <h2><i class="fas fa-book me-2"></i>Lesson Plans for <?php echo htmlspecialchars($teacher['name']); ?></h2>
            </div>
            <div class="card-body">
                <div class="row g-4 mb-4">
                    <div class="col-md-4">
                        <div class="stats-card animate__animated animate__fadeIn">
                            <h5>Total Lesson Plans</h5>
                            <p><?php echo $stats['total_plans']; ?></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card animate__animated animate__fadeIn">
                            <h5>Terms Covered</h5>
                            <p><?php echo $stats['total_terms']; ?></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card animate__animated animate__fadeIn">
                            <h5>Classes Taught</h5>
                            <p><?php echo $stats['total_classes']; ?></p>
                        </div>
                    </div>
                </div>
                <form class="filter-form mb-4" id="filter-form">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="filter_week" class="form-label">Filter by Week</label>
                            <input type="number" class="form-control" id="filter_week" name="week" min="1" max="52" placeholder="e.g., 1">
                        </div>
                        <div class="col-md-4">
                            <label for="filter_term_id" class="form-label">Filter by Term</label>
                            <select class="form-select" id="filter_term_id" name="term_id">
                                <option value="">All Terms</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo htmlspecialchars($term['term_id']); ?>">
                                        Term <?php echo htmlspecialchars($term['term_id']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="filter_class_id" class="form-label">Filter by Class</label>
                            <select class="form-select" id="filter_class_id" name="class_id">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['id']); ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary mt-3" data-bs-toggle="tooltip" title="Apply filters to lesson plans">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <span class="loading-spinner filter-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        </div>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Week</th>
                                <th>Class</th>
                                <th>Term</th>
                                <th>Strand</th>
                                <th>Sub-Strand</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="lesson-plans-table"></tbody>
                    </table>
                </div>
                <nav aria-label="Lesson plans pagination" id="pagination"></nav>
            </div>
        </section>
    </div>

    <!-- Notification Modal -->
    <div class="modal fade" id="notificationModal" tabindex="-1" aria-labelledby="notificationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationModalLabel"><i class="fas fa-paper-plane me-2"></i>Send Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="notification-form" novalidate>
                        <input type="hidden" name="action" value="send_notification">
                        <input type="hidden" name="lesson_id" id="notification_lesson_id">
                        <input type="hidden" name="csrf_token" id="notification_csrf_token" value="<?php echo htmlspecialchars($new_csrf_token); ?>">
                        <div class="mb-3">
                            <label for="notification_title" class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="notification_title" name="title" maxlength="100" required aria-describedby="title-counter">
                            <small id="title-counter" class="character-counter">0/100 characters</small>
                            <div class="invalid-feedback">Title is required and must be 100 characters or less.</div>
                        </div>
                        <div class="mb-3">
                            <label for="notification_message" class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="notification_message" name="message" rows="5" maxlength="1000" required aria-describedby="message-counter"></textarea>
                            <small id="message-counter" class="character-counter">0/1000 characters</small>
                            <div class="invalid-feedback">Message is required and must be 1000 characters or less.</div>
                        </div>
                        <div class="mb-3">
                            <label for="notification_type" class="form-label">Notification Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="notification_type" name="type" required>
                                <option value="" disabled selected>Select Type</option>
                                <option value="info">Info</option>
                                <option value="warning">Warning</option>
                                <option value="alert">Alert</option>
                                <option value="reminder">Reminder</option>
                            </select>
                            <div class="invalid-feedback">Please select a notification type.</div>
                        </div>
                        <div class="alert alert-success d-none" id="notification-success" role="alert">
                            Notification sent successfully!
                        </div>
                        <div class="alert alert-danger d-none" id="notification-error" role="alert"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
                    <button type="button" class="btn btn-primary" id="send-notification-btn" data-bs-toggle="tooltip" title="Send notification to teacher">
                        <i class="fas fa-paper-plane me-2"></i>Send
                    </button>
                    <span class="loading-spinner notification-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailsModalLabel"><i class="fas fa-book me-2"></i>Lesson Plan Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="details-content"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous"></script>
    <script>
        // Dependency check
        if (typeof jQuery === 'undefined') {
            console.error('jQuery is not loaded. Please check the CDN or network.');
        }
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap is not loaded. Please check the CDN or network.');
        }

        document.addEventListener('DOMContentLoaded', () => {
            console.log('DOM loaded, initializing scripts');
            document.getElementById('loading-overlay').style.display = 'none';

            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            // Auto-close alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            // Character counters
            const titleInput = document.getElementById('notification_title');
            const messageInput = document.getElementById('notification_message');
            const titleCounter = document.getElementById('title-counter');
            const messageCounter = document.getElementById('message-counter');

            function updateTitleCounter() {
                const length = titleInput.value.length;
                titleCounter.textContent = `${length}/100 characters`;
                titleCounter.classList.toggle('warning', length > 90);
            }

            function updateMessageCounter() {
                const length = messageInput.value.length;
                messageCounter.textContent = `${length}/1000 characters`;
                messageCounter.classList.toggle('warning', length > 900);
            }

            titleInput.addEventListener('input', updateTitleCounter);
            messageInput.addEventListener('input', updateMessageCounter);

            // Form validation
            const form = document.getElementById('notification-form');
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                if (!form.checkValidity()) {
                    form.classList.add('was-validated');
                    return;
                }
            });

            // Notification form submission
            document.getElementById('send-notification-btn').addEventListener('click', () => {
                if (!form.checkValidity()) {
                    form.classList.add('was-validated');
                    return;
                }

                const spinner = document.querySelector('.notification-spinner');
                const successAlert = document.getElementById('notification-success');
                const errorAlert = document.getElementById('notification-error');
                const sendButton = document.getElementById('send-notification-btn');

                successAlert.classList.add('d-none');
                errorAlert.classList.add('d-none');
                spinner.style.display = 'inline-block';
                sendButton.disabled = true;

                console.log('Sending notification:', {
                    title: titleInput.value,
                    message: messageInput.value,
                    type: document.getElementById('notification_type').value,
                    lesson_id: document.getElementById('notification_lesson_id').value
                });

                $.post('<?php echo $_SERVER['PHP_SELF'] . "?teacher_id=$teacher_id&csrf_token=$csrf_token"; ?>', 
                    $('#notification-form').serialize(),
                    function(response) {
                        spinner.style.display = 'none';
                        sendButton.disabled = false;
                        console.log('Notification response:', response);

                        if (response.success) {
                            successAlert.classList.remove('d-none');
                            successAlert.textContent = 'Notification sent successfully!';
                            form.reset();
                            form.classList.remove('was-validated');
                            updateTitleCounter();
                            updateMessageCounter();
                            if (response.new_csrf_token) {
                                document.getElementById('notification_csrf_token').value = response.new_csrf_token;
                            }
                            setTimeout(() => {
                                $('#notificationModal').modal('hide');
                                successAlert.classList.add('d-none');
                            }, 2000);
                        } else {
                            errorAlert.classList.remove('d-none');
                            errorAlert.textContent = response.error + ' (Debug: ' + response.debug + ')';
                        }
                    },
                    'json'
                ).fail(function(xhr, status, error) {
                    spinner.style.display = 'none';
                    sendButton.disabled = false;
                    console.error('Notification AJAX error:', status, error, xhr.responseText);
                    errorAlert.classList.remove('d-none');
                    errorAlert.textContent = 'Error sending notification: ' + status + ' - ' + error;
                });
            });

            // Filter form submission
            document.getElementById('filter-form').addEventListener('submit', (e) => {
                e.preventDefault();
                loadLessonPlans(1);
            });

            // Load lesson plans
            loadLessonPlans(1);
        });

        function loadLessonPlans(page) {
            const tableBody = document.getElementById('lesson-plans-table');
            const pagination = document.getElementById('pagination');
            const spinner = document.querySelector('.filter-spinner');
            const week = document.getElementById('filter_week').value;
            const term_id = document.getElementById('filter_term_id').value;
            const class_id = document.getElementById('filter_class_id').value;

            spinner.style.display = 'inline-block';
            tableBody.innerHTML = '';
            pagination.innerHTML = '';

            $.get('<?php echo $_SERVER['PHP_SELF']; ?>?action=get_lesson_plans', {
                teacher_id: <?php echo $teacher_id; ?>,
                week: week,
                term_id: term_id,
                class_id: class_id,
                page: page,
                t: new Date().getTime()
            }, function(response) {
                spinner.style.display = 'none';
                console.log('Lesson plans response:', response);

                if (response.error) {
                    tableBody.innerHTML = `<tr><td colspan="7" class="text-center text-danger">
                        Error: ${response.error} (${response.debug})
                    </td></tr>`;
                } else if (response.lesson_plans.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="7" class="text-center">No lesson plans found.</td></tr>';
                } else {
                    response.lesson_plans.forEach(plan => {
                        const row = `
                            <tr>
                                <td>${plan.id}</td>
                                <td>${plan.week}</td>
                                <td>${plan.class_name}</td>
                                <td>Term ${plan.term_id}</td>
                                <td>${plan.strand || 'N/A'}</td>
                                <td>${plan.sub_strand || 'N/A'}</td>
                                <td>
                                    <button class="btn btn-info btn-sm view-details-btn" 
                                            data-id="${plan.id}" 
                                            data-bs-toggle="tooltip" 
                                            title="View lesson plan details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-primary btn-sm notify-btn" 
                                            data-id="${plan.id}" 
                                            data-week="${plan.week}" 
                                            data-class="${plan.class_name}" 
                                            data-term="${plan.term_id}" 
                                            data-bs-toggle="tooltip" 
                                            title="Send notification about this lesson plan">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </td>
                            </tr>`;
                        tableBody.insertAdjacentHTML('beforeend', row);
                    });

                    let paginationHtml = `
                        <ul class="pagination justify-content-center">
                            <li class="page-item ${page <= 1 ? 'disabled' : ''}">
                                <a class="page-link" href="#" data-page="${page - 1}" aria-label="Previous">
                                    <span aria-hidden="true">«</span>
                                </a>
                            </li>`;
                    for (let i = 1; i <= response.total_pages; i++) {
                        paginationHtml += `
                            <li class="page-item ${i === page ? 'active' : ''}">
                                <a class="page-link" href="#" data-page="${i}">${i}</a>
                            </li>`;
                    }
                    paginationHtml += `
                            <li class="page-item ${page >= response.total_pages ? 'disabled' : ''}">
                                <a class="page-link" href="#" data-page="${page + 1}" aria-label="Next">
                                    <span aria-hidden="true">»</span>
                                </a>
                            </li>
                        </ul>`;
                    pagination.innerHTML = paginationHtml;

                    // Re-initialize tooltips
                    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

                    // Pagination click handler
                    document.querySelectorAll('.page-link').forEach(link => {
                        link.addEventListener('click', (e) => {
                            e.preventDefault();
                            const newPage = link.getAttribute('data-page');
                            if (newPage) {
                                loadLessonPlans(newPage);
                            }
                        });
                    });

                    // View details button handler
                    document.querySelectorAll('.view-details-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const lessonId = btn.getAttribute('data-id');
                            const plan = response.lesson_plans.find(p => p.id == lessonId);
                            if (plan) {
                                const details = `
                                    <h5>Lesson Plan ID: ${plan.id}</h5>
                                    <p><strong>Week:</strong> ${plan.week}</p>
                                    <p><strong>Class:</strong> ${plan.class_name}</p>
                                    <p><strong>Term:</strong> Term ${plan.term_id}</p>
                                    <p><strong>Strand:</strong> ${plan.strand || 'N/A'}</p>
                                    <p><strong>Sub-Strand:</strong> ${plan.sub_strand || 'N/A'}</p>
                                    <p><strong>Content Standard:</strong> ${plan.content_standard || 'N/A'}</p>
                                    <p><strong>Indicator:</strong> ${plan.indicator || 'N/A'}</p>
                                    <p><strong>Reference:</strong> ${plan.reference || 'N/A'}</p>
                                    <p><strong>Resources:</strong> ${plan.resources || 'N/A'}</p>
                                    <p><strong>Keywords:</strong> ${plan.keywords || 'N/A'}</p>
                                    <p><strong>Starter:</strong> ${plan.starter || 'N/A'}</p>
                                    <p><strong>Presentation:</strong> ${plan.presentation || 'N/A'}</p>
                                    <p><strong>Summary:</strong> ${plan.summary || 'N/A'}</p>
                                    <p><strong>Created At:</strong> ${plan.created_at}</p>`;
                                document.getElementById('details-content').innerHTML = details;
                                $('#detailsModal').modal('show');
                            }
                        });
                    });
                }
            }, 'json').fail(function(xhr, status, error) {
                spinner.style.display = 'none';
                console.error('Lesson plans AJAX error:', status, error, xhr.responseText);
                tableBody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading lesson plans: ' + status + ' - ' + error + '</td></tr>';
            });

            // Notify button handler (event delegation)
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.notify-btn');
                if (btn) {
                    console.log('Notify button clicked:', btn.dataset);
                    const lessonId = btn.dataset.id;
                    const week = btn.dataset.week;
                    const className = btn.dataset.class;
                    const termId = btn.dataset.term;

                    document.getElementById('notification_lesson_id').value = lessonId;
                    document.getElementById('notification_title').value = `Feedback on Lesson Plan (Week ${week}, ${className})`;
                    document.getElementById('notification_message').value = `Dear <?php echo htmlspecialchars($teacher['name']); ?>,\n\nRegarding your lesson plan for Week ${week}, ${className}, Term ${termId}, please note the following feedback:\n\n[Enter your feedback here]\n\nBest regards,\nAdmin`;
                    document.getElementById('notification_type').value = '';
                    updateTitleCounter();
                    updateMessageCounter();

                    try {
                        $('#notificationModal').modal('show');
                        console.log('Notification modal triggered');
                    } catch (err) {
                        console.error('Error showing notification modal:', err);
                        alert('Error opening notification modal. Please check the console for details.');
                    }
                }
            });
        }
    </script>
</body>
</html>

<?php
mysqli_close($conn);
ob_end_flush();
?>