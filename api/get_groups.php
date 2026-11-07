<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt to get_groups.php: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    echo json_encode(['error' => 'Unauthorized access. Please log in as a teacher.']);
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__, 2) . '/error.log');

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    echo json_encode(['error' => 'Server configuration error.']);
    exit();
}
require_once($db_path);

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: 'Unknown error'));
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// Validate inputs
$teacher_id = (int)$_SESSION['user_id'];
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);

if (!$class_id) {
    error_log("Invalid class ID: " . ($class_id ?? 'null'));
    echo json_encode(['error' => 'Invalid class ID.']);
    exit();
}

// Verify teacher has access to the class
$verify_sql = "SELECT c.id, c.department_id 
               FROM classes c 
               LEFT JOIN class_schedules cs ON c.id = cs.class_id 
               WHERE c.id = ? 
               AND (c.teacher_id = ? OR cs.teacher_id = ?) 
               AND c.academic_year = '2024/2025'";
$verify_stmt = $conn->prepare($verify_sql);
if (!$verify_stmt) {
    error_log("Prepare failed for verify class query: " . $conn->error);
    echo json_encode(['error' => 'Failed to verify class access.']);
    exit();
}
$verify_stmt->bind_param("iii", $class_id, $teacher_id, $teacher_id);
$verify_stmt->execute();
$class = $verify_stmt->get_result()->fetch_assoc();
$verify_stmt->close();

if (!$class) {
    error_log("Class not found or unauthorized: class_id=$class_id, teacher_id=$teacher_id");
    echo json_encode(['error' => 'Class not found or you do not have permission.']);
    exit();
}

// Verify teacher's department
$user_check_sql = "SELECT department_id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
$user_check_stmt->bind_param("i", $teacher_id);
$user_check_stmt->execute();
$user = $user_check_stmt->get_result()->fetch_assoc();
$user_check_stmt->close();

if (!$user || $user['department_id'] != $class['department_id']) {
    error_log("Department mismatch: teacher_id=$teacher_id, class_id=$class_id");
    echo json_encode(['error' => 'You do not have permission for this class.']);
    exit();
}

// Log API access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed Groups API", "Fetched groups for class ID $class_id");

// Fetch groups
$groups = [];
$sql = "SELECT g.id, g.name, g.project_title, g.description, g.deadline, g.max_points, g.status,
               (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') 
                FROM group_members gm 
                JOIN users u ON gm.user_id = u.id 
                WHERE gm.group_id = g.id AND gm.status = 'active') as members
        FROM groups g
        WHERE g.class_id = ? AND g.status = 'active'
        ORDER BY g.name ASC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed for groups query: " . $conn->error);
    echo json_encode(['error' => 'Failed to fetch groups.']);
    exit();
}
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['name'] = htmlspecialchars($row['name']);
    $row['project_title'] = htmlspecialchars($row['project_title']);
    $row['description'] = htmlspecialchars($row['description'] ?? '');
    $row['members'] = htmlspecialchars($row['members'] ?? '');
    $groups[] = $row;
}
$stmt->close();

mysqli_close($conn);
echo json_encode(['groups' => $groups]);
?>