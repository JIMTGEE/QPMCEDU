<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');

$assignment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$csrf_token = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
$academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';

if (!$assignment_id || !$user_id || !$csrf_token || !$academic_year || !isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id || $_SESSION['user_role'] !== 'student') {
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

// Fetch assignment details
$stmt = $conn->prepare("
    SELECT a.title, s.subject_name, a.due_date, asub.status, a.description
    FROM assignments a
    JOIN student_classes sc ON a.class_id = sc.class_id
    JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.user_id = ?
    WHERE a.id = ? AND sc.user_id = ? AND sc.academic_year = ? AND a.status = 'published'
");
$stmt->bind_param("iiis", $user_id, $assignment_id, $user_id, $academic_year);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'title' => $row['title'],
        'subject_name' => $row['subject_name'],
        'due_date' => $row['due_date'],
        'status' => $row['status'] ?? 'Pending',
        'description' => $row['description'] ?? 'No description'
    ]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assignment not found or not accessible']);
}
$stmt->close();
?>