<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');

$club_name = isset($_GET['name']) ? $_GET['name'] : '';
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$csrf_token = isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '';
$academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';

if (!$club_name || !$user_id || !$csrf_token || !$academic_year || !isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id || $_SESSION['user_role'] !== 'student') {
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

// Fetch club details
$stmt = $conn->prepare("
    SELECT c.name, c.description
    FROM clubs c
    JOIN club_memberships cm ON c.id = cm.club_id
    WHERE c.name = ? AND cm.user_id = ? AND cm.status = 'active'
");
$stmt->bind_param("si", $club_name, $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    // Fetch upcoming events
    $stmt_events = $conn->prepare("
        SELECT title, event_date
        FROM club_events
        WHERE club_id = (SELECT id FROM clubs WHERE name = ?) AND event_date >= CURDATE()
        ORDER BY event_date ASC
        LIMIT 5
    ");
    $stmt_events->bind_param("s", $club_name);
    $stmt_events->execute();
    $events_result = $stmt_events->get_result();
    $events = [];
    while ($event = $events_result->fetch_assoc()) {
        $events[] = $event['title'] . ' on ' . $event['event_date'];
    }
    $stmt_events->close();

    echo json_encode([
        'success' => true,
        'name' => $row['name'],
        'description' => $row['description'] ?? 'No description',
        'events' => $events
    ]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Club not found or not accessible']);
}
$stmt->close();
?>