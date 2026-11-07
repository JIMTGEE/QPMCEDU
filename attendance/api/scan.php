<?php
// /attendance/api/scan.php
// Main scan endpoint - handles student entry/exit and staff clock in/out

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only staff can scan
requireRoleAPI(['admin', 'teacher', 'security', 'head_academics']);

header('Content-Type: application/json');

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$qrData = $input['qr'] ?? '';
$location = $input['location'] ?? 'Main Gate';
$isOffline = $input['offline'] ?? false;

if (empty($qrData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'QR data is required']);
    exit();
}

try {
    // Extract user ID from QR (format: USER_ID|HASHED_PASSWORD)
    $parts = explode('|', $qrData);
    $userId = (int)($parts[0] ?? 0);
    
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid QR code format']);
        exit();
    }
    
    // Get user details
    $stmt = $conn->prepare("SELECT id, name, email, role, status, profile_picture FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }
    
    if ($user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'User account is not active']);
        exit();
    }
    
    $currentUserRole = getCurrentUserRole();
    $currentUserId = getCurrentUserId();
    
    // Check if user is staff
    if (in_array($user['role'], ['admin', 'teacher', 'security', 'head_academics'])) {
        // Staff clock in/out
        $clockResult = staffClock($userId);
        
        // Log the scan
        logAccess(
            $userId,
            $clockResult['action'] === 'in' ? 'entry' : 'exit',
            'allowed',
            null,
            null,
            null,
            $qrData,
            $location
        );
        
        echo json_encode([
            'success' => true,
            'user_type' => 'staff',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'role' => $user['role'],
                'photo' => $user['profile_picture'] ?: 'assets/default-avatar.png'
            ],
            'action' => $clockResult['action'],
            'time' => $clockResult['time'],
            'message' => $clockResult['message'],
            'speech' => $clockResult['message'],
            'is_staff' => true
        ]);
        exit();
    }
    
    // --- Student processing ---
    if ($user['role'] !== 'student') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid user type for gate access']);
        exit();
    }
    
    // Get student details with class
    $studentDetails = getStudentDetails($userId);
    if (!$studentDetails) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student details not found']);
        exit();
    }
    
    // Check if student is enrolled
    $term = getActiveTerm();
    if (!$term) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No active academic term']);
        exit();
    }
    
    if (!isStudentEnrolled($userId, $term['id'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Student is not enrolled in any class for the current term',
            'student' => $studentDetails
        ]);
        exit();
    }
    
    // Determine scan type (entry or exit)
    $lastScan = getLastScanToday($userId);
    $scanType = ($lastScan === 'entry') ? 'exit' : 'entry';
    
    // For entry, check fee threshold
    $feeCheck = ['allowed' => true, 'reason' => '', 'shortfall' => 0];
    $decision = 'allowed';
    $autoReason = null;
    
    if ($scanType === 'entry') {
        $feeCheck = checkFeeThreshold($userId);
        if (!$feeCheck['allowed']) {
            $decision = 'denied';
            $autoReason = $feeCheck['reason'];
        }
    }
    
    // Process the scan
    $logId = logAccess(
        $userId,
        $scanType,
        $decision,
        $autoReason,
        null,
        null,
        $qrData,
        $location
    );
    
    if (!$logId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to log access']);
        exit();
    }
    
    // Mark attendance if entry is allowed or overridden
    $attendanceStatus = null;
    if ($scanType === 'entry' && $decision === 'allowed') {
        // Determine if late (after 8:30 AM)
        $currentTime = strtotime(date('H:i:s'));
        $cutoffTime = strtotime('08:30:00');
        $status = ($currentTime > $cutoffTime) ? 'late' : 'present';
        
        markAttendance($userId, $studentDetails['class_id'], $status);
        $attendanceStatus = $status;
    } elseif ($scanType === 'exit') {
        // Update departure time
        markAttendance($userId, $studentDetails['class_id'], 'present', date('Y-m-d H:i:s'));
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'user_type' => 'student',
        'student' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'photo' => $user['profile_picture'] ?: 'assets/default-avatar.png',
            'class_name' => $studentDetails['class_name'] ?? 'N/A',
            'gender' => $studentDetails['gender'] ?? ''
        ],
        'scan_type' => $scanType,
        'decision' => $decision,
        'log_id' => (int)$logId,
        'can_override' => canOverride(),
        'attendance_status' => $attendanceStatus,
        'fee_info' => [
            'allowed' => $feeCheck['allowed'],
            'reason' => $feeCheck['reason'],
            'shortfall' => (float)$feeCheck['shortfall']
        ]
    ];
    
    // Generate speech text
    $speech = '';
    if ($scanType === 'exit') {
        $speech = "Goodbye, " . $user['name'] . ". Have a great day!";
    } else if ($decision === 'allowed') {
        $speech = "Welcome, " . $user['name'] . ". You may enter.";
    } else {
        $speech = "Access denied for " . $user['name'] . ". " . $feeCheck['reason'];
    }
    
    $response['speech'] = $speech;
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>