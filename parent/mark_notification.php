<?php
session_start();
require_once '../../config/db.php';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['id'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR target_role = 'parent')");
    $stmt->bind_param("ii", $_POST['id'], $_SESSION['user_id']);
    $stmt->execute();
}
?>