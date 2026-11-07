```php
<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset') . ", IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access. Please log in as a teacher.']);
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__, 2) . '/teacher/error.log');

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error.']);
    exit();
}
require_once($db_path);

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: 'Unknown error'));
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// Validate inputs
$teacher_id = (int)$_SESSION['user_id'];
$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
$is_group = filter_input(INPUT_GET, 'is_group', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$group_id = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

if ($assignment_id === false || $assignment_id <= 0) {
    error_log("Invalid or missing assignment_id: " . ($_GET['assignment_id'] ?? 'unset'));
    http_response_code(400);
    echo json_encode(['error' => 'Invalid assignment ID.']);
    exit();
}

// Verify teacher owns the assignment
$verify_sql = "SELECT id, max_points, is_group_assignment, group_id FROM assignments WHERE id = ? AND teacher_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
if (!$verify_stmt) {
    error_log("Prepare failed for assignment verification: " . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Database error.']);
    exit();
}
$verify_stmt->bind_param("ii", $assignment_id, $teacher_id);
$verify_stmt->execute();
$assignment = $verify_stmt->get_result()->fetch_assoc();
$verify_stmt->close();

if (!$assignment) {
    error_log("Assignment not found or unauthorized: assignment_id=$assignment_id, teacher_id=$teacher_id");
    http_response_code(403);
    echo json_encode(['error' => 'Assignment not found or unauthorized.']);
    exit();
}

// Validate is_group and group_id
$is_group_assignment = (bool)$assignment['is_group_assignment'];
if ($is_group !== $is_group_assignment) {
    error_log("Mismatched is_group parameter: provided=$is_group, expected=$is_group_assignment, assignment_id=$assignment_id");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group assignment parameter.']);
    exit();
}
if ($is_group_assignment && $group_id !== $assignment['group_id'] && $assignment['group_id'] !== null) {
    error_log("Invalid group_id: provided=$group_id, expected=" . ($assignment['group_id'] ?? 'null') . ", assignment_id=$assignment_id");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid group ID for this assignment.']);
    exit();
}

// Fetch submissions
$submissions = [];
if ($is_group_assignment) {
    $sql = "SELECT s.id, s.assignment_id, s.group_id, s.file_paths, s.submission_date, s.score, s.feedback, s.status, 
                   g.name AS name
            FROM assignment_submissions s
            LEFT JOIN groups g ON s.group_id = g.id
            WHERE s.assignment_id = ? AND (s.group_id = ? OR ? IS NULL)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for group submissions query: " . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Database error.']);
        exit();
    }
    $stmt->bind_param("iii", $assignment_id, $group_id, $group_id);
} else {
    $sql = "SELECT s.id, s.assignment_id, s.user_id, s.file_paths, s.submission_date, s.score, s.feedback, s.status, 
                   u.name AS name
            FROM assignment_submissions s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.assignment_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for individual submissions query: " . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Database error.']);
        exit();
    }
    $stmt->bind_param("i", $assignment_id);
}

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $submissions[] = [
        'id' => (int)$row['id'],
        'assignment_id' => (int)$row['assignment_id'],
        'name' => $row['name'] ?? 'Unknown',
        'file_paths' => $row['file_paths'] ? explode(',', $row['file_paths']) : [],
        'submission_date' => $row['submission_date'] ? date('Y-m-d H:i:s', strtotime($row['submission_date'])) : null,
        'score' => $row['score'] !== null ? floatval($row['score']) : null,
        'feedback' => $row['feedback'] ?? '',
        'status' => $row['status'] ?? 'pending',
        'max_points' => floatval($assignment['max_points'])
    ];
}
$stmt->close();

// Log successful fetch
error_log("Fetched " . count($submissions) . " submissions for assignment_id=$assignment_id, teacher_id=$teacher_id, is_group=$is_group_assignment");

// Output JSON
http_response_code(200);
echo json_encode([
    'success' => true,
    'submissions' => $submissions
]);

mysqli_close($conn);
ob_end_flush();
?>
```