<?php
// /api/log_error.php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: https://qpmsedu.com');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

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

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    echo json_encode(['error' => 'Database connection failed']);
    http_response_code(500);
    exit();
}

// Validate input
$input = json_decode(file_get_contents('php://input'), true);
$teacher_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$action = isset($input['action']) ? filter_var($input['action'], FILTER_SANITIZE_STRING) : 'Unknown';
$description = isset($input['description']) ? substr(filter_var($input['description'], FILTER_SANITIZE_STRING), 0, 255) : 'No description';
$ip_address = isset($input['ip_address']) ? filter_var($input['ip_address'], FILTER_VALIDATE_IP) : 'unknown';
$user_agent = isset($input['user_agent']) ? substr(filter_var($input['user_agent'], FILTER_SANITIZE_STRING), 0, 255) : 'unknown';

// Validate user (optional, since Service Worker may call this)
if ($teacher_id > 0 && (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $teacher_id || $_SESSION['user_role'] !== 'teacher')) {
    error_log("Unauthorized error log attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", requested_id=$teacher_id");
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit();
}

// Log error to user_logs
$sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed for error log: " . $conn->error);
    echo json_encode(['error' => 'Failed to log error']);
    http_response_code(500);
    exit();
}
$stmt->bind_param("issss", $teacher_id, $action, $description, $ip_address, $user_agent);
if (!$stmt->execute()) {
    error_log("Error logging to user_logs: " . $stmt->error);
    echo json_encode(['error' => 'Failed to log error']);
    http_response_code(500);
    exit();
}
$stmt->close();

// Output response
echo json_encode(['success' => true]);
http_response_code(200);
mysqli_close($conn);
?>