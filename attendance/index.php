<?php
// /attendance/index.php
// ENHANCED GATE ACCESS SCANNER – with camera permission, live feed fix, full user details

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

// Default test user
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'Admin User';
$_SESSION['logged_in'] = true;

if (!isset($_SESSION['device_id'])) {
    $_SESSION['device_id'] = 'DEV-' . strtoupper(substr(md5(uniqid() . $_SERVER['REMOTE_ADDR']), 0, 8));
}
$deviceId = $_SESSION['device_id'];
$isAdminDevice = ($_SESSION['role'] === 'admin');

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function getActiveTerm() {
    global $conn;
    $q = "SELECT * FROM academic_terms WHERE is_active = 1 LIMIT 1";
    $r = $conn->query($q);
    return $r->fetch_assoc();
}
function getActiveAcademicYear() {
    global $conn;
    $q = "SELECT * FROM academic_years WHERE is_active = 1 LIMIT 1";
    $r = $conn->query($q);
    return $r->fetch_assoc();
}
function getStudentNetDebt($userId, $termId) {
    global $conn;
    $stmt = $conn->prepare("SELECT SUM(amount) as total_fees FROM fees WHERE user_id = ? AND term_id = ? AND target_type IN ('Learner','Student')");
    $stmt->bind_param("ii", $userId, $termId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalFees = (float)($row['total_fees'] ?? 0);
    $stmt = $conn->prepare("SELECT SUM(p.amount_paid) as total_paid FROM payments p JOIN fees f ON p.fee_id = f.id WHERE p.user_id = ? AND f.term_id = ?");
    $stmt->bind_param("ii", $userId, $termId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalPaid = (float)($row['total_paid'] ?? 0);
    return max(0, $totalFees - $totalPaid);
}
function checkFeeThreshold($userId) {
    $term = getActiveTerm();
    if (!$term) return ['allowed' => true, 'reason' => 'No active term', 'status' => 'Fees OK', 'debt' => 0];
    $debt = getStudentNetDebt($userId, $term['id']);
    $threshold = 3000;
    if ($debt > $threshold) {
        return ['allowed' => false, 'reason' => "Debt exceeds limit: GHS $debt (limit: GHS $threshold)", 'status' => 'Debt Exceeded', 'debt' => $debt];
    }
    return ['allowed' => true, 'reason' => '', 'status' => 'Fees OK', 'debt' => $debt];
}
function getLastScanToday($userId) {
    global $conn;
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT scan_type FROM access_logs WHERE user_id = ? AND DATE(scanned_at) = ? ORDER BY scanned_at DESC LIMIT 1");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? $row['scan_type'] : null;
}
function getStudentDetails($userId) {
    global $conn;
    $stmt = $conn->prepare("SELECT id, name, email, profile_picture as photo, status, gender, phone FROM users WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
function getStudentClassInfo($userId) {
    global $conn;
    $ay = getActiveAcademicYear();
    if ($ay) {
        $stmt = $conn->prepare("SELECT sc.class_id, c.class_name, c.department FROM student_classes sc LEFT JOIN classes c ON sc.class_id = c.id WHERE sc.user_id = ? AND sc.academic_year_id = ? LIMIT 1");
        $stmt->bind_param("ii", $userId, $ay['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) return $row;
    }
    $stmt = $conn->prepare("SELECT sc.class_id, c.class_name, c.department FROM student_classes sc LEFT JOIN classes c ON sc.class_id = c.id WHERE sc.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
function markAttendance($userId, $classId, $status, $departureTime = null) {
    global $conn;
    $date = date('Y-m-d');
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->bind_param("is", $userId, $date);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        if ($departureTime) {
            $stmt = $conn->prepare("UPDATE attendance SET departure_time = ? WHERE id = ?");
            $stmt->bind_param("si", $departureTime, $row['id']);
        } else {
            $stmt = $conn->prepare("UPDATE attendance SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $row['id']);
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO attendance (user_id, class_id, date, status, departure_time) VALUES (?,?,?,?,?)");
        $stmt->bind_param("iisss", $userId, $classId, $date, $status, $departureTime);
    }
    return $stmt->execute();
}
function logAccess($userId, $scanType, $decision, $autoReason = null, $qrCode = null, $location = 'Main Gate', $deviceId = null) {
    global $conn;
    $scannedAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO access_logs (user_id, scan_type, scanned_at, decision, auto_reason, qr_code, location) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("issssss", $userId, $scanType, $scannedAt, $decision, $autoReason, $qrCode, $location);
    return $stmt->execute() ? $conn->insert_id : false;
}
function staffClock($userId) {
    global $conn;
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT id, clock_in, clock_out FROM staff_attendance_logs WHERE user_id = ? AND date = ? ORDER BY clock_in DESC LIMIT 1");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && is_null($row['clock_out'])) {
        $stmt = $conn->prepare("UPDATE staff_attendance_logs SET clock_out = ? WHERE id = ?");
        $stmt->bind_param("si", $now, $row['id']);
        $stmt->execute();
        return ['action' => 'out', 'time' => $now, 'message' => 'Clocked out at ' . date('H:i', strtotime($now))];
    } else {
        $status = 'present';
        $cutoff = strtotime($today . ' 08:30:00');
        if (strtotime($now) > $cutoff) $status = 'late';
        $stmt = $conn->prepare("INSERT INTO staff_attendance_logs (user_id, clock_in, date, status) VALUES (?,?,?,?)");
        $stmt->bind_param("isss", $userId, $now, $today, $status);
        $stmt->execute();
        return ['action' => 'in', 'time' => $now, 'message' => 'Clocked in at ' . date('H:i', strtotime($now))];
    }
}

// ─── AJAX HANDLERS ──────────────────────────────────────────────────────────
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Scan
    if ($action === 'scan') {
        $qrData = $_POST['qr'] ?? '';
        $location = $_POST['location'] ?? 'Main Gate';
        $deviceId = $_POST['device_id'] ?? $_SESSION['device_id'] ?? 'UNKNOWN';
        if (empty($qrData)) { echo json_encode(['success' => false, 'error' => 'QR data required']); exit; }
        try {
            $parts = explode('|', $qrData);
            $userId = (int)($parts[0] ?? 0);
            if ($userId <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid QR']); exit; }
            $stmt = $conn->prepare("SELECT id, name, email, role, status, profile_picture FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if (!$user || $user['status'] !== 'active') {
                echo json_encode(['success' => false, 'error' => 'User not found or inactive']); exit;
            }
            $currentTime = time();
            $timeStr = date('H:i', $currentTime);

            // Staff
            if (in_array($user['role'], ['admin','teacher','security','head_academics'])) {
                $lastScan = getLastScanToday($userId);
                $scanType = ($lastScan === 'entry') ? 'exit' : 'entry';
                if ($scanType === 'entry') {
                    $cutoff = strtotime('07:00:00');
                    if ($currentTime > $cutoff) {
                        $decision = 'denied';
                        $autoReason = 'Staff entry after 7:00 AM is not allowed.';
                        logAccess($userId, 'entry', $decision, $autoReason, $qrData, $location);
                        $firstName = explode(' ', trim($user['name']))[0] ?? $user['name'];
                        echo json_encode([
                            'success' => true,
                            'user_type' => 'staff',
                            'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role'], 'photo' => $user['profile_picture'] ?: '/assets/default_avatar.png'],
                            'scan_type' => 'entry',
                            'decision' => 'denied',
                            'message' => $autoReason,
                            'speech' => "Access denied for $firstName. $autoReason",
                            'is_staff' => true,
                            'device_id' => $deviceId
                        ]); exit;
                    }
                }
                $clockResult = staffClock($userId);
                logAccess($userId, $clockResult['action'] === 'in' ? 'entry' : 'exit', 'allowed', null, $qrData, $location);
                echo json_encode([
                    'success' => true,
                    'user_type' => 'staff',
                    'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role'], 'photo' => $user['profile_picture'] ?: '/assets/default_avatar.png'],
                    'action' => $clockResult['action'],
                    'message' => $clockResult['message'],
                    'speech' => $clockResult['message'],
                    'is_staff' => true,
                    'device_id' => $deviceId
                ]); exit;
            }

            // Student
            if ($user['role'] !== 'student') {
                echo json_encode(['success' => false, 'error' => 'Invalid user type']); exit;
            }
            $studentDetails = getStudentDetails($userId);
            $classInfo = getStudentClassInfo($userId);
            $lastScan = getLastScanToday($userId);
            $scanType = ($lastScan === 'entry') ? 'exit' : 'entry';
            $decision = 'allowed';
            $autoReason = null;
            $feeStatus = 'Fees OK';

            if ($scanType === 'entry') {
                $cutoff = strtotime('08:00:00');
                if ($currentTime > $cutoff) {
                    $decision = 'denied';
                    $autoReason = 'Student entry after 8:00 AM is not allowed.';
                    $feeStatus = 'Late Entry';
                } else {
                    $feeCheck = checkFeeThreshold($userId);
                    if (!$feeCheck['allowed']) {
                        $decision = 'denied';
                        $autoReason = $feeCheck['reason'];
                        $feeStatus = $feeCheck['status'];
                    } else {
                        $feeStatus = $feeCheck['status'];
                    }
                }
            }
            $logId = logAccess($userId, $scanType, $decision, $autoReason, $qrData, $location);
            if ($scanType === 'entry' && $decision === 'allowed' && $classInfo && $classInfo['class_id']) {
                $currentTime2 = strtotime(date('H:i:s'));
                $cutoffTime = strtotime('08:30:00');
                $status = ($currentTime2 > $cutoffTime) ? 'late' : 'present';
                markAttendance($userId, $classInfo['class_id'], $status);
            } elseif ($scanType === 'exit' && $classInfo && $classInfo['class_id']) {
                markAttendance($userId, $classInfo['class_id'], 'present', date('Y-m-d H:i:s'));
            }
            $firstName = explode(' ', trim($user['name']))[0] ?? $user['name'];
            $speech = $scanType === 'exit' ? "Goodbye, $firstName. Have a great day!" :
                      ($decision === 'allowed' ? "Welcome, $firstName. $feeStatus" :
                      "Access denied for $firstName. $autoReason");

            echo json_encode([
                'success' => true,
                'user_type' => 'student',
                'student' => [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'photo' => $user['profile_picture'] ?: '/assets/default_avatar.png',
                    'class_name' => $classInfo['class_name'] ?? 'Not Assigned',
                    'department' => $classInfo['department'] ?? 'N/A',
                    'role' => 'Student',
                    'fee_status' => $feeStatus
                ],
                'scan_type' => $scanType,
                'decision' => $decision,
                'log_id' => (int)$logId,
                'can_override' => true,
                'fee_info' => ['allowed' => $decision === 'allowed', 'reason' => $autoReason ?? '', 'status' => $feeStatus],
                'speech' => $speech,
                'device_id' => $deviceId
            ]); exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]); exit;
        }
    }

    // Override
    if ($action === 'override') {
        $logId = (int)($_POST['log_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($logId <= 0 || empty($reason)) { echo json_encode(['success' => false, 'error' => 'Invalid request']); exit; }
        try {
            $stmt = $conn->prepare("SELECT id, user_id FROM access_logs WHERE id = ? AND decision = 'denied'");
            $stmt->bind_param("i", $logId);
            $stmt->execute();
            $log = $stmt->get_result()->fetch_assoc();
            if (!$log) { echo json_encode(['success' => false, 'error' => 'Log not found or already processed']); exit; }
            $stmt = $conn->prepare("UPDATE access_logs SET decision = 'overridden', overridden_by = 1, override_reason = ? WHERE id = ?");
            $stmt->bind_param("si", $reason, $logId);
            $stmt->execute();
            $classInfo = getStudentClassInfo($log['user_id']);
            if ($classInfo && $classInfo['class_id']) {
                $currentTime = strtotime(date('H:i:s'));
                $cutoffTime = strtotime('08:30:00');
                $status = ($currentTime > $cutoffTime) ? 'late' : 'present';
                markAttendance($log['user_id'], $classInfo['class_id'], $status);
            }
            echo json_encode(['success' => true, 'message' => 'Entry overridden successfully']); exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]); exit;
        }
    }

    // Get recent logs (with safe date formatting)
    if ($action === 'get_recent_logs') {
        $limit = (int)($_POST['limit'] ?? 20);
        $query = "SELECT al.*, u.name as user_name, u.profile_picture as user_photo 
                  FROM access_logs al 
                  LEFT JOIN users u ON al.user_id = u.id 
                  ORDER BY al.id DESC LIMIT $limit";
        $result = $conn->query($query);
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure scanned_at is a valid date string
            if (isset($row['scanned_at']) && $row['scanned_at'] !== '0000-00-00 00:00:00') {
                $row['scanned_at_formatted'] = date('H:i:s', strtotime($row['scanned_at']));
            } else {
                $row['scanned_at_formatted'] = '--:--:--';
            }
            $logs[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $logs]); exit;
    }

    // Get students (for cache)
    if ($action === 'get_students') {
        $query = "SELECT id, name, profile_picture as photo FROM users WHERE role = 'student' AND status = 'active' ORDER BY name ASC";
        $result = $conn->query($query);
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'photo' => $row['photo'] ?: '/assets/default_avatar.png'];
        }
        echo json_encode(['success' => true, 'data' => $students]); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gate Access Scanner</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0a0e17;
            min-height: 100vh;
            color: #fff;
        }
        .bg-pattern {
            position: fixed;
            top:0; left:0; right:0; bottom:0;
            background: radial-gradient(ellipse at 20% 50%, rgba(230,126,34,0.12) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 50%, rgba(243,156,18,0.08) 0%, transparent 50%),
                        radial-gradient(ellipse at 50% 100%, rgba(230,126,34,0.05) 0%, transparent 50%);
            z-index:0;
            pointer-events:none;
        }
        .container {
            position:relative;
            z-index:1;
            max-width:1400px;
            margin:0 auto;
            padding:20px;
        }
        /* Header */
        .header {
            background: linear-gradient(135deg, rgba(230,126,34,0.15), rgba(243,156,18,0.1));
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(230,126,34,0.2);
            border-radius: 16px;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header-left .logo {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, #e67e22, #f39c12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .header-left h1 {
            font-size: 22px;
            font-weight: 700;
            background: linear-gradient(135deg, #f39c12, #e67e22);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .header-left .subtitle {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .device-badge {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            color: rgba(255,255,255,0.7);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .device-badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4caf50;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%,100% { opacity:1; }
            50% { opacity:0.3; }
        }
        .user-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.08);
            padding: 5px 15px 5px 10px;
            border-radius: 25px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .user-badge img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 2px solid rgba(230,126,34,0.5);
            object-fit: cover;
        }
        .btn {
            padding: 8px 18px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #e67e22, #f39c12);
            color: #fff;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(230,126,34,0.3);
        }
        .btn-outline {
            background: transparent;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .btn-outline:hover {
            background: rgba(255,255,255,0.1);
        }
        /* Status Bar */
        .status-bar {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        .status-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }
        .status-item .value {
            color: #fff;
            font-weight: 600;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-dot.online {
            background: #4caf50;
            animation: pulse-dot 2s infinite;
        }
        .status-dot.offline {
            background: #f44336;
            animation: pulse-dot 1s infinite;
        }
        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 992px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }
        /* Cards */
        .card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s;
        }
        .card:hover {
            border-color: rgba(230,126,34,0.3);
        }
        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: rgba(255,255,255,0.9);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-title .badge {
            font-size: 10px;
            background: rgba(230,126,34,0.2);
            color: #f39c12;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 400;
        }
        #reader {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
            background: #000;
            position: relative;
        }
        #reader video {
            border-radius: 12px;
        }
        .manual-input {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .manual-input input {
            flex: 1;
            min-width: 150px;
            padding: 12px 18px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            font-size: 14px;
            background: rgba(255,255,255,0.05);
            color: #fff;
            transition: all 0.3s;
        }
        .manual-input input:focus {
            border-color: #e67e22;
            outline: none;
            background: rgba(255,255,255,0.08);
            box-shadow: 0 0 0 3px rgba(230,126,34,0.15);
        }
        .manual-input input::placeholder {
            color: rgba(255,255,255,0.3);
        }
        .camera-status {
            text-align: center;
            margin-top: 10px;
            font-size: 12px;
            color: rgba(255,255,255,0.4);
        }
        /* Cooldown */
        .cooldown-bar {
            margin-top: 15px;
            height: 4px;
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
            overflow: hidden;
            display: none;
        }
        .cooldown-bar.active { display: block; }
        .cooldown-bar .fill {
            height: 100%;
            background: linear-gradient(90deg, #f39c12, #e67e22);
            border-radius: 2px;
            width: 0%;
            transition: width 0.1s linear;
        }
        .cooldown-text {
            text-align: center;
            font-size: 12px;
            color: rgba(255,255,255,0.4);
            margin-top: 5px;
            display: none;
        }
        .cooldown-text.active { display: block; }
        /* Result */
        .result-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: rgba(255,255,255,0.3);
            text-align: center;
            min-height: 300px;
        }
        .result-placeholder .icon {
            font-size: 56px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .result-card {
            display: none;
            animation: slideUp 0.5s ease;
        }
        .result-card.active { display: block; }
        @keyframes slideUp {
            from { opacity:0; transform:translateY(30px) scale(0.95); }
            to { opacity:1; transform:translateY(0) scale(1); }
        }
        .result-student {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 15px;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            margin-bottom: 15px;
        }
        .result-student img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(230,126,34,0.3);
        }
        .result-student .info h3 {
            font-size: 18px;
            color: #fff;
            margin-bottom: 2px;
        }
        .result-student .info .designation {
            font-size: 13px;
            color: rgba(255,255,255,0.6);
        }
        .result-student .info .extra {
            font-size: 12px;
            color: rgba(255,255,255,0.3);
            margin-top: 2px;
        }
        .result-decision {
            padding: 14px 20px;
            border-radius: 12px;
            text-align: center;
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 15px;
        }
        .result-decision.allowed {
            background: rgba(76,175,80,0.15);
            color: #4caf50;
            border: 1px solid rgba(76,175,80,0.3);
        }
        .result-decision.denied {
            background: rgba(244,67,54,0.15);
            color: #f44336;
            border: 1px solid rgba(244,67,54,0.3);
        }
        .result-decision.overridden {
            background: rgba(243,156,18,0.15);
            color: #f39c12;
            border: 1px solid rgba(243,156,18,0.3);
        }
        .result-decision.exit {
            background: rgba(33,150,243,0.15);
            color: #2196f3;
            border: 1px solid rgba(33,150,243,0.3);
        }
        .result-decision.staff {
            background: rgba(156,39,176,0.15);
            color: #9c27b0;
            border: 1px solid rgba(156,39,176,0.3);
        }
        .result-fee-status {
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 15px;
            text-align: center;
            font-weight: 600;
        }
        .result-fee-status.ok {
            background: rgba(76,175,80,0.1);
            color: #4caf50;
            border: 1px solid rgba(76,175,80,0.2);
        }
        .result-fee-status.pending {
            background: rgba(243,156,18,0.1);
            color: #f39c12;
            border: 1px solid rgba(243,156,18,0.2);
        }
        .result-fee-status.denied {
            background: rgba(244,67,54,0.1);
            color: #f44336;
            border: 1px solid rgba(244,67,54,0.2);
        }
        .result-reason {
            padding: 12px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            font-size: 13px;
            color: rgba(255,255,255,0.6);
            margin-bottom: 15px;
            display: none;
        }
        .result-reason.show { display: block; }
        .result-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-override {
            padding: 12px 25px;
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            flex: 1;
            min-width: 120px;
        }
        .btn-override:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(230,126,34,0.3);
        }
        .btn-override:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .btn-reset {
            padding: 12px 25px;
            background: rgba(255,255,255,0.08);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-reset:hover {
            background: rgba(255,255,255,0.15);
        }
        /* Live Feed */
        .live-feed {
            margin-top: 20px;
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .live-feed .feed-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 14px;
            color: rgba(255,255,255,0.6);
        }
        .live-feed .feed-header .count {
            background: rgba(230,126,34,0.2);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            color: #f39c12;
        }
        .feed-scroll {
            max-height: 200px;
            overflow-y: auto;
        }
        .feed-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 8px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }
        .feed-item .time {
            font-size: 11px;
            color: rgba(255,255,255,0.3);
            min-width: 60px;
        }
        .feed-item .name {
            font-weight: 500;
            color: #fff;
            flex: 1;
        }
        .feed-item .decision-tag {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        .feed-item .decision-tag.allowed {
            background: rgba(76,175,80,0.2);
            color: #4caf50;
        }
        .feed-item .decision-tag.denied {
            background: rgba(244,67,54,0.2);
            color: #f44336;
        }
        .feed-item .decision-tag.overridden {
            background: rgba(243,156,18,0.2);
            color: #f39c12;
        }
        .feed-item .device-tag {
            font-size: 10px;
            color: rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.05);
            padding: 1px 6px;
            border-radius: 8px;
        }
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top:0; left:0; right:0; bottom:0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(10px);
            z-index:1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: #1a1a2e;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 100%;
            animation: slideUp 0.3s ease;
        }
        .modal h3 {
            font-size: 20px;
            color: #fff;
            margin-bottom: 10px;
        }
        .modal p {
            color: rgba(255,255,255,0.6);
            margin-bottom: 20px;
        }
        .modal textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            font-size: 14px;
            min-height: 80px;
            resize: vertical;
            font-family: inherit;
            background: rgba(255,255,255,0.05);
            color: #fff;
            transition: all 0.3s;
        }
        .modal textarea:focus {
            border-color: #e67e22;
            outline: none;
            background: rgba(255,255,255,0.08);
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: flex-end;
        }
        .modal-actions button {
            padding: 10px 25px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-confirm-override {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: #fff;
        }
        .btn-confirm-override:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(230,126,34,0.3);
        }
        .btn-cancel {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .btn-cancel:hover {
            background: rgba(255,255,255,0.15);
        }
        /* Toast */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 15px 25px;
            border-radius: 12px;
            color: #fff;
            font-weight: 600;
            z-index: 2000;
            animation: slideUp 0.3s ease;
            max-width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            backdrop-filter: blur(10px);
        }
        .toast.success { background: rgba(76,175,80,0.9); border: 1px solid rgba(76,175,80,0.3); }
        .toast.error { background: rgba(244,67,54,0.9); border: 1px solid rgba(244,67,54,0.3); }
        .toast.warning { background: rgba(243,156,18,0.9); border: 1px solid rgba(243,156,18,0.3); }
        .toast.info { background: rgba(33,150,243,0.9); border: 1px solid rgba(33,150,243,0.3); }
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @media (max-width: 600px) {
            .container { padding: 10px; }
            .header { padding: 12px 15px; }
            .header-left h1 { font-size: 16px; }
            .main-grid { gap: 10px; }
            .card { padding: 16px; }
            .result-student { flex-direction: column; text-align: center; }
            .modal { padding: 20px; margin: 10px; }
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        ::-webkit-scrollbar-thumb { background: rgba(230,126,34,0.3); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(230,126,34,0.5); }
    </style>
</head>
<body>

<div class="bg-pattern"></div>

<div class="container">
    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <div class="logo">🚪</div>
            <div>
                <h1>Gate Access Scanner</h1>
                <div class="subtitle">Real‑time Entry/Exit Control</div>
            </div>
        </div>
        <div class="header-right">
            <div class="device-badge">
                <span class="dot"></span>
                <span id="deviceId"><?php echo htmlspecialchars($deviceId); ?></span>
            </div>
            <div class="user-badge">
                <img src="/assets/default_avatar.png" alt="User">
                <span>Test Mode</span>
                <span style="font-size:10px;background:rgba(230,126,34,0.3);padding:2px 8px;border-radius:10px;">Admin</span>
            </div>
            <a href="/attendance/admin_dashboard.php" class="btn btn-primary" target="_blank">📊 Dashboard</a>
            <button class="btn btn-outline" onclick="location.reload()">🔄 Reload</button>
        </div>
    </header>

    <!-- Status Bar -->
    <div class="status-bar">
        <div class="status-item">
            <span class="status-dot online" id="statusDot"></span>
            <span id="statusText">Online</span>
        </div>
        <div class="status-item">
            📍 <span>Location:</span>
            <select id="locationSelect" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:4px 10px;color:#fff;font-size:13px;">
                <option value="Main Gate">Main Gate</option>
                <option value="Side Gate">Side Gate</option>
                <option value="Back Gate">Back Gate</option>
                <option value="Admin Block">Admin Block</option>
                <option value="Science Block">Science Block</option>
            </select>
        </div>
        <div class="status-item">
            <span id="lastScanTime">Last scan: --</span>
        </div>
        <div class="status-item">
            <span id="cooldownDisplay" style="display:none;">⏱️ <span id="cooldownTimer">5</span>s</span>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="main-grid">
        <!-- Scanner Section -->
        <div class="card">
            <div class="card-title">
                📷 Scan QR Code
                <span class="badge" id="scanModeBadge">Student Mode</span>
            </div>
            <div id="reader"></div>

            <!-- Cooldown -->
            <div class="cooldown-bar" id="cooldownBar">
                <div class="fill" id="cooldownFill"></div>
            </div>
            <div class="cooldown-text" id="cooldownText">⏱️ Please wait <span id="cooldownCount">5</span>s before next scan</div>

            <div class="manual-input">
                <input type="text" id="manualId" placeholder="Enter ID manually..." onkeydown="if(event.key==='Enter') handleManualScan()">
                <button class="btn btn-primary" onclick="handleManualScan()">🔍 Scan</button>
            </div>
            <div class="camera-status" id="cameraStatus">📷 Camera ready</div>
        </div>

        <!-- Result Section -->
        <div class="card">
            <div class="card-title">📋 Scan Result</div>

            <div class="result-placeholder" id="placeholder">
                <div class="icon">📱</div>
                <p>Scan a student or staff QR code</p>
                <p style="font-size:13px;color:rgba(255,255,255,0.2);margin-top:5px;">Point the camera at the QR code on the ID card</p>
            </div>

            <div class="result-card" id="resultCard">
                <div class="result-student">
                    <img id="resultPhoto" src="/assets/default_avatar.png" alt="Photo">
                    <div class="info">
                        <h3 id="resultName">-</h3>
                        <div class="designation" id="resultDesignation">-</div>
                        <div class="extra" id="resultExtra">-</div>
                    </div>
                </div>

                <div class="result-decision" id="resultBadge">-</div>

                <div class="result-fee-status" id="feeStatus" style="display:none;">-</div>

                <div class="result-reason" id="resultReason">
                    <strong>Reason:</strong> <span id="resultReasonText"></span>
                </div>

                <div style="font-size:12px;color:rgba(255,255,255,0.3);margin-bottom:10px;" id="resultMeta">
                    <span id="resultTime"></span>
                </div>

                <div class="result-actions">
                    <button class="btn-override" id="overrideBtn" style="display:none;" onclick="showOverrideModal()">
                        ⚡ Allow Entry (Override)
                    </button>
                    <button class="btn-reset" onclick="resetScanner()">🔄 New Scan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Feed (admin only) -->
    <?php if ($isAdminDevice): ?>
    <div class="live-feed" id="liveFeed">
        <div class="feed-header">
            <span><i class="fas fa-history"></i> Live Activity Feed</span>
            <span class="count" id="feedCount">0 events</span>
        </div>
        <div class="feed-scroll" id="feedScroll">
            <div id="feedItems">
                <div style="text-align:center;color:rgba(255,255,255,0.2);padding:20px;font-size:13px;">
                    Waiting for scans...
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Override Modal -->
<div class="modal-overlay" id="overrideModal">
    <div class="modal">
        <h3>⚡ Override Denied Entry</h3>
        <p>You are about to override a denied entry for <strong id="overrideName">-</strong>. Please provide a valid reason.</p>
        <textarea id="overrideReason" placeholder="Enter the reason for overriding this entry (mandatory)..."></textarea>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeOverrideModal()">Cancel</button>
            <button class="btn-confirm-override" onclick="confirmOverride()">✅ Confirm Override</button>
        </div>
    </div>
</div>

<script>
// ============================================
// CONFIGURATION
// ============================================
const CONFIG = {
    COOLDOWN: 5,
    API_URL: window.location.href,
    DEVICE_ID: '<?php echo htmlspecialchars($deviceId); ?>',
    IS_ADMIN: <?php echo $isAdminDevice ? 'true' : 'false'; ?>
};

// ============================================
// STATE
// ============================================
let state = {
    currentScan: null,
    isProcessing: false,
    isOnline: navigator.onLine,
    overrideLogId: null,
    speechSynth: window.speechSynthesis,
    html5QrCode: null,
    isScanning: false,
    cooldownActive: false,
    cooldownTimer: null,
    cooldownSeconds: CONFIG.COOLDOWN,
    audioContext: null,
    feedInterval: null
};

// ============================================
// DOM REFS
// ============================================
const $ = id => document.getElementById(id);
const dom = {
    reader: $('reader'),
    statusDot: $('statusDot'),
    statusText: $('statusText'),
    lastScanTime: $('lastScanTime'),
    cooldownDisplay: $('cooldownDisplay'),
    cooldownTimer: $('cooldownTimer'),
    cooldownBar: $('cooldownBar'),
    cooldownFill: $('cooldownFill'),
    cooldownText: $('cooldownText'),
    cooldownCount: $('cooldownCount'),
    locationSelect: $('locationSelect'),
    manualId: $('manualId'),
    resultCard: $('resultCard'),
    placeholder: $('placeholder'),
    resultPhoto: $('resultPhoto'),
    resultName: $('resultName'),
    resultDesignation: $('resultDesignation'),
    resultExtra: $('resultExtra'),
    resultBadge: $('resultBadge'),
    feeStatus: $('feeStatus'),
    resultReason: $('resultReason'),
    resultReasonText: $('resultReasonText'),
    resultTime: $('resultTime'),
    overrideBtn: $('overrideBtn'),
    overrideModal: $('overrideModal'),
    overrideName: $('overrideName'),
    overrideReason: $('overrideReason'),
    cameraStatus: $('cameraStatus'),
    deviceId: $('deviceId'),
    feedItems: $('feedItems'),
    feedCount: $('feedCount')
};

// ============================================
// AUDIO - BEEP SOUND
// ============================================
function playBeep(frequency = 800, duration = 300, volume = 0.8) {
    try {
        if (!state.audioContext) {
            state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        const oscillator = state.audioContext.createOscillator();
        const gainNode = state.audioContext.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(state.audioContext.destination);
        oscillator.frequency.value = frequency;
        oscillator.type = 'sine';
        gainNode.gain.setValueAtTime(0, state.audioContext.currentTime);
        gainNode.gain.linearRampToValueAtTime(volume, state.audioContext.currentTime + 0.05);
        gainNode.gain.linearRampToValueAtTime(volume, state.audioContext.currentTime + duration/1000 - 0.05);
        gainNode.gain.linearRampToValueAtTime(0, state.audioContext.currentTime + duration/1000);
        oscillator.start(state.audioContext.currentTime);
        oscillator.stop(state.audioContext.currentTime + duration/1000);

        if (frequency === 400) {
            setTimeout(() => {
                const osc2 = state.audioContext.createOscillator();
                const gain2 = state.audioContext.createGain();
                osc2.connect(gain2);
                gain2.connect(state.audioContext.destination);
                osc2.frequency.value = 400;
                osc2.type = 'sine';
                gain2.gain.setValueAtTime(0, state.audioContext.currentTime);
                gain2.gain.linearRampToValueAtTime(volume * 0.7, state.audioContext.currentTime + 0.05);
                gain2.gain.linearRampToValueAtTime(volume * 0.7, state.audioContext.currentTime + 0.2);
                gain2.gain.linearRampToValueAtTime(0, state.audioContext.currentTime + 0.3);
                osc2.start(state.audioContext.currentTime + 0.1);
                osc2.stop(state.audioContext.currentTime + 0.4);
            }, 200);
        }
        if (frequency === 1200) {
            setTimeout(() => {
                const osc2 = state.audioContext.createOscillator();
                const gain2 = state.audioContext.createGain();
                osc2.connect(gain2);
                gain2.connect(state.audioContext.destination);
                osc2.frequency.value = 1500;
                osc2.type = 'sine';
                gain2.gain.setValueAtTime(0, state.audioContext.currentTime);
                gain2.gain.linearRampToValueAtTime(volume * 0.5, state.audioContext.currentTime + 0.05);
                gain2.gain.linearRampToValueAtTime(0, state.audioContext.currentTime + 0.2);
                osc2.start(state.audioContext.currentTime + 0.1);
                osc2.stop(state.audioContext.currentTime + 0.3);
            }, 150);
        }
    } catch (e) { console.log('Audio error:', e); }
}
function playSuccessBeep() { playBeep(1200, 200, 0.7); }
function playDeniedBeep() { playBeep(400, 400, 0.8); }
function playScanBeep() { playBeep(600, 150, 0.6); }

// ============================================
// CAMERA PERMISSION REQUEST
// ============================================
function requestCameraPermission() {
    return new Promise((resolve, reject) => {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            reject(new Error('getUserMedia not supported'));
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
            .then(stream => {
                stream.getTracks().forEach(t => t.stop());
                resolve(true);
            })
            .catch(err => {
                reject(err);
            });
    });
}

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🔍 Gate Access Scanner - Enhanced');
    console.log('📱 Device ID:', CONFIG.DEVICE_ID);
    console.log('⏱️ Cooldown: ' + CONFIG.COOLDOWN + 's');

    // Request camera permission explicitly
    try {
        await requestCameraPermission();
        console.log('✅ Camera permission granted');
        dom.cameraStatus.textContent = '📷 Camera permission granted';
    } catch (err) {
        console.warn('❌ Camera permission denied:', err);
        dom.cameraStatus.textContent = '⚠️ Camera permission denied. Use manual entry.';
        showToast('Camera access denied. Please allow camera in browser settings or use manual entry.', 'error');
        // We'll still try to initialize scanner – it will fail gracefully
    }

    const savedLocation = localStorage.getItem('attendance_location') || 'Main Gate';
    dom.locationSelect.value = savedLocation;
    dom.locationSelect.addEventListener('change', function() {
        localStorage.setItem('attendance_location', this.value);
    });

    initScanner();
    updateOnlineStatus();
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    if (window.speechSynthesis) {
        window.speechSynthesis.getVoices();
        window.speechSynthesis.onvoiceschanged = function() {
            window.speechSynthesis.getVoices();
        };
    }

    testConnection();

    // Start live feed for admin
    if (CONFIG.IS_ADMIN) {
        startLiveFeed();
    }
});

// ============================================
// TEST CONNECTION
// ============================================
function testConnection() {
    const formData = new FormData();
    formData.append('action', 'get_students');
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('✅ DB connection OK, students:', data.data?.length || 0);
                showToast('✅ System ready', 'success');
            } else {
                console.error('❌ DB error:', data.error);
                showToast('⚠️ DB error: ' + (data.error || 'Unknown'), 'error');
            }
        })
        .catch(error => {
            console.error('❌ Connection error:', error);
            showToast('⚠️ Cannot connect to server', 'error');
        });
}

// ============================================
// QR SCANNER
// ============================================
function initScanner() {
    if (state.html5QrCode) {
        state.html5QrCode.clear();
        state.html5QrCode = null;
    }
    try {
        state.html5QrCode = new Html5Qrcode("reader");
        state.html5QrCode.start(
            { facingMode: "environment" },
            { fps: 15, qrbox: { width: 280, height: 280 }, aspectRatio: 1.0 },
            onScanSuccess,
            onScanError
        ).then(() => {
            state.isScanning = true;
            dom.cameraStatus.textContent = '✅ Camera active';
            console.log('📷 Camera started');
        }).catch(err => {
            console.error('Camera error:', err);
            dom.cameraStatus.textContent = '⚠️ Camera error: ' + err.message;
            showToast('Camera access denied. Please allow camera access or use manual entry.', 'error');
        });
    } catch (err) {
        console.error('Scanner init error:', err);
        dom.cameraStatus.textContent = '⚠️ Scanner error: ' + err.message;
    }
}

function onScanSuccess(decodedText) {
    if (state.isProcessing) return;
    if (state.cooldownActive) {
        showToast('⏱️ Please wait ' + state.cooldownSeconds + 's before next scan', 'warning');
        dom.cameraStatus.textContent = '⏱️ Cooldown: ' + state.cooldownSeconds + 's remaining';
        return;
    }
    console.log('📸 QR Scanned:', decodedText);
    playScanBeep();
    dom.cameraStatus.textContent = '✅ QR detected - processing...';
    if (navigator.vibrate) navigator.vibrate(50);
    handleScan(decodedText);
}
function onScanError(err) {}

// ============================================
// COOLDOWN
// ============================================
function startCooldown() {
    state.cooldownActive = true;
    state.cooldownSeconds = CONFIG.COOLDOWN;
    dom.cooldownBar.classList.add('active');
    dom.cooldownText.classList.add('active');
    dom.cooldownDisplay.style.display = 'inline';
    dom.cooldownCount.textContent = state.cooldownSeconds;
    dom.cooldownTimer.textContent = state.cooldownSeconds;
    if (state.cooldownTimer) clearInterval(state.cooldownTimer);
    state.cooldownTimer = setInterval(function() {
        state.cooldownSeconds--;
        dom.cooldownCount.textContent = state.cooldownSeconds;
        dom.cooldownTimer.textContent = state.cooldownSeconds;
        const progress = ((CONFIG.COOLDOWN - state.cooldownSeconds) / CONFIG.COOLDOWN) * 100;
        dom.cooldownFill.style.width = progress + '%';
        if (state.cooldownSeconds <= 0) {
            clearInterval(state.cooldownTimer);
            state.cooldownActive = false;
            dom.cooldownBar.classList.remove('active');
            dom.cooldownText.classList.remove('active');
            dom.cooldownDisplay.style.display = 'none';
            dom.cooldownFill.style.width = '0%';
            dom.cameraStatus.textContent = '✅ Ready for next scan';
        }
    }, 1000);
}

// ============================================
// MANUAL SCAN
// ============================================
function handleManualScan() {
    if (state.cooldownActive) {
        showToast('⏱️ Please wait ' + state.cooldownSeconds + 's before next scan', 'warning');
        return;
    }
    const id = dom.manualId.value.trim();
    if (!id) { showToast('Please enter a valid ID', 'warning'); return; }
    handleScan(id + '|dummy_hash');
    dom.manualId.value = '';
}

// ============================================
// HANDLE SCAN
// ============================================
function handleScan(qrData) {
    if (state.isProcessing) {
        showToast('Please wait, processing current scan...', 'warning');
        return;
    }
    state.isProcessing = true;
    const location = dom.locationSelect.value;
    const formData = new FormData();
    formData.append('action', 'scan');
    formData.append('qr', qrData);
    formData.append('location', location);
    formData.append('device_id', CONFIG.DEVICE_ID);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            state.isProcessing = false;
            console.log('📥 Scan response:', data);
            if (data.success) {
                displayResult(data);
                speak(data.speech || 'Scan processed');
                if (data.decision === 'allowed' || data.action === 'in' || data.action === 'out') {
                    playSuccessBeep();
                } else if (data.decision === 'denied') {
                    playDeniedBeep();
                }
                startCooldown();
                const now = new Date();
                dom.lastScanTime.textContent = 'Last scan: ' + now.toLocaleTimeString();
                if (CONFIG.IS_ADMIN) refreshFeed();
            } else {
                showToast('Error: ' + (data.error || 'Unknown error'), 'error');
                dom.cameraStatus.textContent = '❌ Scan failed';
                playDeniedBeep();
            }
        })
        .catch(error => {
            state.isProcessing = false;
            console.error('❌ Scan error:', error);
            showToast('Server error: ' + error.message, 'error');
            dom.cameraStatus.textContent = '❌ Server error';
            playDeniedBeep();
        });
}

// ============================================
// DISPLAY RESULT (full details)
// ============================================
function displayResult(data) {
    dom.placeholder.style.display = 'none';
    dom.resultCard.classList.add('active');

    const student = data.student || data.user || {};
    dom.resultPhoto.src = student.photo || '/assets/default_avatar.png';
    dom.resultPhoto.onerror = function() { this.src = '/assets/default_avatar.png'; };
    dom.resultName.textContent = student.name || 'Unknown';

    // Designation: role or class
    let designation = '';
    if (data.user_type === 'staff') {
        designation = (student.role || 'Staff') + ' (Staff)';
    } else {
        designation = student.class_name ? 'Student • ' + student.class_name : 'Student';
    }
    dom.resultDesignation.textContent = designation;

    // Extra info: department, ID, etc.
    let extraParts = [];
    if (student.department && student.department !== 'N/A') extraParts.push('🏫 ' + student.department);
    if (student.id) extraParts.push('ID: ' + student.id);
    dom.resultExtra.textContent = extraParts.join(' • ') || '—';

    // Decision badge
    const badge = dom.resultBadge;
    const decision = data.decision || 'unknown';
    const scanType = data.scan_type || 'entry';
    badge.className = 'result-decision';
    if (data.user_type === 'staff') {
        const action = data.action || (data.scan_type === 'entry' ? 'Clock In' : 'Clock Out');
        badge.textContent = '🕐 ' + action + ' - ' + (data.message || 'Staff');
        badge.classList.add('staff');
    } else if (decision === 'allowed') {
        badge.textContent = '✅ ALLOWED - ' + scanType.toUpperCase();
        badge.classList.add('allowed');
    } else if (decision === 'overridden') {
        badge.textContent = '⚠️ OVERRIDDEN - Entry Forced';
        badge.classList.add('overridden');
    } else if (decision === 'denied') {
        badge.textContent = '❌ ACCESS DENIED';
        badge.classList.add('denied');
    } else {
        badge.textContent = 'ℹ️ ' + decision.toUpperCase();
    }

    // Fee status
    const feeStatus = student.fee_status || data.fee_info?.status || 'Fees OK';
    const feeStatusEl = dom.feeStatus;
    if (data.user_type !== 'staff') {
        feeStatusEl.style.display = 'block';
        feeStatusEl.textContent = '💰 ' + feeStatus;
        feeStatusEl.className = 'result-fee-status ' + (feeStatus === 'Fees OK' ? 'ok' : (feeStatus === 'Debt Exceeded' || feeStatus === 'Late Entry' ? 'denied' : 'pending'));
    } else {
        feeStatusEl.style.display = 'none';
    }

    const reasonText = data.fee_info?.reason || data.message || '';
    if (reasonText && decision === 'denied') {
        dom.resultReason.style.display = 'block';
        dom.resultReasonText.textContent = reasonText;
    } else {
        dom.resultReason.style.display = 'none';
    }

    const now = new Date();
    dom.resultTime.textContent = '🕐 ' + now.toLocaleTimeString() + ' | Device: ' + (data.device_id || CONFIG.DEVICE_ID);

    const canOverride = data.can_override !== undefined ? data.can_override : true;
    if (decision === 'denied' && canOverride && data.user_type !== 'staff') {
        dom.overrideBtn.style.display = 'block';
        dom.overrideBtn.disabled = false;
        state.overrideLogId = data.log_id || null;
        dom.overrideName.textContent = student.name || 'this student';
    } else {
        dom.overrideBtn.style.display = 'none';
    }

    state.currentScan = data;
    dom.cameraStatus.textContent = '✅ Scan completed at ' + now.toLocaleTimeString();
}

// ============================================
// OVERRIDE, RESET, SPEECH, ONLINE/OFFLINE, TOAST
// (same as before)
// ============================================
function showOverrideModal() {
    if (!state.overrideLogId) { showToast('No denied entry to override', 'error'); return; }
    dom.overrideModal.classList.add('active');
    dom.overrideReason.value = '';
    dom.overrideReason.focus();
}
function closeOverrideModal() { dom.overrideModal.classList.remove('active'); }
function confirmOverride() {
    const reason = dom.overrideReason.value.trim();
    if (!reason) { showToast('Please provide a reason for the override', 'warning'); dom.overrideReason.focus(); return; }
    const logId = state.overrideLogId;
    if (!logId) { showToast('No log ID to override', 'error'); return; }
    dom.overrideBtn.disabled = true;
    dom.overrideBtn.innerHTML = '<span class="spinner"></span> Processing...';
    const formData = new FormData();
    formData.append('action', 'override');
    formData.append('log_id', logId);
    formData.append('reason', reason);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            dom.overrideBtn.innerHTML = '⚡ Allow Entry (Override)';
            dom.overrideBtn.disabled = false;
            closeOverrideModal();
            if (data.success) {
                showToast('✅ Entry overridden successfully!', 'success');
                playSuccessBeep();
                if (state.currentScan) {
                    state.currentScan.decision = 'overridden';
                    displayResult(state.currentScan);
                }
                if (CONFIG.IS_ADMIN) refreshFeed();
            } else {
                showToast('Override failed: ' + (data.error || 'Unknown error'), 'error');
                playDeniedBeep();
            }
        })
        .catch(error => {
            dom.overrideBtn.innerHTML = '⚡ Allow Entry (Override)';
            dom.overrideBtn.disabled = false;
            closeOverrideModal();
            showToast('Error: ' + error.message, 'error');
            playDeniedBeep();
        });
}
function resetScanner() {
    dom.resultCard.classList.remove('active');
    dom.placeholder.style.display = 'flex';
    dom.overrideBtn.style.display = 'none';
    state.currentScan = null;
    state.overrideLogId = null;
    state.isProcessing = false;
    dom.cameraStatus.textContent = '✅ Ready for next scan';
    if (!state.isScanning) initScanner();
}
function speak(text) {
    if (!text) return;
    try {
        if (state.speechSynth) {
            state.speechSynth.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'en-US';
            utterance.rate = 0.9;
            utterance.pitch = 1;
            utterance.volume = 1;
            const voices = state.speechSynth.getVoices();
            const femaleVoice = voices.find(v => v.name.includes('Female') || v.name.includes('Google UK Female'));
            if (femaleVoice) utterance.voice = femaleVoice;
            state.speechSynth.speak(utterance);
        }
    } catch (e) { console.log('Speech error:', e); }
}
function updateOnlineStatus() {
    state.isOnline = navigator.onLine;
    if (state.isOnline) {
        dom.statusDot.className = 'status-dot online';
        dom.statusText.textContent = 'Online';
        dom.cameraStatus.textContent = '✅ Online - ready to scan';
    } else {
        dom.statusDot.className = 'status-dot offline';
        dom.statusText.textContent = 'Offline (Limited mode)';
        dom.cameraStatus.textContent = '📶 Offline - limited functionality';
        showToast('📶 You are offline. Some features may not work.', 'warning');
    }
}
function handleOnline() { showToast('🌐 Back online!', 'success'); updateOnlineStatus(); testConnection(); }
function handleOffline() { updateOnlineStatus(); }
function showToast(message, type = 'info') {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ============================================
// LIVE FEED (Admin only)
// ============================================
function startLiveFeed() {
    refreshFeed();
    state.feedInterval = setInterval(refreshFeed, 3000);
}
function refreshFeed() {
    const formData = new FormData();
    formData.append('action', 'get_recent_logs');
    formData.append('limit', 20);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                renderFeed(data.data);
            }
        })
        .catch(err => console.warn('Feed error:', err));
}
function renderFeed(logs) {
    const container = dom.feedItems;
    if (!logs || logs.length === 0) {
        container.innerHTML = '<div style="text-align:center;color:rgba(255,255,255,0.2);padding:20px;font-size:13px;">No recent scans</div>';
        dom.feedCount.textContent = '0 events';
        return;
    }
    let html = '';
    logs.forEach(log => {
        const decision = log.decision || 'unknown';
        const cls = decision === 'allowed' ? 'allowed' : (decision === 'denied' ? 'denied' : 'overridden');
        // Use the pre-formatted scanned_at_formatted
        const time = log.scanned_at_formatted || '--:--';
        html += `
            <div class="feed-item">
                <span class="time">${time}</span>
                <span class="name">${log.user_name || 'Unknown'}</span>
                <span class="decision-tag ${cls}">${decision.toUpperCase()}</span>
                <span class="device-tag">${log.location || 'Main Gate'}</span>
            </div>
        `;
    });
    container.innerHTML = html;
    dom.feedCount.textContent = logs.length + ' events';
    const scroll = document.getElementById('feedScroll');
    if (scroll) scroll.scrollTop = 0;
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeOverrideModal();
    if (e.ctrlKey && e.key === 'r') { e.preventDefault(); resetScanner(); }
});

console.log('✅ Gate Access Scanner ready (Enhanced)');
console.log('⏱️ Cooldown: ' + CONFIG.COOLDOWN + 's');
</script>
</body>
</html>