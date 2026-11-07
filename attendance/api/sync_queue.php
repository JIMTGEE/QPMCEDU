<?php
// /attendance/api/sync_queue.php
// Receives queued offline scans and processes them

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only staff can sync
requireRoleAPI(['admin', 'teacher', 'security', 'head_academics']);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$queue = $input['queue'] ?? [];
$location = $input['location'] ?? 'Main Gate';

if (empty($queue)) {
    echo json_encode(['success' => true, 'processed' => 0, 'message' => 'No items to sync']);
    exit();
}

try {
    $processed = 0;
    $errors = [];
    $results = [];
    
    foreach ($queue as $item) {
        $qrData = $item['qr'] ?? '';
        $scanLocation = $item['location'] ?? $location;
        $timestamp = $item['timestamp'] ?? date('Y-m-d H:i:s');
        
        if (empty($qrData)) {
            $errors[] = 'Missing QR data for item';
            continue;
        }
        
        // Extract user ID
        $parts = explode('|', $qrData);
        $userId = (int)($parts[0] ?? 0);
        
        if ($userId <= 0) {
            $errors[] = 'Invalid QR format for user ID';
            continue;
        }
        
        // Get user details
        $stmt = $conn->prepare("SELECT id, name, role, status FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user || $user['status'] !== 'active') {
            $errors[] = "User $userId not found or inactive";
            continue;
        }
        
        // Determine if student or staff
        if (in_array($user['role'], ['admin', 'teacher', 'security', 'head_academics'])) {
            // Staff clock in/out - use current time for sync
            $clockResult = staffClock($userId);
            
            logAccess(
                $userId,
                $clockResult['action'] === 'in' ? 'entry' : 'exit',
                'allowed',
                null,
                null,
                null,
                $qrData,
                $scanLocation
            );
            
            $results[] = [
                'user_id' => $userId,
                'name' => $user['name'],
                'type' => 'staff',
                'action' => $clockResult['action'],
                'status' => 'processed'
            ];
            
            $processed++;
            continue;
        }
        
        // Student processing
        if ($user['role'] !== 'student') {
            $errors[] = "User $userId is not a student or staff";
            continue;
        }
        
        // Check if enrolled
        $term = getActiveTerm();
        if (!$term) {
            $errors[] = "No active academic term";
            continue;
        }
        
        if (!isStudentEnrolled($userId, $term['id'])) {
            $errors[] = "Student $userId not enrolled";
            continue;
        }
        
        // Determine scan type based on timestamp and existing records
        $lastScan = getLastScanToday($userId);
        $scanType = ($lastScan === 'entry') ? 'exit' : 'entry';
        
        $decision = 'allowed';
        $autoReason = null;
        
        if ($scanType === 'entry') {
            $feeCheck = checkFeeThreshold($userId);
            if (!$feeCheck['allowed']) {
                $decision = 'denied';
                $autoReason = $feeCheck['reason'];
            }
        }
        
        // Log the scan
        $logId = logAccess(
            $userId,
            $scanType,
            $decision,
            $autoReason,
            null,
            null,
            $qrData,
            $scanLocation
        );
        
        if ($logId) {
            // Mark attendance if entry allowed
            if ($scanType === 'entry' && $decision === 'allowed') {
                $studentDetails = getStudentDetails($userId);
                if ($studentDetails && $studentDetails['class_id']) {
                    $currentTime = strtotime(date('H:i:s'));
                    $cutoffTime = strtotime('08:30:00');
                    $status = ($currentTime > $cutoffTime) ? 'late' : 'present';
                    markAttendance($userId, $studentDetails['class_id'], $status);
                }
            } elseif ($scanType === 'exit') {
                $studentDetails = getStudentDetails($userId);
                if ($studentDetails && $studentDetails['class_id']) {
                    markAttendance($userId, $studentDetails['class_id'], 'present', date('Y-m-d H:i:s'));
                }
            }
            
            $results[] = [
                'user_id' => $userId,
                'name' => $user['name'],
                'scan_type' => $scanType,
                'decision' => $decision,
                'log_id' => $logId,
                'status' => 'processed'
            ];
            
            $processed++;
        } else {
            $errors[] = "Failed to log scan for user $userId";
        }
    }
    
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'total' => count($queue),
        'results' => $results,
        'errors' => $errors,
        'message' => "Processed $processed of " . count($queue) . " offline scans"
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>