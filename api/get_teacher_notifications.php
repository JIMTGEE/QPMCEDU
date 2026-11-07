<?php
// /api/get_teacher_notifications.php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: https://qpmsedu.com');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: X-Requested-With');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    echo json_encode(['error' => 'Server configuration error']);
    http_response_code(500);
    exit();
}
require_once $db_path;

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once $log_path;
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    echo json_encode(['error' => 'Database connection failed']);
    http_response_code(500);
    exit();
}

// Validate user
$teacher_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $teacher_id || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", requested_id=$teacher_id");
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit();
}

// Fetch notifications
$sql = "
    SELECT id, title, message, created_at, link
    FROM notifications
    WHERE (user_id = ? OR target_role IN ('teacher', 'all')) AND is_read = 0
    ORDER BY created_at DESC
    LIMIT 50";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed for notifications: " . $conn->error);
    echo json_encode(['error' => 'Failed to fetch notifications']);
    http_response_code(500);
    exit();
}
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => (int)$row['id'],
        'title' => htmlspecialchars($row['title']),
        'message' => htmlspecialchars($row['message']),
        'created_at' => htmlspecialchars($row['created_at']),
        'link' => htmlspecialchars($row['link'] ?? '/teacher/notifications.php')
    ];
}
$stmt->close();

// Log access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed Notifications API", "Fetched " . count($notifications) . " unread notifications from IP: $ip_address");

// Output response
echo json_encode(['notifications' => $notifications]);
http_response_code(200);
mysqli_close($conn);
?>