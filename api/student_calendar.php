<?php
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$user_id = (int)$_POST['user_id'];
$csrf_token = $_POST['csrf_token'] ?? '';
$stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}
$stmt->close();

$events = [];
$current_academic_year = date('Y') . '/' . (date('Y') + 1);

// Assignments
$stmt = $conn->prepare("
    SELECT a.title, a.description, a.due_date AS start, c.class_name 
    FROM assignments a 
    JOIN classes c ON a.class_id = c.id 
    JOIN student_classes sc ON c.id = sc.class_id 
    WHERE sc.user_id = ? AND sc.academic_year = ?
");
$stmt->bind_param("is", $user_id, $current_academic_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $events[] = [
        'title' => htmlspecialchars($row['title'] . ' (' . $row['class_name'] . ')'),
        'start' => $row['start'],
        'description' => htmlspecialchars($row['description'] ?? 'Assignment Due'),
        'color' => '#ff4500'
    ];
}
$stmt->close();

// Exams
$stmt = $conn->prepare("
    SELECT s.subject_name, e.exam_date AS start, e.start_time, c.class_name 
    FROM exam_schedules e 
    JOIN subjects s ON e.subject_id = s.id 
    JOIN classes c ON e.class_id = c.id 
    JOIN student_classes sc ON e.class_id = sc.class_id 
    WHERE sc.user_id = ? AND sc.academic_year = ?
");
$stmt->bind_param("is", $user_id, $current_academic_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $events[] = [
        'title' => htmlspecialchars($row['subject_name'] . ' Exam (' . $row['class_name'] . ')'),
        'start' => $row['start'] . 'T' . ($row['start_time'] ?? '00:00:00'),
        'description' => 'Exam',
        'color' => '#28a745'
    ];
}
$stmt->close();

// School Events
$stmt = $conn->prepare("
    SELECT title, event_date AS start, description 
    FROM school_events 
    WHERE event_date >= CURDATE()
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $events[] = [
        'title' => htmlspecialchars($row['title']),
        'start' => $row['start'],
        'description' => htmlspecialchars($row['description'] ?? ''),
        'color' => '#007bff'
    ];
}
$stmt->close();

echo json_encode($events);
mysqli_close($conn);
?>