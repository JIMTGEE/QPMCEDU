<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// --- Database & log_activity setup (same as original) ---
if (!$conn || $conn->connect_error) {
    die("System error: Database connection failed.");
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) require_once($log_activity_path);
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $details, $conn = null) {
        $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
    }
}
log_activity($_SESSION['user_id'], 'page_access', 'Accessed view_debts.php', $conn);

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Fetch filter data (academic years, departments, classes, fee types) ---
$academic_years = [];
$yr_res = $conn->query("SELECT DISTINCT year FROM academic_calendar WHERE year REGEXP '^[0-9]{4}/[0-9]{4}$' ORDER BY year DESC");
if ($yr_res) { while ($r = $yr_res->fetch_assoc()) $academic_years[] = $r['year']; $yr_res->free(); }

$departments = [];
$dept_res = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if ($dept_res) { while ($r = $dept_res->fetch_assoc()) $departments[$r['id']] = $r['name']; $dept_res->free(); }

$classes = [];
$cls_res = $conn->query("SELECT id, class_name, department_id FROM classes ORDER BY class_name ASC");
if ($cls_res) {
    while ($r = $cls_res->fetch_assoc()) {
        $classes[$r['id']] = ['class_name' => $r['class_name'], 'department_id' => $r['department_id']];
    }
    $cls_res->free();
}

$fee_types = [];
$ft_res = $conn->query("SELECT DISTINCT fee_type FROM fees WHERE fee_type IS NOT NULL AND fee_type != '' ORDER BY fee_type ASC");
if ($ft_res) { while ($r = $ft_res->fetch_assoc()) $fee_types[] = $r['fee_type']; $ft_res->free(); }

// --- Class name mapping (for display) ---
$class_display_map = [
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
    // aliases
    'Sheba 1'           => 'Nursery 1',
    'Sheba 2'           => 'Nursery 2',
    'Yaasant 1'         => 'KG 1',
    'Yaasant 2'         => 'KG 2',
    'Cleopatra 1'       => 'Basic 1',
    'Cleopatra 2'       => 'Basic 2',
    'Nefertiti 1'       => 'Basic 3',
    'Nefertiti 2'       => 'Basic 4',
    'Malika 1'          => 'Basic 5',
    'Malika 2'          => 'Basic 6',
    'Makeda 1'          => 'Basic 7',
    'Makeda 2'          => 'Basic 8',
];
function classDisplay($db_name) {
    global $class_display_map;
    return $class_display_map[$db_name] ?? $db_name;
}

// --- AJAX handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token) {
    header('Content-Type: application/json');
    
    // Filter classes by department
    if ($_POST['action'] === 'filter_classes') {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $filtered = [];
        foreach ($classes as $id => $cls) {
            if ($dept_id == 0 || $cls['department_id'] == $dept_id) {
                $filtered[] = ['id' => $id, 'class_name' => $cls['class_name']];
            }
        }
        echo json_encode(['success' => true, 'classes' => $filtered]);
        exit;
    }
    
    // Filter debts
    if ($_POST['action'] === 'filter_debts') {
        $academic_year = trim($_POST['academic_year'] ?? '');
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $fee_type = trim($_POST['fee_type'] ?? '');
        $status_filter = trim($_POST['status_filter'] ?? '');
        $search = trim($_POST['search'] ?? '');
        
        $debts = [];
        $summary = ['total_students' => 0, 'total_due' => 0, 'total_paid' => 0, 'total_balance' => 0,
                    'paid_count' => 0, 'partial_count' => 0, 'unpaid_count' => 0];
        
        $sql = "SELECT d.id, d.user_id, d.fee_id, d.amount_due, d.academic_year, d.class_id,
                       u.name AS student_name, c.class_name, f.fee_type, f.department,
                       COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
                       u.email AS student_email, u.phone_number AS student_phone
                FROM debts d
                JOIN users u ON d.user_id = u.id
                JOIN classes c ON d.class_id = c.id
                JOIN fees f ON d.fee_id = f.id
                LEFT JOIN payments p ON p.fee_id = d.fee_id AND p.user_id = d.user_id
                WHERE u.role = 'student' AND u.status = 'active'";
        $params = [];
        $types = "";
        
        if ($academic_year && in_array($academic_year, $academic_years)) {
            $sql .= " AND d.academic_year = ?";
            $params[] = $academic_year;
            $types .= "s";
        } else {
            $academic_year = $academic_years[0] ?? '';
            if ($academic_year) {
                $sql .= " AND d.academic_year = ?";
                $params[] = $academic_year;
                $types .= "s";
            }
        }
        if ($dept_id > 0) {
            $sql .= " AND c.department_id = ?";
            $params[] = $dept_id;
            $types .= "i";
        }
        if ($class_id > 0) {
            $sql .= " AND d.class_id = ?";
            $params[] = $class_id;
            $types .= "i";
        }
        if ($fee_type) {
            $sql .= " AND f.fee_type = ?";
            $params[] = $fee_type;
            $types .= "s";
        }
        if ($search) {
            $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ? OR f.fee_type LIKE ?)";
            $like = "%$search%";
            $params = array_merge($params, [$like, $like, $like, $like]);
            $types .= "ssss";
        }
        $sql .= " GROUP BY d.id, d.user_id, d.fee_id ORDER BY c.class_name, u.name LIMIT 1000";
        
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $students_seen = [];
        while ($row = $res->fetch_assoc()) {
            $balance = $row['amount_due'] - $row['amount_paid'];
            $status = $balance <= 0 ? 'Paid' : ($row['amount_paid'] > 0 ? 'Partial' : 'Unpaid');
            if ($status_filter && $status !== $status_filter) continue;
            
            $row['balance'] = $balance;
            $row['status'] = $status;
            $row['payment_percentage'] = $row['amount_due'] > 0 ? ($row['amount_paid'] / $row['amount_due'] * 100) : 0;
            $row['class_display'] = classDisplay($row['class_name']);
            $debts[] = $row;
            
            $students_seen[$row['user_id']] = true;
            $summary['total_due'] += $row['amount_due'];
            $summary['total_paid'] += $row['amount_paid'];
            $summary['total_balance'] += $balance;
            if ($status === 'Paid') $summary['paid_count']++;
            elseif ($status === 'Partial') $summary['partial_count']++;
            else $summary['unpaid_count']++;
        }
        $stmt->close();
        $summary['total_students'] = count($students_seen);
        
        echo json_encode(['success' => true, 'debts' => $debts, 'summary' => $summary]);
        exit;
    }
}

// --- Initial load (default academic year) ---
$default_year = $academic_years[0] ?? '';
$initial_debts = [];
$initial_summary = ['total_students' => 0, 'total_due' => 0, 'total_paid' => 0, 'total_balance' => 0,
                    'paid_count' => 0, 'partial_count' => 0, 'unpaid_count' => 0];
if ($default_year) {
    // similar query as above but without filters
    $sql = "SELECT d.id, d.user_id, d.fee_id, d.amount_due, d.academic_year, d.class_id,
                   u.name AS student_name, c.class_name, f.fee_type, f.department,
                   COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
                   u.email AS student_email, u.phone_number AS student_phone
            FROM debts d
            JOIN users u ON d.user_id = u.id
            JOIN classes c ON d.class_id = c.id
            JOIN fees f ON d.fee_id = f.id
            LEFT JOIN payments p ON p.fee_id = d.fee_id AND p.user_id = d.user_id
            WHERE u.role = 'student' AND u.status = 'active' AND d.academic_year = ?
            GROUP BY d.id, d.user_id, d.fee_id ORDER BY c.class_name, u.name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $default_year);
    $stmt->execute();
    $res = $stmt->get_result();
    $seen = [];
    while ($row = $res->fetch_assoc()) {
        $balance = $row['amount_due'] - $row['amount_paid'];
        $status = $balance <= 0 ? 'Paid' : ($row['amount_paid'] > 0 ? 'Partial' : 'Unpaid');
        $row['balance'] = $balance;
        $row['status'] = $status;
        $row['payment_percentage'] = $row['amount_due'] > 0 ? ($row['amount_paid'] / $row['amount_due'] * 100) : 0;
        $row['class_display'] = classDisplay($row['class_name']);
        $initial_debts[] = $row;
        
        $seen[$row['user_id']] = true;
        $initial_summary['total_due'] += $row['amount_due'];
        $initial_summary['total_paid'] += $row['amount_paid'];
        $initial_summary['total_balance'] += $balance;
        if ($status === 'Paid') $initial_summary['paid_count']++;
        elseif ($status === 'Partial') $initial_summary['partial_count']++;
        else $initial_summary['unpaid_count']++;
    }
    $initial_summary['total_students'] = count($seen);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debt Management – QueensPa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ff6200;
            --primary-dark: #e55a00;
            --primary-light: #fff5f0;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --border-radius: 16px;
            --shadow-sm: 0 2px 12px rgba(0,0,0,.04);
            --shadow-md: 0 6px 24px rgba(0,0,0,.08);
            --shadow-lg: 0 12px 36px rgba(0,0,0,.12);
            --transition: all .3s cubic-bezier(.4,0,.2,1);
        }
        body {
            background: linear-gradient(135deg, #fff5f0 0%, #fff9f0 25%, #fefefe 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            color: #2d3436;
        }
        .dashboard-wrapper { max-width: 1440px; margin: 0 auto; padding: 1rem; }
        .page-header {
            background: white; border-radius: var(--border-radius); padding: 1.5rem 2rem;
            margin-bottom: 1.5rem; box-shadow: var(--shadow-md); border-left: 6px solid var(--primary);
            position: relative; overflow: hidden;
        }
        .page-header::after {
            content: ''; position: absolute; top: -50%; right: -5%; width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(255,98,0,.05) 0%, transparent 70%); border-radius: 50%;
        }
        .page-header h1 { color: var(--primary); font-weight: 800; font-size: clamp(1.4rem,2.5vw,2rem); }
        .page-header p  { color: #ff8c00; margin: .35rem 0 0; font-weight: 500; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card {
            background: white; border-radius: var(--border-radius); padding: 1.25rem;
            box-shadow: var(--shadow-sm); transition: var(--transition); position: relative; overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; }
        .stat-card.c-fees::before     { background: linear-gradient(90deg,#ff6200,#ff8c00); }
        .stat-card.c-paid::before     { background: linear-gradient(90deg,#28a745,#4cd964); }
        .stat-card.c-outstanding::before { background: linear-gradient(90deg,#dc3545,#ff6b6b); }
        .stat-card.c-learners::before { background: linear-gradient(90deg,#17a2b8,#6f42c1); }
        .stat-icon { width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:white; margin-bottom:1rem; }
        .stat-card.c-fees .stat-icon { background: linear-gradient(135deg,#ff6200,#ff8c00); }
        .stat-card.c-paid .stat-icon { background: linear-gradient(135deg,#28a745,#4cd964); }
        .stat-card.c-outstanding .stat-icon { background: linear-gradient(135deg,#dc3545,#ff6b6b); }
        .stat-card.c-learners .stat-icon { background: linear-gradient(135deg,#17a2b8,#6f42c1); }
        .stat-value { font-size: clamp(1.4rem,2.5vw,1.9rem); font-weight: 800; margin: .25rem 0; }
        .stat-label { font-size: .78rem; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; }
        .filter-card { background: white; border-radius: var(--border-radius); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
        .section-label { font-size: .73rem; text-transform: uppercase; letter-spacing: 1px; color: #999; font-weight: 700; margin-bottom: .3rem; }
        .filter-card .form-label { font-weight: 600; color: #555; font-size: .83rem; margin-bottom: .3rem; }
        .filter-card .form-select, .filter-card .form-control {
            border: 2px solid #e8e8e8; border-radius: 10px; padding: .65rem 1rem;
            font-size: .88rem; background: #fafafa; transition: var(--transition);
        }
        .filter-card .form-select:focus, .filter-card .form-control:focus {
            border-color: var(--primary); box-shadow: 0 0 0 .2rem rgba(255,98,0,.1); background: white;
        }
        .status-filters { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
        .sf-btn {
            padding: .42rem .95rem; border-radius: 50px; border: 2px solid #e8e8e8; background: white;
            font-size: .78rem; font-weight: 600; cursor: pointer; transition: var(--transition);
            color: #666; text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;
        }
        .sf-btn:hover, .sf-btn.active { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }
        .sf-btn .cnt { background: #f0f0f0; padding: .13rem .45rem; border-radius: 50px; font-size: .68rem; font-weight: 700; }
        .sf-btn.active .cnt { background: var(--primary); color: white; }
        .btn-px {
            border-radius: 10px; font-weight: 600; font-size: .88rem; display: inline-flex; align-items: center;
            gap: .45rem; text-decoration: none; padding: .65rem 1.35rem; transition: var(--transition); border: none;
        }
        .btn-px:hover { transform: translateY(-2px); }
        .btn-primary-g { background: linear-gradient(135deg,#ff6200,#ff8c00); color: white; }
        .btn-primary-g:hover { box-shadow: 0 8px 24px rgba(255,98,0,.3); color: white; }
        .btn-success-g { background: linear-gradient(135deg,#28a745,#4cd964); color: white; }
        .btn-outline-o { background: white; border: 2px solid var(--primary); color: var(--primary); }
        .btn-outline-o:hover { background: var(--primary); color: white; }
        .table-container { background: white; border-radius: var(--border-radius); box-shadow: var(--shadow-md); overflow: hidden; }
        .table-responsive { overflow-x: auto; }
        .table { margin: 0; font-size: .88rem; min-width: 1000px; }
        .table thead th {
            background: linear-gradient(135deg,#2d3436,#434a54); color: white; font-weight: 600;
            text-transform: uppercase; font-size: .73rem; letter-spacing: .8px; padding: .9rem .75rem; border: none;
        }
        .table tbody td { padding: .82rem .75rem; vertical-align: middle; border-bottom: 1px solid #f0f0f0; }
        .table tbody tr:hover { background: #fff9f0; cursor: pointer; }
        .badge-st { padding:.32rem .7rem; border-radius:50px; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; gap:.3rem; }
        .b-paid { background:rgba(40,167,69,.1); color:#28a745; border:1px solid rgba(40,167,69,.25); }
        .b-partial { background:rgba(255,193,7,.1); color:#cc9a06; border:1px solid rgba(255,193,7,.25); }
        .b-unpaid { background:rgba(220,53,69,.1); color:#dc3545; border:1px solid rgba(220,53,69,.25); }
        .badge-cls { background:rgba(23,162,184,.1); color:#17a2b8; border:1px solid rgba(23,162,184,.2); padding:.23rem .55rem; border-radius:50px; font-size:.72rem; font-weight:600; }
        .progress { height:7px; background:#f0f0f0; border-radius:4px; }
        .prog-wrap { display:flex; align-items:center; gap:.45rem; min-width:100px; }
        .prog-pct { font-size:.73rem; font-weight:700; color:#666; min-width:33px; text-align:right; }
        .mobile-cards { display: none; }
        .summary-row { background: #fff9f0 !important; font-weight: 700; }
        .summary-row td { border-top: 3px solid var(--primary) !important; border-bottom: 3px solid var(--primary) !important; }
        @media (max-width: 768px) {
            .dashboard-wrapper { padding: .5rem; }
            .stats-grid { grid-template-columns: repeat(2,1fr); gap: .5rem; }
            .table-responsive { display: none; }
            .mobile-cards { display: block; }
            .btn-px { width: 100%; justify-content: center; }
            .action-buttons { flex-direction: column; gap: .5rem; }
        }
        @media print {
            .page-header, .filter-card, .action-buttons, .status-filters, .pagination-wrapper, .mobile-cards, .btn-px { display: none !important; }
            .table-responsive { display: block !important; }
        }
    </style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>
<div class="dashboard-wrapper">
    <!-- Header -->
    <div class="page-header">
        <h1><i class="fas fa-exclamation-triangle me-2"></i>Debt Management</h1>
        <p>Monitor outstanding student fees and track payment progress</p>
        <div class="header-badge mt-2"><i class="fas fa-calendar-alt"></i> Academic Year: <?= htmlspecialchars($default_year) ?></div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_SESSION['success_msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION['success_msg']); endif; ?>
    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($_SESSION['error_msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION['error_msg']); endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card c-fees"><div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div><div class="stat-value" id="total_due">₵<?= number_format($initial_summary['total_due'],2) ?></div><div class="stat-label">Total Debt Amount</div></div>
        <div class="stat-card c-paid"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-value" id="total_paid">₵<?= number_format($initial_summary['total_paid'],2) ?></div><div class="stat-label">Amount Paid</div></div>
        <div class="stat-card c-outstanding"><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-value" id="total_balance">₵<?= number_format($initial_summary['total_balance'],2) ?></div><div class="stat-label">Outstanding Balance</div></div>
        <div class="stat-card c-learners"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-value" id="total_students"><?= $initial_summary['total_students'] ?></div><div class="stat-label">Students with Debt</div></div>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
        <div class="section-label">FILTERS</div>
        <form id="filterForm" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Academic Year</label>
                <select name="academic_year" id="academic_year" class="form-select">
                    <?php foreach ($academic_years as $yr): ?>
                        <option value="<?= htmlspecialchars($yr) ?>" <?= $yr === $default_year ? 'selected' : '' ?>><?= htmlspecialchars($yr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select name="department_id" id="department_id" class="form-select">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $id => $name): ?>
                        <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Class</label>
                <select name="class_id" id="class_id" class="form-select">
                    <option value="0">All Classes</option>
                    <?php foreach ($classes as $id => $cls): ?>
                        <option value="<?= $id ?>" data-dept="<?= $cls['department_id'] ?>"><?= htmlspecialchars(classDisplay($cls['class_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Fee Type</label>
                <select name="fee_type" id="fee_type" class="form-select">
                    <option value="">All Fee Types</option>
                    <?php foreach ($fee_types as $ft): ?>
                        <option value="<?= htmlspecialchars($ft) ?>"><?= htmlspecialchars($ft) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Search</label>
                <input type="text" name="search" id="search" class="form-control" placeholder="Name, email, phone...">
            </div>
            <div class="col-md-2">
                <button type="button" id="applyFilters" class="btn-px btn-primary-g w-100"><i class="fas fa-filter"></i> Apply</button>
            </div>
        </form>

        <!-- Status Pills -->
        <div class="status-filters mt-3">
            <?php
            $status_counts = ['all' => $initial_summary['total_students'], 'Paid' => $initial_summary['paid_count'], 'Partial' => $initial_summary['partial_count'], 'Unpaid' => $initial_summary['unpaid_count']];
            $status_labels = ['all' => 'All', 'Paid' => 'Fully Paid', 'Partial' => 'Partial', 'Unpaid' => 'Unpaid'];
            $status_icons = ['all' => 'fa-list', 'Paid' => 'fa-check-circle', 'Partial' => 'fa-clock', 'Unpaid' => 'fa-times-circle'];
            foreach (['all', 'Paid', 'Partial', 'Unpaid'] as $st):
            ?>
                <a href="#" class="sf-btn status-pill" data-status="<?= $st ?>">
                    <i class="fas <?= $status_icons[$st] ?>"></i> <?= $status_labels[$st] ?>
                    <span class="cnt" id="cnt_<?= $st ?>"><?= $status_counts[$st] ?? 0 ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons d-flex flex-wrap gap-2 mb-3">
        <a href="add_fee.php" class="btn-px btn-primary-g"><i class="fas fa-plus-circle"></i> Add New Fee</a>
        <a href="pay_fee.php" class="btn-px btn-success-g"><i class="fas fa-money-bill-wave"></i> Record Payment</a>
        <button type="button" id="sendBulkReminders" class="btn-px btn-outline-o"><i class="fas fa-bell"></i> Send Reminders</button>
        <button type="button" id="exportCSV" class="btn-px btn-outline-o"><i class="fas fa-file-csv"></i> Export CSV</button>
        <button type="button" onclick="window.print()" class="btn-px btn-outline-o"><i class="fas fa-print"></i> Print</button>
    </div>

    <!-- Desktop Table -->
    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover" id="debtsTable">
                <thead>
                    <tr><th>Student</th><th>Class</th><th>Fee Type</th><th>Acad. Year</th><th>Amount Due</th><th>Paid</th><th>Balance</th><th>Progress</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($initial_debts as $debt): ?>
                        <tr data-debt-id="<?= $debt['id'] ?>">
                            <td><div class="d-flex align-items-center gap-2"><i class="fas fa-user" style="color:#ff6200;"></i><?= htmlspecialchars($debt['student_name']) ?><br><small class="text-muted"><?= htmlspecialchars($debt['student_email']) ?></small></div></td>
                            <td><span class="badge-cls"><?= htmlspecialchars($debt['class_display']) ?></span></td>
                            <td><?= htmlspecialchars($debt['fee_type']) ?></td>
                            <td><?= htmlspecialchars($debt['academic_year']) ?></td>
                            <td class="fw-bold text-danger">₵<?= number_format($debt['amount_due'],2) ?></td>
                            <td class="fw-bold text-success">₵<?= number_format($debt['amount_paid'],2) ?></td>
                            <td class="fw-bold text-warning">₵<?= number_format($debt['balance'],2) ?></td>
                            <td><div class="prog-wrap"><div class="progress"><div class="progress-bar <?= $debt['payment_percentage'] >= 100 ? 'bg-success' : ($debt['payment_percentage'] > 0 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min($debt['payment_percentage'],100) ?>%"></div></div><span class="prog-pct"><?= round($debt['payment_percentage'],0) ?>%</span></div></td>
                            <td><span class="badge-st <?= $debt['status'] === 'Paid' ? 'b-paid' : ($debt['status'] === 'Partial' ? 'b-partial' : 'b-unpaid') ?>"><i class="fas <?= $debt['status'] === 'Paid' ? 'fa-check-circle' : ($debt['status'] === 'Partial' ? 'fa-clock' : 'fa-times-circle') ?>"></i> <?= $debt['status'] ?></span></td>
                            <td><button class="btn btn-sm btn-outline-primary view-details" data-debt-id="<?= $debt['id'] ?>"><i class="fas fa-eye"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="summary-row"><tr><td colspan="4" class="text-end fw-bold">TOTALS:</td><td class="fw-bold" id="foot_total_due">₵<?= number_format($initial_summary['total_due'],2) ?></td><td class="fw-bold" id="foot_total_paid">₵<?= number_format($initial_summary['total_paid'],2) ?></td><td class="fw-bold" id="foot_total_balance">₵<?= number_format($initial_summary['total_balance'],2) ?></td><td colspan="3"></td></tr></tfoot>
            </table>
        </div>
    </div>

    <!-- Mobile Cards -->
    <div class="mobile-cards" id="mobileCards"></div>

    <!-- Modals (same as before) -->
    <div class="modal fade" id="debtDetailsModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title">Debt Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="debtDetailsContent"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    let table = $('#debtsTable').DataTable({
        responsive: true, pageLength: 25, dom: 'Bfrtip',
        buttons: [{ extend: 'copy', text: '<i class="fas fa-copy"></i> Copy' }, { extend: 'excel', text: '<i class="fas fa-file-excel"></i> Excel' }, { extend: 'print', text: '<i class="fas fa-print"></i> Print' }],
        language: { searchPlaceholder: "Search debts...", zeroRecords: "No matching debts found" }
    });

    function updateStats(summary) {
        $('#total_due').text('₵' + parseFloat(summary.total_due).toFixed(2));
        $('#total_paid').text('₵' + parseFloat(summary.total_paid).toFixed(2));
        $('#total_balance').text('₵' + parseFloat(summary.total_balance).toFixed(2));
        $('#total_students').text(summary.total_students);
        $('#cnt_all').text(summary.total_students);
        $('#cnt_Paid').text(summary.paid_count);
        $('#cnt_Partial').text(summary.partial_count);
        $('#cnt_Unpaid').text(summary.unpaid_count);
        $('#foot_total_due').text('₵' + parseFloat(summary.total_due).toFixed(2));
        $('#foot_total_paid').text('₵' + parseFloat(summary.total_paid).toFixed(2));
        $('#foot_total_balance').text('₵' + parseFloat(summary.total_balance).toFixed(2));
    }

    function renderMobileCards(debts) {
        let html = '';
        debts.forEach(d => {
            let statusClass = d.status === 'Paid' ? 'b-paid' : (d.status === 'Partial' ? 'b-partial' : 'b-unpaid');
            let statusIcon = d.status === 'Paid' ? 'fa-check-circle' : (d.status === 'Partial' ? 'fa-clock' : 'fa-times-circle');
            let progressClass = d.payment_percentage >= 100 ? 'bg-success' : (d.payment_percentage > 0 ? 'bg-warning' : 'bg-danger');
            html += `<div class="card mb-2 shadow-sm" style="cursor:pointer;" onclick="window.location='view_debt_details.php?debt_id=${d.id}'">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start"><div><i class="fas fa-user-circle fs-4 me-2" style="color:#ff6200;"></i><strong>${escapeHtml(d.student_name)}</strong><br><small class="text-muted">${escapeHtml(d.class_display)} • ${escapeHtml(d.fee_type)}</small></div><span class="badge-st ${statusClass}"><i class="fas ${statusIcon}"></i> ${d.status}</span></div>
                            <div class="row mt-2 text-center"><div class="col-4"><small>Due</small><div class="fw-bold text-danger">₵${parseFloat(d.amount_due).toFixed(2)}</div></div><div class="col-4"><small>Paid</small><div class="fw-bold text-success">₵${parseFloat(d.amount_paid).toFixed(2)}</div></div><div class="col-4"><small>Balance</small><div class="fw-bold text-warning">₵${parseFloat(d.balance).toFixed(2)}</div></div></div>
                            <div class="mt-2"><div class="progress" style="height:6px"><div class="progress-bar ${progressClass}" style="width:${Math.min(d.payment_percentage,100)}%"></div></div><div class="text-end small">${Math.round(d.payment_percentage)}%</div></div>
                        </div>
                    </div>`;
        });
        $('#mobileCards').html(html || '<div class="text-center p-4">No debts found.</div>');
    }

    function escapeHtml(str) { return $('<div>').text(str).html(); }

    function filterDebts(statusOverride = null) {
        let academic_year = $('#academic_year').val();
        if (!academic_year) { alert('Please select an academic year.'); return; }
        let data = {
            action: 'filter_debts',
            academic_year: academic_year,
            department_id: $('#department_id').val(),
            class_id: $('#class_id').val(),
            fee_type: $('#fee_type').val(),
            search: $('#search').val(),
            csrf_token: '<?= $csrf_token ?>'
        };
        if (statusOverride) data.status_filter = statusOverride;
        else data.status_filter = $('.status-pill.active').data('status') === 'all' ? '' : $('.status-pill.active').data('status');
        
        $.post(window.location.href, data, function(res) {
            if (res.success) {
                table.clear();
                res.debts.forEach(d => {
                    let progressBarClass = d.payment_percentage >= 100 ? 'bg-success' : (d.payment_percentage > 0 ? 'bg-warning' : 'bg-danger');
                    table.row.add([
                        `<div><i class="fas fa-user" style="color:#ff6200;"></i> ${escapeHtml(d.student_name)}<br><small>${escapeHtml(d.student_email)}</small></div>`,
                        `<span class="badge-cls">${escapeHtml(d.class_display)}</span>`,
                        escapeHtml(d.fee_type),
                        d.academic_year,
                        `<span class="fw-bold text-danger">₵${parseFloat(d.amount_due).toFixed(2)}</span>`,
                        `<span class="fw-bold text-success">₵${parseFloat(d.amount_paid).toFixed(2)}</span>`,
                        `<span class="fw-bold text-warning">₵${parseFloat(d.balance).toFixed(2)}</span>`,
                        `<div class="prog-wrap"><div class="progress"><div class="progress-bar ${progressBarClass}" style="width:${Math.min(d.payment_percentage,100)}%"></div></div><span class="prog-pct">${Math.round(d.payment_percentage)}%</span></div>`,
                        `<span class="badge-st ${d.status === 'Paid' ? 'b-paid' : (d.status === 'Partial' ? 'b-partial' : 'b-unpaid')}"><i class="fas ${d.status === 'Paid' ? 'fa-check-circle' : (d.status === 'Partial' ? 'fa-clock' : 'fa-times-circle')}"></i> ${d.status}</span>`,
                        `<button class="btn btn-sm btn-outline-primary view-details" data-debt-id="${d.id}"><i class="fas fa-eye"></i></button>`
                    ]);
                });
                table.draw();
                updateStats(res.summary);
                renderMobileCards(res.debts);
                $('.status-pill').removeClass('active');
                if (statusOverride) $(`.status-pill[data-status="${statusOverride}"]`).addClass('active');
                else $('.status-pill.active').addClass('active');
            } else alert('Error loading debts.');
        }, 'json');
    }

    $('#applyFilters').click(() => filterDebts());
    $('.status-pill').click(function(e) { e.preventDefault(); $('.status-pill').removeClass('active'); $(this).addClass('active'); filterDebts($(this).data('status')); });
    $('#department_id').change(function() {
        let dept = $(this).val();
        $.post(window.location.href, { action: 'filter_classes', department_id: dept, csrf_token: '<?= $csrf_token ?>' }, function(res) {
            if (res.success) {
                let $classSelect = $('#class_id');
                $classSelect.html('<option value="0">All Classes</option>');
                res.classes.forEach(c => $classSelect.append(`<option value="${c.id}" data-dept="${dept}">${escapeHtml(c.class_name)}</option>`));
            }
        }, 'json');
    });
    $(document).on('click', '.view-details', function() {
        let debtId = $(this).data('debt-id');
        $.get('view_debt_details.php', { debt_id: debtId }, function(html) { $('#debtDetailsContent').html(html); $('#debtDetailsModal').modal('show'); });
    });
    $('#exportCSV').click(() => table.button('.buttons-excel').trigger());
    $('#sendBulkReminders').click(() => {
        if (confirm('Send payment reminders to all students with unpaid debts in current filter?')) {
            let unpaid = table.rows().data().filter((v,i) => v[8].includes('Unpaid')).count();
            if(unpaid === 0) { alert('No unpaid debts in current filter.'); return; }
            // implement bulk reminder via AJAX if needed – for brevity, we just alert.
            alert('Reminder feature: Would send to ' + unpaid + ' students.');
        }
    });
    // initial
    filterDebts('all');
});
</script>
</body>
</html>