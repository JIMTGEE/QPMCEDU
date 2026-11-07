<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');

$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$csrf_token = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
$academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';

if (!$group_id || !$user_id || !$csrf_token || !$academic_year || !isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id || $_SESSION['user_role'] !== 'student') {
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

// Fetch group details
$stmt = $conn->prepare("
    SELECT g.name, g.project_title, g.deadline, g.description
    FROM groups g
    JOIN group_members gm ON g.id = gm.group_id
    JOIN student_classes sc ON g.class_id = sc.class_id
    WHERE g.id = ? AND gm.user_id = ? AND sc.user_id = ? AND sc.academic_year = ? AND g.status = 'active'
");
$stmt->bind_param("iiis", $group_id, $user_id, $user_id, $academic_year);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    // Fetch group members
    $stmt_members = $conn->prepare("
        SELECT u.name
        FROM group_members gm
        JOIN users u ON gm.user_id = u.id
        WHERE gm.group_id = ? AND gm.status = 'active'
    ");
    $stmt_members->bind_param("i", $group_id);
    $stmt_members->execute();
    $members_result = $stmt_members->get_result();
    $members = [];
    while ($member = $members_result->fetch_assoc()) {
        $members[] = $member['name'];
    }
    $stmt_members->close();

    echo json_encode([
        'success' => true,
        'name' => $row['name'],
        'project_title' => $row['project_title'],
        'deadline' => $row['deadline'],
        'description' => $row['description'] ?? 'No description',
        'members' => $members
    ]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found or not accessible']);
}
$stmt->close();
?>