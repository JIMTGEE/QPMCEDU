<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include log_activity.php and define fallback
$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) {
    require_once($log_activity_path);
} else {
    error_log("log_activity.php not found at $log_activity_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    if (!function_exists('log_activity')) {
        function log_activity($user_id, $action, $details, $conn = null) {
            $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
            $timestamp = date('Y-m-d H:i:s');
            error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
        }
    }
}

// Check if log_activity function exists
if (!function_exists('log_activity')) {
    error_log("log_activity function is undefined after inclusion attempt", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    function log_activity($user_id, $action, $details, $conn = null) {
        $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
    }
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    log_activity($user_id, 'access_denied', 'Attempted to access view_debts.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed view_debts.php, IP: $ip_address, User Agent: $user_agent", $conn);

// Generate and store CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
$csrf_query = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)";
if ($csrf_stmt = $conn->prepare($csrf_query)) {
    $csrf_stmt->bind_param("iss", $_SESSION['user_id'], $csrf_token, $csrf_expiry);
    $csrf_stmt->execute();
    $csrf_stmt->close();
} else {
    error_log("Failed to store CSRF token: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
}
$_SESSION['csrf_token'] = $csrf_token;

// Fetch academic years
$academic_years_result = $conn->query("SELECT DISTINCT year FROM academic_calendar WHERE year REGEXP '^[0-9]{4}/[0-9]{4}$' ORDER BY year DESC");
if (!$academic_years_result) {
    error_log("Error fetching academic years: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch academic years. Please try again later.");
}
$academic_years = [];
while ($row = $academic_years_result->fetch_assoc()) {
    $academic_years[] = $row['year'];
}
$academic_years_count = count($academic_years);
error_log("Fetched $academic_years_count academic years", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$academic_years_result->free();

// Fetch departments
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if (!$departments_result) {
    error_log("Error fetching departments: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch departments. Please try again later.");
}
$departments = [];
$valid_department_enums = ['Lower Primary', 'Upper Primary', 'Junior High', 'Kindergarten', 'Pre-School'];
while ($row = $departments_result->fetch_assoc()) {
    $dept_name = $row['name'];
    if (!in_array($dept_name, $valid_department_enums)) {
        $dept_name = str_contains($row['name'], 'Lower') ? 'Lower Primary' :
                     (str_contains($row['name'], 'Upper') ? 'Upper Primary' :
                     (str_contains($row['name'], 'Junior') ? 'Junior High' :
                     (str_contains($row['name'], 'Kindergarten') ? 'Kindergarten' : $row['name'])));
    }
    $departments[$row['id']] = ['name' => $row['name'], 'enum_name' => $dept_name];
}
$departments_count = count($departments);
error_log("Fetched $departments_count departments", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$departments_result->free();

// Fetch classes
$classes_result = $conn->query("SELECT id, class_name, department_id FROM classes ORDER BY class_name ASC");
if (!$classes_result) {
    error_log("Error fetching classes: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch classes. Please try again later.");
}
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[$row['id']] = ['class_name' => $row['class_name'], 'department_id' => $row['department_id']];
}
$classes_count = count($classes);
error_log("Fetched $classes_count classes", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$classes_result->free();

// Fetch fee types
$fee_types_result = $conn->query("SELECT DISTINCT fee_type FROM fees WHERE fee_type IS NOT NULL AND fee_type != '' ORDER BY fee_type ASC");
if (!$fee_types_result) {
    error_log("Error fetching fee types: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
} else {
    $fee_types = [];
    while ($row = $fee_types_result->fetch_assoc()) {
        $fee_types[] = $row['fee_type'];
    }
    $fee_types_result->free();
    error_log("Fetched " . count($fee_types) . " fee types", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
}

// Fetch debts (initial load with default filters)
$debts = [];
$default_academic_year = !empty($academic_years) ? $academic_years[0] : '';
$summary_stats = [
    'total_students' => 0,
    'total_amount_due' => 0,
    'total_amount_paid' => 0,
    'total_balance' => 0,
    'paid_count' => 0,
    'partial_count' => 0,
    'unpaid_count' => 0
];

if ($default_academic_year) {
    $debt_query = "SELECT d.id, d.user_id, d.fee_id, d.amount_due, d.academic_year, d.class_id, 
                          u.name AS student_name, c.class_name, f.fee_type, 
                          COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
                          u.email AS student_email,
                          u.phone_number AS student_phone,
                          d.created_at,
                          d.updated_at
                   FROM debts d 
                   JOIN users u ON d.user_id = u.id 
                   JOIN classes c ON d.class_id = c.id 
                   JOIN fees f ON d.fee_id = f.id 
                   LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id 
                   WHERE u.role = 'student' AND d.academic_year = ? 
                   GROUP BY d.id, d.user_id, d.fee_id, d.amount_due, d.academic_year, d.class_id, 
                            u.name, c.class_name, f.fee_type, u.email, u.phone_number, d.created_at, d.updated_at
                   ORDER BY c.class_name, u.name";
    if ($stmt = $conn->prepare($debt_query)) {
        $stmt->bind_param("s", $default_academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $balance = $row['amount_due'] - $row['amount_paid'];
            $status = $balance <= 0 ? 'Paid' : ($row['amount_paid'] > 0 ? 'Partial' : 'Unpaid');
            
            // Update summary stats
            $summary_stats['total_amount_due'] += $row['amount_due'];
            $summary_stats['total_amount_paid'] += $row['amount_paid'];
            $summary_stats['total_balance'] += $balance;
            
            if ($status === 'Paid') $summary_stats['paid_count']++;
            elseif ($status === 'Partial') $summary_stats['partial_count']++;
            else $summary_stats['unpaid_count']++;
            
            $debts[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'fee_id' => $row['fee_id'],
                'student_name' => $row['student_name'],
                'student_email' => $row['student_email'],
                'student_phone' => $row['student_phone'],
                'class_name' => $row['class_name'],
                'fee_type' => $row['fee_type'],
                'academic_year' => $row['academic_year'],
                'amount_due' => $row['amount_due'],
                'amount_paid' => $row['amount_paid'],
                'balance' => $balance,
                'status' => $status,
                'class_id' => $row['class_id'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'department_id' => $classes[$row['class_id']]['department_id'] ?? 0
            ];
        }
        $stmt->close();
        $summary_stats['total_students'] = count(array_unique(array_column($debts, 'user_id')));
        error_log("Fetched " . count($debts) . " debts for academic_year=$default_academic_year", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
    } else {
        error_log("Failed to prepare debts query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }
}

// Handle AJAX requests for filtering
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['csrf_token'])) {
    // Validate CSRF token
    $csrf_valid = false;
    $csrf_check_query = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_stmt = $conn->prepare($csrf_check_query)) {
        $csrf_stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
        $csrf_stmt->execute();
        $csrf_result = $csrf_stmt->get_result();
        $csrf_valid = $csrf_result->num_rows > 0;
        $csrf_stmt->close();
    }
    if (!$csrf_valid) {
        error_log("Invalid CSRF token for action {$_POST['action']}", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'filter_classes') {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $filtered_classes = [];
        if ($dept_id > 0) {
            foreach ($classes as $id => $class) {
                if ($class['department_id'] == $dept_id) {
                    $filtered_classes[] = ['id' => $id, 'class_name' => $class['class_name']];
                }
            }
        } else {
            foreach ($classes as $id => $class) {
                $filtered_classes[] = ['id' => $id, 'class_name' => $class['class_name']];
            }
        }
        error_log("Filtered " . count($filtered_classes) . " classes for dept_id=$dept_id", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        echo json_encode(['success' => true, 'classes' => $filtered_classes]);
        exit;
    }
    
    if ($_POST['action'] === 'filter_debts') {
        $academic_year = trim($_POST['academic_year'] ?? '');
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $fee_type = trim($_POST['fee_type'] ?? '');
        $status_filter = trim($_POST['status_filter'] ?? '');
        $search_query = trim($_POST['search_query'] ?? '');
        $filtered_debts = [];
        $summary_stats = [
            'total_students' => 0,
            'total_amount_due' => 0,
            'total_amount_paid' => 0,
            'total_balance' => 0,
            'paid_count' => 0,
            'partial_count' => 0,
            'unpaid_count' => 0
        ];
        
        // Build the query
        $query = "SELECT d.id, d.user_id, d.fee_id, d.amount_due, d.academic_year, d.class_id, 
                         u.name AS student_name, c.class_name, f.fee_type, 
                         COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
                         u.email AS student_email,
                         u.phone_number AS student_phone,
                         d.created_at,
                         d.updated_at
                  FROM debts d 
                  JOIN users u ON d.user_id = u.id 
                  JOIN classes c ON d.class_id = c.id 
                  JOIN fees f ON d.fee_id = f.id 
                  LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id 
                  WHERE u.role = 'student'";
        
        $params = [];
        $types = "";
        
        if ($academic_year && in_array($academic_year, $academic_years)) {
            $query .= " AND d.academic_year = ?";
            $types .= "s";
            $params[] = $academic_year;
        } else if (!empty($academic_years)) {
            $query .= " AND d.academic_year = ?";
            $types .= "s";
            $params[] = $academic_years[0];
        }
        
        if ($dept_id > 0) {
            $query .= " AND c.department_id = ?";
            $types .= "i";
            $params[] = $dept_id;
        }
        
        if ($class_id > 0) {
            $query .= " AND d.class_id = ?";
            $types .= "i";
            $params[] = $class_id;
        }
        
        if ($fee_type && isset($fee_types) && in_array($fee_type, $fee_types)) {
            $query .= " AND f.fee_type = ?";
            $types .= "s";
            $params[] = $fee_type;
        }
        
        if ($search_query) {
            $query .= " AND (u.name LIKE ? OR f.fee_type LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ?)";
            $types .= "ssss";
            $search_param = "%$search_query%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $query .= " GROUP BY d.id, d.user_id, d.fee_id, d.amount_due, d.academic_year, d.class_id, 
                           u.name, c.class_name, f.fee_type, u.email, u.phone_number, d.created_at, d.updated_at 
                    ORDER BY c.class_name, u.name LIMIT 500";
        
        if ($stmt = $conn->prepare($query)) {
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $balance = $row['amount_due'] - $row['amount_paid'];
                $status = $balance <= 0 ? 'Paid' : ($row['amount_paid'] > 0 ? 'Partial' : 'Unpaid');
                
                // Apply status filter
                if ($status_filter && $status !== $status_filter) {
                    continue;
                }
                
                // Update summary stats
                $summary_stats['total_amount_due'] += $row['amount_due'];
                $summary_stats['total_amount_paid'] += $row['amount_paid'];
                $summary_stats['total_balance'] += $balance;
                
                if ($status === 'Paid') $summary_stats['paid_count']++;
                elseif ($status === 'Partial') $summary_stats['partial_count']++;
                else $summary_stats['unpaid_count']++;
                
                $filtered_debts[] = [
                    'id' => $row['id'],
                    'user_id' => $row['user_id'],
                    'fee_id' => $row['fee_id'],
                    'student_name' => $row['student_name'],
                    'student_email' => $row['student_email'],
                    'student_phone' => $row['student_phone'],
                    'class_name' => $row['class_name'],
                    'fee_type' => $row['fee_type'],
                    'academic_year' => $row['academic_year'],
                    'amount_due' => $row['amount_due'],
                    'amount_paid' => $row['amount_paid'],
                    'balance' => $balance,
                    'status' => $status,
                    'class_id' => $row['class_id'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'department_id' => $classes[$row['class_id']]['department_id'] ?? 0
                ];
            }
            $stmt->close();
        }
        
        $summary_stats['total_students'] = count(array_unique(array_column($filtered_debts, 'user_id')));
        
        error_log("Filtered " . count($filtered_debts) . " debts", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        echo json_encode([
            'success' => true, 
            'debts' => $filtered_debts,
            'summary_stats' => $summary_stats
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'log_error') {
        $error_message = trim($_POST['message'] ?? 'Unknown client error');
        log_activity($_SESSION['user_id'], 'client_error', "Client-side error: $error_message", $conn);
        echo json_encode(['success' => true]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin page for viewing and tracking student debts in Queen's Paradise Montessori School.">
    <meta name="keywords" content="Queen's Paradise Montessori School, view debts, financial tracking, admin dashboard">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Debts - Queen's Paradise Montessori School">
    <meta property="og:description" content="Admin interface for tracking student debts by academic year, class, and fee type.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/view_debts.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Debts - Queen's Paradise Montessori School">
    <meta name="twitter:description" content="Admin interface for tracking student debts by academic year, class, and fee type.">
    <title>View Debts - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #ffd700;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        body {
            background-color: #f9fafb;
            font-family: 'Inter', sans-serif;
        }
        
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            border: none;
        }
        
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0 !important;
            padding: 1.25rem 1.5rem;
            border: none;
        }
        
        .card-header h3 {
            margin: 0;
            font-weight: 600;
            font-size: 1.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 98, 0, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            padding: 0.625rem 1.25rem;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: linear-gradient(to right, #e55a00, #e08300);
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            border-radius: 8px;
            padding: 0.625rem 1.25rem;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
        }
        
        .filter-section {
            background-color: #fff;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
        }
        
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .table {
            margin: 0;
        }
        
        .table th {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            font-weight: 600;
            border: none;
            padding: 1rem;
        }
        
        .table td {
            padding: 0.875rem 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #eee;
        }
        
        .table tbody tr:hover {
            background-color: rgba(255, 98, 0, 0.05);
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .loading-spinner {
            display: none;
            margin-left: 10px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-color);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0.5rem 0;
        }
        
        .stat-card .stat-label {
            color: #666;
            font-size: 0.875rem;
            margin: 0;
        }
        
        .badge-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-paid {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .badge-partial {
            background-color: rgba(255, 193, 7, 0.1);
            color: #e0a800;
        }
        
        .badge-unpaid {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #ddd;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .progress-bar {
            border-radius: 4px;
        }
        
        .debt-details {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .dataTables_wrapper {
            padding: 1rem 0;
        }
        
        .dataTables_length select {
            border-radius: 6px;
            padding: 0.375rem 2.25rem 0.375rem 0.75rem;
        }
        
        .dataTables_filter input {
            border-radius: 6px;
            padding: 0.375rem 0.75rem;
            border: 1px solid #ced4da;
        }
        
        .dt-buttons .btn {
            border-radius: 6px;
            margin-right: 0.25rem;
        }
        
        @media (max-width: 767.98px) {
            .filter-section {
                padding: 1rem;
            }
            
            .card-header h3 {
                font-size: 1.25rem;
            }
            
            .form-label {
                font-size: 0.875rem;
            }
            
            .btn {
                font-size: 0.875rem;
                padding: 0.5rem 1rem;
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .table {
                font-size: 0.875rem;
            }
            
            .table th, .table td {
                padding: 0.75rem 0.5rem;
            }
            
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter {
                margin-bottom: 0.5rem;
            }
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            
            .print-section, .print-section * {
                visibility: visible;
            }
            
            .print-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            
            .no-print {
                display: none !important;
            }
        }
        
        .tooltip-inner {
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        
        .modal-lg {
            max-width: 900px;
        }
    </style>
</head>
<body>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) {
        error_log("Admin header file missing: $header_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin header template at $header_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin header template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $header_path; 
    ?>

    <div class="container my-4">
        <section class="card mb-4">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>View Debts</h3>
                <p class="mb-0 opacity-75" style="font-size: 0.9rem;">Track and manage student outstanding fees</p>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php unset($_SESSION['success_msg']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_msg'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php unset($_SESSION['error_msg']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($academic_years)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No academic years found in the database. Please add academic years.
                    </div>
                <?php endif; ?>
                
                <?php if (empty($departments)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No departments found in the database. Please add departments.
                    </div>
                <?php endif; ?>
                
                <?php if (empty($classes)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No classes found in the database. Please add classes.
                    </div>
                <?php endif; ?>

                <!-- Quick Stats -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-label">Total Students with Debts</div>
                            <div class="stat-value" id="total_students"><?php echo $summary_stats['total_students']; ?></div>
                            <small class="text-muted">Unique students</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-label">Total Amount Due</div>
                            <div class="stat-value" id="total_due">₵<?php echo number_format($summary_stats['total_amount_due'], 2); ?></div>
                            <small class="text-muted">Total outstanding</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-label">Total Amount Paid</div>
                            <div class="stat-value" id="total_paid">₵<?php echo number_format($summary_stats['total_amount_paid'], 2); ?></div>
                            <small class="text-muted">Total collected</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-label">Total Balance</div>
                            <div class="stat-value" id="total_balance">₵<?php echo number_format($summary_stats['total_balance'], 2); ?></div>
                            <small class="text-muted">Net balance</small>
                        </div>
                    </div>
                </div>

                <!-- Status Distribution -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="chart-container">
                            <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Debt Status Distribution</h5>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="text-center p-3">
                                        <div class="mb-2">
                                            <span class="badge-status badge-paid d-block mx-auto" style="width: fit-content;">
                                                <i class="fas fa-check-circle me-1"></i>Paid
                                            </span>
                                        </div>
                                        <div class="stat-value" id="paid_count"><?php echo $summary_stats['paid_count']; ?></div>
                                        <div class="stat-label">Fully Paid</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3">
                                        <div class="mb-2">
                                            <span class="badge-status badge-partial d-block mx-auto" style="width: fit-content;">
                                                <i class="fas fa-clock me-1"></i>Partial
                                            </span>
                                        </div>
                                        <div class="stat-value" id="partial_count"><?php echo $summary_stats['partial_count']; ?></div>
                                        <div class="stat-label">Partially Paid</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3">
                                        <div class="mb-2">
                                            <span class="badge-status badge-unpaid d-block mx-auto" style="width: fit-content;">
                                                <i class="fas fa-exclamation-circle me-1"></i>Unpaid
                                            </span>
                                        </div>
                                        <div class="stat-value" id="unpaid_count"><?php echo $summary_stats['unpaid_count']; ?></div>
                                        <div class="stat-label">Unpaid</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3">
                                        <div class="mb-2">
                                            <span class="badge bg-secondary d-block mx-auto" style="width: fit-content;">
                                                <i class="fas fa-percentage me-1"></i>Collection Rate
                                            </span>
                                        </div>
                                        <div class="stat-value" id="collection_rate">
                                            <?php 
                                            $total_records = $summary_stats['paid_count'] + $summary_stats['partial_count'] + $summary_stats['unpaid_count'];
                                            $collection_rate = $total_records > 0 ? ($summary_stats['paid_count'] / $total_records * 100) : 0;
                                            echo number_format($collection_rate, 1) . '%';
                                            ?>
                                        </div>
                                        <div class="stat-label">Fully Collected</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="POST" class="needs-validation" novalidate id="filter-form">
                        <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3">
                            <!-- Academic Year -->
                            <div class="col-md-2">
                                <label for="academic_year" class="form-label">Academic Year</label>
                                <select name="academic_year" id="academic_year" class="form-select" required>
                                    <option value="">Select Academic Year</option>
                                    <?php foreach ($academic_years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $year === $default_academic_year ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select an academic year.</div>
                            </div>

                            <!-- Department -->
                            <div class="col-md-2">
                                <label for="department_id" class="form-label">Department</label>
                                <select name="department_id" id="department_id" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $id => $dept): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>">
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Class -->
                            <div class="col-md-2">
                                <label for="class_id" class="form-label">Class</label>
                                <select name="class_id" id="class_id" class="form-select">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $id => $class): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>" data-dept="<?php echo htmlspecialchars($class['department_id']); ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Fee Type -->
                            <div class="col-md-2">
                                <label for="fee_type" class="form-label">Fee Type</label>
                                <select name="fee_type" id="fee_type" class="form-select">
                                    <option value="">All Fee Types</option>
                                    <?php if (isset($fee_types)): ?>
                                        <?php foreach ($fee_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>">
                                                <?php echo htmlspecialchars($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <!-- Status Filter -->
                            <div class="col-md-2">
                                <label for="status_filter" class="form-label">Status</label>
                                <select name="status_filter" id="status_filter" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="Paid">Paid</option>
                                    <option value="Partial">Partial</option>
                                    <option value="Unpaid">Unpaid</option>
                                </select>
                            </div>

                            <!-- Search Students -->
                            <div class="col-md-2">
                                <label for="search_query" class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" id="search_query" class="form-control" placeholder="Search...">
                                    <button type="button" class="btn btn-primary" id="search_btn" data-bs-toggle="tooltip" title="Search debts">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-primary" id="filter_btn">
                                        <i class="fas fa-filter me-1"></i>Apply Filters
                                        <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                    </button>
                                    <button type="button" class="btn btn-secondary" id="reset_btn" data-bs-toggle="tooltip" title="Reset all filters">
                                        <i class="fas fa-redo me-1"></i>Reset
                                    </button>
                                    <button type="button" class="btn btn-success" id="export_btn" data-bs-toggle="tooltip" title="Export to Excel">
                                        <i class="fas fa-file-excel me-1"></i>Export
                                    </button>
                                    <button type="button" class="btn btn-info" id="print_btn" data-bs-toggle="tooltip" title="Print report">
                                        <i class="fas fa-print me-1"></i>Print
                                    </button>
                                    <button type="button" class="btn btn-warning" id="send_reminders_btn" data-bs-toggle="tooltip" title="Send payment reminders">
                                        <i class="fas fa-bell me-1"></i>Reminders
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Debts Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="debts_table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Contact</th>
                                <th>Class</th>
                                <th>Fee Type</th>
                                <th>Academic Year</th>
                                <th>Amount Due (GH₵)</th>
                                <th>Amount Paid (GH₵)</th>
                                <th>Balance (GH₵)</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($debts)): ?>
                                <tr>
                                    <td colspan="10" class="no-data">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <p>No outstanding debts found for the selected academic year.</p>
                                        <small>Try selecting a different academic year or adjust your filters.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($debts as $debt): 
                                    $status_class = '';
                                    if ($debt['status'] === 'Paid') $status_class = 'badge-paid';
                                    elseif ($debt['status'] === 'Partial') $status_class = 'badge-partial';
                                    else $status_class = 'badge-unpaid';
                                    
                                    $payment_percentage = $debt['amount_due'] > 0 ? ($debt['amount_paid'] / $debt['amount_due'] * 100) : 100;
                                ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fas fa-user" style="color: #ff6200;"></i>
                                                <span><?php echo htmlspecialchars($debt['student_name']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <small class="d-block"><?php echo htmlspecialchars($debt['student_email']); ?></small>
                                            <small class="text-muted"><?php echo htmlspecialchars($debt['student_phone']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($debt['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                                        <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                                        <td class="fw-bold" style="color: #dc3545;"><?php echo number_format($debt['amount_due'], 2); ?></td>
                                        <td class="fw-bold" style="color: #28a745;"><?php echo number_format($debt['amount_paid'], 2); ?></td>
                                        <td class="fw-bold" style="color: #e0a800;"><?php echo number_format($debt['balance'], 2); ?></td>
                                        <td>
                                            <span class="badge-status <?php echo $status_class; ?>">
                                                <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                                <?php echo $debt['status']; ?>
                                            </span>
                                            <div class="progress mt-1" style="height: 4px;">
                                                <div class="progress-bar 
                                                    <?php echo $payment_percentage >= 100 ? 'bg-success' : ($payment_percentage > 0 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                    role="progressbar" 
                                                    style="width: <?php echo min($payment_percentage, 100); ?>%">
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-outline-primary view-details-btn" 
                                                        data-debt-id="<?php echo $debt['id']; ?>"
                                                        data-bs-toggle="tooltip" title="View details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-success record-payment-btn"
                                                        data-debt-id="<?php echo $debt['id']; ?>"
                                                        data-student-name="<?php echo htmlspecialchars($debt['student_name']); ?>"
                                                        data-bs-toggle="tooltip" title="Record payment">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info send-reminder-btn"
                                                        data-student-email="<?php echo htmlspecialchars($debt['student_email']); ?>"
                                                        data-student-name="<?php echo htmlspecialchars($debt['student_name']); ?>"
                                                        data-bs-toggle="tooltip" title="Send reminder">
                                                    <i class="fas fa-envelope"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <!-- Debt Details Modal -->
    <div class="modal fade" id="debtDetailsModal" tabindex="-1" aria-labelledby="debtDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(to right, var(--primary-color), var(--secondary-color)); color: white;">
                    <h5 class="modal-title" id="debtDetailsModalLabel"><i class="fas fa-file-invoice-dollar me-2"></i>Debt Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="debtDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="printDebtBtn">
                        <i class="fas fa-print me-1"></i>Print Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(to right, #28a745, #20c997); color: white;">
                    <h5 class="modal-title" id="recordPaymentModalLabel"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="paymentForm">
                        <input type="hidden" name="debt_id" id="paymentDebtId">
                        <div class="mb-3">
                            <label for="studentName" class="form-label">Student</label>
                            <input type="text" class="form-control" id="studentName" readonly>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="amountDue" class="form-label">Amount Due</label>
                                <div class="input-group">
                                    <span class="input-group-text">₵</span>
                                    <input type="text" class="form-control" id="amountDue" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="balance" class="form-label">Current Balance</label>
                                <div class="input-group">
                                    <span class="input-group-text">₵</span>
                                    <input type="text" class="form-control" id="balance" readonly>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="paymentAmount" class="form-label">Payment Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₵</span>
                                <input type="number" class="form-control" id="paymentAmount" name="payment_amount" step="0.01" min="0.01" required>
                            </div>
                            <div class="form-text">Enter the amount being paid</div>
                        </div>
                        <div class="mb-3">
                            <label for="paymentMethod" class="form-label">Payment Method</label>
                            <select class="form-select" id="paymentMethod" name="payment_method" required>
                                <option value="">Select method</option>
                                <option value="Cash">Cash</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Check">Check</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="receiptNumber" class="form-label">Receipt Number</label>
                            <input type="text" class="form-control" id="receiptNumber" name="receipt_number" placeholder="Optional">
                        </div>
                        <div class="mb-3">
                            <label for="paymentNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="paymentNotes" name="payment_notes" rows="2" placeholder="Optional notes about this payment"></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This payment will be recorded and the debt balance will be updated automatically.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="savePaymentBtn">
                        <i class="fas fa-save me-1"></i>Record Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Admin footer file missing: $footer_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin footer template at $footer_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin footer template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    $conn->close();
    ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            try {
                // Initialize DataTable
                const debtsTable = $('#debts_table').DataTable({
                    responsive: true,
                    pageLength: 25,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    order: [[0, 'asc']],
                    dom: 'Bfrtip',
                    buttons: [
                        {
                            extend: 'copy',
                            className: 'btn btn-sm btn-secondary',
                            text: '<i class="fas fa-copy me-1"></i>Copy'
                        },
                        {
                            extend: 'excel',
                            className: 'btn btn-sm btn-success',
                            text: '<i class="fas fa-file-excel me-1"></i>Excel'
                        },
                        {
                            extend: 'pdf',
                            className: 'btn btn-sm btn-danger',
                            text: '<i class="fas fa-file-pdf me-1"></i>PDF'
                        },
                        {
                            extend: 'print',
                            className: 'btn btn-sm btn-info',
                            text: '<i class="fas fa-print me-1"></i>Print'
                        }
                    ],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search debts...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ debts",
                        infoEmpty: "Showing 0 to 0 of 0 debts",
                        infoFiltered: "(filtered from _MAX_ total debts)",
                        zeroRecords: "No matching debts found"
                    }
                });

                // Initialize tooltips
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });

                // Initialize variables
                let currentDebts = <?php echo json_encode($debts); ?>;
                let currentSummaryStats = <?php echo json_encode($summary_stats); ?>;
                
                // Filter classes via AJAX
                function filterClasses() {
                    const deptId = $('#department_id').val();
                    const academicYear = $('#academic_year').val();
                    if (!academicYear) {
                        $('#class_id').html('<option value="">All Classes</option>');
                        return;
                    }
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_classes',
                            department_id: deptId,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            if (response.error) {
                                console.error('Class filter error:', response.error);
                                showAlert('Error filtering classes: ' + response.error, 'danger');
                                logClientError('Class filter AJAX error: ' + response.error);
                                return;
                            }
                            let options = '<option value="">All Classes</option>';
                            response.classes.forEach(cls => {
                                options += `<option value="${cls.id}">${cls.class_name}</option>`;
                            });
                            $('#class_id').html(options);
                        },
                        error: function(xhr, status, error) {
                            console.error('Class filter AJAX error:', status, error);
                            showAlert('Failed to load classes. Please try again.', 'danger');
                            logClientError('Class filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }

                // Filter debts via AJAX
                function filterDebts() {
                    const academicYear = $('#academic_year').val();
                    const deptId = $('#department_id').val();
                    const classId = $('#class_id').val();
                    const feeType = $('#fee_type').val();
                    const statusFilter = $('#status_filter').val();
                    const searchQuery = $('#search_query').val();
                    
                    if (!academicYear) {
                        debtsTable.clear().draw();
                        $('#debts_table tbody').html(`
                            <tr>
                                <td colspan="10" class="no-data">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    <p>Please select an academic year to view debts.</p>
                                </td>
                            </tr>
                        `);
                        updateStats(0, 0, 0, 0, 0, 0, 0);
                        return;
                    }
                    
                    $('#filter_btn .loading-spinner').show();
                    
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_debts',
                            academic_year: academicYear,
                            department_id: deptId,
                            class_id: classId,
                            fee_type: feeType,
                            status_filter: statusFilter,
                            search_query: searchQuery,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            $('#filter_btn .loading-spinner').hide();
                            
                            if (response.error) {
                                console.error('Debt filter error:', response.error);
                                showAlert('Error filtering debts: ' + response.error, 'danger');
                                logClientError('Debt filter AJAX error: ' + response.error);
                                return;
                            }
                            
                            // Store current debts and stats
                            currentDebts = response.debts;
                            currentSummaryStats = response.summary_stats;
                            
                            // Update DataTable
                            debtsTable.clear();
                            
                            if (response.debts.length === 0) {
                                debtsTable.draw();
                                $('#debts_table tbody').html(`
                                    <tr>
                                        <td colspan="10" class="no-data">
                                            <i class="fas fa-file-invoice-dollar"></i>
                                            <p>No debts found for the selected filters.</p>
                                        </td>
                                    </tr>
                                `);
                            } else {
                                response.debts.forEach(debt => {
                                    const paymentPercentage = debt.amount_due > 0 ? (debt.amount_paid / debt.amount_due * 100) : 100;
                                    const progressClass = paymentPercentage >= 100 ? 'bg-success' : (paymentPercentage > 0 ? 'bg-warning' : 'bg-danger');
                                    
                                    debtsTable.row.add([
                                        `<div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-user" style="color: #ff6200;"></i>
                                            <span>${debt.student_name}</span>
                                        </div>`,
                                        `<small class="d-block">${debt.student_email}</small>
                                         <small class="text-muted">${debt.student_phone}</small>`,
                                        debt.class_name,
                                        debt.fee_type,
                                        debt.academic_year,
                                        `<span class="fw-bold" style="color: #dc3545;">${parseFloat(debt.amount_due).toFixed(2)}</span>`,
                                        `<span class="fw-bold" style="color: #28a745;">${parseFloat(debt.amount_paid).toFixed(2)}</span>`,
                                        `<span class="fw-bold" style="color: #e0a800;">${parseFloat(debt.balance).toFixed(2)}</span>`,
                                        `<span class="badge-status ${debt.status === 'Paid' ? 'badge-paid' : (debt.status === 'Partial' ? 'badge-partial' : 'badge-unpaid')}">
                                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                            ${debt.status}
                                        </span>
                                        <div class="progress mt-1" style="height: 4px;">
                                            <div class="progress-bar ${progressClass}" 
                                                 role="progressbar" 
                                                 style="width: ${Math.min(paymentPercentage, 100)}%">
                                            </div>
                                        </div>`,
                                        `<div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-primary view-details-btn" 
                                                    data-debt-id="${debt.id}"
                                                    data-bs-toggle="tooltip" title="View details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-success record-payment-btn"
                                                    data-debt-id="${debt.id}"
                                                    data-student-name="${debt.student_name}"
                                                    data-bs-toggle="tooltip" title="Record payment">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-info send-reminder-btn"
                                                    data-student-email="${debt.student_email}"
                                                    data-student-name="${debt.student_name}"
                                                    data-bs-toggle="tooltip" title="Send reminder">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        </div>`
                                    ]);
                                });
                                
                                debtsTable.draw();
                            }
                            
                            // Update statistics
                            updateStats(
                                response.summary_stats.total_students,
                                response.summary_stats.total_amount_due,
                                response.summary_stats.total_amount_paid,
                                response.summary_stats.total_balance,
                                response.summary_stats.paid_count,
                                response.summary_stats.partial_count,
                                response.summary_stats.unpaid_count
                            );
                        },
                        error: function(xhr, status, error) {
                            $('#filter_btn .loading-spinner').hide();
                            console.error('Debt filter AJAX error:', status, error);
                            showAlert('Failed to load debts. Please try again.', 'danger');
                            logClientError('Debt filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }

                // Update statistics display
                function updateStats(totalStudents, totalDue, totalPaid, totalBalance, paidCount, partialCount, unpaidCount) {
                    $('#total_students').text(totalStudents);
                    $('#total_due').text('₵' + parseFloat(totalDue).toFixed(2));
                    $('#total_paid').text('₵' + parseFloat(totalPaid).toFixed(2));
                    $('#total_balance').text('₵' + parseFloat(totalBalance).toFixed(2));
                    
                    // Update status distribution
                    $('#paid_count').text(paidCount);
                    $('#partial_count').text(partialCount);
                    $('#unpaid_count').text(unpaidCount);
                    
                    // Update collection rate
                    const totalRecords = paidCount + partialCount + unpaidCount;
                    const collectionRate = totalRecords > 0 ? (paidCount / totalRecords * 100) : 0;
                    $('#collection_rate').text(collectionRate.toFixed(1) + '%');
                }

                // Show alert
                function showAlert(message, type) {
                    const alertHtml = `
                        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                            <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'check-circle'} me-2"></i>
                            ${message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    $('.card-body').prepend(alertHtml);
                    
                    // Auto remove after 5 seconds
                    setTimeout(() => {
                        $('.alert').alert('close');
                    }, 5000);
                }

                // Log client-side errors to server
                function logClientError(message) {
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        data: {
                            action: 'log_error',
                            message: message,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        }
                    });
                }

                // View debt details
                $(document).on('click', '.view-details-btn', function() {
                    const debtId = $(this).data('debt-id');
                    
                    $.ajax({
                        url: 'view_debt_details.php',
                        type: 'GET',
                        data: { debt_id: debtId },
                        success: function(response) {
                            $('#debtDetailsContent').html(response);
                            $('#debtDetailsModal').modal('show');
                        },
                        error: function() {
                            showAlert('Failed to load debt details. Please try again.', 'danger');
                        }
                    });
                });

                // Record payment
                $(document).on('click', '.record-payment-btn', function() {
                    const debtId = $(this).data('debt-id');
                    const studentName = $(this).data('student-name');
                    
                    // Find the debt in current debts
                    const debt = currentDebts.find(d => d.id == debtId);
                    if (debt) {
                        $('#paymentDebtId').val(debtId);
                        $('#studentName').val(studentName);
                        $('#amountDue').val(parseFloat(debt.amount_due).toFixed(2));
                        $('#balance').val(parseFloat(debt.balance).toFixed(2));
                        $('#paymentAmount').val('');
                        $('#paymentMethod').val('');
                        $('#receiptNumber').val('');
                        $('#paymentNotes').val('');
                        
                        $('#recordPaymentModal').modal('show');
                    }
                });

                // Save payment
                $('#savePaymentBtn').click(function() {
                    const formData = {
                        debt_id: $('#paymentDebtId').val(),
                        payment_amount: $('#paymentAmount').val(),
                        payment_method: $('#paymentMethod').val(),
                        receipt_number: $('#receiptNumber').val(),
                        payment_notes: $('#paymentNotes').val(),
                        csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                    };
                    
                    if (!formData.payment_amount || parseFloat(formData.payment_amount) <= 0) {
                        showAlert('Please enter a valid payment amount.', 'warning');
                        return;
                    }
                    
                    if (!formData.payment_method) {
                        showAlert('Please select a payment method.', 'warning');
                        return;
                    }
                    
                    $.ajax({
                        url: 'record_payment.php',
                        type: 'POST',
                        data: formData,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $('#recordPaymentModal').modal('hide');
                                showAlert('Payment recorded successfully!', 'success');
                                filterDebts(); // Refresh the list
                            } else {
                                showAlert('Error: ' + (response.error || 'Failed to record payment'), 'danger');
                            }
                        },
                        error: function() {
                            showAlert('Failed to record payment. Please try again.', 'danger');
                        }
                    });
                });

                // Send reminder
                $(document).on('click', '.send-reminder-btn', function() {
                    const studentEmail = $(this).data('student-email');
                    const studentName = $(this).data('student-name');
                    
                    if (confirm(`Send payment reminder to ${studentName} (${studentEmail})?`)) {
                        $.ajax({
                            url: 'send_payment_reminder.php',
                            type: 'POST',
                            data: {
                                student_email: studentEmail,
                                student_name: studentName,
                                csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    showAlert('Payment reminder sent successfully!', 'success');
                                } else {
                                    showAlert('Error: ' + (response.error || 'Failed to send reminder'), 'danger');
                                }
                            },
                            error: function() {
                                showAlert('Failed to send reminder. Please try again.', 'danger');
                            }
                        });
                    }
                });

                // Bulk send reminders
                $('#send_reminders_btn').click(function() {
                    if (currentDebts.length === 0) {
                        showAlert('No debts to send reminders for.', 'warning');
                        return;
                    }
                    
                    const unpaidDebts = currentDebts.filter(d => d.status === 'Unpaid');
                    if (unpaidDebts.length === 0) {
                        showAlert('No unpaid debts found for the current filter.', 'info');
                        return;
                    }
                    
                    if (confirm(`Send payment reminders to ${unpaidDebts.length} students with unpaid debts?`)) {
                        const studentIds = unpaidDebts.map(d => d.user_id);
                        
                        $.ajax({
                            url: 'send_bulk_reminders.php',
                            type: 'POST',
                            data: {
                                student_ids: studentIds,
                                csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    showAlert(`Payment reminders sent to ${response.sent_count} students successfully!`, 'success');
                                } else {
                                    showAlert('Error: ' + (response.error || 'Failed to send reminders'), 'danger');
                                }
                            },
                            error: function() {
                                showAlert('Failed to send reminders. Please try again.', 'danger');
                            }
                        });
                    }
                });

                // Event Listeners
                $('#filter_btn').click(filterDebts);
                $('#search_btn').click(filterDebts);
                $('#reset_btn').click(function() {
                    $('#academic_year').val('<?php echo $default_academic_year; ?>');
                    $('#department_id').val('');
                    $('#class_id').val('');
                    $('#fee_type').val('');
                    $('#status_filter').val('');
                    $('#search_query').val('');
                    filterClasses();
                    filterDebts();
                });
                $('#export_btn').click(function() {
                    debtsTable.button('.buttons-excel').trigger();
                });
                $('#print_btn').click(function() {
                    debtsTable.button('.buttons-print').trigger();
                });

                // Department change
                $('#department_id').change(filterClasses);
                
                // Academic year change
                $('#academic_year').change(function() {
                    filterClasses();
                    filterDebts();
                });
                
                // Filter changes
                $('#class_id, #fee_type, #status_filter').change(filterDebts);
                
                // Search input with debounce
                let searchTimeout;
                $('#search_query').on('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(filterDebts, 500);
                });
                
                // Initialize with data
                <?php if ($default_academic_year): ?>
                    filterDebts();
                <?php endif; ?>
                
            } catch (e) {
                console.error('JavaScript initialization error:', e);
                logClientError('JavaScript initialization error: ' + e.message);
            }
        });
    </script>
</body>
</html>