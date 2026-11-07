<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$user_id || !isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id || $_SESSION['user_role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized or missing parameters']);
    exit;
}

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR target_role = 'student') AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
echo json_encode(['count' => (int)$result['count']]);
$stmt->close();
?>