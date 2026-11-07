<?php
header('Content-Type: application/json');
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    error_log("CSRF token validation failed: Expected " . ($_SESSION['csrf_token'] ?? 'none') . ", Received " . ($_POST['csrf_token'] ?? 'none'));
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit();
}

// Validate assignment_id
$assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
$teacher_id = (int)$_SESSION['user_id'];
if (!$assignment_id) {
    error_log("Invalid assignment_id: " . ($_POST['assignment_id'] ?? 'unset'));
    echo json_encode(['error' => 'Invalid assignment ID']);
    exit();
}

// Verify teacher owns the assignment
$verify_sql = "SELECT id FROM assignments WHERE id = ? AND teacher_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param("ii", $assignment_id, $teacher_id);
$verify_stmt->execute();
if ($verify_stmt->get_result()->num_rows === 0) {
    error_log("Unauthorized assignment access: assignment_id=$assignment_id, teacher_id=$teacher_id");
    echo json_encode(['error' => 'You do not have permission to access this assignment']);
    exit();
}
$verify_stmt->close();

// Fetch submissions
$submissions_sql = "SELECT s.id, s.assignment_id, s.user_id, s.group_id, s.submission_date, s.status, s.score, s.feedback, s.file_paths, 
                           COALESCE(u.first_name + ' ' + u.last_name, g.name) as name,
                           a.max_points
                    FROM assignment_submissions s
                    LEFT JOIN users u ON s.user_id = u.id
                    LEFT JOIN groups g ON s.group_id = g.id
                    JOIN assignments a ON s.assignment_id = a.id
                    WHERE s.assignment_id = ? AND a.teacher_id = ?";
$submissions_stmt = $conn->prepare($submissions_sql);
$submissions_stmt->bind_param("ii", $assignment_id, $teacher_id);
$submissions_stmt->execute();
$submissions_result = $submissions_stmt->get_result();
$submissions = $submissions_result->fetch_all(MYSQLI_ASSOC);
$submissions_stmt->close();

echo json_encode(['submissions' => $submissions]);
mysqli_close($conn);
ob_end_flush();
?>