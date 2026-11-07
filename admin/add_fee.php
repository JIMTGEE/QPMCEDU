<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/helpers.php');

if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'));
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// SMS API Configuration
define('SMS_API_KEY',   'RFZLcHFxc05CTHlOWXhXY0xlbGo');
define('SMS_API_URL',   'https://sms.arkesel.com/sms/api');
define('SMS_SENDER_ID', 'QUEENSPA');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/logs/fee_errors.log');

$logDir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

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

$standard_fee_types = [
    'Tuition'            => 'Tuition Fee',
    'Feeding'            => 'Feeding Fee',
    'Transportation'     => 'Transportation Fee',
    'Books & Stationery' => 'Books & Stationery',
    'Uniform'            => 'School Uniform',
    'PTA Dues'           => 'PTA Dues',
    'Sports'             => 'Sports Fee',
    'Excursion'          => 'Excursion Fee',
    'Medical'            => 'Medical Fee',
    'Development Levy'   => 'Development Levy',
    'Computer Lab'       => 'Computer Lab Fee',
    'Examination Fee'    => 'Examination Fee',
    'Graduation Fee'     => 'Graduation Fee',
    'Club Fee'           => 'Club Activities Fee',
    'Other'              => 'Other Fee',
];

// ── Auto-detect current term ───────────────────────────────────────────────
$today = date('Y-m-d');

$currentTermStmt = $conn->prepare(
    "SELECT id, year, term_name, start_date, end_date
     FROM academic_calendar
     WHERE ? BETWEEN start_date AND end_date
     ORDER BY start_date ASC LIMIT 1"
);
$currentTermStmt->bind_param("s", $today);
$currentTermStmt->execute();
$currentTerm = $currentTermStmt->get_result()->fetch_assoc();
$currentTermStmt->close();

if (!$currentTerm) {
    $fallbackStmt = $conn->prepare(
        "SELECT id, year, term_name, start_date, end_date
         FROM academic_calendar ORDER BY end_date DESC LIMIT 1"
    );
    $fallbackStmt->execute();
    $currentTerm = $fallbackStmt->get_result()->fetch_assoc();
    $fallbackStmt->close();
}

$autoYearLabel = $currentTerm['year']      ?? '2025/2026';
$autoTermId    = $currentTerm['id']        ?? 0;
$autoTermName  = $currentTerm['term_name'] ?? 'Term 3';

$allYearsResult = $conn->query("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
$allYears = [];
while ($yr = $allYearsResult->fetch_assoc()) $allYears[] = $yr['year'];
$allYearsResult->free();

$allTermsForCurrentYear = [];
if ($autoYearLabel) {
    $termsStmt = $conn->prepare(
        "SELECT id, year, term_name, start_date, end_date
         FROM academic_calendar WHERE year = ? ORDER BY start_date ASC"
    );
    $termsStmt->bind_param("s", $autoYearLabel);
    $termsStmt->execute();
    $tr = $termsStmt->get_result();
    while ($row = $tr->fetch_assoc()) $allTermsForCurrentYear[] = $row;
    $termsStmt->close();
}

// ── Helpers ───────────────────────────────────────────────────────────────

function getAcademicYearId($conn, $year_label) {
    if (empty($year_label)) return 0;
    $stmt = $conn->prepare("SELECT id FROM academic_years WHERE year_label = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param("s", $year_label);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : 0;
}

function getDepartmentNameById($conn, $department_id) {
    if (empty($department_id)) return '';
    $result = $conn->query("SELECT name FROM departments WHERE id = " . intval($department_id) . " LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) return trim($row['name']);
    return '';
}

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

function getStudentPreviousBalance($conn, $student_id, $current_term_id, $current_year) {
    // Get total unpaid debts from previous terms
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount_due), 0) as total_arrears
         FROM debts d
         WHERE d.user_id = ?
         AND d.academic_year = ?
         AND d.term_id < ?
         AND NOT EXISTS (
             SELECT 1 FROM payments p 
             WHERE p.user_id = d.user_id 
             AND p.fee_id = d.fee_id
         )"
    );
    $stmt->bind_param("isi", $student_id, $current_year, $current_term_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ? floatval($result['total_arrears']) : 0;
}

function getStudentsWithParents($conn, $year_label, $department_id = 0, $class_id = 0, $search = '', $current_term_id = 0) {
    $conditions = ["u.role = 'student'", "u.status = 'active'"];
    $params = [];
    $types  = "";

    if ($year_label) {
        $conditions[] = "sc.academic_year = ?";
        $params[] = $year_label;
        $types   .= "s";
    }
    if ($class_id > 0) {
        $conditions[] = "sc.class_id = ?";
        $params[] = $class_id;
        $types   .= "i";
    } elseif ($department_id > 0) {
        $conditions[] = "c.department_id = ?";
        $params[] = $department_id;
        $types   .= "i";
    }
    if ($search !== '') {
        $like = "%{$search}%";
        $conditions[] = "(u.name LIKE ? OR s.student_name LIKE ? OR c.class_name LIKE ? OR pu.name LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "ssss";
    }

    $where = implode(" AND ", $conditions);

    $sql = "SELECT
                u.id                    AS user_id,
                u.name                  AS user_name,
                s.student_name,
                s.emergency_contact_name AS student_emergency_name,
                s.emergency_contact_phone AS student_emergency_phone,
                u.admission_date,
                sc.class_id,
                c.class_name,
                c.department_id,
                d.name                  AS department_name,
                sc.academic_year,
                COUNT(DISTINCT pu.id)   AS parent_count,
                MAX(CASE WHEN psm.id IS NOT NULL THEN 1 ELSE 0 END) AS has_mapping,
                GROUP_CONCAT(DISTINCT pu.name ORDER BY pu.id SEPARATOR '||') AS all_parent_names,
                GROUP_CONCAT(DISTINCT pu.phone ORDER BY pu.id SEPARATOR '||') AS all_parent_phones,
                GROUP_CONCAT(DISTINCT pu.phone_number ORDER BY pu.id SEPARATOR '||') AS all_parent_phones2,
                GROUP_CONCAT(DISTINCT pu.email ORDER BY pu.id SEPARATOR '||') AS all_parent_emails
            FROM users u
            INNER JOIN students s          ON u.id = s.user_id
            INNER JOIN student_classes sc  ON u.id = sc.user_id
            INNER JOIN classes c           ON sc.class_id = c.id
            LEFT  JOIN departments d       ON c.department_id = d.id
            LEFT  JOIN parent_student_mapping psm ON u.id = psm.student_id
            LEFT  JOIN users pu            ON psm.parent_id = pu.id
            WHERE {$where}
            GROUP BY u.id, s.student_name,
                     s.emergency_contact_name, s.emergency_contact_phone,
                     u.admission_date,
                     sc.class_id, c.class_name, c.department_id, d.name, sc.academic_year
            ORDER BY u.admission_date ASC, c.class_name ASC, u.name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getStudentsWithParents prepare failed: " . $conn->error);
        return [];
    }
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Add previous balance to each student
    foreach ($students as &$student) {
        $student['previous_balance'] = getStudentPreviousBalance($conn, $student['user_id'], $current_term_id, $year_label);
    }
    
    return $students;
}

function extractParentPhone($row) {
    if (!empty($row['all_parent_phones'])) {
        $phones = explode('||', $row['all_parent_phones']);
        foreach ($phones as $ph) {
            $ph = normalizePhoneForDisplay(trim($ph));
            if (!empty($ph) && $ph !== '0') return $ph;
        }
    }
    if (!empty($row['all_parent_phones2'])) {
        $phones = explode('||', $row['all_parent_phones2']);
        foreach ($phones as $ph) {
            $ph = normalizePhoneForDisplay(trim($ph));
            if (!empty($ph) && $ph !== '0') return $ph;
        }
    }
    if (!empty($row['student_emergency_phone'])) {
        $ph = normalizePhoneForDisplay(trim($row['student_emergency_phone']));
        if (!empty($ph) && $ph !== '0') return $ph;
    }
    return '';
}

function extractParentDisplayName($row) {
    if (!empty($row['all_parent_names'])) {
        $names = explode('||', $row['all_parent_names']);
        foreach ($names as $n) {
            $n = trim($n);
            if (!empty($n) && strtolower($n) !== 'null') return $n;
        }
    }
    if (!empty($row['student_emergency_name'])) {
        return trim($row['student_emergency_name']);
    }
    return null;
}

function formatStudentRow($row, $class_mapping) {
    $row['sms_phone']           = extractParentPhone($row);
    $row['has_parent_mapping']  = !empty($row['has_mapping']);
    $row['parent_count']        = (int)($row['parent_count'] ?? 0);
    $row['parent_display_name'] = extractParentDisplayName($row);
    $row['student_display_name'] = !empty($row['student_name'])
        ? $row['student_name'] : ($row['user_name'] ?? 'Unknown Student');
    $cn = $row['class_name'] ?? '';
    $row['class_display_name'] = isset($class_mapping[$cn])
        ? $cn . ' (' . $class_mapping[$cn] . ')' : ($cn ?: 'Unassigned');
    return $row;
}

// ── SMS Functions ─────────────────────────────────────────────────────────
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
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        error_log("SMS CURL ERROR: " . $curlError);
        return ['success' => false, 'error' => $curlError, 'phone' => $apiPhone];
    }

    $data    = json_decode($apiResponse, true);
    $success = false;
    if (is_array($data)) {
        $success = (isset($data['code'])    && strtolower($data['code'])    === 'ok')
                || (isset($data['status'])  && strtolower($data['status'])  === 'ok')
                || (isset($data['status'])  && strtolower($data['status'])  === 'success')
                || (isset($data['message']) && stripos($data['message'], 'success') !== false);
    } else {
        $success = stripos($apiResponse, '"code":"ok"')       !== false
                || stripos($apiResponse, '"status":"ok"')     !== false
                || stripos($apiResponse, '"status":"success"') !== false;
    }

    return ['success' => $success, 'response' => $apiResponse, 'phone' => $apiPhone];
}

function getAllParentPhonesForStudent($conn, $user_id) {
    $phones = [];
    $stmt = $conn->prepare(
        "SELECT DISTINCT pu.phone, pu.phone_number, pu.name AS parent_name
         FROM parent_student_mapping psm
         INNER JOIN users pu ON psm.parent_id = pu.id
         WHERE psm.student_id = ?
         ORDER BY pu.id ASC"
    );
    if (!$stmt) return $phones;

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $raw = trim($row['phone'] ?? '');
        if (empty($raw)) $raw = trim($row['phone_number'] ?? '');
        if (!empty($raw)) {
            $phones[] = [
                'phone'     => normalizePhoneForDisplay($raw),
                'phone_api' => formatPhoneForApi($raw),
                'name'      => trim($row['parent_name'] ?? ''),
            ];
        }
    }
    $stmt->close();
    return $phones;
}

function buildSMSMessage($student, $valid_items, $scholarships, $term_name, $year_label, $global_due_date, $previous_balance) {
    $sid = (int)($student['user_id'] ?? 0);
    $student_name = !empty($student['student_name']) ? $student['student_name'] : ($student['user_name'] ?? 'Student');
    $parent_name = $student['parent_display_name'] ?? 'Parent/Guardian';
    if (empty($parent_name)) $parent_name = 'Parent/Guardian';
    
    $scholarship_percent = isset($scholarships[$sid]) ? max(0, min(100, floatval($scholarships[$sid]))) : 0;
    
    // Calculate current term fees
    $current_term_fees = 0;
    foreach ($valid_items as $item) {
        $current_term_fees += $item['amount'];
    }
    
    $current_term_after_scholarship = $scholarship_percent > 0 
        ? $current_term_fees * (1 - $scholarship_percent / 100) 
        : $current_term_fees;
    
    $total_due = $previous_balance + $current_term_after_scholarship;
    $amount_saved = $current_term_fees - $current_term_after_scholarship;
    
    // Build message with arrears breakdown
    $msg = "Dear {$parent_name},\n\n";
    $msg .= "SCHOOL FEE NOTIFICATION\n\n";
    $msg .= "Student: {$student_name}\n";
    $msg .= "Academic Year: {$year_label}\n";
    $msg .= "Term: {$term_name}\n\n";
    $msg .= "--- FEE BREAKDOWN ---\n";
    
    if ($previous_balance > 0) {
        $msg .= "Arrears from previous term(s): GH₵" . number_format($previous_balance, 2) . "\n";
    }
    
    $msg .= "Current Term Fees: GH₵" . number_format($current_term_fees, 2) . "\n";
    
    if ($scholarship_percent > 0) {
        $msg .= "Scholarship Applied: {$scholarship_percent}%\n";
        $msg .= "Scholarship Savings: GH₵" . number_format($amount_saved, 2) . "\n";
        $msg .= "Current Term (after scholarship): GH₵" . number_format($current_term_after_scholarship, 2) . "\n";
    }
    
    $msg .= "\n--- TOTAL AMOUNT DUE ---\n";
    $msg .= "Total to Pay: GH₵" . number_format($total_due, 2) . "\n";
    $msg .= "Due Date: {$global_due_date}\n\n";
    
    $msg .= "Please ensure payment is made by the due date to avoid restrictions.\n";
    $msg .= "For inquiries, contact the school administration.\n\n";
    $msg .= "Thank you for your cooperation.\n";
    $msg .= "Queens Paradise Montessori School";
    
    return $msg;
}

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    switch ($_POST['action']) {
        case 'get_terms_by_year':
            $year_label = trim($_POST['year_label'] ?? '');
            if ($year_label !== '') {
                $stmt = $conn->prepare(
                    "SELECT id, year, term_name, start_date, end_date
                     FROM academic_calendar WHERE year = ? ORDER BY start_date ASC"
                );
                $stmt->bind_param("s", $year_label);
                $stmt->execute();
                $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $response = ['success' => true, 'terms' => $terms];
            }
            break;

        case 'get_all_students':
            $year_label    = trim($_POST['year_label']    ?? '');
            $department_id = (int)($_POST['department_id'] ?? 0);
            $class_id      = (int)($_POST['class_id']      ?? 0);
            $search        = trim($_POST['search']         ?? '');
            $term_id       = (int)($_POST['term_id']       ?? 0);
            try {
                $students  = getStudentsWithParents($conn, $year_label, $department_id, $class_id, $search, $term_id);
                $formatted = array_map(fn($r) => formatStudentRow($r, $class_mapping), $students);
                $response  = ['success' => true, 'students' => $formatted, 'count' => count($formatted)];
            } catch (Exception $e) {
                error_log("get_all_students error: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
            break;

        case 'get_admission_grouped_students':
            $year_label    = trim($_POST['year_label']    ?? '');
            $department_id = (int)($_POST['department_id'] ?? 0);
            $class_id      = (int)($_POST['class_id']      ?? 0);
            $term_id       = (int)($_POST['term_id']       ?? 0);
            $students      = getStudentsWithParents($conn, $year_label, $department_id, $class_id, '', $term_id);
            $grouped       = [];
            $no_date_group = [];
            foreach ($students as $student) {
                $fmt = formatStudentRow($student, $class_mapping);
                if (empty($student['admission_date'])) {
                    $no_date_group[] = $fmt;
                } else {
                    $date_key = date('Y-m', strtotime($student['admission_date']));
                    $grouped[$date_key][] = $fmt;
                }
            }
            if (!empty($no_date_group)) $grouped['No Admission Date'] = $no_date_group;
            $response = ['success' => true, 'groups' => $grouped];
            break;

        case 'get_classes_by_department':
            $department_id = (int)($_POST['department_id'] ?? 0);
            if ($department_id > 0) {
                $stmt = $conn->prepare(
                    "SELECT c.id, c.class_name, c.department_id, d.name AS department_name
                     FROM classes c LEFT JOIN departments d ON c.department_id = d.id
                     WHERE c.department_id = ? ORDER BY c.class_name ASC"
                );
                $stmt->bind_param("i", $department_id);
                $stmt->execute();
                $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $response = ['success' => true, 'classes' => $classes];
            }
            break;
    }
    echo json_encode($response);
    exit;
}

// ── Form submission ────────────────────────────────────────────────────────
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_fee'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid security token. Please refresh and try again.";
    } else {
        $year_label      = trim($_POST['year_label']    ?? '');
        $term_id         = (int)($_POST['term_id']       ?? 0);
        $department_id   = (int)($_POST['department_id'] ?? 0);
        $target_type     = trim($_POST['target_type']   ?? '');
        $class_id        = (int)($_POST['class_id']      ?? 0);
        $global_due_date = $_POST['global_due_date'] ?? date('Y-m-d');
        $fee_types       = $_POST['fee_types']    ?? [];
        $fee_amounts     = $_POST['fee_amounts']  ?? [];
        $recurring       = $_POST['is_recurring'] ?? [];
        $scholarships    = $_POST['scholarships'] ?? [];
        $department_name = getDepartmentNameById($conn, $department_id);
        $db_target_type  = ($target_type === 'Admission') ? 'Bulk Learners' : $target_type;

        $errors = [];
        if (!$year_label)    $errors[] = "Please select an academic year.";
        if ($term_id <= 0)   $errors[] = "Please select a term.";
        if (!$department_id) $errors[] = "Please select a department.";
        if (!$target_type)   $errors[] = "Please select a target type.";
        if (in_array($target_type, ['Class', 'Learner']) && $class_id <= 0)
            $errors[] = "Please select a class.";

        $specific_user_id     = 0;
        $specific_student_ids = [];
        if ($target_type === 'Learner') {
            $specific_user_id = (int)($_POST['user_id'] ?? ($_POST['student_ids'][0] ?? 0));
            if ($specific_user_id <= 0) $errors[] = "Please select a student.";
        } elseif (in_array($target_type, ['Bulk Learners', 'Admission'])) {
            $specific_student_ids = $_POST['student_ids'] ?? [];
            if (empty($specific_student_ids)) $errors[] = "Please select at least one student.";
        }

        $valid_items  = [];
        $total_amount = 0;
        for ($i = 0; $i < count($fee_types); $i++) {
            $item_name   = trim($fee_types[$i]   ?? '');
            $item_amount = floatval($fee_amounts[$i] ?? 0);
            if ($item_name && $item_amount > 0) {
                $valid_items[] = [
                    'type'         => $item_name,
                    'amount'       => $item_amount,
                    'due_date'     => $global_due_date,
                    'is_recurring' => isset($recurring[$i]) ? 1 : 0,
                ];
                $total_amount += $item_amount;
            }
        }
        if (empty($valid_items)) $errors[] = "Please enter amounts for at least one fee type.";

        if (!empty($errors)) {
            $error_msg = "<ul class='mb-0'><li>" . implode("</li><li>", $errors) . "</li></ul>";
        } else {
            $all_students = getStudentsWithParents($conn, $year_label, $department_id, $class_id, '', $term_id);

            if ($target_type === 'Learner' && $specific_user_id > 0) {
                $affected_students = array_values(array_filter($all_students, fn($s) => $s['user_id'] == $specific_user_id));
            } elseif (in_array($target_type, ['Bulk Learners', 'Admission']) && !empty($specific_student_ids)) {
                $affected_students = array_values(array_filter($all_students, fn($s) => in_array($s['user_id'], $specific_student_ids)));
            } else {
                $affected_students = $all_students;
            }

            if (empty($affected_students)) {
                $error_msg = "No students found.";
            } else {
                $conn->begin_transaction();
                try {
                    $total_inserted = $total_skipped = $sms_sent = $sms_failed = $sms_skipped = 0;

                    $tStmt = $conn->prepare("SELECT term_name FROM academic_calendar WHERE id = ?");
                    $tStmt->bind_param("i", $term_id);
                    $tStmt->execute();
                    $term_name = ($r = $tStmt->get_result()->fetch_assoc()) ? $r['term_name'] : '';
                    $tStmt->close();

                    $academic_year_id = getAcademicYearId($conn, $year_label);

                    @$conn->query("ALTER TABLE fees DROP INDEX idx_fee_unique");
                    $conn->query("ALTER TABLE fees ADD UNIQUE KEY idx_fee_unique (class_id, user_id, fee_type, academic_year_id, term_id)");

                    $checkStmt = $conn->prepare("SELECT id FROM fees WHERE fee_type = ? AND class_id = ? AND user_id = ? AND academic_year = ? AND term_id = ? LIMIT 1");
                    $feeStmt   = $conn->prepare("INSERT INTO fees (class_id, user_id, department, fee_type, amount, due_date, academic_year, term_id, target_type, is_recurring, academic_year_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $debtStmt  = $conn->prepare("INSERT IGNORE INTO debts (user_id, fee_id, amount_due, academic_year, term_id, class_id, academic_year_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

                    $scholarshipStmt = null;
                    $stCheck = $conn->query("SHOW TABLES LIKE 'student_scholarships'");
                    if ($stCheck && $stCheck->num_rows > 0) {
                        $scholarshipStmt = $conn->prepare("INSERT INTO student_scholarships (student_id, fee_id, scholarship_percentage, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE scholarship_percentage = VALUES(scholarship_percentage), updated_at = NOW()");
                    }

                    foreach ($valid_items as $item) {
                        foreach ($affected_students as $student) {
                            $sid = (int)($student['user_id'] ?? 0);
                            $cid = (int)($student['class_id'] ?? 0);
                            if (!$sid) continue;

                            $scholarship_percent = isset($scholarships[$sid]) ? max(0, min(100, floatval($scholarships[$sid]))) : 0;
                            $adjusted_amount = $scholarship_percent > 0 ? $item['amount'] * (1 - $scholarship_percent / 100) : $item['amount'];

                            $checkStmt->bind_param("siisi", $item['type'], $cid, $sid, $year_label, $term_id);
                            $checkStmt->execute();
                            if ($checkStmt->get_result()->fetch_assoc()) { $total_skipped++; continue; }

                            $feeStmt->bind_param("iissdssisii", $cid, $sid, $department_name, $item['type'], $adjusted_amount, $item['due_date'], $year_label, $term_id, $db_target_type, $item['is_recurring'], $academic_year_id);
                            $feeStmt->execute();
                            $fee_id = $conn->insert_id;
                            $total_inserted++;

                            $debtStmt->bind_param("iidsiii", $sid, $fee_id, $adjusted_amount, $year_label, $term_id, $cid, $academic_year_id);
                            $debtStmt->execute();

                            if ($scholarship_percent > 0 && $scholarshipStmt) {
                                $scholarshipStmt->bind_param("iid", $sid, $fee_id, $scholarship_percent);
                                $scholarshipStmt->execute();
                            }
                        }
                    }
                    $checkStmt->close(); $feeStmt->close(); $debtStmt->close();
                    if ($scholarshipStmt) $scholarshipStmt->close();

                    foreach ($affected_students as $student) {
                        $sid = (int)($student['user_id'] ?? 0);
                        if (!$sid) continue;

                        $parentPhones = getAllParentPhonesForStudent($conn, $sid);
                        if (empty($parentPhones)) {
                            $sms_skipped++;
                            continue;
                        }

                        $previous_balance = $student['previous_balance'] ?? 0;
                        $msg = buildSMSMessage($student, $valid_items, $scholarships, $term_name, $year_label, $global_due_date, $previous_balance);

                        foreach ($parentPhones as $pi) {
                            $sendPhone = $pi['phone_api'] ?? formatPhoneForApi($pi['phone']);
                            if (empty($sendPhone)) continue;
                            $result = sendSMS($sendPhone, $msg);
                            if ($result['success']) $sms_sent++;
                            else $sms_failed++;
                            usleep(100000);
                        }
                    }

                    $conn->commit();
                    $success_msg = "Successfully debited " . count($valid_items) . " fee type(s) for " . count($affected_students) . " student(s). Total: GH₵" . number_format($total_amount, 2) . ". SMS: {$sms_sent} sent";
                    if ($sms_failed > 0)  $success_msg .= ", {$sms_failed} failed";
                    if ($sms_skipped > 0) $success_msg .= ", {$sms_skipped} skipped (no phone)";
                    $success_msg .= ".";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// ── Dropdown data ──────────────────────────────────────────────────────────
$departments = [];
$deptsResult = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if ($deptsResult) { while ($d = $deptsResult->fetch_assoc()) $departments[] = $d; $deptsResult->free(); }

$classes = [];
$classesResult = $conn->query("SELECT c.id, c.class_name, c.department_id, d.name AS dept_name FROM classes c LEFT JOIN departments d ON c.department_id = d.id ORDER BY c.class_name ASC");
if ($classesResult) { while ($c = $classesResult->fetch_assoc()) $classes[] = $c; $classesResult->free(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Fee - QPMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root { --primary:#FF6200; --primary-dark:#E55A00; --primary-light:#FFF0E6; --success:#10B981; --danger:#EF4444; --warning:#F59E0B; --info:#3B82F6; --bg:#F8FAFC; --card:#FFFFFF; --border:#E2E8F0; --text:#1E293B; --text-muted:#64748B; }
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;min-height:100vh;color:var(--text)}
        .main-wrapper{padding:1rem;max-width:1600px;margin:0 auto}
        .page-header{background:linear-gradient(135deg,#FF6200 0%,#FF8C00 100%);padding:1.25rem 2rem;color:white;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
        .page-header h4{margin:0;font-weight:700;font-size:1.25rem;letter-spacing:-0.5px}
        .page-header .badge-sms{background:rgba(255,255,255,0.2);backdrop-filter:blur(10px);padding:0.4rem 1rem;border-radius:20px;font-size:0.75rem;font-weight:600;border:1px solid rgba(255,255,255,0.3)}
        .page-card{background:var(--card);border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04),0 1px 2px rgba(0,0,0,0.06);overflow:hidden;margin-bottom:1rem;border:1px solid var(--border)}
        .page-body{padding:1.5rem}
        .section-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:1rem;transition:box-shadow 0.2s}
        .section-card:hover{box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .section-title{font-size:0.9rem;font-weight:700;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;letter-spacing:-0.3px}
        .section-title i{color:var(--primary);width:20px;text-align:center;font-size:0.9rem}
        .required-label::after{content:" *";color:var(--danger);font-weight:700}
        .target-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:0.5rem;margin-bottom:1rem}
        .target-btn{display:flex;flex-direction:column;align-items:center;padding:0.75rem 0.5rem;border:2px solid var(--border);border-radius:10px;background:white;cursor:pointer;transition:all 0.2s ease;text-align:center;position:relative}
        .target-btn:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:0 4px 12px rgba(255,98,0,0.1)}
        .target-btn.active{border-color:var(--primary);background:var(--primary-light);box-shadow:0 2px 8px rgba(255,98,0,0.15)}
        .target-btn.active::after{content:'\f00c';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;top:6px;right:8px;color:var(--primary);font-size:0.7rem}
        .target-btn i{font-size:1.3rem;color:var(--primary);margin-bottom:0.25rem}
        .target-btn span{font-weight:600;font-size:0.78rem;line-height:1.2}
        .target-btn small{font-size:0.65rem;color:var(--text-muted);margin-top:0.1rem}
        .fee-items-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem}
        .fee-item-compact{display:grid;grid-template-columns:1fr 110px 35px;gap:0.5rem;align-items:center;padding:0.5rem 0.75rem;background:#F8FAFC;border-radius:8px;border:1px solid var(--border);font-size:0.8rem;transition:all 0.15s}
        .fee-item-compact:hover{border-color:var(--primary);background:#FFF8F0}
        .fee-item-compact.active-fee{border-color:#10B981;background:#F0FDF4}
        .fee-item-compact.active-fee .fee-label{color:#059669}
        .fee-label{font-weight:600;color:var(--text);font-size:0.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .fee-amount-input{border:1.5px solid var(--border);border-radius:6px;padding:0.4rem 0.5rem;font-size:0.8rem;width:100%;transition:all 0.2s;background:white}
        .fee-amount-input:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgba(255,98,0,0.1)}
        .fee-amount-input.has-value{border-color:#10B981;background:#F0FDF4;font-weight:600;color:#059669}
        .students-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:0.4rem;max-height:400px;overflow-y:auto;padding:0.25rem}
        .students-grid::-webkit-scrollbar{width:6px}
        .students-grid::-webkit-scrollbar-thumb{background:#CBD5E1;border-radius:3px}
        .student-card{display:flex;align-items:center;padding:0.5rem 0.65rem;border:1.5px solid var(--border);border-radius:8px;background:white;cursor:pointer;transition:all 0.15s ease;gap:0.5rem}
        .student-card:hover{border-color:var(--primary);background:#FFF8F0;transform:translateX(2px)}
        .student-card.selected{border-color:var(--primary);background:var(--primary-light);box-shadow:0 2px 4px rgba(255,98,0,0.1)}
        .student-check-icon{font-size:1rem;flex-shrink:0;transition:all 0.2s}
        .student-check-icon.fa-check-circle{color:var(--primary)}
        .student-check-icon.fa-circle{color:#CBD5E1}
        .student-info{flex:1;min-width:0}
        .student-name{font-weight:600;font-size:0.8rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .student-meta{font-size:0.7rem;color:var(--text-muted);margin-top:1px}
        .parent-info{display:flex;align-items:center;gap:0.25rem;margin-top:2px;flex-wrap:wrap}
        .parent-badge{display:inline-flex;align-items:center;gap:0.2rem;font-size:0.65rem;padding:2px 8px;border-radius:10px;font-weight:500;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .parent-linked{background:#D1FAE5;color:#065F46}
        .parent-unlinked{background:#FEF3C7;color:#92400E}
        .parent-no-data{background:#FEE2E2;color:#991B1B}
        .border-phone-ready{border-left:3px solid #10B981}
        .border-phone-missing{border-left:3px solid #F59E0B}
        .border-no-parent{border-left:3px solid #EF4444}
        .group-header{background:#F1F5F9;padding:0.4rem 0.75rem;font-weight:600;font-size:0.75rem;border-radius:6px;margin-top:0.5rem;margin-bottom:0.2rem;grid-column:1/-1;color:var(--text-muted);display:flex;align-items:center;gap:0.5rem;letter-spacing:0.3px;text-transform:uppercase}
        .admission-group-header{background:#EFF6FF;color:#1E40AF}
        .sms-section{background:linear-gradient(135deg,#ECFDF5 0%,#D1FAE5 100%);border:1px solid #A7F3D0;border-radius:10px;padding:0.75rem 1rem}
        .sms-badge{background:var(--success);color:white;padding:0.2rem 0.6rem;border-radius:12px;font-size:0.65rem;font-weight:700}
        .sms-preview{font-family:'SF Mono','Courier New',monospace;font-size:0.72rem;color:#065F46;line-height:1.5;max-height:120px;overflow-y:auto;white-space:pre-wrap}
        .summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem}
        .summary-item{text-align:center;padding:0.6rem 0.5rem;background:#F8FAFC;border-radius:8px;border:1px solid var(--border)}
        .summary-label{font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;font-weight:600;margin-bottom:0.15rem}
        .summary-value{font-weight:700;font-size:0.9rem;color:var(--text)}
        .total-highlight{background:var(--primary);color:white;padding:0.4rem 1rem;border-radius:20px;font-size:1rem;font-weight:700;display:inline-block;box-shadow:0 2px 8px rgba(255,98,0,0.2)}
        .due-date-highlight{background:#EFF6FF;border:2px solid #3B82F6;border-radius:8px;padding:0.5rem 1rem;display:inline-flex;align-items:center;gap:0.5rem;font-weight:600;font-size:0.85rem}
        .due-date-highlight i{color:#3B82F6}
        .btn-primary{background:var(--primary);border-color:var(--primary);font-weight:600;font-size:0.85rem;padding:0.6rem 1.5rem;border-radius:8px}
        .btn-primary:hover{background:var(--primary-dark);border-color:var(--primary-dark);transform:translateY(-1px);box-shadow:0 4px 12px rgba(255,98,0,0.25)}
        .btn-primary:disabled{opacity:0.5;cursor:not-allowed;transform:none;box-shadow:none}
        .btn-outline-primary{color:var(--primary);border-color:var(--primary);font-size:0.85rem;border-radius:8px}
        .btn-outline-secondary{font-size:0.85rem;border-radius:8px}
        .loading-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.95);backdrop-filter:blur(4px);display:none;justify-content:center;align-items:center;z-index:9999}
        .loading-overlay.show{display:flex}
        .loading-box{text-align:center;background:white;padding:2.5rem 3.5rem;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.1)}
        .select2-container--default .select2-selection--single{border:1.5px solid var(--border)!important;border-radius:8px!important;height:38px!important}
        .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px!important;font-size:0.85rem}
        @media(max-width:1200px){.fee-items-grid{grid-template-columns:1fr}.summary-grid{grid-template-columns:repeat(2,1fr)}.target-grid{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:768px){.target-grid{grid-template-columns:repeat(2,1fr)}.students-grid{grid-template-columns:1fr;max-height:300px}.summary-grid{grid-template-columns:1fr}.page-header{flex-direction:column;text-align:center}}
    </style>
</head>
<body>
    <?php
    $headerPath = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (file_exists($headerPath)) include $headerPath;
    else echo '<nav class="navbar navbar-dark bg-dark"><div class="container"><span class="navbar-brand">QPMS</span></div></nav>';
    ?>

    <div class="loading-overlay" id="loadingOverlay"><div class="loading-box"><div class="spinner-border text-primary mb-3" role="status" style="width:2.5rem;height:2.5rem;"></div><p class="fw-bold text-muted mb-0" id="loadingText">Processing...</p></div></div>

    <div class="main-wrapper">
        <?php if ($success_msg): ?><div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:10px;border:none;font-size:.85rem;"><i class="fas fa-check-circle me-2"></i><strong>Success!</strong> <?= $success_msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error_msg): ?><div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:10px;border:none;font-size:.85rem;"><i class="fas fa-exclamation-triangle me-2"></i><strong>Error!</strong> <?= $error_msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <form method="POST" id="feeForm" onsubmit="return prepareSubmission()">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="submit_fee" value="1">
            <input type="hidden" name="target_type" id="targetType" value="">
            <input type="hidden" name="year_label" id="yearLabelHidden" value="<?= htmlspecialchars($autoYearLabel) ?>">
            <input type="hidden" name="global_due_date" id="globalDueDate" value="">

            <div class="page-card">
                <div class="page-header">
                    <div><h4><i class="fas fa-plus-circle me-2"></i>Add New Fee</h4><small style="opacity:0.9;">Debit student accounts • SMS sent automatically to parents</small></div>
                    <span class="badge-sms"><i class="fas fa-sms me-1"></i> SMS Auto-Enabled</span>
                </div>
                <div class="page-body">
                    <div class="row g-3 mb-3">
                        <div class="col-lg-5">
                            <div class="section-card h-100">
                                <h5 class="section-title"><i class="fas fa-calendar-alt"></i> Academic Period</h5>
                                <?php if ($autoYearLabel): ?><div class="mb-2"><span style="background:#E8F5E9;color:#2E7D32;border:1px solid #A5D6A7;border-radius:6px;padding:.3rem .75rem;font-size:.75rem;font-weight:600;"><i class="fas fa-circle-check me-1"></i>Current: <?= htmlspecialchars($autoYearLabel) ?> — <?= htmlspecialchars($autoTermName) ?></span></div><?php endif; ?>
                                <div class="row g-2">
                                    <div class="col-6"><label class="form-label required-label" style="font-size:.78rem;font-weight:600;">Academic Year</label><select class="form-select form-select-sm" id="academicYear" required><option value="">-- Select --</option><?php foreach ($allYears as $yr): ?><option value="<?= htmlspecialchars($yr) ?>" <?= ($yr === $autoYearLabel) ? 'selected' : '' ?>><?= htmlspecialchars($yr) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-6"><label class="form-label required-label" style="font-size:.78rem;font-weight:600;">Term</label><select class="form-select form-select-sm" id="termSelect" name="term_id" required><option value="">-- Select --</option><?php foreach ($allTermsForCurrentYear as $term): ?><option value="<?= $term['id'] ?>" <?= ($term['id'] == $autoTermId) ? 'selected' : '' ?>><?= htmlspecialchars($term['term_name']) ?></option><?php endforeach; ?></select></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <div class="section-card h-100">
                                <h5 class="section-title"><i class="fas fa-bullseye"></i> Target Selection</h5>
                                <div class="target-grid" id="targetCards">
                                    <div class="target-btn" onclick="selectTarget('Class')" id="targetClass"><i class="fas fa-users"></i><span>Class</span><small>All in class</small></div>
                                    <div class="target-btn" onclick="selectTarget('Learner')" id="targetLearner"><i class="fas fa-user-graduate"></i><span>Single</span><small>One student</small></div>
                                    <div class="target-btn" onclick="selectTarget('Bulk Learners')" id="targetBulk"><i class="fas fa-user-friends"></i><span>Multiple</span><small>Select students</small></div>
                                    <div class="target-btn" onclick="selectTarget('Admission')" id="targetAdmission"><i class="fas fa-calendar-plus"></i><span>Admission</span><small>By date</small></div>
                                    <div class="target-btn" onclick="selectTarget('School')" id="targetSchool"><i class="fas fa-school"></i><span>Department</span><small>All in dept</small></div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6"><label class="form-label required-label" style="font-size:.78rem;font-weight:600;">Department</label><select class="form-select form-select-sm" id="departmentSelect" name="department_id" required><option value="">-- Select --</option><?php foreach ($departments as $dept): ?><option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-6" id="classSelectContainer"><label class="form-label required-label" style="font-size:.78rem;font-weight:600;">Class</label><select class="form-select form-select-sm" id="classSelect" name="class_id"><option value="">-- Select --</option><?php foreach ($classes as $class): ?><option value="<?= $class['id'] ?>" data-dept="<?= $class['department_id'] ?>"><?= htmlspecialchars($class['class_name']) ?></option><?php endforeach; ?></select></div>
                                </div>
                                <div class="mt-2" id="studentSelectionArea" style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center mb-1"><span class="fw-bold" style="font-size:.78rem;"><i class="fas fa-list me-1 text-primary"></i>Students</span><div class="d-flex gap-1"><input type="text" class="form-control form-control-sm" placeholder="Search..." id="studentSearch" onkeyup="debounceSearch()" style="width:180px;font-size:.75rem;"><button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAllVisible()" style="font-size:.7rem;">All</button><button type="button" class="btn btn-outline-danger btn-sm" onclick="deselectAll()" style="font-size:.7rem;">Clear</button></div></div>
                                    <div class="students-grid" id="studentsGrid"></div>
                                    <div class="d-flex justify-content-between align-items-center mt-1"><span class="badge bg-secondary" id="selectedCountBadge" style="font-size:.7rem;"><span id="selectedCount">0</span> selected</span><small class="text-muted" style="font-size:.7rem;"><span id="totalCount">0</span> loaded</small></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="section-card">
                                <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="section-title mb-0"><i class="fas fa-list-ul"></i> Fee Itemization</h5><span class="text-muted" style="font-size:.7rem;">Enter amounts for applicable fees only</span></div>
                                <div class="fee-items-grid" id="feeItemsContainer"></div>
                                <div class="text-center mt-3 pt-3 border-top"><div class="due-date-highlight"><i class="fas fa-calendar-check"></i><span>Due Date:</span><input type="date" id="dueDateInput" style="border:1.5px solid #93C5FD;border-radius:6px;padding:.35rem .5rem;font-size:.85rem;font-weight:600;" onchange="updateDueDate()"></div><span class="ms-3 total-highlight" id="grandTotal">GH₵ 0.00</span></div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="section-card sms-section mb-2">
                                <h5 class="section-title mb-1"><i class="fas fa-sms"></i> SMS Preview <span class="sms-badge">AUTO</span></h5>
                                <small class="text-muted d-block mb-1" style="font-size:.68rem;">Message includes: Arrears + Current Term Fees = Total Due</small>
                                <div class="sms-preview" id="smsPreview">Select students and enter fee amounts to preview SMS</div>
                                <div class="d-flex justify-content-between mt-1">
                                    <small style="font-size:.65rem;">Chars: <span id="smsCharCount">0</span></small>
                                    <small style="font-size:.65rem;">Recipients: <span id="smsRecipientCount">0</span></small>
                                </div>
                            </div>
                            <div class="section-card"><h5 class="section-title mb-2"><i class="fas fa-clipboard-check"></i> Summary</h5>
                                <div class="summary-grid">
                                    <div class="summary-item"><div class="summary-label">Year</div><div class="summary-value" id="summaryYear">-</div></div>
                                    <div class="summary-item"><div class="summary-label">Term</div><div class="summary-value" id="summaryTerm">-</div></div>
                                    <div class="summary-item"><div class="summary-label">Target</div><div class="summary-value" id="summaryTarget">-</div></div>
                                    <div class="summary-item"><div class="summary-label">Students</div><div class="summary-value" id="summaryStudents">0</div></div>
                                    <div class="summary-item"><div class="summary-label">Active Fees</div><div class="summary-value" id="summaryItems">0</div></div>
                                    <div class="summary-item"><div class="summary-label">Total</div><div class="summary-value" style="color:var(--primary);" id="summaryAmount">GH₵ 0</div></div>
                                    <div class="summary-item"><div class="summary-label">Scholarships</div><div class="summary-value" id="summaryScholarships">0</div></div>
                                    <div class="summary-item"><div class="summary-label">Due Date</div><div class="summary-value" id="summaryDueDate">-</div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3"><a href="/admin/fees_payments.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Fees</a><div class="d-flex gap-2"><button type="button" class="btn btn-outline-primary" onclick="resetForm()"><i class="fas fa-redo me-1"></i>Reset</button><button type="submit" class="btn btn-primary px-4" id="submitBtn" disabled><i class="fas fa-paper-plane me-1"></i>Debit Fees & Send SMS</button></div></div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    let selectedTarget='',allStudents=[],selectedStudentIds=new Set(),admissionGroups={},searchTimeout,studentScholarships={},globalDueDate='';
    const standardFeeTypes=<?= json_encode($standard_fee_types) ?>,classMapping=<?= json_encode($class_mapping) ?>;

    function normalizePhoneDisplay(p){
        if(!p)return'';
        let c=p.replace(/[^0-9+]/g,'');
        if(!c)return'';
        if(c.startsWith('+233'))return'0'+c.slice(4);
        if(c.startsWith('233')&&c.length===12)return'0'+c.slice(3);
        if(c.length===9&&c[0]!=='0')return'0'+c;
        return c;
    }

    $(function(){
        $('#academicYear,#termSelect,#departmentSelect,#classSelect').select2({width:'100%',minimumResultsForSearch:5});
        let d=new Date(Date.now()+30*86400000);globalDueDate=d.toISOString().split('T')[0];
        $('#dueDateInput').val(globalDueDate);$('#globalDueDate').val(globalDueDate);
        initializeFeeItems();updateSummary();updateSmsPreview();
    });

    function initializeFeeItems(){
        let c=$('#feeItemsContainer');c.empty();
        Object.entries(standardFeeTypes).forEach(([k,l])=>{
            c.append(`<div class="fee-item-compact" data-fee-type="${k}">
                <div class="fee-label" title="${l}">${l}</div>
                <input type="number" class="fee-amount-input" step="0.01" min="0" placeholder="GH₵ 0.00" oninput="onFeeAmountChange(this)" onfocus="this.select()">
                <div class="text-center"><input class="form-check-input" type="checkbox" title="Recurring monthly" style="width:16px;height:16px;cursor:pointer;"></div>
            </div>`);
        });
    }

    function onFeeAmountChange(i){
        let v=parseFloat(i.value)||0,r=$(i).closest('.fee-item-compact');
        r.toggleClass('active-fee',v>0);$(i).toggleClass('has-value',v>0);
        calculateTotal();
    }
    function updateDueDate(){globalDueDate=$('#dueDateInput').val();$('#globalDueDate').val(globalDueDate);updateSummary();updateSmsPreview();}
    function showLoading(m){$('#loadingText').text(m||'Loading...');$('#loadingOverlay').addClass('show');}
    function hideLoading(){$('#loadingOverlay').removeClass('show');}

    $('#academicYear').on('change',function(){let y=this.value;if(y){$('#yearLabelHidden').val(y);onYearChange(y);}});
    $('#termSelect,#departmentSelect').on('change',function(){updateSummary();updateSmsPreview();});
    $('#departmentSelect').on('change',function(){onDepartmentChange();updateSummary();});
    $('#classSelect').on('change',function(){loadStudents();updateSummary();});

    function onYearChange(y){
        showLoading('Loading terms…');
        $.post('<?= $_SERVER['PHP_SELF'] ?>',{action:'get_terms_by_year',year_label:y},function(r){
            let o='<option value="">-- Select --</option>';
            if(r.success&&r.terms)r.terms.forEach(t=>{o+=`<option value="${t.id}">${t.term_name}</option>`;});
            $('#termSelect').html(o).trigger('change');updateSummary();
        },'json').always(hideLoading);
    }

    function selectTarget(t){
        selectedTarget=t;$('#targetType').val(t);$('.target-btn').removeClass('active');
        let m={Class:'targetClass',Learner:'targetLearner','Bulk Learners':'targetBulk',Admission:'targetAdmission',School:'targetSchool'};
        if(m[t])$('#'+m[t]).addClass('active');
        $('#classSelectContainer').toggle(t!=='School');
        if(t==='School')$('#classSelect').val('').trigger('change');
        let s=['Learner','Bulk Learners','Admission'].includes(t);
        $('#studentSelectionArea').toggle(s);
        if(s)loadStudents();else{selectedStudentIds.clear();studentScholarships={};}
        updateSummary();updateSubmitButton();updateSmsPreview();
    }

    function onDepartmentChange(){
        let d=$('#departmentSelect').val();
        $('#classSelect option').each(function(){if(!$(this).val())return;$(this).toggle(!d||$(this).data('dept')==d);});
        $('#classSelect').val('').trigger('change');
        if(d)loadStudents();
    }

    function loadStudents(){
        let y=$('#yearLabelHidden').val(),d=$('#departmentSelect').val(),c=$('#classSelect').val(),s=$('#studentSearch').val()||'',t=$('#termSelect').val();
        if(!y||!d){$('#studentsGrid').html('<div class="text-center text-muted py-4" style="grid-column:1/-1;"><i class="fas fa-info-circle fa-2x mb-2 d-block" style="opacity:.3;"></i><p style="font-size:.8rem;">Select year and department first</p></div>');return;}
        if(selectedTarget==='Admission')loadAdmissionGroupedStudents(y,d,c,t);
        else loadRegularStudents(y,d,c,s,t);
    }

    function loadRegularStudents(y,d,c,s,termId){
        showLoading('Loading students…');
        $.post('<?= $_SERVER['PHP_SELF'] ?>',{action:'get_all_students',year_label:y,department_id:d,class_id:c,search:s,term_id:termId},function(r){
            if(r.success){allStudents=r.students||[];renderStudents(allStudents);$('#totalCount').text(r.count||allStudents.length);}
            else{allStudents=[];renderStudents([]);}
            updateSubmitButton();updateSmsPreview();
        },'json').always(hideLoading);
    }

    function loadAdmissionGroupedStudents(y,d,c,termId){
        showLoading('Loading by admission…');
        $.post('<?= $_SERVER['PHP_SELF'] ?>',{action:'get_admission_grouped_students',year_label:y,department_id:d,class_id:c,term_id:termId},function(r){
            if(r.success&&r.groups){admissionGroups=r.groups;allStudents=[];Object.values(admissionGroups).forEach(g=>{allStudents=allStudents.concat(g);});renderAdmissionGroupedStudents(admissionGroups);$('#totalCount').text(allStudents.length);}
            else{admissionGroups={};allStudents=[];renderAdmissionGroupedStudents({});}
            updateSubmitButton();updateSmsPreview();
        },'json').always(hideLoading);
    }

    function renderStudents(s){
        let g=$('#studentsGrid');g.empty();
        if(!s||!s.length){g.html('<div class="text-center text-muted py-4" style="grid-column:1/-1;"><i class="fas fa-user-slash fa-2x mb-2 d-block" style="opacity:.3;"></i><p style="font-size:.8rem;">No students found</p></div>');return;}
        let gr={};s.forEach(x=>{let cn=x.class_name||'Unassigned';(gr[cn]=gr[cn]||[]).push(x);});
        let o=Object.keys(classMapping);
        Object.keys(gr).sort((a,b)=>{let ia=o.indexOf(a),ib=o.indexOf(b);return(ia<0&&ib<0)?a.localeCompare(b):(ia<0?1:ib<0?-1:ia-ib);}).forEach(cn=>{
            let cs=gr[cn],mn=classMapping[cn]?` (${classMapping[cn]})`:'';
            g.append(`<div class="group-header"><i class="fas fa-users"></i>${escHtml(cn)}${mn} <span class="text-muted">(${cs.length})</span></div>`);
            cs.forEach(x=>addStudentCard(g,x));
        });
        updateSelectedCount();
    }

    function renderAdmissionGroupedStudents(gr){
        let g=$('#studentsGrid');g.empty();
        if(!gr||Object.keys(gr).length===0){g.html('<div class="text-center text-muted py-4" style="grid-column:1/-1;"><p style="font-size:.8rem;">No students found</p></div>');return;}
        Object.entries(gr).forEach(([dk,ss])=>{
            let mn=dk!=='No Admission Date'?new Date(dk+'-01').toLocaleString('default',{month:'long',year:'numeric'}):'No Admission Date';
            g.append(`<div class="group-header admission-group-header"><i class="fas fa-calendar-alt"></i><strong>${mn}</strong> <span>(${ss.length})</span></div>`);
            ss.forEach(s=>addStudentCard(g,s));
        });
        updateSelectedCount();
    }

    function addStudentCard(g, s) {
        let sel = selectedStudentIds.has(s.user_id);
        let rp = s.sms_phone || '', dp = rp ? normalizePhoneDisplay(rp) : '';
        let hm = s.has_parent_mapping, pc = s.parent_count || 0, parentName = s.parent_display_name || '';
        let hasEmergencyContact = !!(s.student_emergency_phone || s.student_emergency_name);
        let bc = 'border-no-parent', ph = '';
        let prevBalance = s.previous_balance || 0;
        let balanceDisplay = prevBalance > 0 ? `<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">Arrears: GH₵${prevBalance.toFixed(2)}</span>` : '';
        
        if (hm && parentName) {
            bc = dp ? 'border-phone-ready' : 'border-phone-missing';
            let ex = pc > 1 ? ` +${pc - 1} more` : '';
            ph = `<span class="parent-badge parent-linked" title="${escHtml(parentName)}${ex}"><i class="fas fa-user-check"></i> ${escHtml(parentName)}${ex}</span>`;
            ph += dp ? `<span class="parent-badge parent-linked" style="font-size:.6rem;"><i class="fas fa-phone"></i> ${escHtml(dp)}</span>` : `<span class="parent-badge parent-unlinked" style="font-size:.6rem;"><i class="fas fa-phone-slash"></i> No phone</span>`;
        } else if (hm && !parentName) {
            bc = 'border-phone-missing';
            ph = `<span class="parent-badge parent-unlinked"><i class="fas fa-user"></i> Parent #${pc}</span>`;
            ph += dp ? `<span class="parent-badge parent-unlinked" style="font-size:.6rem;"><i class="fas fa-phone"></i> ${escHtml(dp)}</span>` : `<span class="parent-badge parent-unlinked" style="font-size:.6rem;"><i class="fas fa-phone-slash"></i> Add phone</span>`;
        } else if (hasEmergencyContact) {
            bc = 'border-phone-missing';
            let en = s.student_emergency_name || 'Emergency', ep = s.student_emergency_phone ? normalizePhoneDisplay(s.student_emergency_phone) : '';
            ph = `<span class="parent-badge parent-unlinked"><i class="fas fa-exclamation-triangle"></i> ${escHtml(en)}</span>`;
            if(ep) ph += `<span class="parent-badge parent-unlinked" style="font-size:.6rem;"><i class="fas fa-phone"></i> ${escHtml(ep)}</span>`;
            ph += `<span class="parent-badge parent-no-data" style="font-size:.55rem;">Not linked</span>`;
        } else {
            bc = 'border-no-parent';
            ph = `<span class="parent-badge parent-no-data"><i class="fas fa-user-slash"></i> No parent data</span>`;
        }
        let sp = studentScholarships[s.user_id] || 0, cn = s.class_name || 'Unassigned', mn = classMapping[cn] ? ` (${classMapping[cn]})` : '';
        g.append(`<div class="student-card ${sel?'selected':''} ${bc}" data-student-id="${s.user_id}" data-class-id="${s.class_id}">
            <i class="fas ${sel?'fa-check-circle':'fa-circle'} student-check-icon" onclick="toggleStudent(${s.user_id},this.closest('.student-card'))"></i>
            <div class="student-info" onclick="toggleStudent(${s.user_id},this.closest('.student-card'))">
                <div class="student-name">${escHtml(s.student_display_name||s.user_name)} ${balanceDisplay}</div>
                <div class="student-meta">${escHtml(cn)}${mn}</div>
                <div class="parent-info">${ph}</div>
            </div>
            <div class="text-center" style="flex-shrink:0;" onclick="event.stopPropagation();">
                <input type="number" class="form-control form-control-sm" min="0" max="100" step="1" value="${sp}" placeholder="0%" style="width:55px;padding:.2rem;font-size:.7rem;text-align:center;border-radius:6px;" onchange="updateScholarship(${s.user_id},this.value)" title="Scholarship %">
                <div style="font-size:.55rem;color:#888;">Scholar%</div>
            </div>
        </div>`);
    }

    function updateScholarship(id,p){p=Math.max(0,Math.min(100,parseFloat(p)||0));studentScholarships[id]=p;updateSummary();updateSmsPreview();}
    function toggleStudent(id,c){
        if(selectedTarget==='Learner'){selectedStudentIds.clear();selectedStudentIds.add(id);$('.student-card').each(function(){let i=parseInt($(this).data('student-id'));$(this).toggleClass('selected',i===id);$(this).find('.student-check-icon').toggleClass('fa-check-circle',i===id).toggleClass('fa-circle',i!==id);});}
        else{if(selectedStudentIds.has(id)){selectedStudentIds.delete(id);$(c).removeClass('selected').find('.student-check-icon').removeClass('fa-check-circle').addClass('fa-circle');}else{selectedStudentIds.add(id);$(c).addClass('selected').find('.student-check-icon').removeClass('fa-circle').addClass('fa-check-circle');}}
        updateSelectedCount();updateSummary();updateSubmitButton();updateSmsPreview();
    }
    function selectAllVisible(){if(selectedTarget==='Learner'){alert('Single Student mode');return;}$('.student-card').each(function(){selectedStudentIds.add(parseInt($(this).data('student-id')));});refreshCards();updateSelectedCount();updateSummary();updateSubmitButton();updateSmsPreview();}
    function deselectAll(){selectedStudentIds.clear();refreshCards();updateSelectedCount();updateSummary();updateSubmitButton();updateSmsPreview();}
    function refreshCards(){$('.student-card').each(function(){let i=parseInt($(this).data('student-id')),s=selectedStudentIds.has(i);$(this).toggleClass('selected',s).find('.student-check-icon').toggleClass('fa-check-circle',s).toggleClass('fa-circle',!s);});}
    function updateSelectedCount(){let n=selectedStudentIds.size;$('#selectedCount').text(n);$('#selectedCountBadge').toggleClass('bg-primary',n>0).toggleClass('bg-secondary',n===0);}
    function debounceSearch(){clearTimeout(searchTimeout);searchTimeout=setTimeout(loadStudents,400);}
    function calculateTotal(){let t=0;$('.fee-amount-input').each(function(){t+=parseFloat($(this).val())||0;});$('#grandTotal').text('GH₵ '+t.toFixed(2));updateSmsPreview();updateSummary();updateSubmitButton();}
    function updateSubmitButton(){
        let h=false;$('.fee-amount-input').each(function(){if((parseFloat($(this).val())||0)>0)h=true;});
        let o=h&&$('#yearLabelHidden').val()&&$('#termSelect').val()&&selectedTarget;
        if(['Learner','Bulk Learners','Admission'].includes(selectedTarget))o=o&&selectedStudentIds.size>0;
        if(['Class','Learner'].includes(selectedTarget))o=o&&!!$('#classSelect').val();
        if(selectedTarget==='School')o=o&&!!$('#departmentSelect').val();
        $('#submitBtn').prop('disabled',!o);
    }
    
    function updateSmsPreview(){
        let y=$('#yearLabelHidden').val()||'[Year]',t=($('#termSelect option:selected').text()||'[Term]'),d=globalDueDate||'[Due Date]',a=['School','Class'].includes(selectedTarget)?allStudents:allStudents.filter(s=>selectedStudentIds.has(s.user_id)),ps=new Set();
        
        a.forEach(s=>{let p=s.sms_phone;if(p&&p.trim())ps.add(normalizePhoneDisplay(p.trim()));});
        $('#smsRecipientCount').text(ps.size);
        
        if(a.length>0){
            let f=a[0],sn=f.student_display_name||f.user_name||'Student',pn=f.parent_display_name||'Parent/Guardian',sc=studentScholarships[f.user_id]||0,prevBal=f.previous_balance||0,msg='';
            
            // Calculate current term fees
            let currentFees=0;
            $('.fee-item-compact').each(function(){let am=parseFloat($(this).find('.fee-amount-input').val())||0;currentFees+=am;});
            let currentAfterScholarship = sc > 0 ? currentFees * (1 - sc/100) : currentFees;
            let totalDue = prevBal + currentAfterScholarship;
            let amountSaved = currentFees - currentAfterScholarship;
            
            msg = `Dear ${pn},\n\n`;
            msg += `SCHOOL FEE NOTIFICATION\n\n`;
            msg += `Student: ${sn}\n`;
            msg += `Academic Year: ${y}\n`;
            msg += `Term: ${t}\n\n`;
            msg += `--- FEE BREAKDOWN ---\n`;
            
            if(prevBal > 0){
                msg += `Arrears from previous term(s): GH₵${prevBal.toFixed(2)}\n`;
            }
            
            msg += `Current Term Fees: GH₵${currentFees.toFixed(2)}\n`;
            
            if(sc > 0){
                msg += `Scholarship Applied: ${sc}%\n`;
                msg += `Scholarship Savings: GH₵${amountSaved.toFixed(2)}\n`;
                msg += `Current Term (after scholarship): GH₵${currentAfterScholarship.toFixed(2)}\n`;
            }
            
            msg += `\n--- TOTAL AMOUNT DUE ---\n`;
            msg += `Total to Pay: GH₵${totalDue.toFixed(2)}\n`;
            msg += `Due Date: ${d}\n\n`;
            
            msg += `Please ensure payment is made by the due date to avoid restrictions.\n`;
            msg += `For inquiries, contact the school administration.\n\n`;
            msg += `Thank you for your cooperation.\n`;
            msg += `Queens Paradise Montessori School`;
            
            $('#smsPreview').text(msg);
            $('#smsCharCount').text(msg.length);
        }else{
            $('#smsPreview').text('Select students and enter fee amounts to preview SMS');
            $('#smsCharCount').text(0);
        }
    }
    
    function updateSummary(){
        let y=$('#academicYear').val()||'';$('#summaryYear').text(y||'-');if(y)$('#yearLabelHidden').val(y);
        $('#summaryTerm').text($('#termSelect option:selected').text()||'-');$('#summaryTarget').text(selectedTarget||'-');
        let t=parseFloat($('#grandTotal').text().replace('GH₵ ',''))||0;$('#summaryAmount').text('GH₵ '+t.toFixed(2));
        let ac=0;$('.fee-amount-input').each(function(){if((parseFloat($(this).val())||0)>0)ac++;});$('#summaryItems').text(ac);
        let st='0';if(['School','Class'].includes(selectedTarget))st=allStudents.length.toString();else if(['Learner','Bulk Learners','Admission'].includes(selectedTarget))st=selectedStudentIds.size.toString();$('#summaryStudents').text(st);
        let sc=0;Object.values(studentScholarships).forEach(p=>{if(p>0)sc++;});$('#summaryScholarships').text(sc);$('#summaryDueDate').text(globalDueDate||'-');
    }
    
    function prepareSubmission(){
        $('.dynamic-input').remove();let y=$('#yearLabelHidden').val();
        if(!y){alert('Please select an academic year.');return false;}
        if(!$('#termSelect').val()){alert('Please select a term.');return false;}
        if(!$('#departmentSelect').val()){alert('Please select a department.');return false;}
        if(!selectedTarget){alert('Please select a target type.');return false;}
        if(['Class','Learner'].includes(selectedTarget)&&!$('#classSelect').val()){alert('Please select a class.');return false;}
        if(!globalDueDate){alert('Please set a due date.');return false;}
        let h=false,vc=0;
        $('.fee-item-compact').each(function(){let ft=$(this).data('fee-type'),am=parseFloat($(this).find('.fee-amount-input').val())||0,rc=$(this).find('input[type="checkbox"]').is(':checked')?'1':'0';if(ft&&am>0){$('#feeForm').append(`<input type="hidden" class="dynamic-input" name="fee_types[]" value="${escHtml(ft)}">`);$('#feeForm').append(`<input type="hidden" class="dynamic-input" name="fee_amounts[]" value="${am.toFixed(2)}">`);$('#feeForm').append(`<input type="hidden" class="dynamic-input" name="is_recurring[]" value="${rc}">`);h=true;vc++;}});
        if(!h){alert('Please enter amounts for at least one fee type.');return false;}
        if(['Learner','Bulk Learners','Admission'].includes(selectedTarget)){if(!selectedStudentIds.size){alert('Please select at least one student.');return false;}selectedStudentIds.forEach(id=>{let s=allStudents.find(x=>x.user_id==id);if(s){$('#feeForm').append(`<input type="hidden" class="dynamic-input" name="student_ids[]" value="${id}">`);if(selectedTarget==='Learner')$('#feeForm').append(`<input type="hidden" class="dynamic-input" name="user_id" value="${id}">`);if(studentScholarships[id]&&studentScholarships[id]>0)$('#feeForm').append(`<input type="hidden" class="dynamic-input" name="scholarships[${id}]" value="${studentScholarships[id]}">`);}});}
        let ta=parseFloat($('#grandTotal').text().replace('GH₵ ',''))||0,msg=`Confirm Fee Debit:\n\nYear: ${y}\nTerm: ${$('#termSelect option:selected').text()}\nDept: ${$('#departmentSelect option:selected').text()}\nTarget: ${selectedTarget}\nStudents: ${selectedStudentIds.size||'All'}\nFee Items: ${vc}\nTotal: GH₵ ${ta.toFixed(2)}\nDue: ${globalDueDate}\n\nSMS will include: Arrears + Current Term Fees = Total Due\nProceed?`;
        if(!confirm(msg))return false;showLoading('Debiting fees & sending SMS…');return true;
    }
    function resetForm(){if(confirm('Reset all form data?'))location.reload();}
    function escHtml(t){if(!t)return'';let d=document.createElement('div');d.textContent=t;return d.innerHTML;}
    </script>
</body>
</html>