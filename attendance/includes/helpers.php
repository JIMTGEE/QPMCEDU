<?php
// /attendance/includes/helpers.php
// Core helper functions for the attendance module

require_once __DIR__ . '/../../config/db.php'; // Adjust path as needed

/**
 * Get the currently active academic term
 * @return array|false Term data or false if none found
 */
function getActiveTerm() {
    global $conn;
    $query = "SELECT * FROM academic_terms WHERE is_active = 1 LIMIT 1";
    $result = $conn->query($query);
    return $result->fetch_assoc();
}

/**
 * Get academic year details
 * @param int $yearId
 * @return array|false
 */
function getAcademicYear($yearId) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM academic_years WHERE id = ?");
    $stmt->bind_param("i", $yearId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Calculate weeks elapsed since term start
 * @param string $startDate
 * @return int Number of weeks elapsed (minimum 1)
 */
function getWeeksElapsed($startDate) {
    $start = new DateTime($startDate);
    $now = new DateTime();
    $diff = $start->diff($now);
    $weeks = ceil($diff->days / 7);
    return max(1, $weeks);
}

/**
 * Get expected fee percentage for a given week
 * @param int $weekNumber
 * @return float Expected percentage
 */
function getExpectedFeePercentage($weekNumber) {
    global $conn;
    $stmt = $conn->prepare("SELECT expected_percentage FROM fee_payment_schedule WHERE week_number = ?");
    $stmt->bind_param("i", $weekNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row) {
        return (float)$row['expected_percentage'];
    }
    
    // Fallback: linear interpolation (10% per week, max 100%)
    return min(100, $weekNumber * 10);
}

/**
 * Calculate total fees for a student in a given term
 * @param int $userId
 * @param int $termId
 * @return float Total fees
 */
function getStudentTotalFees($userId, $termId) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT SUM(amount) as total 
        FROM fees 
        WHERE user_id = ? AND term_id = ? AND target_type = 'Learner'
    ");
    $stmt->bind_param("ii", $userId, $termId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

/**
 * Calculate total paid for a student in a given term
 * @param int $userId
 * @param int $termId
 * @return float Total paid
 */
function getStudentTotalPaid($userId, $termId) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT SUM(p.amount_paid) as total 
        FROM payments p
        JOIN fees f ON p.fee_id = f.id
        WHERE p.user_id = ? AND f.term_id = ?
    ");
    $stmt->bind_param("ii", $userId, $termId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

/**
 * Check if student meets fee threshold for entry
 * @param int $userId
 * @return array ['allowed' => bool, 'reason' => string, 'shortfall' => float]
 */
function checkFeeThreshold($userId) {
    $term = getActiveTerm();
    if (!$term) {
        return ['allowed' => false, 'reason' => 'No active academic term found', 'shortfall' => 0];
    }
    
    $weeksElapsed = getWeeksElapsed($term['start_date']);
    $expectedPercent = getExpectedFeePercentage($weeksElapsed);
    
    $totalFees = getStudentTotalFees($userId, $term['id']);
    $totalPaid = getStudentTotalPaid($userId, $term['id']);
    
    // Buffer of 10% of total fees
    $buffer = $totalFees * 0.10;
    $required = ($totalFees * $expectedPercent / 100) - $buffer;
    
    if ($totalPaid >= $required) {
        return ['allowed' => true, 'reason' => '', 'shortfall' => 0];
    }
    
    $shortfall = $required - $totalPaid;
    return [
        'allowed' => false,
        'reason' => sprintf('Fee threshold not met. Required: %.2f, Paid: %.2f, Shortfall: %.2f', $required, $totalPaid, $shortfall),
        'shortfall' => $shortfall
    ];
}

/**
 * Get student's last scan for today
 * @param int $userId
 * @return string|null 'entry' or 'exit' or null if no scan today
 */
function getLastScanToday($userId) {
    global $conn;
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT scan_type 
        FROM access_logs 
        WHERE user_id = ? AND DATE(scanned_at) = ? 
        ORDER BY scanned_at DESC 
        LIMIT 1
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? $row['scan_type'] : null;
}

/**
 * Get student's class ID
 * @param int $userId
 * @param int $termId
 * @return int|null Class ID or null if not found
 */
function getStudentClass($userId, $termId) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT class_id 
        FROM student_classes 
        WHERE user_id = ? AND term_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $userId, $termId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? (int)$row['class_id'] : null;
}

/**
 * Mark attendance for a student
 * @param int $userId
 * @param int $classId
 * @param string $status 'present' or 'late'
 * @param string|null $departureTime Optional departure time
 * @return bool
 */
function markAttendance($userId, $classId, $status, $departureTime = null) {
    global $conn;
    $date = date('Y-m-d');
    
    // Check if attendance record exists for today
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->bind_param("is", $userId, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Update existing record
        if ($departureTime) {
            $stmt = $conn->prepare("UPDATE attendance SET departure_time = ? WHERE id = ?");
            $stmt->bind_param("si", $departureTime, $row['id']);
        } else {
            $stmt = $conn->prepare("UPDATE attendance SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $row['id']);
        }
    } else {
        // Insert new record
        $stmt = $conn->prepare("
            INSERT INTO attendance (user_id, class_id, date, status, departure_time) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iisss", $userId, $classId, $date, $status, $departureTime);
    }
    
    return $stmt->execute();
}

/**
 * Record access log
 * @param int $userId
 * @param string $scanType 'entry' or 'exit'
 * @param string $decision 'allowed', 'denied', or 'overridden'
 * @param string|null $autoReason
 * @param int|null $overriddenBy
 * @param string|null $overrideReason
 * @param string|null $qrCode
 * @param string $location
 * @return int|false Inserted ID or false
 */
function logAccess($userId, $scanType, $decision, $autoReason = null, $overriddenBy = null, $overrideReason = null, $qrCode = null, $location = 'Main Gate') {
    global $conn;
    $scannedAt = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("
        INSERT INTO access_logs 
        (user_id, scan_type, scanned_at, decision, auto_reason, overridden_by, override_reason, qr_code, location) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issssiss", $userId, $scanType, $scannedAt, $decision, $autoReason, $overriddenBy, $overrideReason, $qrCode, $location);
    
    if ($stmt->execute()) {
        return $conn->insert_id;
    }
    return false;
}

/**
 * Staff clock in/out
 * @param int $userId
 * @return array ['action' => 'in'|'out', 'time' => string, 'message' => string]
 */
function staffClock($userId) {
    global $conn;
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    
    // Check if staff already clocked in today without clock out
    $stmt = $conn->prepare("
        SELECT id, clock_in, clock_out 
        FROM staff_attendance_logs 
        WHERE user_id = ? AND date = ? 
        ORDER BY clock_in DESC 
        LIMIT 1
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row && is_null($row['clock_out'])) {
        // Clock out
        $stmt = $conn->prepare("UPDATE staff_attendance_logs SET clock_out = ? WHERE id = ?");
        $stmt->bind_param("si", $now, $row['id']);
        $stmt->execute();
        
        return [
            'action' => 'out',
            'time' => $now,
            'message' => 'Clocked out at ' . date('H:i', strtotime($now))
        ];
    } else {
        // Clock in
        $status = 'present';
        // Check if late (after 8:30 AM)
        $cutoff = strtotime($today . ' 08:30:00');
        if (strtotime($now) > $cutoff) {
            $status = 'late';
        }
        
        $stmt = $conn->prepare("
            INSERT INTO staff_attendance_logs (user_id, clock_in, date, status) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $userId, $now, $today, $status);
        $stmt->execute();
        
        return [
            'action' => 'in',
            'time' => $now,
            'message' => 'Clocked in at ' . date('H:i', strtotime($now))
        ];
    }
}

/**
 * Get student details including photo
 * @param int $userId
 * @return array|false
 */
function getStudentDetails($userId) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT 
            u.id, 
            u.name, 
            u.email, 
            u.profile_picture as photo,
            u.status,
            u.gender,
            s.class_id,
            c.class_name
        FROM users u
        LEFT JOIN student_classes s ON u.id = s.user_id
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE u.id = ? AND u.role = 'student'
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Check if user is staff (teacher, admin, security, head_academics)
 * @param int $userId
 * @return bool
 */
function isStaffUser($userId) {
    global $conn;
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row && in_array($row['role'], ['admin', 'teacher', 'security', 'head_academics']);
}

/**
 * Determine if a user is a student
 * @param int $userId
 * @return bool
 */
function isStudent($userId) {
    global $conn;
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row && $row['role'] === 'student';
}

/**
 * Check if student is enrolled in a class for current term
 * @param int $userId
 * @param int $termId
 * @return bool
 */
function isStudentEnrolled($userId, $termId) {
    $classId = getStudentClass($userId, $termId);
    return !is_null($classId);
}

/**
 * Generate speech text for a scan result
 * @param array $result Scan result from checkFeeThreshold or similar
 * @param string $scanType 'entry' or 'exit'
 * @param string $studentName
 * @return string Speech text
 */
function generateSpeechText($result, $scanType, $studentName) {
    if ($scanType === 'exit') {
        return "Goodbye, " . $studentName . ". Have a great day!";
    }
    
    if ($result['allowed']) {
        return "Welcome, " . $studentName . ". You may enter.";
    }
    
    return "Access denied for " . $studentName . ". " . $result['reason'];
}