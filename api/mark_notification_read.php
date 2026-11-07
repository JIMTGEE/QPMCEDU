<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

$notification_id = isset($input['id']) ? (int)$input['id'] : 0;
$csrf_token = isset($input['csrf_token']) ? $input['csrf_token'] : '';

if (!$notification_id || !$csrf_token || !isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized or missing parameters']);
    exit;
}

// Validate CSRF token
$stmt = $conn->prepare("SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
$stmt->bind_param("is", $_SESSION['user_id'], $csrf_token);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    $stmt->close();
    exit;
}
$stmt->close();

// Mark notification as read
$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR target_role = 'student')");
$stmt->bind_param("ii", $notification_id, $_SESSION['user_id']);
if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Notification not found or already read']);
}
$stmt->close();
?>