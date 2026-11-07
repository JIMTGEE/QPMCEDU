<?php
session_start();

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Initialize variables
$user_id = $_SESSION['user_id'];
$success_msg = $error_msg = "";
$notification_types = ['info', 'warning', 'alert', 'reminder'];
$filter_type = isset($_GET['filter_type']) && in_array($_GET['filter_type'], $notification_types) ? $_GET['filter_type'] : '';
$filter_status = isset($_GET['filter_status']) && in_array($_GET['filter_status'], ['all', 'read', 'unread']) ? $_GET['filter_status'] : 'all';
$sort_by = isset($_GET['sort_by']) && in_array($_GET['sort_by'], ['date_desc', 'date_asc', 'type']) ? $_GET['sort_by'] : 'date_desc';

// Handle mark as read (individual or all)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        if (isset($_POST['mark_all_read'])) {
            $update_sql = "UPDATE notifications 
                           SET is_read = 1 
                           WHERE is_read = 0 AND (user_id = ? OR target_role IN ('teacher', 'all'))";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $user_id);
            $update_stmt->execute();
            $affected_rows = $update_stmt->affected_rows;
            $update_stmt->close();

            $success_msg = "All notifications marked as read.";
            logActivity($conn, $user_id, "Marked All Notifications Read", "Marked $affected_rows notifications as read");
        } elseif (isset($_POST['mark_read']) && !empty($_POST['notification_id'])) {
            $notification_id = (int)$_POST['notification_id'];
            $check_sql = "SELECT id FROM notifications 
                          WHERE id = ? AND (user_id = ? OR target_role IN ('teacher', 'all'))";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $notification_id, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                $update_sql = "UPDATE notifications SET is_read = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $notification_id);
                $update_stmt->execute();
                $update_stmt->close();
                $success_msg = "Notification marked as read.";
                logActivity($conn, $user_id, "Marked Notification Read", "Notification ID: $notification_id");
            } else {
                $error_msg = "Invalid notification.";
            }
            $check_stmt->close();
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error updating notifications: " . $e->getMessage();
        error_log("Teacher notifications error: " . $e->getMessage());
    }
}

// Fetch unread notifications count
$unread_sql = "SELECT COUNT(*) as unread_count 
               FROM notifications 
               WHERE is_read = 0 AND (user_id = ? OR target_role IN ('teacher', 'all'))";
$unread_stmt = $conn->prepare($unread_sql);
$unread_stmt->bind_param("i", $user_id);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_count = $unread_result->fetch_assoc()['unread_count'];
$unread_stmt->close();

// Fetch notifications
$notifications_sql = "SELECT id, user_id, target_role, title, message, type, is_read, created_at 
                     FROM notifications 
                     WHERE user_id = ? OR target_role IN ('teacher', 'all')";
$params = [$user_id];
$types = "i";

// Apply filters
if ($filter_type) {
    $notifications_sql .= " AND type = ?";
    $params[] = $filter_type;
    $types .= "s";
}
if ($filter_status === 'read') {
    $notifications_sql .= " AND is_read = 1";
} elseif ($filter_status === 'unread') {
    $notifications_sql .= " AND is_read = 0";
}

// Apply sorting
if ($sort_by === 'date_asc') {
    $notifications_sql .= " ORDER BY created_at ASC";
} elseif ($sort_by === 'type') {
    $notifications_sql .= " ORDER BY type ASC, created_at DESC";
} else {
    $notifications_sql .= " ORDER BY created_at DESC";
}

$notifications_stmt = $conn->prepare($notifications_sql);
if (!empty($params)) {
    $notifications_stmt->bind_param($types, ...$params);
}
$notifications_stmt->execute();
$notifications_result = $notifications_stmt->get_result();
$notifications = $notifications_result->fetch_all(MYSQLI_ASSOC);
$notifications_stmt->close();

// Log page view
logActivity($conn, $user_id, "Viewed Notifications", "Teacher viewed notifications page");

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher notifications center for viewing admin notifications in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, notifications, teacher dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Teacher Notifications">
    <meta property="og:description" content="Teacher interface for viewing notifications in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/notifications.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Teacher Notifications">
    <meta name="twitter:description" content="Teacher interface for viewing notifications in QueensPa School.">
    <title>Notifications - QueensPa School Teacher Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-success {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-success:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
        }
        .table thead {
            background: var(--primary-color);
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .badge-info { background-color: #17a2b8; }
        .badge-warning { background-color: #ffc107; }
        .badge-danger { background-color: #dc3545; }
        .badge-primary { background-color: #007bff; }
        .unread { font-weight: bold; background-color: #fff3cd; }
        .filter-form { max-width: 600px; }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0">📬 Notifications</h3>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge bg-danger"><?php echo $unread_count; ?> Unread</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <form class="filter-form mb-3" method="GET">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="filter_type" class="form-label">Filter by Type</label>
                                <select class="form-select" id="filter_type" name="filter_type">
                                    <option value="">All Types</option>
                                    <?php foreach ($notification_types as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="filter_status" class="form-label">Filter by Status</label>
                                <select class="form-select" id="filter_status" name="filter_status">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="read" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Read</option>
                                    <option value="unread" <?php echo $filter_status === 'unread' ? 'selected' : ''; ?>>Unread</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="sort_by" class="form-label">Sort By</label>
                                <select class="form-select" id="sort_by" name="sort_by">
                                    <option value="date_desc" <?php echo $sort_by === 'date_desc' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="date_asc" <?php echo $sort_by === 'date_asc' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="type" <?php echo $sort_by === 'type' ? 'selected' : ''; ?>>Type</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-filter"></i> Apply Filters</button>
                            </div>
                        </div>
                    </form>
                    <form method="POST" class="mb-3">
                        <button type="submit" name="mark_all_read" class="btn btn-success" <?php echo $unread_count == 0 ? 'disabled' : ''; ?>><i class="fas fa-check-double"></i> Mark All as Read</button>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Message</th>
                                    <th>Type</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th>Sent At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($notifications)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No notifications found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($notifications as $note): ?>
                                        <tr class="<?php echo $note['is_read'] == 0 ? 'unread' : ''; ?>">
                                            <td><?php echo $note['id']; ?></td>
                                            <td><?php echo htmlspecialchars($note['title']); ?></td>
                                            <td><?php echo htmlspecialchars($note['message']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $note['type'] === 'alert' ? 'danger' : ($note['type'] === 'warning' ? 'warning' : ($note['type'] === 'reminder' ? 'info' : 'primary')); ?>">
                                                    <?php echo ucfirst($note['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $note['user_id'] ? 'You' : ($note['target_role'] ? ucfirst($note['target_role']) : 'All Users'); ?></td>
                                            <td><?php echo $note['is_read'] ? 'Read' : 'Unread'; ?></td>
                                            <td><?php echo $note['created_at']; ?></td>
                                            <td>
                                                <?php if ($note['is_read'] == 0): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="notification_id" value="<?php echo $note['id']; ?>">
                                                        <button type="submit" name="mark_read" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Mark Read</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>