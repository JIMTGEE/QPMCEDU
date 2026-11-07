<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Database connection failed. Please contact the administrator.");
}

// Fallback logging function
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $details) {
        error_log("[$user_id] $action: $details", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
    }
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    log_activity($user_id, 'access_denied', 'Attempted to access view_payments.php without admin role');
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed view_payments.php, IP: $ip_address, User Agent: $user_agent");

// Generate and store CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
$csrf_query = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
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

// Fetch payments (initial load with default filters)
$payments = [];
$default_academic_year = !empty($academic_years) ? $academic_years[0] : '';
if ($default_academic_year) {
    $payment_query = "SELECT p.id, p.user_id, p.fee_id, p.amount_paid, p.payment_date, p.payment_method, p.receipt_number, 
                             p.class_id, u.name AS student_name, c.class_name, f.fee_type, f.academic_year, 
                             ac.term_name
                      FROM payments p 
                      JOIN users u ON p.user_id = u.id 
                      JOIN classes c ON p.class_id = c.id 
                      JOIN fees f ON p.fee_id = f.id 
                      LEFT JOIN debts d ON p.fee_id = d.fee_id AND p.user_id = d.user_id 
                      LEFT JOIN academic_calendar ac ON d.term_id = ac.id 
                      WHERE u.role = 'student' AND u.status = 'active' AND f.academic_year = ? 
                      ORDER BY p.payment_date DESC, c.class_name, u.name";
    if ($stmt = $conn->prepare($payment_query)) {
        $stmt->bind_param("s", $default_academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $payments[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'fee_id' => $row['fee_id'],
                'student_name' => $row['student_name'],
                'class_name' => $row['class_name'],
                'fee_type' => $row['fee_type'],
                'academic_year' => $row['academic_year'],
                'term_name' => $row['term_name'] ?? 'N/A',
                'amount_paid' => $row['amount_paid'],
                'payment_date' => $row['payment_date'],
                'payment_method' => $row['payment_method'],
                'receipt_number' => $row['receipt_number'],
                'class_id' => $row['class_id'],
                'department_id' => $classes[$row['class_id']]['department_id']
            ];
        }
        $stmt->close();
        error_log("Fetched " . count($payments) . " payments for academic_year=$default_academic_year", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
    } else {
        error_log("Failed to prepare payments query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
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
    if ($_POST['action'] === 'filter_payments') {
        $academic_year = trim($_POST['academic_year'] ?? '');
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $search_query = trim($_POST['search_query'] ?? '');
        $filtered_payments = [];
        $query = "SELECT p.id, p.user_id, p.fee_id, p.amount_paid, p.payment_date, p.payment_method, p.receipt_number, 
                         p.class_id, u.name AS student_name, c.class_name, f.fee_type, f.academic_year, 
                         ac.term_name
                  FROM payments p 
                  JOIN users u ON p.user_id = u.id 
                  JOIN classes c ON p.class_id = c.id 
                  JOIN fees f ON p.fee_id = f.id 
                  LEFT JOIN debts d ON p.fee_id = d.fee_id AND p.user_id = d.user_id 
                  LEFT JOIN academic_calendar ac ON d.term_id = ac.id 
                  WHERE u.role = 'student' AND u.status = 'active'";
        $params = [];
        $types = "";
        if ($academic_year && in_array($academic_year, $academic_years)) {
            $query .= " AND f.academic_year = ?";
            $types .= "s";
            $params[] = &$academic_year;
        }
        if ($dept_id > 0) {
            $query .= " AND c.department_id = ?";
            $types .= "i";
            $params[] = &$dept_id;
        }
        if ($class_id > 0) {
            $query .= " AND p.class_id = ?";
            $types .= "i";
            $params[] = &$class_id;
        }
        if ($search_query) {
            $query .= " AND u.name LIKE ?";
            $types .= "s";
            $search_param = "%$search_query%";
            $params[] = &$search_param;
        }
        $query .= " ORDER BY p.payment_date DESC, c.class_name, u.name LIMIT 100";
        if ($stmt = $conn->prepare($query)) {
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $filtered_payments[] = [
                    'id' => $row['id'],
                    'user_id' => $row['user_id'],
                    'fee_id' => $row['fee_id'],
                    'student_name' => $row['student_name'],
                    'class_name' => $row['class_name'],
                    'fee_type' => $row['fee_type'],
                    'academic_year' => $row['academic_year'],
                    'term_name' => $row['term_name'] ?? 'N/A',
                    'amount_paid' => $row['amount_paid'],
                    'payment_date' => $row['payment_date'],
                    'payment_method' => $row['payment_method'],
                    'receipt_number' => $row['receipt_number'],
                    'class_id' => $row['class_id'],
                    'department_id' => $classes[$row['class_id']]['department_id']
                ];
            }
            $stmt->close();
        }
        error_log("Filtered " . count($filtered_payments) . " payments for academic_year=$academic_year, dept_id=$dept_id, class_id=$class_id, search_query=$search_query", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        echo json_encode(['success' => true, 'payments' => $filtered_payments]);
        exit;
    }
    if ($_POST['action'] === 'log_error') {
        $error_message = trim($_POST['message'] ?? 'Unknown client error');
        log_activity($_SESSION['user_id'], 'client_error', "Client-side error: $error_message");
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
    <meta name="description" content="Admin page for viewing and tracking student payments in Queen's Paradise Montessori School.">
    <meta name="keywords" content="Queen's Paradise Montessori School, view payments, financial tracking, admin dashboard">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Payments - Queen's Paradise Montessori School">
    <meta property="og:description" content="Admin interface for tracking student payments by academic year, class, and term with print options.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/view_payments.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Payments - Queen's Paradise Montessori School">
    <meta name="twitter:description" content="Admin interface for tracking student payments by academic year, class, and term with print options.">
    <title>View Payments - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
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
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 98, 0, 0.25);
        }
        .btn-primary {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            margin-right: 0.5rem;
        }
        .btn-primary:hover {
            background: linear-gradient(to right, #e55a00, #e08300);
            animation: animate__pulse 0.5s;
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            border-radius: 8px;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
        }
        .filter-section {
            background-color: #fff;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
        }
        .alert {
            border-radius: 8px;
        }
        .table-responsive {
            border-radius: 8px;
            overflow-x: auto;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .loading-spinner {
            display: none;
            margin-left: 10px;
        }
        .button-section {
            margin-top: 1rem;
        }
        .print-table {
            display: none;
        }
        @media print {
            body {
                background: white;
            }
            .card, .filter-section, .alert, .button-section {
                display: none;
            }
            .print-table {
                display: table;
                width: 100%;
                border-collapse: collapse;
                font-size: 12pt;
            }
            .print-table th, .print-table td {
                border: 1px solid #000;
                padding: 8px;
                text-align: left;
            }
            .print-table th {
                background-color: #f0f0f0;
                font-weight: bold;
            }
            @page {
                size: A4;
                margin: 1cm;
            }
        }
        @media (max-width: 767.98px) {
            .filter-section {
                padding: 0.5rem;
            }
            .card-header h3 {
                font-size: 1.2rem;
            }
            .form-label {
                font-size: 0.9rem;
            }
            .btn {
                font-size: 0.9rem;
                padding: 0.5rem;
                width: 100%;
                margin-bottom: 0.5rem;
            }
            .button-section {
                text-align: center;
            }
            .table {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) {
        error_log("Admin header file missing: $header_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin header template at $header_path");
        $_SESSION['error_msg'] = "Error: Admin header template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $header_path; 
    ?>

    <div class="container my-4">
        <section class="card mb-4">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-money-check-alt me-2"></i>View Payments</h3>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php unset($_SESSION['success_msg']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error_msg'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php unset($_SESSION['error_msg']); ?>
                    </div>
                <?php endif; ?>
                <?php if (empty($academic_years)): ?>
                    <div class="alert alert-warning">No academic years found in the database. Please add academic years.</div>
                <?php endif; ?>
                <?php if (empty($departments)): ?>
                    <div class="alert alert-warning">No departments found in the database. Please add departments.</div>
                <?php endif; ?>
                <?php if (empty($classes)): ?>
                    <div class="alert alert-warning">No classes found in the database. Please add classes.</div>
                <?php endif; ?>
                <?php if (empty($payments)): ?>
                    <div class="alert alert-warning">No payment records found for the selected academic year. Try different filters.</div>
                <?php endif; ?>

                <div class="filter-section">
                    <form method="POST" class="needs-validation" novalidate id="filter-form" aria-label="Filter payments">
                        <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3">
                            <!-- Academic Year -->
                            <div class="col-md-4">
                                <label for="academic_year" class="form-label">Academic Year</label>
                                <select name="academic_year" id="academic_year" class="form-select" required>
                                    <option value="">Select Academic Year</option>
                                    <?php foreach ($academic_years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo isset($_POST['academic_year']) && $_POST['academic_year'] == $year ? 'selected' : ($year === $default_academic_year ? 'selected' : ''); ?>>
                                            <?php echo htmlspecialchars($year); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select an academic year.</div>
                            </div>

                            <!-- Department -->
                            <div class="col-md-4">
                                <label for="department_id" class="form-label">Department</label>
                                <select name="department_id" id="department_id" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $id => $dept): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>" <?php echo isset($_POST['department_id']) && $_POST['department_id'] == $id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a department.</div>
                            </div>

                            <!-- Class -->
                            <div class="col-md-4">
                                <label for="class_id" class="form-label">Class</label>
                                <select name="class_id" id="class_id" class="form-select">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $id => $class): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>" data-dept="<?php echo htmlspecialchars($class['department_id']); ?>" <?php echo isset($_POST['class_id']) && $_POST['class_id'] == $id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a class.</div>
                            </div>

                            <!-- Search Students -->
                            <div class="col-md-6">
                                <label for="search_query" class="form-label">Search Students</label>
                                <input type="text" id="search_query" class="form-control" placeholder="Enter student name..." value="<?php echo isset($_POST['search_query']) ? htmlspecialchars($_POST['search_query']) : ''; ?>">
                            </div>

                            <!-- Filter and Print Buttons -->
                            <div class="col-md-6 button-section">
                                <button type="button" class="btn btn-primary" id="filter_btn">
                                    <i class="fas fa-filter me-1"></i>Apply Filters
                                    <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                </button>
                                <button type="button" class="btn btn-primary" id="print_btn">
                                    <i class="fas fa-print me-1"></i>Print Report
                                    <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Payments Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="payments_table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Class</th>
                                <th>Fee Type</th>
                                <th>Academic Year</th>
                                <th>Term</th>
                                <th>Amount Paid (GH₵)</th>
                                <th>Payment Date</th>
                                <th>Payment Method</th>
                                <th>Receipt Number</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['fee_type']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['academic_year']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['term_name']); ?></td>
                                    <td><?php echo number_format($payment['amount_paid'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Print Table -->
                <table class="print-table">
                    <tr><th>Student Name</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['student_name']); ?></td><?php endforeach; ?></tr>
                    <tr><th>Class</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['class_name']); ?></td><?php endforeach; ?></tr>
                    <tr><th>Fee Type</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['fee_type']); ?></td><?php endforeach; ?></tr>
                    <tr><th>Academic Year</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['academic_year']); ?></td><?php endforeach; ?></tr>
                    <tr><th>Term</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['term_name']); ?></td><?php endforeach; ?></tr>
                    <tr><th>Amount Paid (GH₵)</th><?php foreach ($payments as $payment): ?><td><?php echo number_format($payment['amount_paid'], 2); ?></td><?php endforeach; ?></tr>
                    <tr><th>Payment Date</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['payment_date']); ?></td><?php endforeach; ?></tr>
                    <tr><th>Payment Method</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['payment_method']); ?></td><?php endforeach; ?></tr>
                    <tr><th>Receipt Number</th><?php foreach ($payments as $payment): ?><td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td><?php endforeach; ?></tr>
                </table>
            </div>
        </section>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Admin footer file missing: $footer_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin footer template at $footer_path");
        $_SESSION['error_msg'] = "Error: Admin footer template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    $conn->close();
    ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            try {
                // Initialize Bootstrap validation
                const form = document.getElementById('filter-form');
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    form.classList.add('was-validated');
                }, false);

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
                                alert('Error filtering classes: ' + response.error);
                                logClientError('Class filter AJAX error: ' + response.error);
                                return;
                            }
                            let options = '<option value="">All Classes</option>';
                            response.classes.forEach(cls => {
                                options += `<option value="${cls.id}" data-dept="${deptId}">${cls.class_name}</option>`;
                            });
                            $('#class_id').html(options);
                            filterPayments();
                        },
                        error: function(xhr, status, error) {
                            console.error('Class filter AJAX error:', status, error);
                            alert('Failed to load classes. Please try again.');
                            logClientError('Class filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }

                // Filter payments via AJAX
                function filterPayments() {
                    const academicYear = $('#academic_year').val();
                    const deptId = $('#department_id').val();
                    const classId = $('#class_id').val();
                    const searchQuery = $('#search_query').val();
                    if (!academicYear) {
                        $('#payments_table tbody').html('');
                        $('.print-table').html('');
                        return;
                    }
                    $('#filter_btn .loading-spinner').show();
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_payments',
                            academic_year: academicYear,
                            department_id: deptId,
                            class_id: classId,
                            search_query: searchQuery,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            $('#filter_btn .loading-spinner').hide();
                            if (response.error) {
                                console.error('Payment filter error:', response.error);
                                alert('Error filtering payments: ' + response.error);
                                logClientError('Payment filter AJAX error: ' + response.error);
                                return;
                            }
                            let tbody = '';
                            let printRows = {
                                student_name: '<tr><th>Student Name</th>',
                                class_name: '<tr><th>Class</th>',
                                fee_type: '<tr><th>Fee Type</th>',
                                academic_year: '<tr><th>Academic Year</th>',
                                term_name: '<tr><th>Term</th>',
                                amount_paid: '<tr><th>Amount Paid (GH₵)</th>',
                                payment_date: '<tr><th>Payment Date</th>',
                                payment_method: '<tr><th>Payment Method</th>',
                                receipt_number: '<tr><th>Receipt Number</th>'
                            };
                            if (response.payments.length === 0) {
                                tbody = '<tr><td colspan="9" class="text-center">No payments found for the selected filters.</td></tr>';
                            } else {
                                response.payments.forEach(payment => {
                                    tbody += `
                                        <tr>
                                            <td>${payment.student_name}</td>
                                            <td>${payment.class_name}</td>
                                            <td>${payment.fee_type}</td>
                                            <td>${payment.academic_year}</td>
                                            <td>${payment.term_name}</td>
                                            <td>${parseFloat(payment.amount_paid).toFixed(2)}</td>
                                            <td>${payment.payment_date}</td>
                                            <td>${payment.payment_method}</td>
                                            <td>${payment.receipt_number || 'N/A'}</td>
                                        </tr>`;
                                    printRows.student_name += `<td>${payment.student_name}</td>`;
                                    printRows.class_name += `<td>${payment.class_name}</td>`;
                                    printRows.fee_type += `<td>${payment.fee_type}</td>`;
                                    printRows.academic_year += `<td>${payment.academic_year}</td>`;
                                    printRows.term_name += `<td>${payment.term_name}</td>`;
                                    printRows.amount_paid += `<td>${parseFloat(payment.amount_paid).toFixed(2)}</td>`;
                                    printRows.payment_date += `<td>${payment.payment_date}</td>`;
                                    printRows.payment_method += `<td>${payment.payment_method}</td>`;
                                    printRows.receipt_number += `<td>${payment.receipt_number || 'N/A'}</td>`;
                                });
                            }
                            $('#payments_table tbody').html(tbody);
                            $('.print-table').html(
                                printRows.student_name + '</tr>' +
                                printRows.class_name + '</tr>' +
                                printRows.fee_type + '</tr>' +
                                printRows.academic_year + '</tr>' +
                                printRows.term_name + '</tr>' +
                                printRows.amount_paid + '</tr>' +
                                printRows.payment_date + '</tr>' +
                                printRows.payment_method + '</tr>' +
                                printRows.receipt_number + '</tr>'
                            );
                        },
                        error: function(xhr, status, error) {
                            $('#filter_btn .loading-spinner').hide();
                            console.error('Payment filter AJAX error:', status, error);
                            alert('Failed to load payments. Please try again.');
                            logClientError('Payment filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
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

                // Handle filter button click
                $('#filter_btn').click(function() {
                    filterPayments();
                });

                // Handle print button
                $('#print_btn').click(function() {
                    $('#print_btn .loading-spinner').show();
                    setTimeout(() => {
                        window.print();
                        $('#print_btn .loading-spinner').hide();
                    }, 500);
                });

                // Handle search input (debounced)
                let searchTimeout;
                $('#search_query').on('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(filterPayments, 500);
                });

                // Initialize filters
                $('#department_id').change(filterClasses);
                $('#academic_year').change(function() {
                    filterClasses();
                    filterPayments();
                });
                $('#class_id').change(filterPayments);
                <?php if (isset($_POST['academic_year'])): ?>
                    filterClasses();
                    filterPayments();
                <?php endif; ?>
            } catch (e) {
                console.error('JavaScript initialization error:', e);
                logClientError('JavaScript initialization error: ' + e.message);
            }
        });
    </script>
</body>
</html>