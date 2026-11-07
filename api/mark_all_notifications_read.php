<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

$user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$csrf_token = isset($input['csrf_token']) ? $input['csrf_token'] : '';

if (!$user_id || !$csrf_token || !isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id || $_SESSION['user_role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized or missing parameters']);
    exit;
}

// Validate CSRF token
$stmt = $conn->prepare("SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    $stmt->close();
    exit;
}
$stmt->close();

// Mark all notifications as read
$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? OR target_role = 'student'");
$stmt->bind_param("i", $user_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to mark notifications as read']);
}
$stmt->close();
?>