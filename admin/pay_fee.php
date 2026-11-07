<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/helpers.php');

if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'));
}

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to access this page.";
    header('Location: /login.php');
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';
$user_id   = $_SESSION['user_id'];

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── SMS API ───────────────────────────────────────────────────────────────────
if (!defined('SMS_API_KEY'))   define('SMS_API_KEY',   'RFZLcHFxc05CTHlOWXhXY0xlbGo');
if (!defined('SMS_API_URL'))   define('SMS_API_URL',   'https://sms.arkesel.com/sms/api');
if (!defined('SMS_SENDER_ID')) define('SMS_SENDER_ID', 'QUEENSPA');

// ── Error logging (never display in production) ───────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
ini_set('error_log', $logDir . '/payment_errors.log');

$class_mapping = [
    'Esther Foundation' => 'Creche',
    'Sheba One'         => 'Nursery 1',
    'Sheba Two'         => 'Nursery 2',
    'Yaasant One'       => 'KG 1',
    'Yaasant Two'       => 'KG 2',
    'Cleopatra One'     => 'Basic 1',
    'Cleopatra Two'     => 'Basic 2',
    'Nefertiti One'     => 'Basic 3',
    'Nefertiti Two'     => 'Basic 4',
    'Malika One'        => 'Basic 5',
    'Malika Two'        => 'Basic 6',
    'Makeda One'        => 'Basic 7',
    'Makeda Two'        => 'Basic 8',
    'An Majesty'        => 'Basic 9',
];

// ── Helper Functions ──────────────────────────────────────────────────────────

function normalizePhoneForDisplay($phone) {
    if (empty($phone)) return '';
    $clean = preg_replace('/[^0-9+]/', '', trim($phone));
    if (empty($clean)) return '';
    if (substr($clean, 0, 4) === '+233') return '0' . substr($clean, 4);
    if (substr($clean, 0, 3) === '233' && strlen($clean) === 12) return '0' . substr($clean, 3);
    if (strlen($clean) === 9 && $clean[0] !== '0') return '0' . $clean;
    return $clean;
}

function formatPhoneForApi($phone) {
    $clean = preg_replace('/[^0-9]/', '', trim($phone));
    if (empty($clean)) return '';
    if (strlen($clean) === 12 && substr($clean, 0, 3) === '233') return $clean;
    if (strlen($clean) === 10 && $clean[0] === '0') return '233' . substr($clean, 1);
    if (strlen($clean) === 9) return '233' . $clean;
    return $clean;
}

function sendSMS($phone, $message) {
    $apiPhone = formatPhoneForApi($phone);
    if (empty($apiPhone)) {
        error_log("SMS SKIPPED: Empty phone");
        return ['success' => false, 'error' => 'Empty phone number'];
    }

    $url = SMS_API_URL . '?' . http_build_query([
        'action'  => 'send-sms',
        'api_key' => SMS_API_KEY,
        'to'      => $apiPhone,
        'from'    => SMS_SENDER_ID,
        'sms'     => $message,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $apiResponse = curl_exec($ch);
    $curlError   = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("SMS CURL ERROR: " . $curlError);
        return ['success' => false, 'error' => $curlError];
    }

    $data = json_decode($apiResponse, true);
    $success = false;
    if (is_array($data)) {
        $success = (isset($data['code'])    && strtolower($data['code'])    === 'ok')
                || (isset($data['status'])  && strtolower($data['status'])  === 'ok')
                || (isset($data['status'])  && strtolower($data['status'])  === 'success')
                || (isset($data['message']) && stripos($data['message'], 'success') !== false);
    } else {
        $success = stripos($apiResponse, '"code":"ok"')        !== false
                || stripos($apiResponse, '"status":"ok"')      !== false
                || stripos($apiResponse, '"status":"success"') !== false;
    }

    return ['success' => $success, 'response' => $apiResponse];
}

// FIX: returns full student row including parents array, used by both details and SMS
function getStudentDetails($conn, $student_id) {
    $stmt = $conn->prepare(
        "SELECT u.id, u.name, u.email, u.phone, u.admission_date,
                s.student_name, s.emergency_contact_name, s.emergency_contact_phone,
                sc.class_id, c.class_name, c.department_id, d.name as department_name,
                sc.academic_year
         FROM users u
         INNER JOIN students s  ON u.id = s.user_id
         INNER JOIN student_classes sc ON u.id = sc.user_id
         INNER JOIN classes c   ON sc.class_id = c.id
         LEFT  JOIN departments d ON c.department_id = d.id
         WHERE u.id = ? AND u.role = 'student'
         LIMIT 1"
    );
    if (!$stmt) {
        error_log("getStudentDetails prepare failed: " . $conn->error);
        return null;
    }
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($student) {
        // Always populate parents so SMS helper has what it needs
        $parentStmt = $conn->prepare(
            "SELECT DISTINCT pu.phone, pu.phone_number, pu.name as parent_name
             FROM parent_student_mapping psm
             INNER JOIN users pu ON psm.parent_id = pu.id
             WHERE psm.student_id = ?
             ORDER BY pu.id ASC"
        );
        if ($parentStmt) {
            $parentStmt->bind_param("i", $student_id);
            $parentStmt->execute();
            $parents = $parentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $parentStmt->close();
            $student['parents'] = $parents;

            // Resolve best display phone (normalised, local format)
            $resolved_phone = '';
            foreach ($parents as $p) {
                $raw = trim($p['phone'] ?? '');
                if (empty($raw)) $raw = trim($p['phone_number'] ?? '');
                $norm = normalizePhoneForDisplay($raw);
                if (!empty($norm) && $norm !== '0') { $resolved_phone = $norm; break; }
            }
            // Fallback to emergency contact
            if (empty($resolved_phone) && !empty($student['emergency_contact_phone'])) {
                $resolved_phone = normalizePhoneForDisplay($student['emergency_contact_phone']);
            }
            $student['parent_phone'] = $resolved_phone;
        } else {
            $student['parents']      = [];
            $student['parent_phone'] = '';
        }
    }

    return $student;
}

function getStudentFeeSummary($conn, $student_id, $academic_year = null, $term_id = null) {
    $conditions = ["d.user_id = ?"];
    $params = [$student_id];
    $types  = "i";

    if ($academic_year && $academic_year !== '') {
        $conditions[] = "d.academic_year = ?";
        $params[]      = $academic_year;
        $types        .= "s";
    }
    if ($term_id && $term_id > 0) {
        $conditions[] = "d.term_id = ?";
        $params[]      = (int)$term_id;
        $types        .= "i";
    }

    $where = implode(" AND ", $conditions);
    // FIX: fan-out bug — multiple payment rows per fee cause SUM(amount_due) to be
    // multiplied and SUM(amount_paid) to be overstated, collapsing balance to 0.
    // Use a pre-aggregated payments subquery so each fee_id contributes exactly once.
    $sql = "SELECT
                COALESCE(SUM(d.amount_due), 0)                                          AS total_due,
                COALESCE(SUM(COALESCE(p.total_paid, 0)), 0)                             AS total_paid,
                COALESCE(SUM(d.amount_due - COALESCE(p.total_paid, 0)), 0)              AS balance
            FROM debts d
            LEFT JOIN (
                SELECT fee_id, user_id, SUM(amount_paid) AS total_paid
                FROM payments
                WHERE user_id = {$student_id}
                GROUP BY fee_id, user_id
            ) p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
            WHERE {$where}";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getStudentFeeSummary prepare failed: " . $conn->error);
        return ['total_due' => 0, 'total_paid' => 0, 'balance' => 0];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'total_due'  => floatval($result['total_due']),
        'total_paid' => floatval($result['total_paid']),
        'balance'    => max(0, floatval($result['balance'])),
    ];
}

// FIX: corrected param binding — second user_id for the subquery added at the right position
function getStudentFees($conn, $student_id, $academic_year = null, $term_id = null) {
    $conditions = ["d.user_id = ?", "(d.amount_due - COALESCE(p.total_paid, 0)) > 0"];
    $params = [$student_id];
    $types  = "i";

    if ($academic_year && $academic_year !== '') {
        $conditions[] = "d.academic_year = ?";
        $params[]      = $academic_year;
        $types        .= "s";
    }
    if ($term_id && $term_id > 0) {
        $conditions[] = "d.term_id = ?";
        $params[]      = (int)$term_id;
        $types        .= "i";
    }

    $where = implode(" AND ", $conditions);

    // Subquery param: student_id for the payments subquery — must come FIRST in bind list
    // because it appears inside the FROM clause before WHERE
    $sql = "SELECT
                d.id          AS debt_id,
                d.fee_id,
                d.amount_due,
                d.academic_year,
                d.term_id,
                d.class_id,
                f.fee_type,
                f.department,
                f.due_date,
                COALESCE(p.total_paid, 0)                          AS paid_amount,
                (d.amount_due - COALESCE(p.total_paid, 0))         AS outstanding
            FROM debts d
            INNER JOIN fees f ON d.fee_id = f.id
            LEFT JOIN (
                SELECT fee_id, SUM(amount_paid) AS total_paid
                FROM payments
                WHERE user_id = ?
                GROUP BY fee_id
            ) p ON d.fee_id = p.fee_id
            WHERE {$where}
            ORDER BY f.due_date ASC";

    // FIX: subquery user_id must be the FIRST placeholder — prepend it
    array_unshift($params, $student_id);
    $types = "i" . $types;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getStudentFees prepare failed: " . $conn->error);
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// FIX: bind_param type string corrected — payment_date is string (s), not date (d)
function recordPayment($conn, $student_id, $fee_ids, $amount, $payment_method, $recorded_by, $receipt_number) {
    $payment_date   = date('Y-m-d');
    $success_count  = 0;
    $payment_ids    = [];
    $amount_remaining = floatval($amount);

    $student = getStudentDetails($conn, $student_id);
    if (!$student) {
        return ['success' => false, 'error' => 'Student not found'];
    }

    if (empty($fee_ids)) {
        $all_fees = getStudentFees($conn, $student_id);
        $fee_ids  = array_column($all_fees, 'fee_id');
    }

    foreach ($fee_ids as $fee_id) {
        if ($amount_remaining <= 0.001) break;
        $fee_id = (int)$fee_id;

        $stmt = $conn->prepare(
            "SELECT d.id AS debt_id, d.amount_due,
                    COALESCE(SUM(p.amount_paid), 0) AS paid
             FROM debts d
             LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
             WHERE d.user_id = ? AND d.fee_id = ?
             GROUP BY d.id"
        );
        if (!$stmt) continue;
        $stmt->bind_param("ii", $student_id, $fee_id);
        $stmt->execute();
        $debt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$debt) continue;
        $outstanding = floatval($debt['amount_due']) - floatval($debt['paid']);
        if ($outstanding <= 0.001) continue;

        $payment_amount = min($amount_remaining, $outstanding);

        // FIX: correct type string — user_id(i) fee_id(i) amount(d) date(s) method(s) receipt(s) class_id(i) recorded_by(i)
        $stmt = $conn->prepare(
            "INSERT INTO payments
                (user_id, fee_id, amount_paid, payment_date, payment_method, receipt_number, class_id, recorded_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        if (!$stmt) continue;
        $stmt->bind_param("iidssiii",
            $student_id, $fee_id, $payment_amount, $payment_date,
            $payment_method, $receipt_number, $student['class_id'], $recorded_by
        );

        if ($stmt->execute()) {
            $payment_ids[]     = $conn->insert_id;
            $success_count++;
            $amount_remaining -= $payment_amount;
        } else {
            error_log("recordPayment insert failed: " . $stmt->error);
        }
        $stmt->close();
    }

    return [
        'success'       => $success_count > 0,
        'payment_count' => $success_count,
        'payment_ids'   => $payment_ids,
        'amount_paid'   => $amount - $amount_remaining,
        'receipt_number'=> $receipt_number,
    ];
}

// FIX: receipt_number and payment_method passed as string — type string was wrong before
function sendPaymentReceiptSMS($conn, $student, $amount_paid, $receipt_number, $balance, $payment_method) {
    $parent_name  = !empty($student['parents'][0]['parent_name'])
                        ? $student['parents'][0]['parent_name'] : 'Parent/Guardian';
    $student_name = $student['student_name'] ?? $student['name'] ?? 'Student';

    $msg  = "Dear {$parent_name},\n\n";
    $msg .= "PAYMENT RECEIPT\n\n";
    $msg .= "Student: {$student_name}\n";
    $msg .= "Receipt No: {$receipt_number}\n";
    $msg .= "Amount Paid: GHS" . number_format($amount_paid, 2) . "\n";
    $msg .= "Method: " . ucfirst(str_replace('_', ' ', $payment_method)) . "\n";
    $msg .= "Outstanding: GHS" . number_format($balance, 2) . "\n\n";

    if ($balance > 0) {
        $msg .= "An outstanding balance of GHS" . number_format($balance, 2) . " remains. Please settle promptly.\n\n";
    } else {
        $msg .= "Fees are fully paid. Thank you!\n\n";
    }
    $msg .= "Queenest Paradise Montessori Complex";

    if (!empty($student['parent_phone'])) {
        return sendSMS($student['parent_phone'], $msg);
    }
    return ['success' => false, 'error' => 'No parent phone number found'];
}

// FIX: returns terms filtered by year when supplied; gracefully handles no year
function getAcademicTerms($conn, $academic_year = null) {
    if ($academic_year && $academic_year !== '') {
        $stmt = $conn->prepare(
            "SELECT id, year, term_name, start_date, end_date
             FROM academic_calendar WHERE year = ? ORDER BY start_date ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param("s", $academic_year);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, year, term_name, start_date, end_date
             FROM academic_calendar ORDER BY year DESC, start_date ASC"
        );
        if (!$stmt) return [];
    }
    $stmt->execute();
    $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $terms;
}

function getAllStudents($conn, $search = '', $class_id = null) {
    $conditions = ["u.role = 'student'", "u.status = 'active'"];
    $params     = [];
    $types      = "";

    if ($search && $search !== '') {
        $like          = "%{$search}%";
        $conditions[] = "(u.name LIKE ? OR s.student_name LIKE ? OR u.email LIKE ?)";
        $params[]      = $like; $params[] = $like; $params[] = $like;
        $types        .= "sss";
    }
    if ($class_id && (int)$class_id > 0) {
        $conditions[] = "sc.class_id = ?";
        $params[]      = (int)$class_id;
        $types        .= "i";
    }

    $where = implode(" AND ", $conditions);
    $sql   = "SELECT
                  u.id, u.name, u.email, u.phone,
                  s.student_name,
                  sc.class_id, c.class_name,
                  sc.academic_year
              FROM users u
              INNER JOIN students s  ON u.id = s.user_id
              INNER JOIN student_classes sc ON u.id = sc.user_id
              INNER JOIN classes c   ON sc.class_id = c.id
              WHERE {$where}
              ORDER BY s.student_name ASC
              LIMIT 200";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getAllStudents prepare failed: " . $conn->error);
        return [];
    }
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $students;
}

// ── AJAX Handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Unknown action'];

    switch ($_POST['action']) {

        case 'get_student_details':
            $student_id = (int)($_POST['student_id'] ?? 0);
            if ($student_id <= 0) {
                $response['message'] = 'Invalid student ID';
                break;
            }
            $student = getStudentDetails($conn, $student_id);
            if (!$student) {
                $response['message'] = 'Student not found';
                break;
            }
            $academic_year = $_POST['academic_year'] ?? '';
            $term_id       = (int)($_POST['term_id'] ?? 0);

            $fee_summary = getStudentFeeSummary($conn, $student_id, $academic_year ?: null, $term_id ?: null);
            $fees        = getStudentFees($conn, $student_id, $academic_year ?: null, $term_id ?: null);

            $response = [
                'success'     => true,
                'student'     => $student,
                'fee_summary' => $fee_summary,
                'fees'        => $fees,
            ];
            break;

        case 'get_students':
            $search   = trim($_POST['search']   ?? '');
            $class_id = (int)($_POST['class_id'] ?? 0);
            $students = getAllStudents($conn, $search, $class_id ?: null);
            $response = ['success' => true, 'students' => $students];
            break;

        // FIX: filter terms by year when year is supplied
        case 'get_terms':
            $academic_year = trim($_POST['academic_year'] ?? '');
            $terms         = getAcademicTerms($conn, $academic_year ?: null);
            $response      = ['success' => true, 'terms' => $terms];
            break;
    }

    echo json_encode($response);
    exit;
}

// ── Form Submission ───────────────────────────────────────────────────────────
$success_msg  = '';
$error_msg    = '';
$payment_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid security token. Please refresh and try again.";
    } else {
        $student_id     = (int)($_POST['student_id']     ?? 0);
        $fee_ids_input  = trim($_POST['fee_ids']          ?? '');
        $fee_ids        = $fee_ids_input !== '' ? array_filter(explode(',', $fee_ids_input), 'is_numeric') : [];
        // FIX: amount comes from POST field 'amount' — must match the form input name
        $amount         = floatval($_POST['amount']        ?? 0);
        $payment_method = $_POST['payment_method']         ?? 'cash';
        $academic_year  = trim($_POST['academic_year']     ?? '');
        $term_id        = (int)($_POST['term_id']          ?? 0);

        $errors = [];
        if ($student_id <= 0) $errors[] = "Please select a student.";
        if ($amount     <= 0) $errors[] = "Please enter a valid payment amount.";
        if (!in_array($payment_method, ['cash', 'mobile_money', 'bank_transfer', 'cheque'])) {
            $errors[] = "Invalid payment method.";
        }

        if (!empty($errors)) {
            $error_msg = implode("<br>", $errors);
        } else {
            $student = getStudentDetails($conn, $student_id);
            if (!$student) {
                $error_msg = "Student not found.";
            } else {
                $fee_summary = getStudentFeeSummary($conn, $student_id,
                                $academic_year ?: null, $term_id ?: null);

                if ($amount > ($fee_summary['balance'] + 0.001)) {
                    $error_msg = "Payment amount (GHS " . number_format($amount, 2) .
                                 ") exceeds outstanding balance (GHS " .
                                 number_format($fee_summary['balance'], 2) . ").";
                } else {
                    $receipt_number = generateReceiptNumber();
                    $result = recordPayment(
                        $conn, $student_id, $fee_ids, $amount,
                        $payment_method, $user_id, $receipt_number
                    );

                    if ($result['success']) {
                        $new_balance = max(0, $fee_summary['balance'] - $result['amount_paid']);
                        $sms_result  = sendPaymentReceiptSMS(
                            $conn, $student, $result['amount_paid'],
                            $receipt_number, $new_balance, $payment_method
                        );

                        $payment_data = [
                            'student_name'     => $student['student_name'] ?? $student['name'],
                            'amount_paid'      => $result['amount_paid'],
                            'receipt_number'   => $receipt_number,
                            'previous_balance' => $fee_summary['balance'],
                            'new_balance'      => $new_balance,
                            'payment_method'   => $payment_method,
                            'sms_sent'         => $sms_result['success'],
                        ];

                        $success_msg = "Payment of GHS " . number_format($result['amount_paid'], 2) .
                                       " recorded. Receipt #: {$receipt_number}.";
                        $success_msg .= $sms_result['success']
                            ? " SMS receipt sent to parent."
                            : " (SMS could not be sent — no parent phone on file.)";

                        // Regenerate CSRF token after successful action
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    } else {
                        $error_msg = "Failed to record payment. Please try again.";
                    }
                }
            }
        }
    }
}

// ── Dropdown data ─────────────────────────────────────────────────────────────
$academic_years = [];
$yearsResult = $conn->query("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
if ($yearsResult) {
    while ($yr = $yearsResult->fetch_assoc()) $academic_years[] = $yr['year'];
    $yearsResult->free();
}

// Auto-detect current term
$today = date('Y-m-d');
$currentTermStmt = $conn->prepare(
    "SELECT id, year, term_name FROM academic_calendar
     WHERE ? BETWEEN start_date AND end_date ORDER BY start_date ASC LIMIT 1"
);
$currentTermStmt->bind_param("s", $today);
$currentTermStmt->execute();
$currentTerm = $currentTermStmt->get_result()->fetch_assoc();
$currentTermStmt->close();

if (!$currentTerm) {
    $fb = $conn->prepare("SELECT id, year, term_name FROM academic_calendar ORDER BY end_date DESC LIMIT 1");
    $fb->execute();
    $currentTerm = $fb->get_result()->fetch_assoc();
    $fb->close();
}

$autoYear   = $currentTerm['year']      ?? ($academic_years[0] ?? '');
$autoTermId = $currentTerm['id']        ?? 0;
$autoTermName = $currentTerm['term_name'] ?? '';

// Pre-load terms for the auto year
$initialTerms = getAcademicTerms($conn, $autoYear ?: null);

$classes = [];
$classesResult = $conn->query("SELECT id, class_name, department_id FROM classes ORDER BY class_name ASC");
if ($classesResult) {
    while ($c = $classesResult->fetch_assoc()) $classes[] = $c;
    $classesResult->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Fees — QPMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root {
            --primary:#FF6200; --primary-dark:#E55A00; --primary-light:#FFF0E6;
            --success:#10B981; --danger:#EF4444; --warning:#F59E0B; --info:#3B82F6;
            --bg:#F8FAFC; --card:#FFFFFF; --border:#E2E8F0;
            --text:#1E293B; --text-muted:#64748B;
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;min-height:100vh;color:var(--text)}
        .main-wrapper{padding:1rem;max-width:1400px;margin:0 auto}
        .page-card{background:var(--card);border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;margin-bottom:1rem;border:1px solid var(--border)}
        .page-header{background:linear-gradient(135deg,#FF6200,#FF8C00);padding:1.25rem 2rem;color:white;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
        .page-header h4{margin:0;font-weight:700;font-size:1.25rem;letter-spacing:-.5px}
        .badge-sms{background:rgba(255,255,255,.2);backdrop-filter:blur(10px);padding:.4rem 1rem;border-radius:20px;font-size:.75rem;font-weight:600;border:1px solid rgba(255,255,255,.3)}
        .page-body{padding:1.5rem}
        .section-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:1rem;transition:box-shadow .2s}
        .section-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.04)}
        .section-title{font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;letter-spacing:-.3px}
        .section-title i{color:var(--primary);width:20px;text-align:center;font-size:.9rem}
        .fee-item{display:flex;justify-content:space-between;align-items:center;padding:.75rem;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;border-left:3px solid transparent}
        .fee-item:last-child{border-bottom:none}
        .fee-item:hover{background:var(--primary-light)}
        .fee-item.selected{background:#D1FAE5;border-left-color:var(--success)}
        .fee-type{font-weight:600;font-size:.85rem}
        .fee-amount{font-weight:700;color:var(--primary)}
        .summary-card{border-radius:12px;padding:1rem;text-align:center;color:white}
        .summary-amount{font-size:1.4rem;font-weight:700}
        .summary-label{font-size:.65rem;opacity:.85;text-transform:uppercase;letter-spacing:1px;margin-bottom:.2rem}
        .receipt-card{background:linear-gradient(135deg,#ECFDF5,#D1FAE5);border:1px solid #A7F3D0;border-radius:12px;padding:1.25rem}
        .btn-primary{background:var(--primary);border-color:var(--primary);font-weight:600;font-size:.85rem;padding:.6rem 1.5rem;border-radius:8px}
        .btn-primary:hover{background:var(--primary-dark);border-color:var(--primary-dark);transform:translateY(-1px);box-shadow:0 4px 12px rgba(255,98,0,.25)}
        .btn-primary:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
        .loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.95);backdrop-filter:blur(4px);display:none;justify-content:center;align-items:center;z-index:9999}
        .loading-overlay.show{display:flex}
        .loading-box{text-align:center;background:white;padding:2rem 3rem;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.1)}
        .select2-container--default .select2-selection--single{border:1.5px solid var(--border)!important;border-radius:8px!important;height:38px!important}
        .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px!important;font-size:.85rem}
        .student-result-item{cursor:pointer;transition:background .15s}
        .student-result-item:hover{background:var(--primary-light)}
        .amount-input-wrapper{position:relative}
        .amount-input-wrapper .currency-prefix{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);font-weight:600;color:var(--text-muted);pointer-events:none;font-size:.85rem}
        .amount-input-wrapper input{padding-left:2.5rem}
        .current-term-badge{background:#E8F5E9;color:#2E7D32;border:1px solid #A5D6A7;border-radius:6px;padding:.3rem .75rem;font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem}
        @media(max-width:768px){.page-header{flex-direction:column;text-align:center}}
    </style>
</head>
<body>
<?php
$headerPath = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
if (file_exists($headerPath)) include $headerPath;
else echo '<nav class="navbar navbar-dark bg-dark"><div class="container"><span class="navbar-brand">QPMC</span></div></nav>';
?>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-box">
        <div class="spinner-border text-primary mb-3" role="status" style="width:2.5rem;height:2.5rem;"></div>
        <p class="fw-bold text-muted mb-0" id="loadingText">Processing...</p>
    </div>
</div>

<div class="main-wrapper">
    <?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:10px;border:none;font-size:.85rem;">
        <i class="fas fa-check-circle me-2"></i><strong>Success!</strong> <?= htmlspecialchars($success_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:10px;border:none;font-size:.85rem;">
        <i class="fas fa-exclamation-triangle me-2"></i><strong>Error!</strong> <?= $error_msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($payment_data): ?>
    <div class="receipt-card mb-3" id="receiptCard">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 fw-bold"><i class="fas fa-receipt me-2 text-success"></i>Payment Receipt</h6>
            <button class="btn btn-sm btn-outline-primary" onclick="printReceipt()">
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
        <div id="receiptContent">
            <div class="text-center mb-3">
                <h5 class="mb-0">Queenest Paradise Montessori Complex</h5>
                <small class="text-muted">Official Payment Receipt</small>
            </div>
            <table class="table table-sm table-borderless mb-0">
                <tr><td width="45%"><strong>Receipt No:</strong></td><td><?= htmlspecialchars($payment_data['receipt_number']) ?></td></tr>
                <tr><td><strong>Student:</strong></td><td><?= htmlspecialchars($payment_data['student_name']) ?></td></tr>
                <tr><td><strong>Date:</strong></td><td><?= date('d M Y') ?></td></tr>
                <tr><td><strong>Method:</strong></td><td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $payment_data['payment_method']))) ?></td></tr>
                <tr><td><strong>Previous Balance:</strong></td><td>GHS <?= number_format($payment_data['previous_balance'], 2) ?></td></tr>
                <tr><td><strong>Amount Paid:</strong></td><td><strong class="text-success">GHS <?= number_format($payment_data['amount_paid'], 2) ?></strong></td></tr>
                <tr><td><strong>Outstanding Balance:</strong></td><td><strong class="<?= $payment_data['new_balance'] > 0 ? 'text-danger' : 'text-success' ?>">GHS <?= number_format($payment_data['new_balance'], 2) ?></strong></td></tr>
            </table>
            <div class="text-center mt-3">
                <small class="text-muted">
                    <?php if ($payment_data['sms_sent']): ?>
                        <i class="fas fa-sms text-success me-1"></i>SMS receipt sent to parent
                    <?php else: ?>
                        <i class="fas fa-sms text-muted me-1"></i>SMS not sent (no parent phone)
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="paymentForm" onsubmit="return validateForm()">
        <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="process_payment" value="1">
        <input type="hidden" name="student_id"    id="studentIdInput"   value="">
        <input type="hidden" name="fee_ids"        id="selectedFeeIds"   value="">
        <input type="hidden" name="academic_year"  id="filterYearHidden" value="<?= htmlspecialchars($autoYear) ?>">
        <input type="hidden" name="term_id"        id="filterTermHidden" value="<?= (int)$autoTermId ?>">

        <div class="page-card">
            <div class="page-header">
                <div>
                    <h4><i class="fas fa-money-bill-wave me-2"></i>Process Fee Payment</h4>
                    <small style="opacity:.9;">Record payments and auto-generate SMS receipts</small>
                </div>
                <span class="badge-sms"><i class="fas fa-sms me-1"></i>SMS Receipt Auto-Sent</span>
            </div>

            <div class="page-body">
                <?php if ($autoYear): ?>
                <div class="mb-3">
                    <span class="current-term-badge">
                        <i class="fas fa-circle-check"></i>
                        Current: <?= htmlspecialchars($autoYear) ?><?= $autoTermName ? ' — ' . htmlspecialchars($autoTermName) : '' ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="row g-3">
                    <!-- LEFT: Student + Filters -->
                    <div class="col-lg-5">
                        <div class="section-card">
                            <h5 class="section-title"><i class="fas fa-filter"></i>Filter Options</h5>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold" style="font-size:.78rem;">Academic Year</label>
                                    <select class="form-select form-select-sm" id="filterYear" onchange="onYearChange(this.value)">
                                        <option value="">All Years</option>
                                        <?php foreach ($academic_years as $year): ?>
                                        <option value="<?= htmlspecialchars($year) ?>" <?= ($year === $autoYear) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($year) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold" style="font-size:.78rem;">Term</label>
                                    <select class="form-select form-select-sm" id="filterTerm" onchange="onTermChange()">
                                        <option value="">All Terms</option>
                                        <?php foreach ($initialTerms as $term): ?>
                                        <option value="<?= (int)$term['id'] ?>" <?= ($term['id'] == $autoTermId) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($term['term_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold" style="font-size:.78rem;">Class (optional)</label>
                                    <select class="form-select form-select-sm" id="filterClass" onchange="searchStudents()">
                                        <option value="">All Classes</option>
                                        <?php foreach ($classes as $class): ?>
                                        <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="section-card">
                            <h5 class="section-title"><i class="fas fa-user-graduate"></i>Student Search</h5>
                            <div class="mb-3">
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" id="studentSearch"
                                           placeholder="Type student name and press Enter or click search…">
                                    <button class="btn btn-outline-primary" type="button" id="searchBtn" onclick="searchStudents()">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div id="studentResults" class="mt-2" style="max-height:280px;overflow-y:auto;border-radius:8px;"></div>
                            </div>

                            <div id="selectedStudentInfo" style="display:none;">
                                <hr class="my-2">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width:48px;height:48px;background:var(--primary-light);flex-shrink:0;">
                                        <i class="fas fa-user-graduate text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold" id="selectedStudentName" style="font-size:.95rem;"></div>
                                        <small class="text-muted" id="selectedStudentClass"></small>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="clearStudent()" title="Change student">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="summary-card" style="background:linear-gradient(135deg,#667eea,#764ba2);">
                                            <div class="summary-label">Total Due</div>
                                            <div class="summary-amount" id="totalDue">GHS 0.00</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="summary-card" style="background:linear-gradient(135deg,#F59E0B,#EF4444);">
                                            <div class="summary-label">Outstanding</div>
                                            <div class="summary-amount" id="outstandingBalance">GHS 0.00</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: Fees + Payment -->
                    <div class="col-lg-7">
                        <div class="section-card">
                            <h5 class="section-title"><i class="fas fa-list-ul"></i>Outstanding Fees</h5>
                            <div id="feesList" style="max-height:320px;overflow-y:auto;border-radius:8px;border:1px solid var(--border);">
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-search fa-2x mb-2 d-block" style="opacity:.3;"></i>
                                    <p style="font-size:.85rem;">Select a student to view their outstanding fees</p>
                                </div>
                            </div>
                            <div id="feesActions" style="display:none;" class="mt-2 d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="selectAllFees()">
                                    <i class="fas fa-check-double me-1"></i>Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllFees()">
                                    <i class="fas fa-times me-1"></i>Clear Selection
                                </button>
                            </div>

                            <div id="paymentSection" style="display:none;" class="mt-3 pt-3 border-top">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" style="font-size:.85rem;">Payment Method</label>
                                        <select class="form-select" id="paymentMethodSelect" name="payment_method" required>
                                            <option value="cash">Cash</option>
                                            <option value="mobile_money">Mobile Money</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" style="font-size:.85rem;">Amount to Pay (GHS)</label>
                                        <div class="amount-input-wrapper">
                                            <span class="currency-prefix">GHS</span>
                                            <!-- FIX: name="amount" so POST captures it correctly -->
                                            <input type="number" class="form-control" id="paymentAmountInput"
                                                   name="amount" step="0.01" min="0.01" placeholder="0.00"
                                                   oninput="validatePaymentAmount()">
                                        </div>
                                        <div id="amountFeedback" class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="alert alert-info mt-3 mb-0 py-2" style="font-size:.8rem;">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Click fee rows to target specific items, or leave all unselected to pay towards total balance.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="/admin/fees_payments.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-arrow-left me-1"></i>Back to Fees
                            </a>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary" onclick="resetPage()">
                                    <i class="fas fa-redo me-1"></i>Reset
                                </button>
                                <button type="submit" class="btn btn-primary px-4" id="submitBtn" disabled>
                                    <i class="fas fa-paper-plane me-1"></i>Process Payment
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
'use strict';

// ── State ─────────────────────────────────────────────────────────────────────
let currentStudentId  = 0;
let currentFees       = [];
let selectedFees      = new Set();   // set of fee_id (numbers)
let currentMaxAmount  = 0;

// ── Utilities ─────────────────────────────────────────────────────────────────
function showLoading(msg) {
    $('#loadingText').text(msg || 'Processing…');
    $('#loadingOverlay').addClass('show');
}
function hideLoading() {
    $('#loadingOverlay').removeClass('show');
}
function escHtml(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}
// FIX: robust numeric parse — strips any currency text then parses
function parseCurrencyText(text) {
    if (!text) return 0;
    // Remove everything except digits, dots, commas
    const clean = String(text).replace(/[^\d.,]/g, '').replace(/,/g, '');
    return parseFloat(clean) || 0;
}

// ── Initialisation ────────────────────────────────────────────────────────────
$(function() {
    $('#filterYear, #filterTerm, #filterClass, #paymentMethodSelect').select2({ width: '100%' });

    // Allow Enter key to search
    $('#studentSearch').on('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); searchStudents(); }
    });
});

// ── Year/Term filters ─────────────────────────────────────────────────────────
// FIX: when year changes, reload terms for that year then refresh student fees
function onYearChange(year) {
    $('#filterYearHidden').val(year);
    // Reload terms for this year
    showLoading('Loading terms…');
    $.post(window.location.href, { action: 'get_terms', academic_year: year }, function(r) {
        let html = '<option value="">All Terms</option>';
        if (r.success && r.terms) {
            r.terms.forEach(function(t) {
                html += '<option value="' + t.id + '">' + escHtml(t.term_name) + ' (' + escHtml(t.year) + ')</option>';
            });
        }
        $('#filterTerm').html(html).trigger('change.select2');
        $('#filterTermHidden').val('');
        refreshStudentFees();
    }, 'json').fail(hideLoading).always(hideLoading);
}

function onTermChange() {
    const termId = $('#filterTerm').val();
    $('#filterTermHidden').val(termId || '');
    refreshStudentFees();
}

// ── Student search ────────────────────────────────────────────────────────────
function searchStudents() {
    const search  = $.trim($('#studentSearch').val());
    const classId = $('#filterClass').val();

    if (!search && !classId) {
        $('#studentResults').html(
            '<div class="text-center text-muted py-3" style="font-size:.82rem;">' +
            '<i class="fas fa-search me-1"></i>Enter a name or select a class to search</div>'
        );
        return;
    }

    showLoading('Searching…');
    $.post(window.location.href, {
        action:   'get_students',
        search:   search,
        class_id: classId || 0,
    }, function(r) {
        if (r.success && r.students && r.students.length > 0) {
            let html = '<div class="list-group list-group-flush" style="border-radius:8px;overflow:hidden;">';
            r.students.forEach(function(s) {
                const name  = escHtml(s.student_name || s.name);
                const klass = escHtml(s.class_name   || '');
                html += '<button type="button" class="list-group-item list-group-item-action student-result-item" ' +
                        'onclick="selectStudent(' + s.id + ', \'' + name + '\', \'' + klass + '\')">' +
                        '<div class="d-flex justify-content-between align-items-center">' +
                        '<div><strong>' + name + '</strong><br>' +
                        '<small class="text-muted">' + klass + '</small></div>' +
                        '<i class="fas fa-chevron-right text-muted"></i></div></button>';
            });
            html += '</div>';
            $('#studentResults').html(html);
        } else {
            $('#studentResults').html('<div class="alert alert-warning py-2 mb-0" style="font-size:.82rem;">No students found.</div>');
        }
    }, 'json').fail(function() {
        $('#studentResults').html('<div class="alert alert-danger py-2 mb-0" style="font-size:.82rem;">Search failed. Please try again.</div>');
    }).always(hideLoading);
}

function selectStudent(id, name, className) {
    currentStudentId = id;
    $('#studentIdInput').val(id);
    $('#selectedStudentName').text(name);
    $('#selectedStudentClass').text(className);
    $('#selectedStudentInfo').show();
    $('#studentResults').empty();
    $('#studentSearch').val(name);

    selectedFees.clear();
    refreshStudentFees();
}

function clearStudent() {
    currentStudentId = 0;
    currentFees      = [];
    selectedFees.clear();
    currentMaxAmount = 0;

    $('#studentIdInput').val('');
    $('#studentSearch').val('');
    $('#selectedStudentInfo').hide();
    $('#studentResults').empty();
    resetFeesPanel();
}

// ── Fees panel ────────────────────────────────────────────────────────────────
function refreshStudentFees() {
    if (!currentStudentId) return;

    const year   = $('#filterYear').val()  || '';
    const termId = $('#filterTerm').val()  || '';

    // Keep hidden fields in sync with form POST
    $('#filterYearHidden').val(year);
    $('#filterTermHidden').val(termId);

    showLoading('Loading fees…');
    $.post(window.location.href, {
        action:        'get_student_details',
        student_id:    currentStudentId,
        academic_year: year,
        term_id:       termId || 0,
    }, function(r) {
        if (r.success) {
            currentFees = r.fees || [];

            // FIX: derive balance from the fees list directly as the ground truth.
            // The SQL summary can return 0 if the DB has fan-out issues; the fees
            // list outstanding values are computed per-row with a subquery and are reliable.
            let derivedBalance = 0;
            currentFees.forEach(function(f) {
                derivedBalance += Math.max(0, parseFloat(f.outstanding) || 0);
            });
            // Use the larger of the two — never let a bad summary zero-out a real balance
            const summaryBalance = Math.max(0, parseFloat(r.fee_summary.balance) || 0);
            currentMaxAmount = Math.max(derivedBalance, summaryBalance);

            // Update summary cards
            const totalDue = Math.max(
                parseFloat(r.fee_summary.total_due) || 0,
                currentFees.reduce(function(s, f){ return s + (parseFloat(f.amount_due) || 0); }, 0)
            );
            $('#totalDue').text('GHS ' + totalDue.toFixed(2));
            $('#outstandingBalance').text('GHS ' + currentMaxAmount.toFixed(2));

            renderFees(currentFees);

            if (currentFees.length > 0 && currentMaxAmount > 0) {
                $('#paymentSection').show();
                $('#feesActions').show();
                $('#paymentAmountInput')
                    .attr('max', currentMaxAmount)
                    .attr('placeholder', 'Max: GHS ' + currentMaxAmount.toFixed(2))
                    .val('');
                $('#submitBtn').prop('disabled', true);
            } else if (currentFees.length > 0 && currentMaxAmount <= 0) {
                // Fees exist but balance computed as 0 — show a warning, don't block
                $('#paymentSection').hide();
                $('#feesActions').show();
                $('#feesList').prepend(
                    '<div class="alert alert-warning py-2 mb-2" style="font-size:.8rem;">' +
                    '<i class="fas fa-exclamation-triangle me-1"></i>' +
                    'Outstanding balance calculated as GHS 0.00. Fees may already be settled or there is a data issue.</div>'
                );
            } else {
                $('#paymentSection').hide();
                $('#feesActions').hide();
            }
        } else {
            resetFeesPanel();
            $('#feesList').html('<div class="alert alert-warning mb-0">' + escHtml(r.message || 'Could not load fees.') + '</div>');
        }
    }, 'json').fail(function() {
        resetFeesPanel();
        $('#feesList').html('<div class="alert alert-danger mb-0">Error loading fees. Please try again.</div>');
    }).always(hideLoading);
}

function resetFeesPanel() {
    currentFees      = [];
    currentMaxAmount = 0;
    selectedFees.clear();
    $('#selectedFeeIds').val('');
    $('#feesList').html(
        '<div class="text-center text-muted py-4">' +
        '<i class="fas fa-search fa-2x mb-2 d-block" style="opacity:.3;"></i>' +
        '<p style="font-size:.85rem;">Select a student to view their outstanding fees</p></div>'
    );
    $('#paymentSection').hide();
    $('#feesActions').hide();
    $('#submitBtn').prop('disabled', true);
    $('#totalDue').text('GHS 0.00');
    $('#outstandingBalance').text('GHS 0.00');
}

function renderFees(fees) {
    if (!fees || fees.length === 0) {
        $('#feesList').html('<div class="alert alert-success mb-0"><i class="fas fa-check-circle me-2"></i>No outstanding fees for this student.</div>');
        return;
    }

    let html = '';
    fees.forEach(function(fee) {
        const isSelected = selectedFees.has(Number(fee.fee_id));
        const outAmt     = parseFloat(fee.outstanding) || 0;
        html += '<div class="fee-item ' + (isSelected ? 'selected' : '') + '" ' +
                'data-fee-id="' + fee.fee_id + '" onclick="toggleFee(' + fee.fee_id + ')">' +
                '<div><div class="fee-type">' + escHtml(fee.fee_type) + '</div>' +
                '<small class="text-muted">Due: ' + escHtml(fee.due_date || 'N/A') + '</small></div>' +
                '<div class="text-end">' +
                '<div class="fee-amount">GHS ' + outAmt.toFixed(2) + '</div>' +
                '<small class="text-muted">of GHS ' + (parseFloat(fee.amount_due) || 0).toFixed(2) + '</small>' +
                '</div></div>';
    });
    $('#feesList').html(html);
    syncSelectedFeesInput();
}

function toggleFee(feeId) {
    feeId = Number(feeId);
    if (selectedFees.has(feeId)) selectedFees.delete(feeId);
    else selectedFees.add(feeId);
    // FIX: sync hidden input immediately, then re-render
    syncSelectedFeesInput();
    renderFees(currentFees);
    validatePaymentAmount();
}

function selectAllFees() {
    currentFees.forEach(function(f) {
        if ((parseFloat(f.outstanding) || 0) > 0) selectedFees.add(Number(f.fee_id));
    });
    syncSelectedFeesInput();
    renderFees(currentFees);
    validatePaymentAmount();
}

function deselectAllFees() {
    selectedFees.clear();
    syncSelectedFeesInput();
    renderFees(currentFees);
    validatePaymentAmount();
}

// FIX: always update hidden input when selectedFees changes
function syncSelectedFeesInput() {
    $('#selectedFeeIds').val(Array.from(selectedFees).join(','));
}

// ── Payment validation ────────────────────────────────────────────────────────
// FIX: parse outstanding from a data attribute (number) not from displayed text
function validatePaymentAmount() {
    const amount = parseFloat($('#paymentAmountInput').val()) || 0;
    const max    = currentMaxAmount;
    const $inp   = $('#paymentAmountInput');
    const $fb    = $('#amountFeedback');

    if (amount <= 0) {
        $inp.removeClass('is-valid is-invalid');
        $fb.text('');
        $('#submitBtn').prop('disabled', true);
        return false;
    }

    if (amount > max + 0.001) {
        $inp.addClass('is-invalid').removeClass('is-valid');
        $fb.text('Exceeds outstanding balance of GHS ' + max.toFixed(2));
        $('#submitBtn').prop('disabled', true);
        return false;
    }

    $inp.addClass('is-valid').removeClass('is-invalid');
    $fb.text('');
    $('#submitBtn').prop('disabled', false);
    return true;
}

function validateForm() {
    if (!$('#studentIdInput').val()) {
        alert('Please select a student.');
        return false;
    }
    if (!validatePaymentAmount()) {
        alert('Please enter a valid payment amount not exceeding the outstanding balance.');
        return false;
    }

    const amount  = parseFloat($('#paymentAmountInput').val()).toFixed(2);
    const name    = $('#selectedStudentName').text();
    const method  = $('#paymentMethodSelect option:selected').text();
    const selCount = selectedFees.size;
    let msg = 'Confirm payment:\n\nStudent: ' + name + '\nAmount: GHS ' + amount +
              '\nMethod: ' + method + '\n';
    msg += selCount > 0
        ? 'Applied to: ' + selCount + ' selected fee(s)\n'
        : 'Applied to: total outstanding balance\n';
    msg += '\nProceed?';

    if (!confirm(msg)) return false;
    showLoading('Recording payment…');
    return true;
}

// ── Misc ──────────────────────────────────────────────────────────────────────
function resetPage() {
    if (confirm('Reset the form? All unsaved data will be lost.')) {
        location.reload();
    }
}

function printReceipt() {
    const content = document.getElementById('receiptContent').innerHTML;
    const win = window.open('', '_blank');
    win.document.write(
        '<!DOCTYPE html><html><head><title>Payment Receipt</title>' +
        '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' +
        '<style>body{padding:20px}@media print{.no-print{display:none}}</style></head>' +
        '<body><div class="container">' + content +
        '<div class="text-center mt-4 no-print">' +
        '<button class="btn btn-primary me-2" onclick="window.print()">Print</button>' +
        '<button class="btn btn-secondary" onclick="window.close()">Close</button>' +
        '</div></div></body></html>'
    );
    win.document.close();
}
</script>
</body>
</html>