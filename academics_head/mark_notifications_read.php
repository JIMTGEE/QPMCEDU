<?php
session_start();
// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');


if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $notification_id = filter_var($_POST['id'], FILTER_SANITIZE_NUMBER_INT);
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $_SESSION['user_id']);
    if ($stmt->execute()) {
        http_response_code(200);
    } else {
        http_response_code(500);
    }
    $stmt->close();
} else {
    http_response_code(400);
}
?>