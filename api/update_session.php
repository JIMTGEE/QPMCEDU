<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['academic_year'], $input['csrf_token'], $_SESSION['user_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$academic_year = $input['academic_year'];
$csrf_token = $input['csrf_token'];
$user_id = $_SESSION['user_id'];

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

// Validate academic year exists
$stmt = $conn->prepare("SELECT year FROM academic_calendar WHERE year = ?");
$stmt->bind_param("s", $academic_year);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid academic year']);
    $stmt->close();
    exit;
}
$stmt->close();

// Update session
$_SESSION['academic_year'] = $academic_year;
$_SESSION['metrics'] = null; // Clear metrics cache to force refresh
echo json_encode(['success' => true]);
?>