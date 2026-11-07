<?php
// /api/get_teacher_schedule.php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: https://qpmsedu.com');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: X-Requested-With');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    echo json_encode(['error' => 'Server configuration error']);
    http_response_code(500);
    exit();
}
require_once $db_path;

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once $log_path;
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    echo json_encode(['error' => 'Database connection failed']);
    http_response_code(500);
    exit();
}

// Validate user
$teacher_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $teacher_id || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", requested_id=$teacher_id");
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit();
}

// Get current term
$current_term_sql = "SELECT id FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
if (!$current_term_stmt) {
    error_log("Prepare failed for current term: " . $conn->error);
    echo json_encode(['error' => 'Failed to fetch term']);
    http_response_code(500);
    exit();
}
$current_term_stmt->execute();
$current_term = $current_term_stmt->get_result()->fetch_assoc();
$current_term_id = $current_term['id'] ?? 1;
$current_term_stmt->close();

// Fetch schedules
$sql = "
    SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time, c.class_name, s.subject_name, t.term_name
    FROM class_schedules cs
    JOIN classes c ON cs.class_id = c.id
    JOIN subjects s ON cs.subject_id = s.id
    JOIN academic_calendar t ON cs.term_id = t.id
    WHERE cs.teacher_id = ? AND cs.term_id = ?
    ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), cs.start_time";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed for schedules: " . $conn->error);
    echo json_encode(['error' => 'Failed to fetch schedules']);
    http_response_code(500);
    exit();
}
$stmt->bind_param("ii", $teacher_id, $current_term_id);
$stmt->execute();
$result = $stmt->get_result();
$schedules = [];
while ($row = $result->fetch_assoc()) {
    $schedules[] = [
        'id' => (int)$row['id'],
        'day_of_week' => htmlspecialchars($row['day_of_week']),
        'start_time' => htmlspecialchars($row['start_time']),
        'end_time' => htmlspecialchars($row['end_time']),
        'class_name' => htmlspecialchars($row['class_name']),
        'subject_name' => htmlspecialchars($row['subject_name']),
        'term_name' => htmlspecialchars($row['term_name'])
    ];
}
$stmt->close();

// Log access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed Schedule API", "Fetched schedules for term_id=$current_term_id from IP: $ip_address");

// Output response
echo json_encode(['schedules' => $schedules]);
http_response_code(200);
mysqli_close($conn);
?>