<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';

if (!$user_id || !$academic_year || !isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id || $_SESSION['user_role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized or missing parameters']);
    exit;
}

$events = [];

// Fetch class schedules
$stmt = $conn->prepare("
    SELECT cs.day_of_week, cs.start_time, cs.end_time, s.subject_name, c.class_name
    FROM class_schedules cs
    JOIN student_classes sc ON cs.class_id = sc.class_id
    JOIN subjects s ON cs.subject_id = s.id
    JOIN classes c ON cs.class_id = c.id
    WHERE sc.user_id = ? AND sc.academic_year = ?
");
$stmt->bind_param("is", $user_id, $academic_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $start = $row['day_of_week'] . ' ' . $row['start_time'];
    $end = $row['day_of_week'] . ' ' . $row['end_time'];
    $events[] = [
        'title' => $row['subject_name'] . ' (' . $row['class_name'] . ')',
        'start' => $start,
        'end' => $end,
        'color' => '#ff4500'
    ];
}
$stmt->close();

// Fetch exam schedules
$stmt = $conn->prepare("
    SELECT es.exam_date, es.start_time, es.end_time, s.subject_name, c.class_name
    FROM exam_schedules es
    JOIN student_classes sc ON es.class_id = sc.class_id
    JOIN subjects s ON es.subject_id = s.id
    JOIN classes c ON es.class_id = c.id
    WHERE sc.user_id = ? AND sc.academic_year = ?
");
$stmt->bind_param("is", $user_id, $academic_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $start = $row['exam_date'] . ' ' . ($row['start_time'] ?? '00:00:00');
    $end = $row['exam_date'] . ' ' . ($row['end_time'] ?? '23:59:59');
    $events[] = [
        'title' => 'Exam: ' . $row['subject_name'] . ' (' . $row['class_name'] . ')',
        'start' => $start,
        'end' => $end,
        'color' => '#ff0000'
    ];
}
$stmt->close();

// Fetch school events
$stmt = $conn->prepare("
    SELECT title, event_date, start_time, end_time
    FROM school_events
    WHERE event_date >= CURDATE()
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $start = $row['event_date'] . ' ' . ($row['start_time'] ?? '00:00:00');
    $end = $row['event_date'] . ' ' . ($row['end_time'] ?? '23:59:59');
    $events[] = [
        'title' => $row['title'],
        'start' => $start,
        'end' => $end,
        'color' => '#38a169'
    ];
}
$stmt->close();

echo json_encode($events);
?>