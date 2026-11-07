<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// ── DB guard ──────────────────────────────────────────────────────────────
if (!$conn || $conn->connect_error) {
    die("System error: Database connection failed.");
}

// ── Auth guard ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// ── log_activity ──────────────────────────────────────────────────────────
$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) require_once($log_activity_path);
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $details, $conn = null) {
        $log_file  = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
    }
}
log_activity($_SESSION['user_id'], 'page_access', 'Accessed fees_payments.php', $conn);

// ── CSRF ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ── Class name map ────────────────────────────────────────────────────────
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
    'Sheba 1'           => ['display' => 'Nursery 1', 'level' => 'Pre-School'],
    'Sheba 2'           => ['display' => 'Nursery 2', 'level' => 'Pre-School'],
    'Yaasant 1'         => ['display' => 'KG 1',      'level' => 'Kindergarten'],
    'Yaasant 2'         => ['display' => 'KG 2',      'level' => 'Kindergarten'],
    'Cleopatra 1'       => ['display' => 'Basic 1',   'level' => 'Lower Primary'],
    'Cleopatra 2'       => ['display' => 'Basic 2',   'level' => 'Lower Primary'],
    'Nefertiti 1'       => ['display' => 'Basic 3',   'level' => 'Lower Primary'],
    'Nefertiti 2'       => ['display' => 'Basic 4',   'level' => 'Lower Primary'],
    'Malika 1'          => ['display' => 'Basic 5',   'level' => 'Upper Primary'],
    'Malika 2'          => ['display' => 'Basic 6',   'level' => 'Upper Primary'],
    'Makeda 1'          => ['display' => 'Basic 7',   'level' => 'Junior High'],
    'Makeda 2'          => ['display' => 'Basic 8',   'level' => 'Junior High'],
];

function classDisplay(string $db_name, array $map): string {
    return isset($map[$db_name]) ? $map[$db_name]['display'] : $db_name;
}

// ── Academic years ────────────────────────────────────────────────────────
$academic_years = [];
$yr_res = $conn->query("SELECT DISTINCT academic_year FROM fees ORDER BY academic_year DESC");
if ($yr_res) { while ($r = $yr_res->fetch_assoc()) $academic_years[] = $r['academic_year']; $yr_res->free(); }
if (empty($academic_years)) {
    $yr_res = $conn->query("SELECT DISTINCT year FROM academic_calendar WHERE year REGEXP '^[0-9]{4}/[0-9]{4}$' ORDER BY year DESC");
    if ($yr_res) { while ($r = $yr_res->fetch_assoc()) $academic_years[] = $r['year']; $yr_res->free(); }
}

// ── Departments ───────────────────────────────────────────────────────────
$departments = [];
$dept_res = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if ($dept_res) { while ($r = $dept_res->fetch_assoc()) $departments[$r['id']] = $r['name']; $dept_res->free(); }

// ── Classes ───────────────────────────────────────────────────────────────
$classes = [];
$cls_res = $conn->query("SELECT id, class_name, department_id FROM classes ORDER BY class_name ASC");
if ($cls_res) {
    while ($r = $cls_res->fetch_assoc()) {
        $classes[$r['id']] = [
            'class_name'    => $r['class_name'],
            'display_name'  => classDisplay($r['class_name'], $class_name_map),
            'department_id' => (int)$r['department_id'],
        ];
    }
    $cls_res->free();
}

// ── Terms for selected year ───────────────────────────────────────────────
function getTermsForYear(mysqli $conn, string $year): array {
    $stmt = $conn->prepare("SELECT id, term_name FROM academic_calendar WHERE year = ? ORDER BY start_date ASC");
    if (!$stmt) return [];
    $stmt->bind_param("s", $year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ══════════════════════════════════════════════════════════════════════════
//  AJAX HANDLER FOR LIVE SEARCH
// ══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json');
    
    $search_term = isset($_GET['term']) ? trim($_GET['term']) : '';
    $academic_year = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : '';
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $term_id = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
    
    $dept_name = $departments[$department_id] ?? '';
    
    $search_conditions = ["u.role = 'student'", "u.status = 'active'"];
    $search_params = [];
    $search_types = "";
    
    if ($academic_year) {
        $search_conditions[] = "f.academic_year = ?";
        $search_params[] = $academic_year;
        $search_types .= "s";
    }
    if ($term_id > 0) {
        $search_conditions[] = "f.term_id = ?";
        $search_params[] = $term_id;
        $search_types .= "i";
    }
    if ($dept_name) {
        $search_conditions[] = "f.department = ?";
        $search_params[] = $dept_name;
        $search_types .= "s";
    }
    if ($class_id > 0) {
        $search_conditions[] = "f.class_id = ?";
        $search_params[] = $class_id;
        $search_types .= "i";
    }
    if ($search_term !== '') {
        $search_conditions[] = "(u.name LIKE ? OR s.student_name LIKE ?)";
        $like = "%{$search_term}%";
        $search_params[] = $like;
        $search_params[] = $like;
        $search_types .= "ss";
    }
    
    $fee_match_condition = "
        (
            (f.target_type = 'Learner' AND f.user_id = u.id)
         OR (f.target_type = 'Bulk Learners' AND f.user_id = u.id)
         OR f.target_type = 'School'
         OR (f.target_type = 'Class' AND EXISTS (
                SELECT 1 FROM student_classes scx
                 WHERE scx.user_id = u.id
                   AND scx.class_id = f.class_id
                   AND scx.academic_year = f.academic_year
            ))
        )
    ";
    
    $search_sql = "
        SELECT DISTINCT
            u.id AS learner_id,
            COALESCE(NULLIF(s.student_name, ''), u.name) AS learner_name,
            u.admission_date
        FROM users u
        INNER JOIN students s ON u.id = s.user_id
        INNER JOIN fees f ON {$fee_match_condition}
        WHERE " . implode(" AND ", $search_conditions) . "
        ORDER BY learner_name ASC
        LIMIT 50
    ";
    
    $search_stmt = $conn->prepare($search_sql);
    if (!empty($search_params)) {
        $search_stmt->bind_param($search_types, ...$search_params);
    }
    $search_stmt->execute();
    $results = $search_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $search_stmt->close();
    
    echo json_encode($results);
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────
$selected_year  = isset($_GET['academic_year']) ? trim($_GET['academic_year'])  : ($academic_years[0] ?? '');
$selected_dept  = isset($_GET['department_id']) ? (int)$_GET['department_id']  : 0;
$selected_class = isset($_GET['class_id'])      ? (int)$_GET['class_id']       : 0;
$selected_term  = isset($_GET['term_id'])       ? (int)$_GET['term_id']        : 0;
$search_query   = isset($_GET['search'])        ? trim($_GET['search'])        : '';
$status_filter  = isset($_GET['status'])        ? $_GET['status']             : 'all';
$page           = isset($_GET['page'])          ? max(1, (int)$_GET['page'])   : 1;
$per_page       = 25;
$offset         = ($page - 1) * $per_page;

$terms_for_year = getTermsForYear($conn, $selected_year);

// ══════════════════════════════════════════════════════════════════════════
//  CORE QUERY STRATEGY - FIXED FOR PAYMENTS TABLE
// ══════════════════════════════════════════════════════════════════════════

// ── Build filter WHERE conditions ─────────────────────────────────────────
$where_conditions = [];
$params           = [];
$types            = "";

if ($selected_year !== '') {
    $where_conditions[] = "f.academic_year = ?";
    $params[] = $selected_year;
    $types   .= "s";
}
if ($selected_term > 0) {
    $where_conditions[] = "f.term_id = ?";
    $params[] = $selected_term;
    $types   .= "i";
}
if ($selected_dept > 0 && isset($departments[$selected_dept])) {
    $where_conditions[] = "f.department = ?";
    $params[] = $departments[$selected_dept];
    $types   .= "s";
}
if ($selected_class > 0) {
    $where_conditions[] = "f.class_id = ?";
    $params[] = $selected_class;
    $types   .= "i";
}
if ($search_query !== '') {
    $like = "%{$search_query}%";
    $where_conditions[] = "(u.name LIKE ? OR s.student_name LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $types   .= "ss";
}

$where_clause = !empty($where_conditions)
    ? "WHERE " . implode(" AND ", $where_conditions)
    : "";

// ── Fee-matching logic ────────────────────────────────────────────────────
$fee_match_condition = "
    (
        (f.target_type = 'Learner'       AND f.user_id = u.id)
     OR (f.target_type = 'Bulk Learners' AND f.user_id = u.id)
     OR  f.target_type = 'School'
     OR (f.target_type = 'Class' AND EXISTS (
            SELECT 1 FROM student_classes scx
             WHERE scx.user_id       = u.id
               AND scx.class_id      = f.class_id
               AND scx.academic_year = f.academic_year
        ))
    )
";

// ── Base JOIN spine ───────────────────────────────────────────────────────
$base_joins = "
    FROM users u
    INNER JOIN students s        ON u.id = s.user_id
    INNER JOIN fees f            ON {$fee_match_condition}
    LEFT  JOIN student_classes sc ON sc.user_id       = u.id
                                 AND sc.academic_year  = f.academic_year
    LEFT  JOIN classes c         ON sc.class_id = c.id
    LEFT  JOIN departments d     ON c.department_id = d.id
";

$role_conditions = "
    AND u.role   = 'student'
    AND u.status = 'active'
";

// ── TOTAL PAYMENTS SUBQUERY (by user_id, across all fees) ────────────────
$total_payments_subquery = "
    COALESCE((
        SELECT SUM(p.amount_paid)
          FROM payments p
         WHERE p.user_id = u.id
    ), 0)
";

// ── PAYMENTS PER FEE SUBQUERY ────────────────────────────────────────────
$payments_per_fee_subquery = "
    COALESCE((
        SELECT SUM(p.amount_paid)
          FROM payments p
         WHERE p.user_id = u.id
           AND p.fee_id = f.id
    ), 0)
";

// ── DEBT SUBQUERY ────────────────────────────────────────────────────────
$debt_subquery = "
    COALESCE((
        SELECT SUM(db.amount_due)
          FROM debts db
         WHERE db.user_id = u.id
           AND db.fee_id = f.id
    ), 0)
";

// ── Pagination count ──────────────────────────────────────────────────────
$count_sql = "
    SELECT COUNT(DISTINCT u.id) AS total
    {$base_joins}
    {$where_clause}
    {$role_conditions}
";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_learners = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_learners / $per_page));

// ── Main paginated query ──────────────────────────────────────────────────
$main_sql = "
    SELECT
        u.id                                                AS learner_id,
        COALESCE(NULLIF(s.student_name, ''), u.name)        AS learner_name,
        u.admission_date,
        u.status                                            AS learner_status,
        sc.class_id,
        c.class_name,
        d.name                                              AS department,
        f.academic_year,
        f.term_id,
        COUNT(DISTINCT f.id)                                AS fee_count,
        COALESCE(SUM(f.amount), 0)                          AS total_fees_charged,
        {$total_payments_subquery}                          AS total_amount_paid,
        COALESCE(SUM({$payments_per_fee_subquery}), 0)      AS payments_per_fee_sum,
        COALESCE(SUM({$debt_subquery}), 0)                  AS total_debt_recorded
    {$base_joins}
    {$where_clause}
    {$role_conditions}
    GROUP BY u.id, f.academic_year
    ORDER BY learner_name ASC
    LIMIT ? OFFSET ?
";

$main_stmt = $conn->prepare($main_sql);
if ($main_stmt === false) {
    die("Prepare failed: " . $conn->error);
}

$types_paged = $types . "ii";
$params_paged = array_merge($params, [$per_page, $offset]);

if (!empty($params_paged)) {
    $main_stmt->bind_param($types_paged, ...$params_paged);
}
$main_stmt->execute();
$raw_rows = $main_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$main_stmt->close();

// ── Process rows + apply status filter ───────────────────────────────────
$learners = [];
foreach ($raw_rows as $row) {
    $row['class_display_name'] = classDisplay($row['class_name'] ?? '', $class_name_map);

    $charged = (float)$row['total_fees_charged'];
    $paid    = (float)$row['total_amount_paid'];

    $row['amount_outstanding'] = $charged - $paid;

    if ($charged > 0) {
        $pct = ($paid / $charged) * 100;
        $row['payment_status']     = $pct >= 100 ? 'fully_paid' : ($pct > 0 ? 'partially_paid' : 'unpaid');
        $row['payment_percentage'] = (int)round(min($pct, 100));
    } else {
        $row['payment_status']     = 'unpaid';
        $row['payment_percentage'] = 0;
    }

    if ($status_filter !== 'all' && $row['payment_status'] !== $status_filter) continue;
    $learners[] = $row;
}

// ── Grand totals ──────────────────────────────────────────────────────────
// Simplified totals query to avoid parameter issues
$totals_fees_sql = "
SELECT COALESCE(SUM(f_total.amount), 0) AS total_fees
FROM (
    SELECT DISTINCT f.id, f.amount
        FROM fees f
        INNER JOIN users u ON (
            (f.target_type = 'Learner' AND f.user_id = u.id)
         OR (f.target_type = 'Bulk Learners' AND f.user_id = u.id)
         OR f.target_type = 'School'
         OR (f.target_type = 'Class' AND EXISTS (
                SELECT 1 FROM student_classes scx
                WHERE scx.user_id = u.id
                AND scx.class_id = f.class_id
                AND scx.academic_year = f.academic_year
            ))
        )
        INNER JOIN students s ON u.id = s.user_id
        WHERE u.role = 'student' AND u.status = 'active'
        " . ($where_clause ? str_replace("WHERE", "AND", $where_clause) : "") . "
    ) f_total
";

$totals_fees_stmt = $conn->prepare($totals_fees_sql);
if (!empty($params) && $totals_fees_stmt) {
    $totals_fees_stmt->bind_param($types, ...$params);
}
$totals_fees_stmt->execute();
$total_fees_result = $totals_fees_stmt->get_result()->fetch_assoc();
$overall_total_fees = (float)($total_fees_result['total_fees'] ?? 0);
$totals_fees_stmt->close();

// Get total payments - simpler approach
$totals_payments_sql = "
    SELECT COALESCE(SUM(p.amount_paid), 0) AS total_paid
    FROM payments p
    WHERE p.user_id IN (
        SELECT DISTINCT u.id
        FROM users u
        INNER JOIN students s ON u.id = s.user_id
        INNER JOIN fees f ON (
            (f.target_type = 'Learner' AND f.user_id = u.id)
         OR (f.target_type = 'Bulk Learners' AND f.user_id = u.id)
         OR f.target_type = 'School'
         OR (f.target_type = 'Class' AND EXISTS (
                SELECT 1 FROM student_classes scx
                WHERE scx.user_id = u.id
                AND scx.class_id = f.class_id
                AND scx.academic_year = f.academic_year
            ))
        )
        WHERE u.role = 'student' AND u.status = 'active'
        " . ($where_clause ? str_replace("WHERE", "AND", $where_clause) : "") . "
    )
";

$totals_payments_stmt = $conn->prepare($totals_payments_sql);
if (!empty($params) && $totals_payments_stmt) {
    $totals_payments_stmt->bind_param($types, ...$params);
}
$totals_payments_stmt->execute();
$total_payments_result = $totals_payments_stmt->get_result()->fetch_assoc();
$overall_total_paid = (float)($total_payments_result['total_paid'] ?? 0);
$totals_payments_stmt->close();

$overall_total_due = $overall_total_fees - $overall_total_paid;

// ── Status counts ─────────────────────────────────────────────────────────
$status_counts = ['fully_paid' => 0, 'partially_paid' => 0, 'unpaid' => 0];
foreach ($raw_rows as $row) {
    $charged = (float)$row['total_fees_charged'];
    $paid    = (float)$row['total_amount_paid'];
    if ($charged > 0) {
        $pct = ($paid / $charged) * 100;
        $st  = $pct >= 100 ? 'fully_paid' : ($pct > 0 ? 'partially_paid' : 'unpaid');
    } else {
        $st = 'unpaid';
    }
    $status_counts[$st]++;
}

// ── AJAX: class list by dept ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'classes') {
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['dept_id'] ?? 0);
    $out = [];
    foreach ($classes as $id => $cls) {
        if ($dept_id === 0 || $cls['department_id'] === $dept_id) {
            $out[] = ['id' => $id, 'display_name' => $cls['display_name'], 'class_name' => $cls['class_name']];
        }
    }
    echo json_encode($out);
    exit;
}

// ── CSV export ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fees_report_' . $selected_year . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ["QUEEN'S PARADISE MONTESSORI SCHOOL – FEES & PAYMENTS REPORT"]);
    fputcsv($out, ['Academic Year:', $selected_year]);
    fputcsv($out, ['Generated:', date('F j, Y, g:i A')]);
    fputcsv($out, ['Generated By:', $_SESSION['user_name'] ?? 'Admin']);
    fputcsv($out, []);
    fputcsv($out, [
        'Learner Name', 'Class', 'Department',
        'Total Fees (GH₵)', 'Total Paid (GH₵)', 'Outstanding (GH₵)',
        'Payment %', 'Status', 'Fee Count', 'Admission Date'
    ]);
    foreach ($learners as $l) {
        fputcsv($out, [
            $l['learner_name'],
            $l['class_display_name'],
            $l['department'],
            number_format($l['total_fees_charged'], 2),
            number_format($l['total_amount_paid'],  2),
            number_format($l['amount_outstanding'],  2),
            $l['payment_percentage'] . '%',
            ucwords(str_replace('_', ' ', $l['payment_status'])),
            $l['fee_count'],
            $l['admission_date'] ?? 'N/A',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, [
        'TOTALS', '', '',
        number_format($overall_total_fees, 2),
        number_format($overall_total_paid, 2),
        number_format($overall_total_due,  2),
    ]);
    fclose($out);
    log_activity($_SESSION['user_id'], 'export_csv', 'Exported fees report CSV', $conn);
    exit;
}

// ── Pagination URL helper ─────────────────────────────────────────────────
function pageUrl(int $p, string $year, int $dept, int $class, int $term, string $search, string $status): string {
    $params = [
        'academic_year' => $year,
        'department_id' => $dept,
        'class_id'      => $class,
        'term_id'       => $term,
        'search'        => $search,
        'status'        => $status,
        'page'          => $p,
    ];
    // Remove empty parameters
    $params = array_filter($params, function($v) { return $v !== '' && $v !== '0'; });
    return "fees_payments.php?" . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees &amp; Payments – QPMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary:        #ff6200;
            --primary-dark:   #e55a00;
            --primary-light:  #fff5f0;
            --success:        #28a745;
            --warning:        #ffc107;
            --danger:         #dc3545;
            --info:           #17a2b8;
            --dark:           #2d3436;
            --border-radius:  16px;
            --shadow-sm:  0 2px 12px rgba(0,0,0,.04);
            --shadow-md:  0 6px 24px rgba(0,0,0,.08);
            --shadow-lg:  0 12px 36px rgba(0,0,0,.12);
            --transition: all .3s cubic-bezier(.4,0,.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: linear-gradient(135deg, #fff5f0 0%, #fff9f0 25%, #fefefe 100%);
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            color: var(--dark);
            -webkit-font-smoothing: antialiased;
        }
        .dashboard-wrapper { max-width: 1440px; margin: 0 auto; padding: 1rem; }

        .page-header {
            background: white; border-radius: var(--border-radius);
            padding: 1.5rem 2rem; margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md); border-left: 6px solid var(--primary);
            position: relative; overflow: hidden;
        }
        .page-header::after {
            content: ''; position: absolute; top: -50%; right: -5%;
            width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(255,98,0,.05) 0%, transparent 70%);
            border-radius: 50%;
        }
        .page-header h1 { color: var(--primary); font-weight: 800; font-size: clamp(1.4rem,2.5vw,2rem); position: relative; z-index: 1; }
        .page-header p  { color: #ff8c00; margin: .35rem 0 0; font-weight: 500; font-size: .95rem; position: relative; z-index: 1; }
        .header-badge {
            display: inline-flex; align-items: center; gap: .4rem;
            background: var(--primary-light); color: var(--primary);
            padding: .3rem .8rem; border-radius: 50px;
            font-size: .8rem; font-weight: 600; margin-top: .5rem; position: relative; z-index: 1;
        }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card {
            background: white; border-radius: var(--border-radius); padding: 1.25rem;
            box-shadow: var(--shadow-sm); position: relative; overflow: hidden;
            transition: var(--transition);
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; }
        .stat-card.c-fees::before         { background: linear-gradient(90deg,#ff6200,#ff8c00); }
        .stat-card.c-paid::before         { background: linear-gradient(90deg,#28a745,#4cd964); }
        .stat-card.c-outstanding::before  { background: linear-gradient(90deg,#dc3545,#ff6b6b); }
        .stat-card.c-learners::before     { background: linear-gradient(90deg,#17a2b8,#6f42c1); }
        .stat-icon { width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:white; margin-bottom:1rem; }
        .stat-card.c-fees        .stat-icon { background: linear-gradient(135deg,#ff6200,#ff8c00); }
        .stat-card.c-paid        .stat-icon { background: linear-gradient(135deg,#28a745,#4cd964); }
        .stat-card.c-outstanding .stat-icon { background: linear-gradient(135deg,#dc3545,#ff6b6b); }
        .stat-card.c-learners    .stat-icon { background: linear-gradient(135deg,#17a2b8,#6f42c1); }
        .stat-value { font-size: clamp(1.4rem,2.5vw,1.9rem); font-weight: 800; color: var(--dark); margin: .25rem 0; }
        .stat-label { font-size: .78rem; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; }
        .stat-sub   { font-size: .73rem; color: #999; margin-top: .2rem; }
        .stat-dots  { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .5rem; }
        .stat-dot-item { display:flex; align-items:center; gap:.3rem; font-size:.73rem; font-weight:600; }
        .dot { width:8px; height:8px; border-radius:50%; }

        .sync-badge {
            display: inline-flex; align-items: center; gap: .3rem;
            background: rgba(40,167,69,.1); color: #28a745;
            border: 1px solid rgba(40,167,69,.25);
            padding: .2rem .6rem; border-radius: 50px;
            font-size: .7rem; font-weight: 700; margin-left: .5rem;
        }

        .filter-card { background: white; border-radius: var(--border-radius); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
        .section-label { font-size: .73rem; text-transform: uppercase; letter-spacing: 1px; color: #999; font-weight: 700; margin-bottom: .3rem; }
        .filter-card .form-label  { font-weight: 600; color: #555; font-size: .83rem; margin-bottom: .3rem; }
        .filter-card .form-select,
        .filter-card .form-control {
            border: 2px solid #e8e8e8; border-radius: 10px; padding: .65rem 1rem;
            font-size: .88rem; transition: var(--transition); background: #fafafa; font-weight: 500;
        }
        .filter-card .form-select:focus,
        .filter-card .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 .2rem rgba(255,98,0,.1); background: white; }
        .filter-card .input-group-text  { background: white; border: 2px solid #e8e8e8; border-right: none; border-radius: 10px 0 0 10px; color: #999; }
        .filter-card .input-group .form-control { border-left: none; border-radius: 0 10px 10px 0; }

        .live-search-container { position: relative; }
        .search-results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e8e8e8;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow-md);
            display: none;
        }
        .search-results-dropdown.show { display: block; }
        .search-result-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: var(--transition);
        }
        .search-result-item:hover { background: var(--primary-light); }
        .search-result-name { font-weight: 600; color: var(--dark); }
        .search-result-id { font-size: 0.7rem; color: #999; }

        .status-filters { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
        .sf-btn {
            padding: .42rem .95rem; border-radius: 50px; border: 2px solid #e8e8e8;
            background: white; font-size: .78rem; font-weight: 600; cursor: pointer;
            transition: var(--transition); color: #666; text-decoration: none;
            display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap;
        }
        .sf-btn:hover, .sf-btn.active { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }
        .sf-btn.active { box-shadow: 0 2px 8px rgba(255,98,0,.15); }
        .sf-btn .cnt { background: #f0f0f0; padding: .13rem .45rem; border-radius: 50px; font-size: .68rem; font-weight: 700; }
        .sf-btn.active .cnt { background: var(--primary); color: white; }

        .btn-px {
            border-radius: 10px; font-weight: 600; font-size: .88rem;
            display: inline-flex; align-items: center; gap: .45rem;
            text-decoration: none; cursor: pointer; white-space: nowrap;
            padding: .65rem 1.35rem; border: none; transition: var(--transition);
        }
        .btn-px:hover { transform: translateY(-2px); }
        .btn-primary-g  { background: linear-gradient(135deg,#ff6200,#ff8c00); color: white; }
        .btn-primary-g:hover  { box-shadow: 0 8px 24px rgba(255,98,0,.3); color: white; }
        .btn-success-g  { background: linear-gradient(135deg,#28a745,#4cd964); color: white; }
        .btn-success-g:hover  { box-shadow: 0 8px 24px rgba(40,167,69,.3); color: white; }
        .btn-warning-g  { background: linear-gradient(135deg,#ffc107,#ffb300); color: #333; }
        .btn-warning-g:hover  { box-shadow: 0 8px 24px rgba(255,193,7,.3);  color: #333; }
        .btn-danger-g   { background: linear-gradient(135deg,#dc3545,#ff6b6b); color: white; }
        .btn-danger-g:hover   { box-shadow: 0 8px 24px rgba(220,53,69,.3);  color: white; }
        .btn-outline-o  { background: white; border: 2px solid var(--primary); color: var(--primary); }
        .btn-outline-o:hover  { background: var(--primary); color: white; box-shadow: 0 6px 20px rgba(255,98,0,.2); }
        .btn-sm-px { padding: .38rem .85rem; font-size: .78rem; border-radius: 8px; }
        .action-buttons { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.5rem; }

        .table-container { background: white; border-radius: var(--border-radius); box-shadow: var(--shadow-md); overflow: hidden; }
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table { margin: 0; font-size: .88rem; min-width: 960px; }
        .table thead th {
            background: linear-gradient(135deg,#2d3436,#434a54);
            color: white; font-weight: 600; text-transform: uppercase;
            font-size: .73rem; letter-spacing: .8px;
            padding: .9rem .75rem; border: none; vertical-align: middle; white-space: nowrap;
        }
        .table tbody td { padding: .82rem .75rem; vertical-align: middle; border-bottom: 1px solid #f0f0f0; }
        .table tbody tr { transition: background .2s; }
        .table tbody tr:hover { background: #fff9f0; }
        .table tbody tr.clickable-row { cursor: pointer; }
        .table tbody tr.clickable-row:hover { background: #fff5f0; }

        .debt-note { font-size: .63rem; color: #aaa; font-style: italic; margin-top: .15rem; }
        .debt-note.mismatch { color: #dc3545; }
        .credit-note { font-size: .63rem; color: #28a745; font-weight: 600; margin-top: .15rem; }

        .badge-st { padding:.32rem .7rem; border-radius:50px; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; gap:.3rem; white-space:nowrap; }
        .b-paid     { background:rgba(40,167,69,.1);  color:#28a745; border:1px solid rgba(40,167,69,.25); }
        .b-partial  { background:rgba(255,193,7,.1);  color:#cc9a06; border:1px solid rgba(255,193,7,.25); }
        .b-unpaid   { background:rgba(220,53,69,.1);  color:#dc3545; border:1px solid rgba(220,53,69,.25); }
        .badge-cls  { background:rgba(23,162,184,.1); color:#17a2b8; border:1px solid rgba(23,162,184,.2); padding:.23rem .55rem; border-radius:50px; font-size:.72rem; font-weight:600; }
        .badge-dept { background:#f8f9fa; color:#666; border:1px solid #ddd; padding:.23rem .55rem; border-radius:50px; font-size:.7rem; font-weight:600; }

        .prog-wrap { display:flex; align-items:center; gap:.45rem; min-width:110px; }
        .progress  { height:7px; background:#f0f0f0; border-radius:4px; flex:1; }
        .progress-bar { border-radius:4px; transition:width .6s ease; }
        .prog-pct  { font-size:.73rem; font-weight:700; color:#666; min-width:33px; text-align:right; }

        .learner-info { display:flex; align-items:center; gap:.6rem; }
        .learner-avatar {
            width:36px; height:36px; border-radius:10px;
            background: linear-gradient(135deg,var(--primary),#ff8c00);
            color:white; display:flex; align-items:center; justify-content:center;
            font-weight:700; font-size:.83rem; flex-shrink:0;
        }
        .learner-name { font-weight:600; color:var(--dark); }
        .learner-id   { font-size:.68rem; color:#999; }

        .summary-row { background:#fff9f0 !important; font-weight:700; }
        .summary-row td { border-top: 3px solid var(--primary) !important; border-bottom: 3px solid var(--primary) !important; }

        .empty-state { text-align:center; padding:3rem 1rem; color:#999; }
        .empty-state i { font-size:3.5rem; margin-bottom:1rem; color:#ddd; display:block; }
        .empty-state h5 { color:#666; font-weight:600; }

        .alert-custom { border-radius:12px; border:none; border-left:5px solid; padding:1rem 1.5rem; font-weight:500; font-size:.88rem; margin-bottom:1rem; }
        .alert-success { background:rgba(40,167,69,.06);  border-left-color:#28a745; color:#28a745; }
        .alert-danger  { background:rgba(220,53,69,.06);  border-left-color:#dc3545; color:#dc3545; }
        .alert-warning { background:rgba(255,193,7,.06);  border-left-color:#ffc107; color:#cc9a06; }

        .pagination-wrapper { display:flex; justify-content:center; align-items:center; padding:1rem; gap:.5rem; flex-wrap:wrap; }
        .pagination-wrapper .page-link { border-radius:8px; border:2px solid #e8e8e8; color:#666; font-weight:600; padding:.48rem .9rem; font-size:.83rem; transition:var(--transition); }
        .pagination-wrapper .page-link:hover { background:var(--primary-light); border-color:var(--primary); color:var(--primary); }
        .pagination-wrapper .page-item.active .page-link { background:var(--primary); border-color:var(--primary); color:white; }
        .pagination-wrapper .page-item.disabled .page-link { opacity:.5; pointer-events:none; }

        .results-info { font-size:.83rem; color:#666; font-weight:500; }
        .term-pill { display:inline-flex; align-items:center; gap:.3rem; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:.2rem .6rem; border-radius:50px; font-size:.68rem; font-weight:700; }

        .mobile-cards { display:none; }

        @media(max-width:992px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
        @media(max-width:768px) {
            .dashboard-wrapper { padding:.5rem; }
            .page-header { padding:1rem; border-radius:12px; }
            .page-header h1 { font-size:1.3rem; }
            .stats-grid { grid-template-columns: repeat(2,1fr); gap:.5rem; }
            .stat-card { padding:.85rem; }
            .action-buttons { flex-direction:column; gap:.5rem; }
            .btn-px { width:100%; justify-content:center; }
            .table-responsive { display:none; }
            .mobile-cards { display:block; }
        }
        @media(max-width:480px) { .stats-grid { grid-template-columns:1fr 1fr; gap:.4rem; } }
        @media print {
            .page-header, .filter-card, .action-buttons, .status-filters,
            .pagination-wrapper, .mobile-cards, .btn-px, .results-info { display:none !important; }
            .table-responsive { display:block !important; }
            .table { min-width:100%; font-size:.7rem; }
        }
        
        .payment-info-badge {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.2);
            padding: 0.2rem 0.5rem;
            border-radius: 50px;
            font-size: 0.68rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
    </style>
</head>
<body>
<?php
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
if (file_exists($header_path)) include $header_path;
?>

<div class="dashboard-wrapper">

    <div class="page-header">
        <h1>
            <i class="fas fa-money-bill-wave me-2"></i>Fees &amp; Payments Dashboard
            <span class="sync-badge"><i class="fas fa-sync-alt"></i> Live Balances</span>
            <span class="payment-info-badge"><i class="fas fa-info-circle"></i> Payments tracked by student</span>
        </h1>
        <p>Manage and monitor all learner fees, payments and outstanding balances</p>
        <div class="header-badge">
            <i class="fas fa-calendar-alt"></i>
            Academic Year: <?= htmlspecialchars($selected_year) ?>
        </div>
    </div>

    <?php foreach (['success_msg' => 'alert-success', 'error_msg' => 'alert-danger'] as $key => $cls): ?>
        <?php if (isset($_SESSION[$key])): ?>
            <div class="alert-custom <?= $cls ?> alert-dismissible fade show">
                <i class="fas <?= $cls === 'alert-success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                <?= htmlspecialchars($_SESSION[$key]) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION[$key]); ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (empty($academic_years)): ?>
        <div class="alert-custom alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No academic years found. Please add fees via <a href="add_fee.php">Add Fee</a> first.
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card c-fees">
            <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="stat-value">GH₵ <?= number_format($overall_total_fees, 2) ?></div>
            <div class="stat-label">Total Fees Charged</div>
            <div class="stat-sub">Across all learners</div>
        </div>
        <div class="stat-card c-paid">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value">GH₵ <?= number_format($overall_total_paid, 2) ?></div>
            <div class="stat-label">Total Collected</div>
            <div class="stat-sub"><?= $overall_total_fees > 0 ? round(($overall_total_paid / $overall_total_fees) * 100) : 0 ?>% of total fees</div>
        </div>
        <div class="stat-card c-outstanding">
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-value <?= $overall_total_due < 0 ? 'text-success' : 'text-danger' ?>">
                GH₵ <?= number_format(abs($overall_total_due), 2) ?>
                <?php if ($overall_total_due < 0): ?>
                    <small style="font-size:0.7rem;">(Credit)</small>
                <?php endif; ?>
            </div>
            <div class="stat-label"><?= $overall_total_due < 0 ? 'Overpayment' : 'Total Outstanding' ?></div>
            <div class="stat-sub">Yet to be collected</div>
        </div>
        <div class="stat-card c-learners">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?= $total_learners ?></div>
            <div class="stat-label">Total Learners</div>
            <div class="stat-dots">
                <span class="stat-dot-item"><span class="dot" style="background:#28a745;"></span><?= $status_counts['fully_paid'] ?> Paid</span>
                <span class="stat-dot-item"><span class="dot" style="background:#ffc107;"></span><?= $status_counts['partially_paid'] ?> Partial</span>
                <span class="stat-dot-item"><span class="dot" style="background:#dc3545;"></span><?= $status_counts['unpaid'] ?> Unpaid</span>
            </div>
        </div>
    </div>

    <div class="filter-card">
        <div class="section-label">FILTERS</div>
        <form method="GET" id="filterForm" class="row g-2 g-md-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="col-6 col-md-2">
                <label class="form-label">Academic Year</label>
                <select name="academic_year" class="form-select" onchange="onYearChange(this)">
                    <?php foreach ($academic_years as $yr): ?>
                        <option value="<?= htmlspecialchars($yr) ?>" <?= $selected_year === $yr ? 'selected' : '' ?>>
                            <?= htmlspecialchars($yr) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label">Term</label>
                <select name="term_id" class="form-select" id="termSelect">
                    <option value="0">All Terms</option>
                    <?php foreach ($terms_for_year as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $selected_term === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['term_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select" id="deptSelect" onchange="updateClasses()">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $id => $name): ?>
                        <option value="<?= $id ?>" <?= $selected_dept === $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label">Class</label>
                <select name="class_id" class="form-select" id="classSelect">
                    <option value="0">All Classes</option>
                    <?php foreach ($classes as $id => $cls): ?>
                        <option value="<?= $id ?>"
                                data-dept="<?= $cls['department_id'] ?>"
                                <?= $selected_class === $id ? 'selected' : '' ?>
                                <?= ($selected_dept > 0 && $cls['department_id'] !== $selected_dept) ? 'style="display:none"' : '' ?>>
                            <?= htmlspecialchars($cls['display_name']) ?> (<?= htmlspecialchars($cls['class_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label">Search Learner</label>
                <div class="live-search-container">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" id="liveSearchInput" class="form-control"
                               placeholder="Start typing name..." value="<?= htmlspecialchars($search_query) ?>"
                               autocomplete="off">
                    </div>
                    <div id="searchResults" class="search-results-dropdown"></div>
                </div>
            </div>

            <div class="col-3 col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn-px btn-primary-g w-100">
                    <i class="fas fa-filter"></i><span class="d-none d-md-inline"> Apply</span>
                </button>
            </div>
            <div class="col-3 col-md-1">
                <label class="form-label">&nbsp;</label>
                <a href="fees_payments.php" class="btn-px btn-outline-o w-100" title="Clear Filters">
                    <i class="fas fa-sync-alt"></i>
                </a>
            </div>
        </form>

        <div class="status-filters">
            <span style="font-size:.78rem;color:#999;font-weight:600;margin-right:.3rem;">Quick:</span>
            <?php
            $pill_data = [
                'all'            => ['label' => 'All',        'icon' => 'fa-list',        'count' => $total_learners],
                'fully_paid'     => ['label' => 'Fully Paid', 'icon' => 'fa-check-circle','count' => $status_counts['fully_paid']],
                'partially_paid' => ['label' => 'Partial',    'icon' => 'fa-clock',       'count' => $status_counts['partially_paid']],
                'unpaid'         => ['label' => 'Unpaid',     'icon' => 'fa-times-circle','count' => $status_counts['unpaid']],
            ];
            foreach ($pill_data as $val => $pill):
                $url = pageUrl($page, $selected_year, $selected_dept, $selected_class, $selected_term, $search_query, $val);
            ?>
                <a href="<?= $url ?>" class="sf-btn <?= $status_filter === $val ? 'active' : '' ?>">
                    <i class="fas <?= $pill['icon'] ?>"></i> <?= $pill['label'] ?>
                    <span class="cnt"><?= $pill['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="action-buttons">
        <a href="add_fee.php"       class="btn-px btn-primary-g"><i class="fas fa-plus-circle"></i> Add New Fee</a>
        <a href="pay_fee.php"       class="btn-px btn-success-g"><i class="fas fa-money-bill-wave"></i> Record Payment</a>
        <a href="bulk_reminder.php" class="btn-px btn-warning-g"><i class="fas fa-sms"></i> Bulk Reminders</a>
        <a href="view_debts.php"    class="btn-px btn-danger-g"><i class="fas fa-exclamation-triangle"></i> View Debts</a>
        <a href="view_payments.php" class="btn-px btn-outline-o"><i class="fas fa-list"></i> All Payments</a>
        <a href="<?= pageUrl($page, $selected_year, $selected_dept, $selected_class, $selected_term, $search_query, $status_filter) ?>&export=csv"
           class="btn-px btn-outline-o"><i class="fas fa-file-csv"></i> Export CSV</a>
        <button onclick="window.print()" class="btn-px btn-outline-o"><i class="fas fa-print"></i> Print</button>
    </div>

    <div class="results-info mb-2">
        Showing <?= count($learners) ?> of <?= $total_learners ?> learner<?= $total_learners !== 1 ? 's' : '' ?>
        <?= $search_query ? ' matching "' . htmlspecialchars($search_query) . '"' : '' ?>
        <?= $status_filter !== 'all' ? ' · Status: <strong>' . ucwords(str_replace('_', ' ', $status_filter)) . '</strong>' : '' ?>
        <?php if ($selected_term > 0):
            $t_label = array_column($terms_for_year, 'term_name', 'id')[$selected_term] ?? 'Term'; ?>
            · <span class="term-pill"><i class="fas fa-calendar-week"></i> <?= htmlspecialchars($t_label) ?></span>
        <?php endif; ?>
    </div>

    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Learner</th>
                        <th>Class</th>
                        <th>Dept</th>
                        <th>Term</th>
                        <th>Total Fees</th>
                        <th>Paid</th>
                        <th>Outstanding</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($learners)): ?>
                    <tr>
                        <td colspan="11">
                            <div class="empty-state">
                                <i class="fas fa-search"></i>
                                <h5>No Learners Found</h5>
                                <p class="mt-1">Adjust the filters or <a href="add_fee.php">add new fees</a>.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $term_lookup = [];
                    foreach ($terms_for_year as $t) $term_lookup[(int)$t['id']] = $t['term_name'];

                    $counter = $offset + 1;
                    foreach ($learners as $lrn):
                        $st_class = match($lrn['payment_status']) {
                            'fully_paid'     => 'b-paid',
                            'partially_paid' => 'b-partial',
                            default          => 'b-unpaid',
                        };
                        $st_icon = match($lrn['payment_status']) {
                            'fully_paid'     => 'fa-check-circle',
                            'partially_paid' => 'fa-clock',
                            default          => 'fa-times-circle',
                        };
                        $st_label = match($lrn['payment_status']) {
                            'fully_paid'     => 'Fully Paid',
                            'partially_paid' => 'Partially Paid',
                            default          => 'Unpaid',
                        };
                        $prog_cls = match($lrn['payment_status']) {
                            'fully_paid'     => 'bg-success',
                            'partially_paid' => 'bg-warning',
                            default          => 'bg-danger',
                        };

                        $initials    = strtoupper(mb_substr($lrn['learner_name'], 0, 2));
                        $details_url = "fee_details.php?learner_id={$lrn['learner_id']}&academic_year=" . urlencode($lrn['academic_year']) . "&show_all=1";
                        $term_label  = $term_lookup[$lrn['term_id']] ?? '—';

                        $live_outstanding  = $lrn['amount_outstanding'];
                        $debt_recorded     = (float)$lrn['total_debt_recorded'];
                        $debt_synced       = (abs($live_outstanding - $debt_recorded) < 0.01);
                        $is_credit         = $live_outstanding < 0;
                    ?>
                    <tr class="clickable-row" onclick="window.location='<?= $details_url ?>'" title="View full fee details">
                        <td><?= $counter++ ?></td>
                        <td>
                            <div class="learner-info">
                                <div class="learner-avatar"><?= htmlspecialchars($initials) ?></div>
                                <div>
                                    <div class="learner-name"><?= htmlspecialchars($lrn['learner_name']) ?></div>
                                    <div class="learner-id">ID: <?= $lrn['learner_id'] ?> · <?= $lrn['fee_count'] ?> fee<?= $lrn['fee_count'] != 1 ? 's' : '' ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge-cls"><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($lrn['class_display_name']) ?></span></td>
                        <td><span class="badge-dept"><?= htmlspecialchars($lrn['department'] ?? 'N/A') ?></span></td>
                        <td><span class="term-pill"><i class="fas fa-calendar-week"></i> <?= htmlspecialchars($term_label) ?></span></td>
                        <td><strong>GH₵ <?= number_format($lrn['total_fees_charged'], 2) ?></strong></td>
                        <td class="text-success"><strong>GH₵ <?= number_format($lrn['total_amount_paid'], 2) ?></strong>
                            <?php if ((float)$lrn['payments_per_fee_sum'] != (float)$lrn['total_amount_paid']): ?>
                                <div class="credit-note" style="font-size:0.6rem;">
                                    <i class="fas fa-info-circle"></i> Cross-fee payment
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong class="<?= $is_credit ? 'text-success' : 'text-danger' ?>">
                                GH₵ <?= number_format(abs($live_outstanding), 2) ?>
                                <?php if ($is_credit): ?>
                                    <small>(Credit)</small>
                                <?php endif; ?>
                            </strong>
                            <?php if (!$debt_synced && !$is_credit): ?>
                                <div class="debt-note mismatch" title="Debts table has GH₵ <?= number_format($debt_recorded,2) ?> — may need sync">
                                    <i class="fas fa-exclamation-circle"></i> debt record differs
                                </div>
                            <?php endif; ?>
                            <?php if ($is_credit): ?>
                                <div class="credit-note">
                                    <i class="fas fa-check-circle"></i> Overpayment Credit
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="prog-wrap">
                                <div class="progress">
                                    <div class="progress-bar <?= $prog_cls ?>" style="width:<?= min(100, $lrn['payment_percentage']) ?>%"></div>
                                </div>
                                <span class="prog-pct"><?= min(100, $lrn['payment_percentage']) ?>%</span>
                            </div>
                        </td>
                        <td><span class="badge-st <?= $st_class ?>"><i class="fas <?= $st_icon ?>"></i> <?= $st_label ?></span></td>
                        <td>
                            <a href="<?= $details_url ?>" class="btn-px btn-primary-g btn-sm-px"
                               onclick="event.stopPropagation();" title="View fee history">
                                <i class="fas fa-eye"></i> Details
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <tr class="summary-row">
                        <td colspan="5" class="text-end"><strong>GRAND TOTALS:</strong></td>
                        <td><strong>GH₵ <?= number_format($overall_total_fees, 2) ?></strong></td>
                        <td class="text-success"><strong>GH₵ <?= number_format($overall_total_paid, 2) ?></strong></td>
                        <td class="<?= $overall_total_due < 0 ? 'text-success' : 'text-danger' ?>">
                            <strong>GH₵ <?= number_format(abs($overall_total_due), 2) ?></strong>
                            <?php if ($overall_total_due < 0): ?>
                                <small>(Credit)</small>
                            <?php endif; ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mobile-cards">
        <?php if (empty($learners)): ?>
            <div style="text-align:center;padding:2rem;background:white;border-radius:12px;">
                <i class="fas fa-search" style="font-size:2rem;color:#ddd;"></i>
                <h5 style="color:#666;margin-top:.5rem;">No Learners Found</h5>
            </div>
        <?php else: ?>
            <?php
            $mc_n = $offset + 1;
            foreach ($learners as $lrn):
                $st_class = match($lrn['payment_status']) { 'fully_paid' => 'b-paid', 'partially_paid' => 'b-partial', default => 'b-unpaid' };
                $st_icon  = match($lrn['payment_status']) { 'fully_paid' => 'fa-check-circle', 'partially_paid' => 'fa-clock', default => 'fa-times-circle' };
                $st_label = match($lrn['payment_status']) { 'fully_paid' => 'Paid', 'partially_paid' => 'Partial', default => 'Unpaid' };
                $prog_cls = match($lrn['payment_status']) { 'fully_paid' => 'bg-success', 'partially_paid' => 'bg-warning', default => 'bg-danger' };
                $initials    = strtoupper(mb_substr($lrn['learner_name'], 0, 2));
                $details_url = "fee_details.php?learner_id={$lrn['learner_id']}&academic_year=" . urlencode($lrn['academic_year']) . "&show_all=1";
                $term_label  = $term_lookup[$lrn['term_id']] ?? '—';
                $live_outstanding = $lrn['amount_outstanding'];
                $is_credit = $live_outstanding < 0;
            ?>
            <div style="background:white;border-radius:12px;padding:1rem;margin-bottom:.75rem;box-shadow:var(--shadow-sm);cursor:pointer;"
                 onclick="window.location='<?= $details_url ?>'">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#ff6200,#ff8c00);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:.93rem;"><?= htmlspecialchars($lrn['learner_name']) ?></div>
                            <div style="font-size:.72rem;color:#999;">
                                <i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($lrn['class_display_name']) ?>
                                &nbsp;·&nbsp; <?= htmlspecialchars($term_label) ?>
                            </div>
                        </div>
                    </div>
                    <span class="badge-st <?= $st_class ?>"><i class="fas <?= $st_icon ?>"></i> <?= $st_label ?></span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;margin-bottom:.75rem;">
                    <?php foreach ([
                        ['Total Fees', 'total_fees_charged', ''],
                        ['Paid',       'total_amount_paid',  'text-success'],
                        ['Owing',      'amount_outstanding', $is_credit ? 'text-success' : 'text-danger'],
                    ] as [$lbl, $key, $cls]): ?>
                    <div style="text-align:center;padding:.5rem;background:#fafafa;border-radius:8px;">
                        <div style="font-size:.62rem;text-transform:uppercase;color:#999;font-weight:600;"><?= $lbl ?></div>
                        <div style="font-size:.85rem;font-weight:700;" class="<?= $cls ?>">
                            GH₵ <?= number_format(abs($lrn[$key]), 2) ?>
                            <?php if ($key === 'amount_outstanding' && $is_credit): ?>
                                <small style="font-size:0.6rem;">Credit</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-bottom:.65rem;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:.2rem;">
                        <small style="font-size:.68rem;color:#999;">Progress</small>
                        <small style="font-weight:700;font-size:.72rem;"><?= min(100, $lrn['payment_percentage']) ?>%</small>
                    </div>
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar <?= $prog_cls ?>" style="width:<?= min(100, $lrn['payment_percentage']) ?>%"></div>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <small style="color:#999;font-size:.68rem;">#<?= $mc_n++ ?> · <?= $lrn['fee_count'] ?> fee<?= $lrn['fee_count'] != 1 ? 's' : '' ?></small>
                    <a href="<?= $details_url ?>" class="btn-px btn-primary-g btn-sm-px" onclick="event.stopPropagation();">
                        <i class="fas fa-eye"></i> Details
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination-wrapper">
        <nav><ul class="pagination mb-0">
            <?php
            $prev = $page > 1;
            $next = $page < $total_pages;
            ?>
            <li class="page-item <?= $prev ? '' : 'disabled' ?>">
                <a class="page-link" href="<?= $prev ? pageUrl($page-1,$selected_year,$selected_dept,$selected_class,$selected_term,$search_query,$status_filter) : '#' ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
            <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if ($start > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= pageUrl(1,$selected_year,$selected_dept,$selected_class,$selected_term,$search_query,$status_filter) ?>">1</a></li>
                <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= pageUrl($i,$selected_year,$selected_dept,$selected_class,$selected_term,$search_query,$status_filter) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($end < $total_pages): ?>
                <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= pageUrl($total_pages,$selected_year,$selected_dept,$selected_class,$selected_term,$search_query,$status_filter) ?>"><?= $total_pages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $next ? '' : 'disabled' ?>">
                <a class="page-link" href="<?= $next ? pageUrl($page+1,$selected_year,$selected_dept,$selected_class,$selected_term,$search_query,$status_filter) : '#' ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>

</div>

<?php
$footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
if (file_exists($footer_path)) include $footer_path;
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateClasses() {
    const deptId = document.getElementById('deptSelect').value;
    const clsSel = document.getElementById('classSelect');
    clsSel.querySelectorAll('option').forEach(opt => {
        if (opt.value === '0') return;
        const match = (deptId === '0' || opt.dataset.dept === deptId);
        opt.style.display = match ? '' : 'none';
        if (!match && opt.selected) clsSel.value = '0';
    });
}

function onYearChange(sel) {
    const year    = sel.value;
    const termSel = document.getElementById('termSelect');
    termSel.innerHTML = '<option value="0">Loading…</option>';

    fetch('add_fee.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action:     'get_terms_by_year',
            year_label: year,
            csrf_token: '<?= htmlspecialchars($csrf_token) ?>'
        })
    })
    .then(r => r.json())
    .then(data => {
        termSel.innerHTML = '<option value="0">All Terms</option>';
        if (data.success && data.terms) {
            data.terms.forEach(t => {
                const o = document.createElement('option');
                o.value       = t.id;
                o.textContent = t.term_name;
                termSel.appendChild(o);
            });
        }
        document.getElementById('filterForm').submit();
    })
    .catch(() => {
        termSel.innerHTML = '<option value="0">All Terms</option>';
        document.getElementById('filterForm').submit();
    });
}

let searchTimeout;
const searchInput = document.getElementById('liveSearchInput');
const searchResults = document.getElementById('searchResults');

function getCurrentFilters() {
    return {
        academic_year: document.querySelector('select[name="academic_year"]')?.value || '',
        department_id: document.getElementById('deptSelect')?.value || '0',
        class_id: document.getElementById('classSelect')?.value || '0',
        term_id: document.getElementById('termSelect')?.value || '0'
    };
}

async function performLiveSearch(term) {
    if (term.length < 2) {
        searchResults.classList.remove('show');
        searchResults.innerHTML = '';
        return;
    }
    
    const filters = getCurrentFilters();
    
    try {
        const params = new URLSearchParams({
            ajax: 'search',
            term: term,
            academic_year: filters.academic_year,
            department_id: filters.department_id,
            class_id: filters.class_id,
            term_id: filters.term_id
        });
        
        const response = await fetch(`fees_payments.php?${params}`);
        const data = await response.json();
        
        if (data.length > 0) {
            searchResults.innerHTML = data.map(student => `
                <div class="search-result-item" data-learner-id="${student.learner_id}">
                    <div class="search-result-name">${escapeHtml(student.learner_name)}</div>
                    <div class="search-result-id">ID: ${student.learner_id} · ${student.admission_date || 'No date'}</div>
                </div>
            `).join('');
            searchResults.classList.add('show');
            
            document.querySelectorAll('.search-result-item').forEach(item => {
                item.addEventListener('click', function() {
                    const learnerId = this.dataset.learnerId;
                    const learnerName = this.querySelector('.search-result-name')?.innerText || '';
                    searchInput.value = learnerName;
                    searchResults.classList.remove('show');
                    const hiddenSearch = document.createElement('input');
                    hiddenSearch.type = 'hidden';
                    hiddenSearch.name = 'search';
                    hiddenSearch.value = learnerName;
                    document.getElementById('filterForm').appendChild(hiddenSearch);
                    document.getElementById('filterForm').submit();
                });
            });
        } else {
            searchResults.innerHTML = '<div class="search-result-item" style="color:#999;">No learners found</div>';
            searchResults.classList.add('show');
        }
    } catch (error) {
        console.error('Live search error:', error);
        searchResults.innerHTML = '<div class="search-result-item" style="color:#dc3545;">Search error</div>';
        searchResults.classList.add('show');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

searchInput.addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    const term = e.target.value.trim();
    searchTimeout = setTimeout(() => performLiveSearch(term), 300);
});

document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        searchResults.classList.remove('show');
    }
});

document.addEventListener('DOMContentLoaded', function () {
    updateClasses();

    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(el => {
            const a = bootstrap.Alert.getOrCreateInstance(el);
            if (a) a.close();
        });
    }, 5000);

    document.querySelectorAll('[onclick]').forEach(card => {
        card.addEventListener('touchstart', () => card.style.opacity = '.85', { passive: true });
        card.addEventListener('touchend',   () => card.style.opacity = '1',   { passive: true });
    });
});
</script>
</body>
</html>