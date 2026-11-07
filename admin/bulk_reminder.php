<?php
// ─── Bootstrap ────────────────────────────────────────────────────────────────
session_start();

if (headers_sent()) {
    error_log("Headers already sent before session_start()", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

if (!$conn || $conn->connect_error) {
    error_log(
        "Database connection failed: " . ($conn->connect_error ?? 'Connection is null'),
        3,
        $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log'
    );
    die("System error: Database connection failed. Please contact the administrator.");
}

// ─── Error reporting / logging ────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/logs/bulk_reminder_errors.log');

$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ─── Activity logger ──────────────────────────────────────────────────────────
$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) {
    require_once($log_activity_path);
} else {
    error_log(
        "log_activity.php not found at $log_activity_path",
        3,
        $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log'
    );
}
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $details, $conn = null) {
        $log_file  = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[{$timestamp}] [user_id: {$user_id}] {$action}: {$details}\n", 3, $log_file);
    }
}

// ─── SMS API Configuration ────────────────────────────────────────────────────
if (!defined('SMS_API_KEY'))     define('SMS_API_KEY',     'RFZLcHFxc05CTHlOWXhXY0xlbGo');
if (!defined('SMS_SENDER_ID'))   define('SMS_SENDER_ID',   'QUEENSPA');
if (!defined('SMS_API_URL'))     define('SMS_API_URL',     'https://sms.arkesel.com/sms/api');
if (!defined('SMS_BALANCE_URL')) define('SMS_BALANCE_URL', 'https://sms.arkesel.com/sms/api?action=check-balance&api_key=' . SMS_API_KEY . '&response=json');

// ─── SMS character limit ──────────────────────────────────────────────────────
// Arkesel rejects Unicode (non-GSM-7) messages longer than 652 characters.
// All messages must use plain ASCII only and stay under this hard cap.
define('SMS_MAX_CHARS', 640); // safe margin below the 652 hard limit

// ─── Phone helpers ────────────────────────────────────────────────────────────

function normalizePhoneForDisplay(string $phone): string {
    if (empty($phone)) return '';
    $clean = preg_replace('/[^0-9+]/', '', trim($phone));
    if (empty($clean)) return '';
    if (substr($clean, 0, 4) === '+233')                         return '0' . substr($clean, 4);
    if (substr($clean, 0, 3) === '233' && strlen($clean) === 12) return '0' . substr($clean, 3);
    if (strlen($clean) === 9 && $clean[0] !== '0')               return '0' . $clean;
    return $clean;
}

function formatPhoneForApi(string $phone): string {
    $clean = preg_replace('/[^0-9]/', '', trim($phone));
    if (empty($clean)) return '';
    if (strlen($clean) === 12 && substr($clean, 0, 3) === '233') return $clean;
    if (strlen($clean) === 10 && $clean[0] === '0')              return '233' . substr($clean, 1);
    if (strlen($clean) === 9)                                    return '233' . $clean;
    return $clean;
}

// ─── Plain-ASCII sanitiser ────────────────────────────────────────────────────
/**
 * Strips every non-GSM-7 character from a string so the SMS gateway
 * treats it as plain ASCII (160 chars/segment, 612+ multi-part) instead
 * of Unicode (70 chars/segment, 652 char Arkesel cap).
 *
 * Keeps: letters, digits, standard punctuation, newlines, spaces.
 */
function toGSM7(string $text): string {
    // Replace common Unicode look-alikes with ASCII equivalents first
    $map = [
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2018}" => "'", "\u{2019}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{2026}" => '...', "\u{00A0}" => ' ',
        "\u{00A3}" => 'GHC', // pound sign -> GHC (already using GHC in messages)
        "\u{20B5}" => 'GHC', // cedi sign
        "\u{00B7}" => '*',
    ];
    $text = str_replace(array_keys($map), array_values($map), $text);

    // Strip any remaining non-ASCII character (covers all emoji, box-drawing, etc.)
    $text = preg_replace('/[^\x00-\x7F]/', '', $text);

    // Collapse excessive blank lines produced by stripping decorators
    $text = preg_replace("/(\r?\n){3,}/", "\n\n", $text);

    return $text;
}

/**
 * Ensures a message fits within the SMS_MAX_CHARS hard cap.
 * Appends a note when truncation occurs.
 */
function enforceSmsLimit(string $message): string {
    $message = toGSM7($message);
    if (strlen($message) <= SMS_MAX_CHARS) {
        return $message;
    }
    $suffix = '... [msg truncated]';
    return substr($message, 0, SMS_MAX_CHARS - strlen($suffix)) . $suffix;
}

// ─── SMS send ─────────────────────────────────────────────────────────────────

/**
 * Send a single SMS via the Arkesel API.
 *
 * @return array{success:bool, response:string, http_code:int, phone:string, error_msg:string}
 */
function sendSMS(string $phone, string $message): array {
    $apiPhone = formatPhoneForApi($phone);
    $smsLog   = $_SERVER['DOCUMENT_ROOT'] . '/logs/sms.log';

    if (empty($apiPhone)) {
        error_log("SMS SKIPPED: Empty phone after formatting (raw: $phone)", 3, $smsLog);
        return ['success' => false, 'response' => 'Empty phone number', 'http_code' => 0, 'phone' => $phone, 'error_msg' => 'Empty phone number'];
    }

    // Enforce plain-ASCII + length limit before sending
    $safeMessage = enforceSmsLimit($message);

    $url = SMS_API_URL . '?' . http_build_query([
        'action'  => 'send-sms',
        'api_key' => SMS_API_KEY,
        'to'      => $apiPhone,
        'from'    => SMS_SENDER_ID,
        'sms'     => $safeMessage,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Cache-Control: no-cache'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $apiResponse = curl_exec($ch);
    $curlError   = curl_error($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log every attempt
    error_log(
        "SMS API — to:{$apiPhone} | HTTP:{$httpCode} | chars:" . strlen($safeMessage) . " | resp:" . substr((string)$apiResponse, 0, 300),
        3,
        $smsLog
    );

    if ($curlError) {
        error_log("SMS cURL ERROR: $curlError", 3, $smsLog);
        return ['success' => false, 'response' => $curlError, 'http_code' => 0, 'phone' => $apiPhone, 'error_msg' => $curlError];
    }

    $rawResponse = (string)$apiResponse;
    $data        = json_decode($rawResponse, true);
    $success     = false;
    $errorMsg    = '';

    if (is_array($data)) {
        // Check every known success shape Arkesel returns
        $code   = strtolower(trim((string)($data['code']   ?? '')));
        $status = strtolower(trim((string)($data['status'] ?? '')));
        $msgStr = strtolower(trim((string)($data['message'] ?? '')));

        $success = ($code   === 'ok')
                || ($status === 'ok')
                || ($status === 'success')
                || (strpos($msgStr, 'success') !== false)
                || (strpos($msgStr, 'sent')    !== false);

        if (!$success) {
            // Capture a meaningful error from the API payload
            $errorMsg = $data['message'] ?? $data['error'] ?? $data['info'] ?? json_encode($data);
        }
    } else {
        // Non-JSON response
        if ($httpCode === 200) {
            // Arkesel occasionally returns HTTP 200 with a plain-text body
            $bodyLower = strtolower($rawResponse);
            $success   = strpos($bodyLower, '"code":"ok"')        !== false
                      || strpos($bodyLower, '"status":"ok"')      !== false
                      || strpos($bodyLower, '"status":"success"') !== false
                      || strpos($bodyLower, 'message sent')       !== false;

            if (!$success) {
                // HTTP 200 but cannot confirm — treat as success and flag in log
                $success = true;
                error_log("SMS API — HTTP 200 non-JSON body, treating as success. Raw: $rawResponse", 3, $smsLog);
            }
        } else {
            $errorMsg = "HTTP {$httpCode}: $rawResponse";
        }
    }

    return [
        'success'   => $success,
        'response'  => $rawResponse,
        'http_code' => $httpCode,
        'phone'     => $apiPhone,
        'error_msg' => $errorMsg,
    ];
}

/**
 * Check the Arkesel SMS account balance.
 *
 * @return array{success:bool, balance:string, response:string}
 */
function checkSMSBalance(): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => SMS_BALANCE_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Cache-Control: no-cache'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("SMS Balance cURL ERROR: $curlError", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/sms.log');
        return ['success' => false, 'balance' => '0', 'response' => $curlError];
    }

    $data    = json_decode((string)$response, true);
    $balance = '0';

    if ($httpCode === 200 && is_array($data)) {
        $balance = $data['balance'] ?? $data['data']['balance'] ?? '0';
    }

    return [
        'success'  => ($httpCode === 200),
        'balance'  => (string)$balance,
        'response' => (string)$response,
    ];
}

/**
 * Fetch ALL parent phone numbers for a given student.
 *
 * @return array<int,array{phone:string,phone_api:string,name:string}>
 */
function getAllParentPhonesForStudent(mysqli $conn, int $student_id): array {
    $phones = [];
    $errLog = $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log';

    $stmt = $conn->prepare(
        "SELECT DISTINCT pu.phone, pu.phone_number, pu.name AS parent_name
         FROM parent_student_mapping psm
         INNER JOIN users pu ON psm.parent_id = pu.id
         WHERE psm.student_id = ?
         ORDER BY pu.id ASC"
    );
    if (!$stmt) {
        error_log("getAllParentPhonesForStudent prepare failed: " . $conn->error, 3, $errLog);
        return $phones;
    }

    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $raw = trim($row['phone'] ?? '');
        if (empty($raw)) $raw = trim($row['phone_number'] ?? '');
        if (!empty($raw)) {
            $display = normalizePhoneForDisplay($raw);
            $api     = formatPhoneForApi($raw);
            if (!empty($api)) {
                $phones[] = [
                    'phone'     => $display,
                    'phone_api' => $api,
                    'name'      => trim($row['parent_name'] ?? ''),
                ];
            }
        }
    }
    $stmt->close();

    // Emergency contact fallback when no mapped parent has a phone
    if (empty($phones)) {
        $ecStmt = $conn->prepare(
            "SELECT emergency_contact_phone, emergency_contact_name
             FROM students WHERE user_id = ? LIMIT 1"
        );
        if ($ecStmt) {
            $ecStmt->bind_param("i", $student_id);
            $ecStmt->execute();
            $ec = $ecStmt->get_result()->fetch_assoc();
            $ecStmt->close();

            if (!empty($ec['emergency_contact_phone'])) {
                $raw = trim($ec['emergency_contact_phone']);
                $api = formatPhoneForApi($raw);
                if (!empty($api)) {
                    $phones[] = [
                        'phone'     => normalizePhoneForDisplay($raw),
                        'phone_api' => $api,
                        'name'      => trim($ec['emergency_contact_name'] ?? ''),
                    ];
                }
            }
        }
    }

    return $phones;
}

// ─── Admin auth check ─────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = $_SESSION['user_id'] ?? 0;
    log_activity($user_id, 'access_denied', 'Attempted to access bulk_reminder.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

$ip_address = $_SERVER['REMOTE_ADDR'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed bulk_reminder.php, IP: {$ip_address}", $conn);

// ─── CSRF token ───────────────────────────────────────────────────────────────
$csrf_token  = bin2hex(random_bytes(32));
$csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

$csrf_stmt = $conn->prepare(
    "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)"
);
if ($csrf_stmt) {
    $csrf_stmt->bind_param("iss", $_SESSION['user_id'], $csrf_token, $csrf_expiry);
    if (!$csrf_stmt->execute()) {
        error_log("Failed to store CSRF token: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }
    $csrf_stmt->close();
}
$_SESSION['csrf_token'] = $csrf_token;

function validateCsrf(mysqli $conn, int $user_id, string $token): bool {
    $stmt = $conn->prepare(
        "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()"
    );
    if (!$stmt) return false;
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid  = $result->num_rows > 0;
    $result->free();
    $stmt->close();
    return $valid;
}

// ─── Class display-name map ───────────────────────────────────────────────────
$class_name_map = [
    'Esther Foundation' => ['display' => 'Creche',    'level' => 'Pre-School'],
    'Sheba One'         => ['display' => 'Nursery 1', 'level' => 'Pre-School'],
    'Sheba Two'         => ['display' => 'Nursery 2', 'level' => 'Pre-School'],
    'Yaasant One'       => ['display' => 'KG 1',      'level' => 'Kindergarten'],
    'Yaasant Two'       => ['display' => 'KG 2',      'level' => 'Kindergarten'],
    'Cleopatra One'     => ['display' => 'Basic 1',   'level' => 'Lower Primary'],
    'Cleopatra Two'     => ['display' => 'Basic 2',   'level' => 'Lower Primary'],
    'Nefertiti One'     => ['display' => 'Basic 3',   'level' => 'Lower Primary'],
    'Nefertiti Two'     => ['display' => 'Basic 4',   'level' => 'Lower Primary'],
    'Malika One'        => ['display' => 'Basic 5',   'level' => 'Upper Primary'],
    'Malika Two'        => ['display' => 'Basic 6',   'level' => 'Upper Primary'],
    'Makeda One'        => ['display' => 'Basic 7',   'level' => 'Junior High'],
    'Makeda Two'        => ['display' => 'Basic 8',   'level' => 'Junior High'],
    'An Majesty'        => ['display' => 'Basic 9',   'level' => 'Junior High'],
];

// ─── Academic years ───────────────────────────────────────────────────────────
$academic_years_result = $conn->query(
    "SELECT DISTINCT year FROM academic_calendar
     WHERE year REGEXP '^[0-9]{4}/[0-9]{4}$'
     ORDER BY year DESC"
);
if (!$academic_years_result) {
    error_log("Error fetching academic years: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch academic years.");
}
$academic_years = [];
while ($row = $academic_years_result->fetch_assoc()) {
    $academic_years[] = $row['year'];
}
$academic_years_result->free();

// ─── Departments ──────────────────────────────────────────────────────────────
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if (!$departments_result) {
    die("System error: Unable to fetch departments.");
}
$departments      = [];
$valid_dept_enums = ['Lower Primary', 'Upper Primary', 'Junior High', 'Kindergarten', 'Pre-School'];
while ($row = $departments_result->fetch_assoc()) {
    $dept_name = $row['name'];
    if (!in_array($dept_name, $valid_dept_enums)) {
        $dept_name = str_contains($row['name'], 'Lower')        ? 'Lower Primary'
                  : (str_contains($row['name'], 'Upper')        ? 'Upper Primary'
                  : (str_contains($row['name'], 'Junior')       ? 'Junior High'
                  : (str_contains($row['name'], 'Kindergarten') ? 'Kindergarten'
                  : $row['name'])));
    }
    $departments[$row['id']] = ['name' => $row['name'], 'enum_name' => $dept_name];
}
$departments_result->free();

// ─── Classes ──────────────────────────────────────────────────────────────────
$classes_result = $conn->query(
    "SELECT id, class_name, department_id FROM classes ORDER BY class_name ASC"
);
if (!$classes_result) {
    die("System error: Unable to fetch classes.");
}
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $display = isset($class_name_map[$row['class_name']])
        ? $class_name_map[$row['class_name']]['display']
        : $row['class_name'];
    $classes[$row['id']] = [
        'class_name'    => $row['class_name'],
        'display_name'  => $display,
        'department_id' => $row['department_id'],
    ];
}
$classes_result->free();

// ─── Debt-status helpers ──────────────────────────────────────────────────────

function calculateDebtStatus(float $total_fees, float $total_paid, bool $has_arrears = false): array {
    if ($total_fees <= 0) {
        return ['status' => 'green', 'level' => 3, 'label' => 'Fully Paid'];
    }
    $pct = ($total_paid / $total_fees) * 100;

    if ($has_arrears || $pct < 50) {
        return ['status' => 'red',    'level' => 1, 'label' => 'Critical - High Debt'];
    }
    if ($pct < 90) {
        return ['status' => 'yellow', 'level' => 2, 'label' => 'Partial Payment Reminder'];
    }
    return     ['status' => 'green',  'level' => 3, 'label' => 'Good Standing'];
}

function getStudentArrears(mysqli $conn, int $student_id, string $current_year): float {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(d.amount_due), 0) AS total_arrears
         FROM debts d
         JOIN fees f ON d.fee_id = f.id
         WHERE d.user_id = ? AND f.academic_year < ?"
    );
    if (!$stmt) return 0.0;
    $stmt->bind_param("is", $student_id, $current_year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['total_arrears'] ?? 0);
}

// ─── SMS message builder ──────────────────────────────────────────────────────
/**
 * Builds a compact, plain-ASCII SMS message for a student.
 *
 * Fee breakdown is intentionally excluded — the message contains only
 * the essential payment information parents need to act on.
 *
 * All messages use only printable ASCII (GSM-7 subset) so the gateway
 * handles them as standard SMS (160 chars/segment) instead of Unicode
 * (70 chars/segment), avoiding Arkesel's 652-char Unicode rejection.
 */
function buildStatusMessage(array $student, array $status_info, string $academic_year): string {
    $student_name  = htmlspecialchars_decode($student['student_name']);
    $class_display = $student['class_display_name'] ?? $student['class_name'];
    $total_debt    = (float)$student['total_debt'];
    $total_fees    = (float)($student['total_fees_charged'] ?? $total_debt);
    $total_paid    = (float)($student['total_amount_paid']  ?? 0);
    $pct           = $total_fees > 0 ? (int)min(round(($total_paid / $total_fees) * 100), 100) : 100;
    $has_arrears   = $student['has_arrears'] ?? false;

    $school = 'QUEENS PARADISE MONTESSORI SCHOOL';

    switch ($status_info['status']) {

        // ── RED: critical / high debt ─────────────────────────────────────────
        case 'red':
            $msg  = "{$school}\n";
            $msg .= "--- URGENT FEE NOTICE ---\n\n";
            $msg .= "Dear Parent/Guardian,\n";
            $msg .= "Student: {$student_name}\n";
            $msg .= "Class: {$class_display} | Year: {$academic_year}\n\n";

            if ($has_arrears) {
                $msg .= "!! ARREARS OUTSTANDING !!\n";
                $msg .= "Total owed (arrears + current): GHC" . number_format($total_debt, 2) . "\n";
                $msg .= "Your ward may be DENIED ENTRY until all fees are cleared.\n";
            } else {
                $msg .= "Outstanding Balance: GHC" . number_format($total_debt, 2) . "\n";
                $msg .= "Payment Progress: {$pct}% paid.\n";
                $msg .= "Please pay immediately to avoid school restrictions.\n";
            }

            $msg .= "\nPay at: Finance Office or MoMo: 055-123-4567\n";
            $msg .= "Contact admin for queries.";
            break;

        // ── YELLOW: partial payment ───────────────────────────────────────────
        case 'yellow':
            $msg  = "{$school}\n";
            $msg .= "--- FEE PAYMENT REMINDER ---\n\n";
            $msg .= "Dear Parent/Guardian,\n";
            $msg .= "Student: {$student_name}\n";
            $msg .= "Class: {$class_display} | Year: {$academic_year}\n\n";
            $msg .= "Total Fees: GHC"   . number_format($total_fees, 2) . "\n";
            $msg .= "Amount Paid: GHC"  . number_format($total_paid, 2) . "\n";
            $msg .= "Balance Owed: GHC" . number_format($total_debt, 2) . " ({$pct}% paid)\n";
            $msg .= "\nKindly settle the balance before the next term.\n";
            $msg .= "Pay at: Finance Office or MoMo: 055-123-4567\n";
            $msg .= "Thank you. Queens Paradise Montessori School";
            break;

        // ── GREEN: good standing / fully paid ─────────────────────────────────
        case 'green':
            if ($pct >= 99) {
                $msg  = "{$school}\n\n";
                $msg .= "Dear Parent/Guardian,\n";
                $msg .= "Student: {$student_name}\n";
                $msg .= "Class: {$class_display} | Year: {$academic_year}\n\n";
                $msg .= "STATUS: FULLY PAID\n\n";
                $msg .= "Thank you for your prompt payment. Your support enables us to provide quality education for your ward.\n\n";
                $msg .= "Queens Paradise Montessori School";
            } else {
                $msg  = "{$school}\n\n";
                $msg .= "Dear Parent/Guardian,\n";
                $msg .= "Student: {$student_name}\n";
                $msg .= "Class: {$class_display} | Year: {$academic_year}\n\n";
                $msg .= "Status: GOOD STANDING ({$pct}% paid)\n";
                $msg .= "Remaining Balance: GHC" . number_format($total_debt, 2) . "\n\n";
                $msg .= "Thank you for your consistent payments. Please clear the small balance at your convenience.\n";
                $msg .= "Queens Paradise Montessori School";
            }
            break;

        default:
            $msg = "Dear Parent, reminder about fees for {$student_name} ({$class_display})."
                 . " Balance: GHC" . number_format($total_debt, 2)
                 . ". Please pay promptly. Queens Paradise Montessori School.";
            break;
    }

    // Final safety: ensure plain ASCII and under hard character cap
    return enforceSmsLimit($msg);
}

// ─── Student query + debt-status enrichment ───────────────────────────────────

function buildStudentsQueryWithStatus(
    mysqli $conn,
    string $academic_year,
    int    $dept_id,
    int    $class_id,
    string $search_query,
    string $status_filter,
    array  $departments,
    array  $class_name_map
): array {
    $sql = "SELECT
        u.id                                                          AS student_id,
        u.name                                                        AS student_name,
        u.admission_date,
        c.id                                                          AS class_id,
        c.class_name,
        COALESCE(SUM(f.amount), 0)                                    AS total_fees_charged,
        COALESCE(SUM(p.amount_paid), 0)                               AS total_amount_paid,
        COALESCE(SUM(f.amount) - SUM(COALESCE(p.amount_paid, 0)), 0) AS total_debt,
        COUNT(DISTINCT f.id)                                          AS fee_count,
        COALESCE(MAX(DATEDIFF(NOW(), f.due_date)), 0)                 AS days_overdue
    FROM users u
    JOIN student_classes sc  ON u.id = sc.user_id AND sc.academic_year = ?
    JOIN classes c           ON sc.class_id = c.id
    LEFT JOIN fees f ON (
            (f.target_type = 'Learner'       AND f.user_id   = u.id  AND f.academic_year = ?)
         OR (f.target_type = 'Bulk Learners' AND f.user_id   = u.id  AND f.academic_year = ?)
         OR (f.target_type = 'Class'         AND f.class_id  = c.id  AND f.academic_year = ?)
         OR (f.target_type = 'School'                                 AND f.academic_year = ?)
    )
    LEFT JOIN payments p ON p.user_id = u.id AND p.fee_id = f.id
    WHERE u.role = 'student' AND u.status = 'active'";

    $types  = "sssss";
    $params = [$academic_year, $academic_year, $academic_year, $academic_year, $academic_year];

    if ($dept_id > 0 && isset($departments[$dept_id])) {
        $sql     .= " AND c.department_id = ?";
        $types   .= "i";
        $params[] = $dept_id;
    }
    if ($class_id > 0) {
        $sql     .= " AND c.id = ?";
        $types   .= "i";
        $params[] = $class_id;
    }
    if ($search_query !== '') {
        $sql    .= " AND (u.name LIKE ?
                    OR EXISTS (
                        SELECT 1 FROM parent_student_mapping psm2
                        INNER JOIN users pu2 ON psm2.parent_id = pu2.id
                        WHERE psm2.student_id = u.id
                          AND (pu2.name LIKE ? OR pu2.phone LIKE ?)
                    ))";
        $types   .= "sss";
        $like     = "%{$search_query}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " GROUP BY u.id, c.id, c.class_name, u.name, u.admission_date
              HAVING total_debt > 0 OR total_fees_charged > 0
              ORDER BY total_debt DESC, days_overdue DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("buildStudentsQueryWithStatus prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        return [[], 0, 0.0, ['red' => 0, 'yellow' => 0, 'green' => 0]];
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result  = $stmt->get_result();
    $grouped = [];

    while ($row = $result->fetch_assoc()) {
        $sid = (int)$row['student_id'];
        if (!isset($grouped[$sid])) {
            $grouped[$sid] = [
                'student_id'         => $sid,
                'student_name'       => $row['student_name'],
                'admission_date'     => $row['admission_date'],
                'class_id'           => (int)$row['class_id'],
                'class_name'         => $row['class_name'],
                'total_fees_charged' => (float)$row['total_fees_charged'],
                'total_amount_paid'  => (float)$row['total_amount_paid'],
                'total_debt'         => (float)$row['total_debt'],
                'fee_count'          => (int)$row['fee_count'],
                'days_overdue'       => (int)$row['days_overdue'],
            ];
        }
    }
    $stmt->close();

    // ── Per-student enrichment ─────────────────────────────────────────────────
    foreach ($grouped as &$student) {
        $sid = $student['student_id'];

        // 1. Arrears flag
        $student['has_arrears'] = getStudentArrears($conn, $sid, $academic_year) > 0;

        // 2. Fee breakdown (kept for internal admin table display only — NOT sent in SMS)
        $feeStmt = $conn->prepare(
            "SELECT f.fee_type, d.amount_due AS amount, f.due_date
             FROM debts d
             JOIN fees f ON d.fee_id = f.id
             WHERE d.user_id = ? AND f.academic_year = ?
             ORDER BY f.due_date ASC"
        );
        $student['fees'] = [];
        if ($feeStmt) {
            $feeStmt->bind_param("is", $sid, $academic_year);
            $feeStmt->execute();
            $feeResult = $feeStmt->get_result();
            while ($fee = $feeResult->fetch_assoc()) {
                $student['fees'][] = [
                    'fee_type' => $fee['fee_type'],
                    'amount'   => (float)$fee['amount'],
                    'due_date' => $fee['due_date'],
                ];
            }
            $feeStmt->close();
        }

        // 3. Debt status
        $status = calculateDebtStatus(
            $student['total_fees_charged'],
            $student['total_amount_paid'],
            $student['has_arrears']
        );
        $student['debt_status'] = $status['status'];
        $student['debt_level']  = $status['level'];
        $student['debt_label']  = $status['label'];

        // 4. Parent phones (all)
        $parentPhones = getAllParentPhonesForStudent($conn, $sid);

        $student['parent_name']       = '';
        $student['parent_phone']      = '';
        $student['parent_email']      = '';
        $student['parent_phones_all'] = $parentPhones;

        if (!empty($parentPhones)) {
            $student['parent_name']  = $parentPhones[0]['name'];
            $student['parent_phone'] = $parentPhones[0]['phone'];
        }

        // 5. Parent email + user_id
        $emailStmt = $conn->prepare(
            "SELECT pu.id AS parent_user_id, pu.email
             FROM parent_student_mapping psm
             INNER JOIN users pu ON psm.parent_id = pu.id
             WHERE psm.student_id = ?
             ORDER BY pu.id ASC LIMIT 1"
        );
        $student['parent_user_id'] = null;
        if ($emailStmt) {
            $emailStmt->bind_param("i", $sid);
            $emailStmt->execute();
            $er = $emailStmt->get_result()->fetch_assoc();
            $emailStmt->close();
            if ($er) {
                $student['parent_email']   = $er['email'] ?? '';
                $student['parent_user_id'] = (int)$er['parent_user_id'];
            }
        }

        // 6. Class display name
        $student['class_display_name'] = isset($class_name_map[$student['class_name']])
            ? $class_name_map[$student['class_name']]['display']
            : $student['class_name'];
    }
    unset($student);

    $students = array_values($grouped);

    // Status filter
    if ($status_filter !== 'all' && $status_filter !== '') {
        $students = array_values(array_filter(
            $students,
            fn($s) => $s['debt_status'] === $status_filter
        ));
    }

    $total_debt_sum = (float)array_sum(array_column($students, 'total_debt'));
    $status_counts  = ['red' => 0, 'yellow' => 0, 'green' => 0];
    foreach ($students as $s) {
        if (isset($status_counts[$s['debt_status']])) {
            $status_counts[$s['debt_status']]++;
        }
    }

    return [$students, count($students), $total_debt_sum, $status_counts];
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST: Send reminders
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {

    if (!validateCsrf($conn, (int)$_SESSION['user_id'], $_POST['csrf_token'])) {
        $_SESSION['error_msg'] = "Invalid security token. Please try again.";
        header('Location: bulk_reminder.php');
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'send_reminders') {
        $academic_year     = trim($_POST['academic_year']   ?? '');
        $dept_id           = (int)($_POST['department_id']  ?? 0);
        $class_id          = (int)($_POST['class_id']       ?? 0);
        $status_filter     = trim($_POST['status_filter']   ?? 'all');
        $selected_students = json_decode($_POST['selected_students'] ?? '[]', true) ?? [];
        $selected_students = array_map('intval', $selected_students);

        if (empty($academic_year)) {
            $_SESSION['error_msg'] = "Please select an academic year.";
            header('Location: bulk_reminder.php');
            exit;
        }
        if (empty($selected_students)) {
            $_SESSION['error_msg'] = "Please select at least one student to send reminders.";
            header('Location: bulk_reminder.php');
            exit;
        }

        // Reload full student data server-side (never trust client selection alone)
        [$all_students] = buildStudentsQueryWithStatus(
            $conn, $academic_year, $dept_id, $class_id, '', 'all', $departments, $class_name_map
        );

        $students_to_send = array_values(array_filter(
            $all_students,
            fn($s) => in_array($s['student_id'], $selected_students, true)
        ));

        if (empty($students_to_send)) {
            $_SESSION['error_msg'] = "No matching students found for the selected IDs.";
            header('Location: bulk_reminder.php');
            exit;
        }

        // ── Counters ────────────────────────────────────────────────────────────
        $sent_count       = 0;
        $failed_count     = 0;
        $skipped_count    = 0;
        $students_reached = 0;
        $failed_details   = [];
        $status_stats     = ['red' => 0, 'yellow' => 0, 'green' => 0];

        foreach ($students_to_send as $student) {
            $sid = $student['student_id'];

            if (isset($status_stats[$student['debt_status']])) {
                $status_stats[$student['debt_status']]++;
            }

            // Resolve parent phones
            $parentPhones = $student['parent_phones_all'] ?? getAllParentPhonesForStudent($conn, $sid);

            if (empty($parentPhones)) {
                $skipped_count++;
                $failed_details[] = "{$student['student_name']} — No parent phone number on record";
                log_activity(
                    $_SESSION['user_id'],
                    'sms_skipped',
                    "No phone for student: {$student['student_name']} (ID:{$sid})",
                    $conn
                );
                continue;
            }

            // Build personalised, plain-ASCII message (no fee breakdown)
            $status_info = calculateDebtStatus(
                $student['total_fees_charged'],
                $student['total_amount_paid'],
                $student['has_arrears']
            );
            $message = buildStatusMessage($student, $status_info, $academic_year);

            // ── Send to every parent phone ───────────────────────────────────
            $student_sent_once = false;

            foreach ($parentPhones as $pi) {
                $send_phone = $pi['phone_api'] ?? formatPhoneForApi($pi['phone'] ?? '');
                if (empty($send_phone)) {
                    continue;
                }

                $sms_result = sendSMS($send_phone, $message);

                if ($sms_result['success']) {
                    $sent_count++;
                    $student_sent_once = true;
                    log_activity(
                        $_SESSION['user_id'],
                        'sms_sent',
                        "SMS sent — student:{$student['student_name']} (ID:{$sid}), status:{$student['debt_status']}, phone:{$send_phone}, chars:" . strlen($message),
                        $conn
                    );
                } else {
                    $failed_count++;
                    $err = $sms_result['error_msg'] ?: ('HTTP ' . $sms_result['http_code']);
                    $failed_details[] = "{$student['student_name']} ({$send_phone}) — {$err}";
                    log_activity(
                        $_SESSION['user_id'],
                        'sms_failed',
                        "SMS failed — student:{$student['student_name']} (ID:{$sid}), phone:{$send_phone}, HTTP:{$sms_result['http_code']}, err:{$err}",
                        $conn
                    );
                }

                usleep(100000); // 0.1 s rate-limit
            }

            if ($student_sent_once) {
                $students_reached++;
            }

            // In-app notification (only when at least one SMS succeeded)
            if ($student_sent_once && !empty($student['parent_user_id'])) {
                $notif_msg = match($student['debt_status']) {
                    'red'    => "URGENT: Outstanding fees for {$student['student_name']} - GHC" . number_format($student['total_debt'], 2),
                    'yellow' => "Reminder: Fee balance for {$student['student_name']} - GHC" . number_format($student['total_debt'], 2),
                    default  => "Thank you: Fee account for {$student['student_name']} is in good standing",
                };
                $n_stmt = $conn->prepare(
                    "INSERT INTO notifications (user_id, title, message, type, category, created_at)
                     VALUES (?, 'Fee Payment Status', ?, 'info', 'administrative', NOW())"
                );
                if ($n_stmt) {
                    $n_stmt->bind_param("is", $student['parent_user_id'], $notif_msg);
                    $n_stmt->execute();
                    $n_stmt->close();
                }
            }
        }

        // ── Summary ─────────────────────────────────────────────────────────────
        $summary = "Red: {$status_stats['red']}, Yellow: {$status_stats['yellow']}, Green: {$status_stats['green']}";

        if ($students_reached > 0 || $sent_count > 0) {
            $_SESSION['success_msg'] =
                "{$students_reached} parent(s) reached via {$sent_count} SMS dispatch(es). {$summary}"
                . ($failed_count  > 0 ? " | Failed dispatches: {$failed_count}." : "")
                . ($skipped_count > 0 ? " | Skipped (no phone): {$skipped_count} student(s)." : "");
        } else {
            $detail = !empty($failed_details)
                ? " Errors: " . implode('; ', array_slice($failed_details, 0, 3))
                : " Check parent contact info and API key.";
            $_SESSION['error_msg'] = "No reminders were delivered.{$detail}";
        }

        if (!empty($failed_details)) {
            $_SESSION['warning_details'] = $failed_details;
        }

        log_activity(
            $_SESSION['user_id'],
            'bulk_reminders_sent',
            "Reached:{$students_reached}, SMS sent:{$sent_count}, Failed:{$failed_count}, Skipped:{$skipped_count}, Year:{$academic_year}, {$summary}",
            $conn
        );

        header('Location: bulk_reminder.php');
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST: AJAX handlers
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['csrf_token'])) {

    if (!validateCsrf($conn, (int)$_SESSION['user_id'], $_POST['csrf_token'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    header('Content-Type: application/json');

    switch ($_POST['action']) {

        case 'filter_classes':
            $dept_id  = (int)($_POST['department_id'] ?? 0);
            $filtered = [];
            foreach ($classes as $id => $class) {
                if ($dept_id === 0 || $class['department_id'] == $dept_id) {
                    $filtered[] = ['id' => $id, 'class_name' => $class['display_name']];
                }
            }
            echo json_encode(['success' => true, 'classes' => $filtered]);
            exit;

        case 'filter_students':
            $academic_year = trim($_POST['academic_year'] ?? '');
            $dept_id       = (int)($_POST['department_id'] ?? 0);
            $class_id      = (int)($_POST['class_id']      ?? 0);
            $search_query  = trim($_POST['search_query']   ?? '');
            $status_filter = trim($_POST['status_filter']  ?? 'all');

            if (!$academic_year) {
                echo json_encode([
                    'success'        => true,
                    'students'       => [],
                    'total_students' => 0,
                    'total_debt'     => 0,
                    'status_counts'  => ['red' => 0, 'yellow' => 0, 'green' => 0],
                ]);
                exit;
            }

            [$students, $total_students, $total_debt, $status_counts] = buildStudentsQueryWithStatus(
                $conn, $academic_year, $dept_id, $class_id, $search_query, $status_filter, $departments, $class_name_map
            );

            $students_clean = array_map(function ($s) {
                unset($s['parent_phones_all']);
                return $s;
            }, $students);

            echo json_encode([
                'success'        => true,
                'students'       => $students_clean,
                'total_students' => $total_students,
                'total_debt'     => $total_debt,
                'status_counts'  => $status_counts,
            ]);
            exit;

        case 'delete_notification':
            $student_id = (int)($_POST['student_id'] ?? 0);
            if ($student_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid student ID']);
                exit;
            }

            $deleted = false;
            $p_stmt  = $conn->prepare(
                "SELECT pu.id AS parent_user_id
                 FROM parent_student_mapping psm
                 INNER JOIN users pu ON psm.parent_id = pu.id
                 WHERE psm.student_id = ? LIMIT 1"
            );
            if ($p_stmt) {
                $p_stmt->bind_param("i", $student_id);
                $p_stmt->execute();
                $p_row = $p_stmt->get_result()->fetch_assoc();
                $p_stmt->close();

                if ($p_row) {
                    $parent_user_id = (int)$p_row['parent_user_id'];
                    $d_stmt = $conn->prepare(
                        "DELETE FROM notifications WHERE user_id = ? AND category = 'administrative'"
                    );
                    if ($d_stmt) {
                        $d_stmt->bind_param("i", $parent_user_id);
                        $d_stmt->execute();
                        $deleted = $d_stmt->affected_rows > 0;
                        $d_stmt->close();
                    }
                }
            }

            log_activity(
                $_SESSION['user_id'],
                'delete_notification',
                "Deleted admin notifications for student ID: {$student_id}",
                $conn
            );
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            exit;

        case 'check_sms_balance':
            $balance_result = checkSMSBalance();
            echo json_encode([
                'success' => $balance_result['success'],
                'balance' => $balance_result['balance'],
            ]);
            exit;

        case 'log_error':
            $msg = trim($_POST['message'] ?? 'Unknown client error');
            log_activity($_SESSION['user_id'], 'client_error', "Client-side error: $msg", $conn);
            echo json_encode(['success' => true]);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Initial page load
// ═══════════════════════════════════════════════════════════════════════════════
$selected_year  = !empty($academic_years) ? $academic_years[0] : '';
$students       = [];
$total_students = 0;
$total_debt     = 0.0;
$status_counts  = ['red' => 0, 'yellow' => 0, 'green' => 0];

if ($selected_year) {
    [$students, $total_students, $total_debt, $status_counts] = buildStudentsQueryWithStatus(
        $conn, $selected_year, 0, 0, '', 'all', $departments, $class_name_map
    );
    log_activity(
        $_SESSION['user_id'],
        'viewed_bulk_reminder',
        "Initial load year={$selected_year}, students={$total_students}, debt=GHC{$total_debt}",
        $conn
    );
}

$sms_balance = checkSMSBalance();

function getStatusBadgeClass(string $status): string {
    return match($status) {
        'red'    => 'badge-danger',
        'yellow' => 'badge-warning',
        'green'  => 'badge-success',
        default  => 'badge-secondary',
    };
}

function getStatusIcon(string $status): string {
    return match($status) {
        'red'    => 'fa-exclamation-triangle',
        'yellow' => 'fa-clock',
        'green'  => 'fa-check-circle',
        default  => 'fa-info-circle',
    };
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta name="description" content="Bulk SMS Reminder System – Queen's Paradise Montessori School.">
    <title>Bulk SMS Reminder System – Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --success-color: #28a745;
            --info-color: #17a2b8;
            --border-radius: 12px;
            --box-shadow: 0 5px 15px rgba(255,98,0,.08);
            --gradient-primary: linear-gradient(135deg,#ff6200 0%,#ff8c00 100%);
        }
        body {
            background: linear-gradient(135deg,#fff8f2 0%,#fffdf5 100%);
            font-family: 'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            color: #333; min-height: 100vh;
        }
        .dashboard-container { max-width: 100%; padding: 20px; }
        .page-header {
            background: white; border-radius: var(--border-radius); padding: 1.2rem 1.8rem;
            margin-bottom: 1.5rem; box-shadow: var(--box-shadow); border-left: 6px solid var(--primary-color);
        }
        .page-header h1 { color: #ff6200; font-weight: 700; margin: 0; font-size: 1.6rem; }
        .page-header .subtitle { color: #ff8c00; font-size: .85rem; margin: .3rem 0 0; }
        .sms-balance-badge {
            background: linear-gradient(135deg,#17a2b8,#138496); color: white;
            padding: .4rem 1rem; border-radius: 20px; font-size: .8rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: .4rem;
        }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.2rem; margin-bottom: 1.8rem; }
        .stat-card {
            background: white; border-radius: var(--border-radius); padding: 1.2rem;
            box-shadow: var(--box-shadow); transition: all .2s ease; cursor: pointer; position: relative; overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(255,98,0,.12); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px; }
        .stat-card.red::before    { background: var(--danger-color); }
        .stat-card.yellow::before { background: var(--warning-color); }
        .stat-card.green::before  { background: var(--success-color); }
        .stat-card.total::before  { background: var(--gradient-primary); }
        .stat-card .stat-icon {
            width: 48px; height: 48px; border-radius: 12px; display: flex;
            align-items: center; justify-content: center; margin-bottom: .8rem;
            font-size: 1.3rem; color: white;
        }
        .stat-card.red    .stat-icon { background: var(--danger-color); }
        .stat-card.yellow .stat-icon { background: var(--warning-color); }
        .stat-card.green  .stat-icon { background: var(--success-color); }
        .stat-card.total  .stat-icon { background: var(--gradient-primary); }
        .stat-card .stat-value { font-size: 1.8rem; font-weight: 800; margin: .4rem 0; line-height: 1; }
        .stat-card.red    .stat-value { color: var(--danger-color); }
        .stat-card.yellow .stat-value { color: #e0a800; }
        .stat-card.green  .stat-value { color: var(--success-color); }
        .stat-card.total  .stat-value { color: var(--primary-color); }
        .stat-card .stat-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin: 0; }
        .stat-card.red    .stat-label { color: var(--danger-color); }
        .stat-card.yellow .stat-label { color: #e0a800; }
        .stat-card.green  .stat-label { color: var(--success-color); }
        .stat-card.total  .stat-label { color: var(--primary-color); }
        .filter-card {
            background: white; border-radius: var(--border-radius); padding: 1.2rem 1.5rem;
            margin-bottom: 1.8rem; box-shadow: var(--box-shadow); border-left: 5px solid var(--primary-color);
        }
        .filter-card .form-label { font-weight: 600; color: #ff6200; font-size: .75rem; margin-bottom: .3rem; }
        .filter-card .form-control,
        .filter-card .form-select {
            border: 1.5px solid #ffe8d6; border-radius: 8px; padding: .5rem .8rem; font-size: .85rem;
        }
        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(255,98,0,.1);
        }
        .badge-status { padding: .3rem .8rem; border-radius: 30px; font-size: .7rem; font-weight: 600; display: inline-flex; align-items: center; gap: .3rem; }
        .badge-danger  { background: linear-gradient(135deg,#dc3545,#ff6b6b); color: white; }
        .badge-warning { background: linear-gradient(135deg,#ffc107,#ffd700); color: #333; }
        .badge-success { background: linear-gradient(135deg,#28a745,#4cd964); color: white; }
        .status-legend { display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .legend-item { display: flex; align-items: center; gap: 0.5rem; font-size: .75rem; }
        .legend-color { width: 16px; height: 16px; border-radius: 4px; }
        .legend-color.red    { background: var(--danger-color); }
        .legend-color.yellow { background: var(--warning-color); }
        .legend-color.green  { background: var(--success-color); }
        .table-container {
            background: white; border-radius: var(--border-radius); overflow: hidden;
            box-shadow: var(--box-shadow); margin-bottom: 1.8rem;
        }
        .table { margin: 0; font-size: .8rem; }
        .table thead th {
            background: var(--gradient-primary); color: white; border: none;
            padding: .8rem 1rem; font-weight: 600; font-size: .7rem; text-transform: uppercase;
        }
        .table tbody tr { border-bottom: 1px solid #ffe8d6; transition: all .2s; }
        .table tbody tr:hover { background-color: rgba(255,248,242,.5); }
        .table tbody td { padding: .7rem 1rem; vertical-align: middle; }
        tr.row-red    { border-left: 4px solid var(--danger-color);  background: rgba(220,53,69,.02); }
        tr.row-yellow { border-left: 4px solid var(--warning-color); background: rgba(255,193,7,.02); }
        tr.row-green  { border-left: 4px solid var(--success-color); background: rgba(40,167,69,.02); }
        .btn-custom {
            background: var(--gradient-primary); border: none; border-radius: 8px; color: white;
            padding: .5rem 1.2rem; font-weight: 600; font-size: .8rem; transition: all .2s;
            display: inline-flex; align-items: center; gap: .5rem;
        }
        .btn-custom:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(255,98,0,.25); color: white; }
        .btn-custom.btn-outline { background: transparent; border: 1.5px solid var(--primary-color); color: var(--primary-color); }
        .btn-custom.btn-outline:hover { background: var(--gradient-primary); color: white; }
        .btn-custom.btn-danger  { background: linear-gradient(135deg,#dc3545,#ff6b6b); }
        .btn-custom.btn-success { background: linear-gradient(135deg,#28a745,#4cd964); }
        .sms-preview {
            background: linear-gradient(to right,rgba(23,162,184,.05),rgba(23,162,184,.02));
            border-radius: 10px; padding: 1rem; border-left: 5px solid var(--info-color);
        }
        .sms-preview .preview-content {
            background: white; padding: .8rem; border-radius: 6px; border: 1px solid #dee2e6;
            font-family: monospace; white-space: pre-wrap; font-size: .75rem;
            max-height: 200px; overflow-y: auto;
        }
        .loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255,255,255,.9); display: none;
            justify-content: center; align-items: center; z-index: 9999;
            flex-direction: column;
        }
        .template-card {
            border: 1.5px solid #ffe8d6; border-radius: 10px; padding: .8rem;
            cursor: pointer; transition: all .2s; background: white;
        }
        .template-card:hover  { border-color: var(--primary-color); background: #fff9f0; transform: translateY(-2px); }
        .template-card.selected { border-color: var(--primary-color); background: rgba(255,98,0,.05); }
        .char-limit-ok   { color: var(--success-color); font-weight: 600; }
        .char-limit-warn { color: #e0a800; font-weight: 600; }
        .char-limit-over { color: var(--danger-color); font-weight: 600; }
        @media (max-width: 768px) {
            .dashboard-container { padding: 12px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: .8rem; }
            .table-container { overflow-x: auto; }
            .table { min-width: 800px; }
        }
    </style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div>
    <div class="mt-2 text-primary fw-bold" id="loadingText">Processing…</div>
</div>

<?php
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
if (file_exists($header_path)) include $header_path;
?>

<div class="dashboard-container">

    <!-- Page Header -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1><i class="fas fa-comment-sms me-2"></i>Bulk SMS Reminder System</h1>
            <p class="subtitle">Send personalised SMS reminders based on debt status — Red / Yellow / Green</p>
        </div>
        <div>
            <span class="sms-balance-badge">
                <i class="fas fa-coins"></i>
                SMS Balance:
                <strong id="sms_balance_display">
                    <?= $sms_balance['success'] ? htmlspecialchars($sms_balance['balance']) : 'N/A' ?>
                </strong>
                <button class="btn btn-sm btn-light ms-1 py-0 px-1" id="refresh_balance_btn" title="Refresh balance" style="font-size:.7rem;">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </span>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= nl2br(htmlspecialchars($_SESSION['success_msg'])) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <?php unset($_SESSION['success_msg']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= htmlspecialchars($_SESSION['error_msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <?php unset($_SESSION['error_msg']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['warning_details']) && is_array($_SESSION['warning_details'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Some dispatches failed:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($_SESSION['warning_details'] as $detail): ?>
                    <li><?= htmlspecialchars($detail) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <?php unset($_SESSION['warning_details']); ?>
        </div>
    <?php endif; ?>

    <!-- Status Legend -->
    <div class="status-legend">
        <div class="legend-item"><div class="legend-color red"></div><span><strong>RED</strong> – Critical / High Debt (arrears or &lt;50% paid) – Urgent action needed</span></div>
        <div class="legend-item"><div class="legend-color yellow"></div><span><strong>YELLOW</strong> – Partial Payment (50–89% paid) – Friendly reminder</span></div>
        <div class="legend-item"><div class="legend-color green"></div><span><strong>GREEN</strong> – Good Standing (&ge;90% paid) – Thank-you message</span></div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card red"    data-filter="red">
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-value" id="red_count"><?= $status_counts['red'] ?></div>
            <div class="stat-label">Critical – Red</div>
        </div>
        <div class="stat-card yellow" data-filter="yellow">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-value" id="yellow_count"><?= $status_counts['yellow'] ?></div>
            <div class="stat-label">Partial – Yellow</div>
        </div>
        <div class="stat-card green"  data-filter="green">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value" id="green_count"><?= $status_counts['green'] ?></div>
            <div class="stat-label">Good – Green</div>
        </div>
        <div class="stat-card total"  data-filter="all">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-value" id="total_students"><?= $total_students ?></div>
            <div class="stat-label">Total with Fees</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-calendar-alt me-1"></i>Academic Year</label>
                <select id="academic_year" class="form-select">
                    <option value="">Select Academic Year</option>
                    <?php foreach ($academic_years as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= $selected_year === $year ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-building me-1"></i>Department</label>
                <select id="department_id" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $id => $dept): ?>
                        <option value="<?= $id ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-chalkboard me-1"></i>Class</label>
                <select id="class_id" class="form-select">
                    <option value="">All Classes</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-filter me-1"></i>Status Filter</label>
                <select id="status_filter" class="form-select">
                    <option value="all">All Status</option>
                    <option value="red">Red – Critical</option>
                    <option value="yellow">Yellow – Partial</option>
                    <option value="green">Green – Good</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-search me-1"></i>Search</label>
                <input type="text" id="search_input" class="form-control" placeholder="Student name…">
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12">
                <div class="d-flex justify-content-end gap-2 flex-wrap">
                    <button class="btn-custom"             id="filter_btn">       <i class="fas fa-filter me-1"></i>Apply Filters</button>
                    <button class="btn-custom btn-outline" id="select_all_btn">   <i class="fas fa-check-square me-1"></i>Select All</button>
                    <button class="btn-custom btn-outline" id="deselect_all_btn"> <i class="fas fa-times-circle me-1"></i>Deselect All</button>
                    <button class="btn-custom btn-danger"  id="select_red_btn">   <i class="fas fa-exclamation-triangle me-1"></i>Select Critical (Red)</button>
                    <button class="btn-custom"             id="quick_preview_btn"><i class="fas fa-eye me-1"></i>Quick Preview</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Selected count bar -->
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h3 class="fw-bold fs-5 mb-0"><i class="fas fa-user-graduate me-2 text-primary"></i>Students with Outstanding Fees</h3>
        <span class="badge bg-primary p-2"><i class="fas fa-users me-1"></i><span id="selected_count">0</span> selected</span>
    </div>

    <!-- Students Table -->
    <div class="table-container">
        <div class="table-responsive">
            <table class="table" id="students_table">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" id="select_all_checkbox"></th>
                        <th>Student</th><th>Class</th><th>Parent Contact</th>
                        <th>Total Fees</th><th>Paid</th><th>Outstanding</th>
                        <th>Status</th><th>Overdue</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="10" class="text-center py-4 text-muted">
                        <i class="fas fa-user-slash fa-2x mb-2 d-block"></i>No students with outstanding fees found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($students as $student):
                        $status    = $student['debt_status'];
                        $row_class = $status === 'red' ? 'row-red' : ($status === 'yellow' ? 'row-yellow' : 'row-green');
                        $pct       = $student['total_fees_charged'] > 0
                            ? (int)round(($student['total_amount_paid'] / $student['total_fees_charged']) * 100) : 0;
                    ?>
                    <tr class="<?= $row_class ?>" data-student-id="<?= $student['student_id'] ?>" data-status="<?= $status ?>">
                        <td><input type="checkbox" class="student-checkbox" value="<?= $student['student_id'] ?>"></td>
                        <td>
                            <strong><?= htmlspecialchars($student['student_name']) ?></strong>
                            <br><small class="text-muted">ID: <?= $student['student_id'] ?></small>
                        </td>
                        <td><?= htmlspecialchars($student['class_display_name'] ?? $student['class_name']) ?></td>
                        <td>
                            <div><i class="fas fa-user me-1"></i><?= htmlspecialchars($student['parent_name'] ?: 'No parent') ?></div>
                            <div class="text-muted small"><i class="fas fa-phone me-1"></i>
                                <?= htmlspecialchars($student['parent_phone'] ?: 'No phone') ?>
                                <?php $extra = count($student['parent_phones_all'] ?? []) - 1;
                                      if ($extra > 0): ?>
                                    <span class="badge bg-info text-dark ms-1" style="font-size:.6rem;">+<?= $extra ?> more</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-primary fw-bold">GHC <?= number_format($student['total_fees_charged'], 2) ?></td>
                        <td class="text-success fw-bold">
                            GHC <?= number_format($student['total_amount_paid'], 2) ?>
                            <br><small><?= $pct ?>%</small>
                        </td>
                        <td class="text-danger fw-bold">GHC <?= number_format($student['total_debt'], 2) ?></td>
                        <td>
                            <span class="badge-status <?= getStatusBadgeClass($status) ?>">
                                <i class="fas <?= getStatusIcon($status) ?> me-1"></i>
                                <?= ucfirst($status) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($student['days_overdue'] > 0): ?>
                                <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i><?= $student['days_overdue'] ?> days</span>
                            <?php else: ?>
                                <span class="text-success"><i class="fas fa-check-circle me-1"></i>On time</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-danger delete-notif-btn"
                                    data-student-id="<?= $student['student_id'] ?>"
                                    data-student-name="<?= htmlspecialchars($student['student_name']) ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Message Composition -->
    <div class="filter-card">
        <form method="POST" id="reminder_form">
            <input type="hidden" name="csrf_token"        value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action"            value="send_reminders">
            <input type="hidden" name="academic_year"     id="form_academic_year" value="<?= htmlspecialchars($selected_year) ?>">
            <input type="hidden" name="department_id"     id="form_department_id" value="">
            <input type="hidden" name="class_id"          id="form_class_id"      value="">
            <input type="hidden" name="status_filter"     id="form_status_filter" value="">
            <input type="hidden" name="selected_students" id="selected_students_input" value="[]">

            <h5 class="fw-bold mb-3"><i class="fas fa-comment-alt me-2 text-primary"></i>Message Templates (Auto-selected by Status)</h5>

            <div class="alert alert-info py-2 mb-3" style="font-size:.8rem;">
                <i class="fas fa-info-circle me-1"></i>
                Messages use <strong>plain ASCII text only</strong> — no emoji, no fee breakdown — for clean, concise delivery.
                All messages stay well under <strong>640 characters</strong> for reliable delivery.
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <div class="template-card selected" data-status="red">
                        <div class="fw-bold text-danger"><i class="fas fa-exclamation-triangle"></i> Red Template</div>
                        <small class="text-muted">Urgent – Critical debt / Arrears</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="template-card" data-status="yellow">
                        <div class="fw-bold text-warning"><i class="fas fa-clock"></i> Yellow Template</div>
                        <small class="text-muted">Reminder – Partial payment</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="template-card" data-status="green">
                        <div class="fw-bold text-success"><i class="fas fa-check-circle"></i> Green Template</div>
                        <small class="text-muted">Thank you – Good standing</small>
                    </div>
                </div>
            </div>

            <div class="sms-preview">
                <div class="fw-bold mb-2"><i class="fas fa-eye me-1"></i>Message Preview (Sample)</div>
                <div class="preview-content" id="message_preview">Select a student to preview the personalised message…</div>
                <div class="d-flex justify-content-between mt-2">
                    <small>
                        Characters: <span id="char_count" class="char-limit-ok">0</span> / 640 &nbsp;|&nbsp;
                        SMS segments: <span id="sms_units">0</span>
                    </small>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn-custom btn-success" id="send_btn" disabled>
                    <i class="fas fa-paper-plane me-1"></i>Send Selected Reminders
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Confirm Send Reminders</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Sending reminders to <strong id="modal_count">0</strong> parent(s).
                    Each message is personalised by status and uses plain ASCII for reliable delivery.
                </div>
                <div id="modal_summary" class="mb-3"></div>
                <div class="sms-preview mb-3">
                    <div class="fw-bold mb-2">Sample Message:</div>
                    <div id="modal_preview" class="preview-content" style="max-height:200px;overflow-y:auto;"></div>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirm_send">
                    <label class="form-check-label" for="confirm_send">I confirm sending these personalised reminders</label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="confirm_send_btn" disabled>
                    <i class="fas fa-paper-plane me-1"></i>Send Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Notification Modal -->
<div class="modal fade" id="deleteNotifModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Delete Notifications</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete all sent notifications for <strong id="delete_student_name"></strong>?</p>
                <div class="alert alert-warning small">This removes notification records from the database.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" id="confirm_delete_btn">Delete</button>
            </div>
        </div>
    </div>
</div>

<?php
$footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
if (file_exists($footer_path)) include $footer_path;
$conn->close();
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────────────────
    let selectedStudents    = new Set();
    let allStudents         = [];
    let currentAcademicYear = <?= json_encode($selected_year) ?>;
    const CSRF              = <?= json_encode($csrf_token) ?>;
    const SMS_MAX_CHARS     = 640;

    // ── Utilities ────────────────────────────────────────────────────────────
    function showLoading(msg) {
        $('#loadingText').text(msg || 'Processing…');
        $('#loadingOverlay').css('display', 'flex');
    }
    function hideLoading() { $('#loadingOverlay').fadeOut(200); }

    function showAlert(message, type) {
        const alertDiv = $(`<div class="alert alert-${type} alert-dismissible fade show">
            <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : (type === 'warning' ? 'exclamation-triangle' : 'check-circle')} me-2"></i>
            ${escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`);
        $('.page-header').after(alertDiv);
        setTimeout(() => alertDiv.alert('close'), 8000);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    function getStatusBadgeClass(status) {
        return status === 'red' ? 'badge-danger' : (status === 'yellow' ? 'badge-warning' : 'badge-success');
    }
    function getStatusIcon(status) {
        return status === 'red' ? 'fa-exclamation-triangle' : (status === 'yellow' ? 'fa-clock' : 'fa-check-circle');
    }

    function normalizePhoneDisplay(raw) {
        if (!raw) return '';
        let clean = raw.replace(/[^0-9+]/g, '');
        if (!clean) return '';
        if (clean.startsWith('+233'))                           return '0' + clean.slice(4);
        if (clean.startsWith('233') && clean.length === 12)     return '0' + clean.slice(3);
        if (clean.length === 9 && clean[0] !== '0')             return '0' + clean;
        return clean;
    }

    // ── Plain-ASCII preview (mirrors PHP buildStatusMessage — no fee breakdown) ──
    /**
     * Builds a plain-ASCII preview message matching exactly what PHP sends.
     * Fee breakdown is excluded from all message types.
     */
    function buildPreviewMessage(student, year) {
        const name   = student.student_name || '';
        const cls    = student.class_display_name || student.class_name || '';
        const debt   = parseFloat(student.total_debt  || 0).toFixed(2);
        const fees   = parseFloat(student.total_fees_charged || 0).toFixed(2);
        const paid   = parseFloat(student.total_amount_paid  || 0).toFixed(2);
        const pct    = parseFloat(fees) > 0 ? Math.min(Math.round((parseFloat(paid) / parseFloat(fees)) * 100), 100) : 100;
        const school  = 'QUEENS PARADISE MONTESSORI SCHOOL';
        const arrears = student.has_arrears || false;
        let msg = '';

        if (student.debt_status === 'red') {
            msg  = `${school}\n--- URGENT FEE NOTICE ---\n\n`;
            msg += `Dear Parent/Guardian,\n`;
            msg += `Student: ${name}\nClass: ${cls} | Year: ${year}\n\n`;
            if (arrears) {
                msg += `!! ARREARS OUTSTANDING !!\nTotal owed: GHC${debt}\n`;
                msg += `Your ward may be DENIED ENTRY until all fees are cleared.\n`;
            } else {
                msg += `Outstanding Balance: GHC${debt}\n`;
                msg += `Payment Progress: ${pct}% paid.\n`;
                msg += `Please pay immediately to avoid school restrictions.\n`;
            }
            msg += `\nPay at: Finance Office or MoMo: 055-123-4567\nContact admin for queries.`;

        } else if (student.debt_status === 'yellow') {
            msg  = `${school}\n--- FEE PAYMENT REMINDER ---\n\n`;
            msg += `Dear Parent/Guardian,\n`;
            msg += `Student: ${name}\nClass: ${cls} | Year: ${year}\n\n`;
            msg += `Total Fees: GHC${fees}\nAmount Paid: GHC${paid}\n`;
            msg += `Balance Owed: GHC${debt} (${pct}% paid)\n\n`;
            msg += `Kindly settle the balance before the next term.\n`;
            msg += `Pay at: Finance Office or MoMo: 055-123-4567\n`;
            msg += `Thank you. Queens Paradise Montessori School`;

        } else {
            if (pct >= 99) {
                msg  = `${school}\n\nDear Parent/Guardian,\n`;
                msg += `Student: ${name}\nClass: ${cls} | Year: ${year}\n\n`;
                msg += `STATUS: FULLY PAID\n\n`;
                msg += `Thank you for your prompt payment. Your support enables us to provide quality education for your ward.\n\n`;
                msg += `Queens Paradise Montessori School`;
            } else {
                msg  = `${school}\n\nDear Parent/Guardian,\n`;
                msg += `Student: ${name}\nClass: ${cls} | Year: ${year}\n\n`;
                msg += `Status: GOOD STANDING (${pct}% paid)\nRemaining Balance: GHC${debt}\n\n`;
                msg += `Thank you for your consistent payments. Please clear the small balance at your convenience.\n`;
                msg += `Queens Paradise Montessori School`;
            }
        }

        // Enforce character cap (matching PHP enforceSmsLimit)
        if (msg.length > SMS_MAX_CHARS) {
            const suffix = '... [msg truncated]';
            msg = msg.slice(0, SMS_MAX_CHARS - suffix.length) + suffix;
        }
        return msg;
    }

    // ── Table render ─────────────────────────────────────────────────────────
    function renderTable(students) {
        allStudents = students;
        let html = '';
        if (!students.length) {
            html = '<tr><td colspan="10" class="text-center py-4 text-muted"><i class="fas fa-user-slash fa-2x mb-2 d-block"></i>No students found.</td></tr>';
        } else {
            students.forEach(s => {
                const rowClass   = s.debt_status === 'red' ? 'row-red' : (s.debt_status === 'yellow' ? 'row-yellow' : 'row-green');
                const pct        = s.total_fees_charged > 0 ? Math.round((s.total_amount_paid / s.total_fees_charged) * 100) : 0;
                const displayPh  = normalizePhoneDisplay(s.parent_phone || '');
                const extra      = (s.parent_phones_all || []).length - 1;
                const extraBadge = extra > 0 ? `<span class="badge bg-info text-dark ms-1" style="font-size:.6rem;">+${extra} more</span>` : '';

                html += `<tr class="${rowClass}" data-student-id="${s.student_id}" data-status="${escapeHtml(s.debt_status)}">
                    <td><input type="checkbox" class="student-checkbox" value="${s.student_id}"></td>
                    <td><strong>${escapeHtml(s.student_name)}</strong><br><small class="text-muted">ID: ${s.student_id}</small></td>
                    <td>${escapeHtml(s.class_display_name || s.class_name)}</td>
                    <td>
                        <div><i class="fas fa-user me-1"></i>${escapeHtml(s.parent_name || 'No parent')}</div>
                        <div class="text-muted small"><i class="fas fa-phone me-1"></i>${escapeHtml(displayPh || 'No phone')}${extraBadge}</div>
                    </td>
                    <td class="text-primary fw-bold">GHC ${parseFloat(s.total_fees_charged).toFixed(2)}</td>
                    <td class="text-success fw-bold">GHC ${parseFloat(s.total_amount_paid).toFixed(2)}<br><small>${pct}%</small></td>
                    <td class="text-danger fw-bold">GHC ${parseFloat(s.total_debt).toFixed(2)}</td>
                    <td><span class="badge-status ${getStatusBadgeClass(s.debt_status)}">
                        <i class="fas ${getStatusIcon(s.debt_status)} me-1"></i>${escapeHtml(s.debt_status.charAt(0).toUpperCase() + s.debt_status.slice(1))}
                    </span></td>
                    <td>${parseInt(s.days_overdue) > 0
                        ? `<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>${s.days_overdue} days</span>`
                        : '<span class="text-success"><i class="fas fa-check-circle me-1"></i>On time</span>'}</td>
                    <td><button class="btn btn-sm btn-outline-danger delete-notif-btn"
                        data-student-id="${s.student_id}"
                        data-student-name="${escapeHtml(s.student_name)}">
                        <i class="fas fa-trash"></i></button></td>
                </tr>`;
            });
        }
        $('#students_table tbody').html(html);

        $('#red_count').text(students.filter(s => s.debt_status === 'red').length);
        $('#yellow_count').text(students.filter(s => s.debt_status === 'yellow').length);
        $('#green_count').text(students.filter(s => s.debt_status === 'green').length);
        $('#total_students').text(students.length);

        $('.student-checkbox').each(function () {
            if (selectedStudents.has(parseInt($(this).val(), 10))) $(this).prop('checked', true);
        });

        updateSelectedCount();
    }

    // ── Preview ───────────────────────────────────────────────────────────────
    function updatePreview(student) {
        const year = $('#academic_year').val() || currentAcademicYear;
        const msg  = buildPreviewMessage(student, year);
        $('#message_preview').text(msg);

        const len   = msg.length;
        const segs  = Math.ceil(len / 160);
        const $cnt  = $('#char_count');
        $cnt.text(len).removeClass('char-limit-ok char-limit-warn char-limit-over');
        if      (len > SMS_MAX_CHARS) $cnt.addClass('char-limit-over');
        else if (len > 500)           $cnt.addClass('char-limit-warn');
        else                          $cnt.addClass('char-limit-ok');
        $('#sms_units').text(segs);
    }

    function updateSelectedCount() {
        $('#selected_count').text(selectedStudents.size);
        $('#send_btn').prop('disabled', selectedStudents.size === 0);

        if (selectedStudents.size > 0 && allStudents.length) {
            const first = allStudents.find(s => selectedStudents.has(s.student_id));
            if (first) updatePreview(first);
        } else {
            $('#message_preview').text('Select a student to preview the personalised message…');
            $('#char_count').text(0).removeClass('char-limit-warn char-limit-over').addClass('char-limit-ok');
            $('#sms_units').text(0);
        }
    }

    // ── AJAX: load students ───────────────────────────────────────────────────
    function loadStudents() {
        const year    = $('#academic_year').val();
        const deptId  = $('#department_id').val();
        const classId = $('#class_id').val();
        const filter  = $('#status_filter').val();
        const search  = $('#search_input').val().trim();

        if (!year) { renderTable([]); return; }

        showLoading('Loading students…');
        $.post(location.href, {
            action:        'filter_students',
            academic_year:  year,
            department_id:  deptId,
            class_id:       classId,
            status_filter:  filter,
            search_query:   search,
            csrf_token:     CSRF,
        })
        .done(function (res) {
            hideLoading();
            if (res && res.success) {
                currentAcademicYear = year;
                $('#form_academic_year').val(year);
                $('#form_department_id').val(deptId);
                $('#form_class_id').val(classId);
                $('#form_status_filter').val(filter);
                renderTable(res.students || []);
            } else {
                showAlert('Error loading students: ' + (res.error || 'Unknown error'), 'danger');
            }
        })
        .fail(function (xhr) {
            hideLoading();
            showAlert('Network error loading students (HTTP ' + xhr.status + ')', 'danger');
            $.post(location.href, { action: 'log_error', message: 'filter_students failed HTTP ' + xhr.status, csrf_token: CSRF });
        });
    }

    // ── AJAX: load classes by department ─────────────────────────────────────
    function loadClasses(callback) {
        const deptId = $('#department_id').val();
        $.post(location.href, {
            action:        'filter_classes',
            department_id:  deptId,
            csrf_token:     CSRF,
        })
        .done(function (res) {
            if (res && res.success) {
                let opts = '<option value="">All Classes</option>';
                res.classes.forEach(c => { opts += `<option value="${c.id}">${escapeHtml(c.class_name)}</option>`; });
                $('#class_id').html(opts);
                if (typeof callback === 'function') callback();
                else loadStudents();
            }
        })
        .fail(function () { loadStudents(); });
    }

    // ── AJAX: SMS balance refresh ─────────────────────────────────────────────
    $('#refresh_balance_btn').click(function () {
        const $btn = $(this);
        $btn.prop('disabled', true).find('i').addClass('fa-spin');
        $.post(location.href, { action: 'check_sms_balance', csrf_token: CSRF })
        .done(function (res) {
            if (res && res.success) {
                $('#sms_balance_display').text(res.balance);
            } else {
                showAlert('Could not fetch SMS balance.', 'warning');
            }
        })
        .fail(function () { showAlert('Network error fetching SMS balance.', 'danger'); })
        .always(function () { $btn.prop('disabled', false).find('i').removeClass('fa-spin'); });
    });

    // ── Filter / search events ────────────────────────────────────────────────
    $('#filter_btn').click(loadStudents);
    $('#academic_year, #class_id, #status_filter').change(loadStudents);
    $('#department_id').change(function () { loadClasses(); });

    let searchTimer;
    $('#search_input').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadStudents, 450);
    });

    // ── Stat-card shortcuts ───────────────────────────────────────────────────
    $('.stat-card[data-filter]').click(function () {
        const f = $(this).data('filter');
        $('#status_filter').val(f === 'total' ? 'all' : f);
        loadStudents();
    });

    // ── Checkbox handling ─────────────────────────────────────────────────────
    $(document).on('change', '.student-checkbox', function () {
        const sid = parseInt($(this).val(), 10);
        $(this).prop('checked') ? selectedStudents.add(sid) : selectedStudents.delete(sid);
        if (!$(this).prop('checked')) $('#select_all_checkbox').prop('checked', false);
        updateSelectedCount();
    });

    $('#select_all_checkbox').change(function () {
        const checked = $(this).prop('checked');
        $('.student-checkbox').prop('checked', checked);
        if (checked) {
            $('.student-checkbox').each(function () { selectedStudents.add(parseInt($(this).val(), 10)); });
        } else {
            selectedStudents.clear();
        }
        updateSelectedCount();
    });

    $('#select_all_btn').click(function () {
        $('#select_all_checkbox').prop('checked', true);
        $('.student-checkbox').prop('checked', true).each(function () {
            selectedStudents.add(parseInt($(this).val(), 10));
        });
        updateSelectedCount();
    });

    $('#deselect_all_btn').click(function () {
        $('.student-checkbox, #select_all_checkbox').prop('checked', false);
        selectedStudents.clear();
        updateSelectedCount();
    });

    $('#select_red_btn').click(function () {
        selectedStudents.clear();
        $('.student-checkbox').each(function () {
            const $row  = $(this).closest('tr');
            const isRed = $row.data('status') === 'red';
            $(this).prop('checked', isRed);
            if (isRed) selectedStudents.add(parseInt($(this).val(), 10));
        });
        $('#select_all_checkbox').prop('checked', false);
        updateSelectedCount();
        showAlert(
            selectedStudents.size > 0
                ? `Selected ${selectedStudents.size} critical (red) student(s).`
                : 'No critical (red) students in the current view.',
            selectedStudents.size > 0 ? 'info' : 'warning'
        );
    });

    // ── Quick preview ─────────────────────────────────────────────────────────
    $('#quick_preview_btn').click(function () {
        if (selectedStudents.size === 0) {
            showAlert('Please select at least one student to preview.', 'warning');
            return;
        }
        const first = allStudents.find(s => selectedStudents.has(s.student_id));
        if (first) updatePreview(first);
        $('html, body').animate({ scrollTop: $('.sms-preview').offset().top - 100 }, 500);
    });

    // ── Delete notification ───────────────────────────────────────────────────
    $(document).on('click', '.delete-notif-btn', function () {
        $('#delete_student_name').text($(this).data('student-name'));
        $('#confirm_delete_btn').data('student-id', $(this).data('student-id'));
        new bootstrap.Modal(document.getElementById('deleteNotifModal')).show();
    });

    $('#confirm_delete_btn').click(function () {
        const studentId = $(this).data('student-id');
        $.post(location.href, { action: 'delete_notification', student_id: studentId, csrf_token: CSRF })
        .done(function (res) {
            bootstrap.Modal.getInstance(document.getElementById('deleteNotifModal')).hide();
            showAlert(res.success ? 'Notifications cleared.' : 'Error: ' + (res.error || 'Unknown'), res.success ? 'success' : 'danger');
        })
        .fail(function () { showAlert('Network error while deleting notifications.', 'danger'); });
    });

    // ── Form submit → confirmation modal ─────────────────────────────────────
    $('#reminder_form').submit(function (e) {
        e.preventDefault();
        if (selectedStudents.size === 0) {
            showAlert('Please select at least one student.', 'warning');
            return;
        }

        const sel = allStudents.filter(s => selectedStudents.has(s.student_id));

        $('#modal_count').text(sel.length);
        $('#modal_summary').html(`
            <div class="d-flex justify-content-between mb-1"><span>Red (Critical):</span><strong>${sel.filter(s => s.debt_status === 'red').length}</strong></div>
            <div class="d-flex justify-content-between mb-1"><span>Yellow (Partial):</span><strong>${sel.filter(s => s.debt_status === 'yellow').length}</strong></div>
            <div class="d-flex justify-content-between"><span>Green (Good):</span><strong>${sel.filter(s => s.debt_status === 'green').length}</strong></div>
        `);

        const first = sel[0];
        $('#modal_preview').text(first ? buildPreviewMessage(first, $('#academic_year').val() || currentAcademicYear) : '');
        $('#confirm_send').prop('checked', false);
        $('#confirm_send_btn').prop('disabled', true);
        new bootstrap.Modal(document.getElementById('confirmModal')).show();
    });

    $('#confirm_send').change(function () {
        $('#confirm_send_btn').prop('disabled', !$(this).prop('checked'));
    });

    $('#confirm_send_btn').click(function () {
        bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
        $('#selected_students_input').val(JSON.stringify(Array.from(selectedStudents)));
        showLoading('Sending reminders…');
        $('#reminder_form')[0].submit();
    });

    // ── Initial load ──────────────────────────────────────────────────────────
    if ($('#academic_year').val()) {
        loadClasses(loadStudents);
    }
});
</script>
</body>
</html>