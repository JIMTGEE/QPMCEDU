<?php
// /attendance/api/bulk_data.php
// Returns all active students with their fee status and last scan
// Used for offline caching on the scanner page

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only staff can access this endpoint
requireRoleAPI(['admin', 'teacher', 'security', 'head_academics']);

header('Content-Type: application/json');

try {
    $term = getActiveTerm();
    if (!$term) {
        echo json_encode([
            'success' => false,
            'error' => 'No active academic term found',
            'data' => []
        ]);
        exit();
    }

    // Get all active students with their class info
    $query = "
        SELECT 
            u.id,
            u.name,
            u.email,
            u.profile_picture as photo,
            u.status,
            u.gender,
            u.phone,
            sc.class_id,
            c.class_name,
            c.department
        FROM users u
        INNER JOIN student_classes sc ON u.id = sc.user_id
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE u.role = 'student' 
        AND u.status = 'active'
        AND sc.term_id = ?
        ORDER BY u.name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $term['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    
    while ($row = $result->fetch_assoc()) {
        // Check fee status for each student
        $feeCheck = checkFeeThreshold($row['id']);
        
        // Get last scan for today
        $lastScan = getLastScanToday($row['id']);
        
        // Get attendance status for today
        $attendanceStatus = 'absent';
        $attStmt = $conn->prepare("SELECT status FROM attendance WHERE user_id = ? AND date = ?");
        $attStmt->bind_param("is", $row['id'], $today);
        $attStmt->execute();
        $attResult = $attStmt->get_result();
        if ($attRow = $attResult->fetch_assoc()) {
            $attendanceStatus = $attRow['status'];
        }
        
        $students[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'photo' => $row['photo'] ?: 'assets/default-avatar.png',
            'status' => $row['status'],
            'gender' => $row['gender'],
            'phone' => $row['phone'],
            'class_id' => $row['class_id'] ? (int)$row['class_id'] : null,
            'class_name' => $row['class_name'],
            'department' => $row['department'],
            'allowed' => $feeCheck['allowed'],
            'fee_reason' => $feeCheck['reason'],
            'shortfall' => (float)$feeCheck['shortfall'],
            'last_scan' => $lastScan,
            'attendance_status' => $attendanceStatus,
            'total_fees' => getStudentTotalFees($row['id'], $term['id']),
            'total_paid' => getStudentTotalPaid($row['id'], $term['id'])
        ];
    }
    
    // Get term info for cache
    $termInfo = [
        'id' => $term['id'],
        'term_name' => $term['term_name'],
        'term_number' => $term['term_number'],
        'start_date' => $term['start_date'],
        'end_date' => $term['end_date'],
        'is_active' => $term['is_active']
    ];
    
    // Get weeks info
    $weeksElapsed = getWeeksElapsed($term['start_date']);
    $expectedPercent = getExpectedFeePercentage($weeksElapsed);
    
    echo json_encode([
        'success' => true,
        'timestamp' => $now,
        'term' => $termInfo,
        'weeks_elapsed' => $weeksElapsed,
        'expected_percentage' => $expectedPercent,
        'total_students' => count($students),
        'data' => $students,
        'cache_version' => '1.0',
        'cache_expiry' => date('Y-m-d H:i:s', strtotime('+5 minutes'))
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'data' => []
    ]);
}
?>