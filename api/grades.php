<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

header('Content-Type: application/json');

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';
$term_id = isset($_GET['term_id']) ? ($_GET['term_id'] === 'all' ? 'all' : (int)$_GET['term_id']) : 'all';

if (!$user_id || !$academic_year || !isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $user_id || $_SESSION['user_role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized or missing parameters']);
    exit;
}

$subjects = [];
$grades = [];

$query = "
    SELECT s.subject_name, g.total
    FROM grades g
    JOIN student_classes sc ON g.class_id = sc.class_id
    JOIN subjects s ON g.subject_id = s.id
    WHERE g.user_id = ? AND sc.academic_year = ?
";
if ($term_id !== 'all') {
    $query .= " AND g.term_id = ?";
}

$stmt = $conn->prepare($query);
if ($term_id === 'all') {
    $stmt->bind_param("is", $user_id, $academic_year);
} else {
    $stmt->bind_param("isi", $user_id, $academic_year, $term_id);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row['subject_name'];
    $grades[] = (float)$row['total'];
}
$stmt->close();

echo json_encode([
    'subjects' => $subjects,
    'grades' => $grades
]);
?>