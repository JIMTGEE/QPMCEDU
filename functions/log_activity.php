<?php
function logActivity($conn, $user_id, $action, $description = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $user_id, $action, $description, $ip_address, $user_agent);
    $stmt->execute();
}
?>
