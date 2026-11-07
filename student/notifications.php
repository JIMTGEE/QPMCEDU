<?php
ob_start();
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Ensure logging directory exists
$log_dir = $_SERVER['DOCUMENT_ROOT'] . '/logs/';
$log_file = $log_dir . 'auth_errors.log';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
if (!is_writable($log_dir)) {
    $log_file = sys_get_temp_dir() . '/qpmseduc_auth_errors.log';
}

// Check user authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Authentication failed: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset') . "\n", FILE_APPEND);
    header("Location: /login.php?error=unauthenticated");
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Generate or reuse CSRF token
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR)) ON DUPLICATE KEY UPDATE token = ?, expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)");
$stmt->bind_param("iss", $user_id, $csrf_token, $csrf_token);
if (!$stmt->execute()) {
    error_log("CSRF token insertion failed: " . $stmt->error);
    $errors[] = "Internal error with security token. Please try again later.";
}
$stmt->close();

// Custom sanitization function
function sanitize_string($input) {
    if (!is_string($input)) {
        return '';
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Handle marking single notification as read
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_read'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $errors[] = "Invalid CSRF token. Please refresh and try again.";
        error_log("CSRF token mismatch on mark_read: submitted=" . ($_POST['csrf_token'] ?? 'unset') . ", expected=$csrf_token");
    } else {
        $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
        if ($notification_id) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notification_id, $user_id);
            if ($stmt->execute()) {
                $success_message = "Notification marked as read.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header("Location: notifications.php");
                exit;
            } else {
                $errors[] = "Failed to mark notification as read.";
                error_log("Notification mark read failed: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $errors[] = "Invalid notification ID.";
        }
    }
}

// Handle marking all notifications as read
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_all_read'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $errors[] = "Invalid CSRF token. Please refresh and try again.";
        error_log("CSRF token mismatch on mark_all_read: submitted=" . ($_POST['csrf_token'] ?? 'unset') . ", expected=$csrf_token");
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $success_message = "All notifications marked as read.";
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: notifications.php");
            exit;
        } else {
            $errors[] = "Failed to mark all notifications as read.";
            error_log("Mark all notifications read failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Handle notification deletion (optional feature)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_notification'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $errors[] = "Invalid CSRF token. Please refresh and try again.";
        error_log("CSRF token mismatch on delete_notification: submitted=" . ($_POST['csrf_token'] ?? 'unset') . ", expected=$csrf_token");
    } else {
        $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
        if ($notification_id) {
            $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notification_id, $user_id);
            if ($stmt->execute()) {
                $success_message = "Notification deleted successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header("Location: notifications.php");
                exit;
            } else {
                $errors[] = "Failed to delete notification.";
                error_log("Notification deletion failed: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $errors[] = "Invalid notification ID.";
        }
    }
}

// Fetch notifications
$notifications = [];
$unread_count = 0;
$stmt = $conn->prepare("SELECT id, title, message, category, is_read, link, created_at 
                        FROM notifications 
                        WHERE user_id = ? 
                        ORDER BY created_at DESC LIMIT 100");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
    if ($row['is_read'] == 0) {
        $unread_count++;
    }
}
$stmt->close();

// Handle AJAX refresh for notifications
if (isset($_GET['ajax'])) {
    ob_clean();
    ?>
    <div id="notificationList" class="bg-white rounded-lg shadow-lg p-8">
        <h2 class="text-2xl font-semibold text-gray-800 mb-6">Notifications (<?php echo count($notifications); ?>, <?php echo $unread_count; ?> Unread)</h2>
        <?php if (empty($notifications)): ?>
        <p class="text-gray-500 italic text-center">No notifications available.</p>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($notifications as $notification): ?>
            <div class="notification-item border border-gray-200 p-6 rounded-lg <?php echo $notification['is_read'] ? 'bg-gray-50' : 'bg-blue-50'; ?>">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <h3 class="text-lg font-medium text-gray-800"><?php echo htmlspecialchars($notification['title']); ?></h3>
                        <p class="mt-2 text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                        <?php if ($notification['link']): ?>
                        <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="text-orange-500 hover:text-orange-700 font-medium mt-2 inline-block">Go to Chat</a>
                        <?php endif; ?>
                        <p class="text-sm text-gray-500 mt-2">Category: <?php echo htmlspecialchars($notification['category']); ?> | <?php echo date('Y-m-d H:i:s', strtotime($notification['created_at'])); ?></p>
                    </div>
                    <div class="flex space-x-2">
                        <?php if (!$notification['is_read']): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Mark this notification as read?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                            <button type="submit" name="mark_read" class="btn-orange px-4 py-2 rounded-lg text-sm font-medium">Mark as Read</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Delete this notification?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                            <button type="submit" name="delete_notification" class="btn-red px-4 py-2 rounded-lg text-sm font-medium">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Student Notifications Dashboard for QueensPa School Management System">
    <meta name="author" content="QueensPa School">
    <title>Student Notifications - QueensPa School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .notification-item {
            transition: all 0.2s ease;
        }
        .notification-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
        }
        .loading-overlay::after {
            content: 'Loading...';
            color: white;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5rem;
            font-weight: bold;
        }
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease-in;
        }
        .success-message {
            background: #ecfdf5;
            color: #059669;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease-in;
        }
        .btn-orange {
            background: #f97316;
            color: white;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .btn-orange:hover {
            background: #ea580c;
            transform: translateY(-1px);
        }
        .btn-red {
            background: #dc2626;
            color: white;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .btn-red:hover {
            background: #b91c1c;
            transform: translateY(-1px);
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    if (file_exists($header_path)) {
        include $header_path;
    } else {
        echo "<div class='error-message text-center'>Error: Header template not found at /templates/student_header.php. Please contact support.</div>";
    }
    ?>
    
    <div class="container mx-auto p-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold text-orange-500">Student Notifications Dashboard</h1>
            <span class="text-lg text-gray-600">Academic Year: 2024/2025</span>
        </div>
        
        <!-- Display Errors or Success Messages -->
        <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
            <p><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
        <div class="success-message">
            <p><?php echo htmlspecialchars($success_message); ?></p>
        </div>
        <?php endif; ?>

        <!-- Mark All Notifications as Read -->
        <div class="bg-white rounded-lg shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-semibold text-gray-800 mb-6">Manage Notifications</h2>
            <?php if ($unread_count > 0): ?>
            <form method="POST" class="mb-6" onsubmit="return confirm('Mark all notifications as read?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="mark_all_read" class="btn-orange px-6 py-3 rounded-lg font-medium w-full sm:w-auto">Mark All as Read (<?php echo $unread_count; ?> Unread)</button>
            </form>
            <?php else: ?>
            <p class="text-gray-500 italic">No unread notifications.</p>
            <?php endif; ?>
        </div>

        <!-- Notification List -->
        <div id="notificationList" class="bg-white rounded-lg shadow-lg p-8">
            <h2 class="text-2xl font-semibold text-gray-800 mb-6">Notifications (<?php echo count($notifications); ?>, <?php echo $unread_count; ?> Unread)</h2>
            <?php if (empty($notifications)): ?>
            <p class="text-gray-500 italic text-center">No notifications available.</p>
            <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($notifications as $notification): ?>
                <div class="notification-item border border-gray-200 p-6 rounded-lg <?php echo $notification['is_read'] ? 'bg-gray-50' : 'bg-blue-50'; ?>">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <h3 class="text-lg font-medium text-gray-800"><?php echo htmlspecialchars($notification['title']); ?></h3>
                            <p class="mt-2 text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                            <?php if ($notification['link']): ?>
                            <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="text-orange-500 hover:text-orange-700 font-medium mt-2 inline-block">Go to Chat</a>
                            <?php endif; ?>
                            <p class="text-sm text-gray-500 mt-2">Category: <?php echo htmlspecialchars($notification['category']); ?> | <?php echo date('Y-m-d H:i:s', strtotime($notification['created_at'])); ?></p>
                        </div>
                        <div class="flex space-x-2">
                            <?php if (!$notification['is_read']): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Mark this notification as read?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                <button type="submit" name="mark_read" class="btn-orange px-4 py-2 rounded-lg text-sm font-medium">Mark as Read</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this notification?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                <button type="submit" name="delete_notification" class="btn-red px-4 py-2 rounded-lg text-sm font-medium">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="loading-overlay"></div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        echo "<div class='error-message text-center'>Error: Footer template not found at /templates/student_footer.php. Please contact support.</div>";
    }
    ?>

    <script>
        // Real-time notification updates
        function updateNotifications() {
            $.ajax({
                url: 'notifications.php',
                type: 'GET',
                data: { ajax: 1 },
                beforeSend: function() {
                    $('.loading-overlay').fadeIn(200);
                },
                success: function(response) {
                    let parser = new DOMParser();
                    let doc = parser.parseFromString(response, 'text/html');
                    $('#notificationList').html($(doc).find('#notificationList').html());
                },
                error: function(xhr, status, error) {
                    $('<div class="error-message text-center">Failed to refresh notifications: ' + error + '. Please try again.</div>').insertAfter('h1').delay(5000).fadeOut(500);
                },
                complete: function() {
                    $('.loading-overlay').fadeOut(200);
                }
            });
        }

        // Poll for updates every 10 seconds
        setInterval(updateNotifications, 10000);

        // Smooth scroll to notification section on load
        if ($('#notificationList').length) {
            $('html, body').animate({
                scrollTop: $('#notificationList').offset().top - 100
            }, 500);
        }
    </script>
</body>
</html>