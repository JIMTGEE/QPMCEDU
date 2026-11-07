<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
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
$success_msg = $error_msg = "";
$valid_roles = ['admin', 'teacher', 'student', 'all'];
$notification_types = ['info', 'warning', 'alert', 'reminder'];

// Pagination and filtering
$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;
$filter_type = isset($_GET['filter_type']) && in_array($_GET['filter_type'], $notification_types) ? $_GET['filter_type'] : '';
$filter_target = isset($_GET['filter_target']) && (in_array($_GET['filter_target'], $valid_roles) || is_numeric($_GET['filter_target'])) ? $_GET['filter_target'] : '';

// Fetch users for dropdown
$users_sql = "SELECT id, name, role FROM users ORDER BY name";
$users_result = mysqli_query($conn, $users_sql);
$users = [];
if ($users_result) {
    while ($row = mysqli_fetch_assoc($users_result)) {
        $users[] = $row;
    }
} else {
    error_log("Error fetching users: " . mysqli_error($conn));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $target = trim($_POST['target'] ?? '');

    // Validate inputs
    if (empty($title)) {
        $error_msg = "Title is required.";
    } elseif (strlen($title) > 100) {
        $error_msg = "Title must be 100 characters or less.";
    } elseif (empty($message)) {
        $error_msg = "Message is required.";
    } elseif (strlen($message) > 500) {
        $error_msg = "Message must be 500 characters or less.";
    } elseif (!in_array($type, $notification_types)) {
        $error_msg = "Invalid notification type.";
    } elseif (empty($target)) {
        $error_msg = "Please select a target.";
    } else {
        $conn->begin_transaction();
        try {
            // Determine if target is a user_id or role
            $user_id = is_numeric($target) ? (int)$target : null;
            $target_role = !is_numeric($target) ? $target : null;

            // Validate target
            if ($user_id && !array_key_exists($user_id, array_column($users, 'id', 'id'))) {
                $error_msg = "Invalid user selected.";
            } elseif ($target_role && !in_array($target_role, $valid_roles)) {
                $error_msg = "Invalid role selected.";
            } else {
                // Prepare insert query
                $insert_sql = "INSERT INTO notifications (user_id, target_role, title, message, type, is_read, created_at) 
                               VALUES (?, ?, ?, ?, ?, 0, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("issss", $user_id, $target_role, $title, $message, $type);
                $insert_stmt->execute();
                $notification_id = $conn->insert_id;
                $insert_stmt->close();

                // Log action
                $admin_id = $_SESSION['user_id'];
                $action = "Sent Notification";
                $description = "Notification ID: $notification_id, Title: $title, Type: $type, Target: " . 
                               ($user_id ? "User ID $user_id" : "Role $target_role");
                logActivity($conn, $admin_id, $action, $description);

                $conn->commit();
                $success_msg = "Notification sent successfully!";
                $_POST = []; // Clear form
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error sending notification: " . $e->getMessage();
            error_log("Send notification error: " . $e->getMessage() . " | Title: $title | Target: $target");
        }
    }
}

// Fetch recent notifications (last 5)
$recent_sql = "SELECT id, user_id, target_role, title, message, type, created_at 
               FROM notifications 
               ORDER BY created_at DESC 
               LIMIT 5";
$recent_result = mysqli_query($conn, $recent_sql);
$recent_notifications = $recent_result ? mysqli_fetch_all($recent_result, MYSQLI_ASSOC) : [];

// Fetch all notifications for pagination
$all_sql = "SELECT COUNT(*) as total 
            FROM notifications";
if ($filter_type) {
    $all_sql .= " WHERE type = '" . mysqli_real_escape_string($conn, $filter_type) . "'";
}
if ($filter_target) {
    if (is_numeric($filter_target)) {
        $all_sql .= ($filter_type ? " AND" : " WHERE") . " user_id = " . (int)$filter_target;
    } else {
        $all_sql .= ($filter_type ? " AND" : " WHERE") . " target_role = '" . mysqli_real_escape_string($conn, $filter_target) . "'";
    }
}
$total_result = mysqli_query($conn, $all_sql);
$total_notifications = $total_result ? mysqli_fetch_assoc($total_result)['total'] : 0;
$total_pages = ceil($total_notifications / $per_page);

// Fetch notifications for current page
$notifications_sql = "SELECT id, user_id, target_role, title, message, type, created_at 
                      FROM notifications";
if ($filter_type || $filter_target) {
    $notifications_sql .= " WHERE";
    if ($filter_type) {
        $notifications_sql .= " type = '" . mysqli_real_escape_string($conn, $filter_type) . "'";
    }
    if ($filter_target) {
        if ($filter_type) {
            $notifications_sql .= " AND";
        }
        if (is_numeric($filter_target)) {
            $notifications_sql .= " user_id = " . (int)$filter_target;
        } else {
            $notifications_sql .= " target_role = '" . mysqli_real_escape_string($conn, $filter_target) . "'";
        }
    }
}
$notifications_sql .= " ORDER BY created_at DESC LIMIT $offset, $per_page";
$notifications_result = mysqli_query($conn, $notifications_sql);
$notifications = $notifications_result ? mysqli_fetch_all($notifications_result, MYSQLI_ASSOC) : [];

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin notifications center for sending and viewing messages in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, notifications, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Admin Notifications">
    <meta property="og:description" content="Admin interface for managing notifications in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/notifications.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Admin Notifications">
    <meta name="twitter:description" content="Admin interface for managing notifications in QueensPa School.">
    <title>Notifications - QueensPa School Admin</title>
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
        .pagination .page-link {
            color: var(--primary-color);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📬 Send Notification</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <form method="POST" class="form-group">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" name="title" placeholder="e.g., Staff Meeting" value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label">Message</label>
                            <textarea class="form-control" id="message" name="message" rows="4" placeholder="e.g., Meeting scheduled for Monday." required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="type" class="form-label">Notification Type</label>
                            <select class="form-select" id="type" name="type" required>
                                <option value="" disabled <?php echo !isset($_POST['type']) ? 'selected' : ''; ?>>Select Type</option>
                                <?php foreach ($notification_types as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo isset($_POST['type']) && $_POST['type'] === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="target" class="form-label">Send To</label>
                            <select class="form-select" id="target" name="target" required>
                                <option value="" disabled <?php echo !isset($_POST['target']) ? 'selected' : ''; ?>>Select Target</option>
                                <optgroup label="Users">
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo isset($_POST['target']) && $_POST['target'] == $user['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['name']) . " (" . ucfirst($user['role']) . ")"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Roles">
                                    <?php foreach ($valid_roles as $role): ?>
                                        <option value="<?php echo $role; ?>" <?php echo isset($_POST['target']) && $_POST['target'] === $role ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($role); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="text-center mt-4">
                            <button type="submit" name="send_notification" class="btn btn-success"><i class="fas fa-paper-plane"></i> Send Notification</button>
                            <a href="dashboard.php" class="btn btn-secondary ms-3"><i class="fas fa-times"></i> Cancel</a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📜 Recent Notifications (Last 5)</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Message</th>
                                    <th>Type</th>
                                    <th>Target</th>
                                    <th>Sent At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_notifications)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No recent notifications.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_notifications as $note): ?>
                                        <tr>
                                            <td><?php echo $note['id']; ?></td>
                                            <td><?php echo htmlspecialchars($note['title']); ?></td>
                                            <td><?php echo htmlspecialchars($note['message']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $note['type'] === 'alert' ? 'danger' : ($note['type'] === 'warning' ? 'warning' : ($note['type'] === 'reminder' ? 'info' : 'primary')); ?>">
                                                    <?php echo ucfirst($note['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $note['user_id'] ? "User ID {$note['user_id']}" : ($note['target_role'] ? ucfirst($note['target_role']) : 'N/A'); ?></td>
                                            <td><?php echo $note['created_at']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📚 All Notifications</h3>
                </div>
                <div class="card-body">
                    <form class="filter-form mb-3" method="GET">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="filter_type" class="form-label">Filter by Type</label>
                                <select class="form-select" id="filter_type" name="filter_type">
                                    <option value="">All Types</option>
                                    <?php foreach ($notification_types as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="filter_target" class="form-label">Filter by Target</label>
                                <select class="form-select" id="filter_target" name="filter_target">
                                    <option value="">All Targets</option>
                                    <optgroup label="Users">
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>" <?php echo $filter_target == $user['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['name']) . " (" . ucfirst($user['role']) . ")"; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Roles">
                                        <?php foreach ($valid_roles as $role): ?>
                                            <option value="<?php echo $role; ?>" <?php echo $filter_target === $role ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($role); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-filter"></i> Apply Filters</button>
                            </div>
                        </div>
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
                                    <th>Sent At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($notifications)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No notifications found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($notifications as $note): ?>
                                        <tr>
                                            <td><?php echo $note['id']; ?></td>
                                            <td><?php echo htmlspecialchars($note['title']); ?></td>
                                            <td><?php echo htmlspecialchars($note['message']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $note['type'] === 'alert' ? 'danger' : ($note['type'] === 'warning' ? 'warning' : ($note['type'] === 'reminder' ? 'info' : 'primary')); ?>">
                                                    <?php echo ucfirst($note['type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $note['user_id'] ? "User ID {$note['user_id']}" : ($note['target_role'] ? ucfirst($note['target_role']) : 'N/A'); ?></td>
                                            <td><?php echo $note['created_at']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Notifications pagination">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&filter_type=<?php echo $filter_type; ?>&filter_target=<?php echo $filter_target; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&filter_type=<?php echo $filter_type; ?>&filter_target=<?php echo $filter_target; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&filter_type=<?php echo $filter_type; ?>&filter_target=<?php echo $filter_target; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>