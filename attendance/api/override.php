<?php
// /attendance/api/override.php
// Allows staff to override a denied entry with a reason

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only users with override permission
requireRoleAPI(['admin', 'teacher', 'security', 'head_academics']);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$logId = (int)($input['log_id'] ?? 0);
$reason = trim($input['reason'] ?? '');
$location = $input['location'] ?? 'Main Gate';

if ($logId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid log ID']);
    exit();
}

if (empty($reason)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Override reason is required']);
    exit();
}

try {
    // Check if the log exists and is denied
    $stmt = $conn->prepare("
        SELECT id, user_id, scan_type, decision, auto_reason 
        FROM access_logs 
        WHERE id = ? AND decision = 'denied'
    ");
    $stmt->bind_param("i", $logId);
    $stmt->execute();
    $result = $stmt->get_result();
    $log = $result->fetch_assoc();
    
    if (!$log) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Log not found or already processed']);
        exit();
    }
    
    $userId = $log['user_id'];
    $scanType = $log['scan_type'];
    $currentUserId = getCurrentUserId();
    
    // Update the log to overridden
    $stmt = $conn->prepare("
        UPDATE access_logs 
        SET decision = 'overridden', 
            overridden_by = ?, 
            override_reason = ? 
        WHERE id = ?
    ");
    $stmt->bind_param("isi", $currentUserId, $reason, $logId);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Failed to update log']);
        exit();
    }
    
    // Mark attendance for the student
    $studentDetails = getStudentDetails($userId);
    if ($studentDetails && $studentDetails['class_id']) {
        $currentTime = strtotime(date('H:i:s'));
        $cutoffTime = strtotime('08:30:00');
        $status = ($currentTime > $cutoffTime) ? 'late' : 'present';
        markAttendance($userId, $studentDetails['class_id'], $status);
    }
    
    // Get updated log
    $stmt = $conn->prepare("
        SELECT * FROM access_logs WHERE id = ?
    ");
    $stmt->bind_param("i", $logId);
    $stmt->execute();
    $updatedLog = $stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'message' => 'Entry overridden successfully',
        'log' => $updatedLog,
        'speech' => 'Entry overridden. Student may enter.'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>