<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

$teacher_id = $_SESSION['user_id'];
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);

if (!$class_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid class ID']);
    exit();
}

// Fetch subjects assigned to teacher for this class
$subjects_sql = "SELECT DISTINCT s.id, s.name 
                 FROM subjects s 
                 JOIN class_schedules cs ON s.id = cs.subject_id 
                 WHERE cs.teacher_id = ? AND cs.class_id = ?";
$subjects_stmt = $conn->prepare($subjects_sql);
$subjects_stmt->bind_param("ii", $teacher_id, $class_id);
$subjects_stmt->execute();
$subjects = $subjects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subjects_stmt->close();

if (empty($subjects)) {
    // Fallback: Fetch subjects for the class's department
    $dept_sql = "SELECT s.id, s.name 
                 FROM subjects s 
                 JOIN classes c ON s.department_id = c.department_id 
                 WHERE c.id = ?";
    $dept_stmt = $conn->prepare($dept_sql);
    $dept_stmt->bind_param("i", $class_id);
    $dept_stmt->execute();
    $subjects = $dept_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dept_stmt->close();
}

echo json_encode(['subjects' => $subjects]);
$conn->close();
?>