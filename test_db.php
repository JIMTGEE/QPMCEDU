<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
try {
    require_once($db_path);
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Database configuration error.";
    header("Location: /login.php");
    exit();
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Restrict to admins who are assigned as teachers
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an admin with teaching privileges.";
    header("Location: /login.php");
    exit();
}

// Validate that the admin is assigned as a teacher
$user_id = (int)$_SESSION['user_id'];
$teacher_check_sql = "SELECT 1 FROM classes WHERE teacher_id = ? UNION SELECT 1 FROM class_schedules WHERE teacher_id = ?";
$teacher_check_stmt = $conn->prepare($teacher_check_sql);
if ($teacher_check_stmt) {
    $teacher_check_stmt->bind_param("ii", $user_id, $user_id);
    $teacher_check_stmt->execute();
    if ($teacher_check_stmt->get_result()->num_rows === 0) {
        error_log("Admin not assigned as teacher: user_id=$user_id");
        $_SESSION['error_msg'] = "You do not have teaching privileges.";
        header("Location: /login.php");
        exit();
    }
    $teacher_check_stmt->close();
} else {
    error_log("Failed to prepare teacher check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate teaching privileges.";
    header("Location: /login.php");
    exit();
}

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

// Validate user_id exists in users table
$user_role = $_SESSION['user_role'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $user_id);
    $user_check_stmt->execute();
    if ($user_check_stmt->get_result()->num_rows === 0) {
        error_log("Invalid user_id in session: $user_id, role: $user_role");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$action = "Accessed Admin Teacher Dashboard";
$description = "Admin with teaching role accessed teacher dashboard";
logActivity($conn, $user_id, $action, $description);

// Generate CSRF token and clean up expired tokens
$conn->begin_transaction();
try {
    $csrf_token = bin2hex(random_bytes(32));
    $csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
    $csrf_stmt = $conn->prepare($csrf_sql);
    if (!$csrf_stmt) {
        throw new Exception("Prepare failed for CSRF token: " . $conn->error);
    }
    $csrf_stmt->bind_param("is", $user_id, $csrf_token);
    $csrf_stmt->execute();
    $csrf_stmt->close();

    $cleanup_sql = "DELETE FROM csrf_tokens WHERE expires_at < NOW()";
    $conn->query($cleanup_sql);
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log("CSRF token error: " . $e->getMessage());
}