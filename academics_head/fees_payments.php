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
    log_activity($user_id, 'access_denied', 'Attempted to access fees_payments.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed fees_payments.php, IP: $ip_address, User Agent: $user_agent", $conn);

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

// Initialize variables
$selected_year = !empty($academic_years) ? $academic_years[0] : '';
$selected_dept = 0;
$selected_class = 0;
$search_query = '';
$fees = [];
$student_debts = [];
$total_fees = 0;
$total_debts = 0;
$total_payments = 0;
$partial_payments_total = 0;
$paid_count = 0;
$unpaid_count = 0;
$partial_count = 0;

// Handle AJAX requests for filtering and export
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
    if ($_POST['action'] === 'filter_data') {
        $academic_year = trim($_POST['academic_year'] ?? '');
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $search_query = trim($_POST['search_query'] ?? '');
        
        // Fetch fees
        $fees_query = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, f.department, c.class_name, u.name as learner_name,
                              CASE WHEN f.class_id IS NOT NULL THEN 'Class' WHEN f.user_id IS NOT NULL THEN 'Learner' ELSE 'School' END as target_type,
                              COALESCE(SUM(d.amount_due), 0) as outstanding_debt,
                              COALESCE(SUM(p.amount_paid), 0) as amount_paid
                       FROM fees f
                       LEFT JOIN classes c ON f.class_id = c.id
                       LEFT JOIN users u ON f.user_id = u.id
                       LEFT JOIN debts d ON f.id = d.fee_id
                       LEFT JOIN payments p ON f.id = p.fee_id
                       WHERE f.academic_year = ?";
        $params = [$academic_year];
        $types = "s";
        if ($dept_id > 0) {
            $fees_query .= " AND f.department = ?";
            $types .= "s";
            $params[] = $departments[$dept_id]['enum_name'];
        }
        if ($class_id > 0) {
            $fees_query .= " AND f.class_id = ?";
            $types .= "i";
            $params[] = $class_id;
        }
        if ($search_query) {
            $fees_query .= " AND (f.fee_type LIKE ? OR u.name LIKE ?)";
            $types .= "ss";
            $search_param = "%$search_query%";
            $params[] = $search_param;
            $params[] = $search_param;
        }
        $fees_query .= " GROUP BY f.id ORDER BY f.due_date";
        $fees_stmt = $conn->prepare($fees_query);
        if ($fees_stmt) {
            if ($params) {
                $fees_stmt->bind_param($types, ...$params);
            }
            $fees_stmt->execute();
            $fees_result = $fees_stmt->get_result();
            $fees = [];
            $total_fees = 0;
            $total_debts = 0;
            $total_payments = 0;
            $partial_payments_total = 0;
            $paid_count = 0;
            $unpaid_count = 0;
            $partial_count = 0;
            while ($row = $fees_result->fetch_assoc()) {
                $fees[] = $row;
                $total_fees += $row['amount'];
                $total_debts += $row['outstanding_debt'];
                $total_payments += $row['amount_paid'];
                
                // CORRECTED: Track partial payments and status counts
                if ($row['amount'] > 0) {
                    $percentage_paid = ($row['amount_paid'] / $row['amount']) * 100;
                    if ($percentage_paid >= 100) {
                        $paid_count++;
                    } elseif ($percentage_paid > 0) {
                        $partial_count++;
                        $partial_payments_total += $row['amount_paid'];
                    } else {
                        $unpaid_count++;
                    }
                }
            }
            $fees_stmt->close();
        } else {
            error_log("Fees query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        }

        // Fetch student debts
        $debts_query = "SELECT u.id as student_id, u.name as student_name, f.id as fee_id, f.fee_type, f.amount, f.academic_year, f.department,
                               c.class_name, d.amount_due, COALESCE(SUM(p.amount_paid), 0) as amount_paid
                        FROM users u
                        JOIN debts d ON u.id = d.user_id
                        JOIN fees f ON d.fee_id = f.id
                        LEFT JOIN classes c ON f.class_id = c.id
                        LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
                        LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
                        WHERE f.academic_year = ? AND u.role = 'student' AND u.status = 'active'";
        $params = [$academic_year];
        $types = "s";
        if ($dept_id > 0) {
            $debts_query .= " AND f.department = ?";
            $types .= "s";
            $params[] = $departments[$dept_id]['enum_name'];
        }
        if ($class_id > 0) {
            $debts_query .= " AND f.class_id = ?";
            $types .= "i";
            $params[] = $class_id;
        }
        if ($search_query) {
            $debts_query .= " AND u.name LIKE ?";
            $types .= "s";
            $search_param = "%$search_query%";
            $params[] = $search_param;
        }
        $debts_query .= " GROUP BY u.id, f.id ORDER BY u.name, f.due_date";
        $debts_stmt = $conn->prepare($debts_query);
        if ($debts_stmt) {
            if ($params) {
                $debts_stmt->bind_param($types, ...$params);
            }
            $debts_stmt->execute();
            $debts_result = $debts_stmt->get_result();
            $student_debts = [];
            while ($row = $debts_result->fetch_assoc()) {
                $student_debts[] = $row;
            }
            $debts_stmt->close();
        } else {
            error_log("Student debts query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        }

        error_log("Filtered fees: " . count($fees) . ", debts: " . count($student_debts) . " for academic_year=$academic_year, dept_id=$dept_id, class_id=$class_id, search_query=$search_query", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        log_activity($_SESSION['user_id'], 'filtered_fees_dashboard', "Filtered data for academic_year=$academic_year, dept_id=$dept_id, class_id=$class_id, search_query=$search_query, Total Fees: ₵$total_fees, Total Debts: ₵$total_debts, Total Payments: ₵$total_payments, Partial Payments: ₵$partial_payments_total, Paid: $paid_count, Partial: $partial_count, Unpaid: $unpaid_count", $conn);
        echo json_encode([
            'success' => true,
            'fees' => $fees,
            'student_debts' => $student_debts,
            'total_fees' => $total_fees,
            'total_debts' => $total_debts,
            'total_payments' => $total_payments,
            'partial_payments_total' => $partial_payments_total,
            'paid_count' => $paid_count,
            'partial_count' => $partial_count,
            'unpaid_count' => $unpaid_count
        ]);
        exit;
    }
    if ($_POST['action'] === 'log_error') {
        $error_message = trim($_POST['message'] ?? 'Unknown client error');
        log_activity($_SESSION['user_id'], 'client_error', "Client-side error: $error_message", $conn);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_POST['action'] === 'delete_fee') {
        $fee_id = (int)$_POST['fee_id'];
        
        $conn->begin_transaction();
        try {
            $delete_payments = "DELETE FROM payments WHERE fee_id = ?";
            if ($stmt = $conn->prepare($delete_payments)) {
                $stmt->bind_param("i", $fee_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Prepare failed for delete payments: " . $conn->error);
            }
            
            $delete_debts = "DELETE FROM debts WHERE fee_id = ?";
            if ($stmt = $conn->prepare($delete_debts)) {
                $stmt->bind_param("i", $fee_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Prepare failed for delete debts: " . $conn->error);
            }
            
            $delete_fee = "DELETE FROM fees WHERE id = ?";
            if ($stmt = $conn->prepare($delete_fee)) {
                $stmt->bind_param("i", $fee_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Prepare failed for delete fee: " . $conn->error);
            }
            
            $conn->commit();
            
            log_activity($_SESSION['user_id'], 'delete_fee', "Deleted fee ID: $fee_id", $conn);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Delete fee failed: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
            echo json_encode(['error' => 'Failed to delete fee: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Initial data fetch
if ($selected_year) {
    $fees_query = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, f.department, c.class_name, u.name as learner_name,
                          CASE WHEN f.class_id IS NOT NULL THEN 'Class' WHEN f.user_id IS NOT NULL THEN 'Learner' ELSE 'School' END as target_type,
                          COALESCE(SUM(d.amount_due), 0) as outstanding_debt,
                          COALESCE(SUM(p.amount_paid), 0) as amount_paid
                   FROM fees f
                   LEFT JOIN classes c ON f.class_id = c.id
                   LEFT JOIN users u ON f.user_id = u.id
                   LEFT JOIN debts d ON f.id = d.fee_id
                   LEFT JOIN payments p ON f.id = p.fee_id
                   WHERE f.academic_year = ?
                   GROUP BY f.id
                   ORDER BY f.due_date";
    if ($fees_stmt = $conn->prepare($fees_query)) {
        $fees_stmt->bind_param("s", $selected_year);
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        while ($row = $fees_result->fetch_assoc()) {
            $fees[] = $row;
            $total_fees += $row['amount'];
            $total_debts += $row['outstanding_debt'];
            $total_payments += $row['amount_paid'];
            
            // CORRECTED: Track partial payments and status counts
            if ($row['amount'] > 0) {
                $percentage_paid = ($row['amount_paid'] / $row['amount']) * 100;
                if ($percentage_paid >= 100) {
                    $paid_count++;
                } elseif ($percentage_paid > 0) {
                    $partial_count++;
                    $partial_payments_total += $row['amount_paid'];
                } else {
                    $unpaid_count++;
                }
            }
        }
        $fees_stmt->close();
    } else {
        error_log("Initial fees query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }

    $debts_query = "SELECT u.id as student_id, u.name as student_name, f.id as fee_id, f.fee_type, f.amount, f.academic_year, f.department,
                           c.class_name, d.amount_due, COALESCE(SUM(p.amount_paid), 0) as amount_paid
                    FROM users u
                    JOIN debts d ON u.id = d.user_id
                    JOIN fees f ON d.fee_id = f.id
                    LEFT JOIN classes c ON f.class_id = c.id
                    LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
                    LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
                    WHERE f.academic_year = ? AND u.role = 'student' AND u.status = 'active'
                    GROUP BY u.id, f.id
                    ORDER BY u.name, f.due_date";
    if ($debts_stmt = $conn->prepare($debts_query)) {
        $debts_stmt->bind_param("s", $selected_year);
        $debts_stmt->execute();
        $debts_result = $debts_stmt->get_result();
        while ($row = $debts_result->fetch_assoc()) {
            $student_debts[] = $row;
        }
        $debts_stmt->close();
    } else {
        error_log("Initial student debts query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }

    log_activity($_SESSION['user_id'], 'viewed_fees_dashboard', "Initial load for academic_year=$selected_year, Total Fees: ₵$total_fees, Total Debts: ₵$total_debts, Total Payments: ₵$total_payments, Partial Payments: ₵$partial_payments_total, Paid: $paid_count, Partial: $partial_count, Unpaid: $unpaid_count", $conn);
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['academic_year'])) {
    $academic_year = trim($_GET['academic_year']);
    $dept_id = (int)($_GET['department_id'] ?? 0);
    $class_id = (int)($_GET['class_id'] ?? 0);
    $search_query = trim($_GET['search_query'] ?? '');

    $fees_query = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, f.department, c.class_name, u.name as learner_name,
                          CASE WHEN f.class_id IS NOT NULL THEN 'Class' WHEN f.user_id IS NOT NULL THEN 'Learner' ELSE 'School' END as target_type,
                          COALESCE(SUM(d.amount_due), 0) as outstanding_debt,
                          COALESCE(SUM(p.amount_paid), 0) as amount_paid
                   FROM fees f
                   LEFT JOIN classes c ON f.class_id = c.id
                   LEFT JOIN users u ON f.user_id = u.id
                   LEFT JOIN debts d ON f.id = d.fee_id
                   LEFT JOIN payments p ON f.id = p.fee_id
                   WHERE f.academic_year = ?";
    $params = [$academic_year];
    $types = "s";
    if ($dept_id > 0) {
        $fees_query .= " AND f.department = ?";
        $types .= "s";
        $params[] = $departments[$dept_id]['enum_name'];
    }
    if ($class_id > 0) {
        $fees_query .= " AND f.class_id = ?";
        $types .= "i";
        $params[] = $class_id;
    }
    if ($search_query) {
        $fees_query .= " AND (f.fee_type LIKE ? OR u.name LIKE ?)";
        $types .= "ss";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    $fees_query .= " GROUP BY f.id ORDER BY f.due_date";
    $fees_stmt = $conn->prepare($fees_query);
    $export_fees = [];
    if ($fees_stmt) {
        if ($params) {
            $fees_stmt->bind_param($types, ...$params);
        }
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        while ($row = $fees_result->fetch_assoc()) {
            $export_fees[] = $row;
        }
        $fees_stmt->close();
    }

    $debts_query = "SELECT u.id as student_id, u.name as student_name, f.id as fee_id, f.fee_type, f.amount, f.academic_year, f.department,
                           c.class_name, d.amount_due, COALESCE(SUM(p.amount_paid), 0) as amount_paid
                    FROM users u
                    JOIN debts d ON u.id = d.user_id
                    JOIN fees f ON d.fee_id = f.id
                    LEFT JOIN classes c ON f.class_id = c.id
                    LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
                    LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
                    WHERE f.academic_year = ? AND u.role = 'student' AND u.status = 'active'";
    $params = [$academic_year];
    $types = "s";
    if ($dept_id > 0) {
        $debts_query .= " AND f.department = ?";
        $types .= "s";
        $params[] = $departments[$dept_id]['enum_name'];
    }
    if ($class_id > 0) {
        $debts_query .= " AND f.class_id = ?";
        $types .= "i";
        $params[] = $class_id;
    }
    if ($search_query) {
        $debts_query .= " AND u.name LIKE ?";
        $types .= "s";
        $search_param = "%$search_query%";
        $params[] = $search_param;
    }
    $debts_query .= " GROUP BY u.id, f.id ORDER BY u.name, f.due_date";
    $debts_stmt = $conn->prepare($debts_query);
    $export_debts = [];
    if ($debts_stmt) {
        if ($params) {
            $debts_stmt->bind_param($types, ...$params);
        }
        $debts_stmt->execute();
        $debts_result = $debts_stmt->get_result();
        while ($row = $debts_result->fetch_assoc()) {
            $export_debts[] = $row;
        }
        $debts_stmt->close();
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="fees_report_' . $academic_year . '_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Fees Summary']);
    fputcsv($output, ['Fee Type', 'Target', 'Class', 'Learner', 'Department', 'Amount (GH₵)', 'Outstanding Debt (GH₵)', 'Amount Paid (GH₵)', 'Payment Status', 'Due Date', 'Academic Year']);
    foreach ($export_fees as $fee) {
        // Determine payment status for CSV export - CORRECTED LOGIC
        $payment_status = '';
        if ($fee['amount'] > 0) {
            $percentage_paid = ($fee['amount_paid'] / $fee['amount']) * 100;
            if ($percentage_paid >= 100) {
                $payment_status = 'Paid';
            } elseif ($percentage_paid > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Unpaid';
            }
        }
        
        fputcsv($output, [
            $fee['fee_type'],
            $fee['target_type'],
            $fee['class_name'] ?? 'N/A',
            $fee['learner_name'] ?? 'N/A',
            $fee['department'],
            number_format($fee['amount'], 2),
            number_format($fee['outstanding_debt'], 2),
            number_format($fee['amount_paid'], 2),
            $payment_status,
            $fee['due_date'],
            $fee['academic_year']
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['Student Debt and Payment Details']);
    fputcsv($output, ['Student', 'Fee Type', 'Department', 'Class', 'Amount (GH₵)', 'Outstanding Debt (GH₵)', 'Amount Paid (GH₵)', 'Payment Status', 'Academic Year']);
    foreach ($export_debts as $debt) {
        // Determine payment status for CSV export - CORRECTED LOGIC
        $payment_status = '';
        if ($debt['amount'] > 0) {
            $percentage_paid = ($debt['amount_paid'] / $debt['amount']) * 100;
            if ($percentage_paid >= 100) {
                $payment_status = 'Paid';
            } elseif ($percentage_paid > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Unpaid';
            }
        }
        
        fputcsv($output, [
            $debt['student_name'],
            $debt['fee_type'],
            $debt['department'],
            $debt['class_name'] ?? 'N/A',
            number_format($debt['amount'], 2),
            number_format($debt['amount_due'], 2),
            number_format($debt['amount_paid'], 2),
            $payment_status,
            $debt['academic_year']
        ]);
    }
    fclose($output);
    log_activity($_SESSION['user_id'], 'export_csv', "Exported fees report for academic_year=$academic_year, dept_id=$dept_id, class_id=$class_id, search_query=$search_query", $conn);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin dashboard for managing fees, debts, and payments at Queen's Paradise Montessori School.">
    <meta name="keywords" content="Queen's Paradise Montessori School, fees, payments, debts, admin dashboard">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Fees & Payments Dashboard - Queen's Paradise Montessori School">
    <meta property="og:description" content="Admin interface for managing fees, debts, and payments with filtering and export options.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/fees_payments.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Fees & Payments Dashboard - Queen's Paradise Montessori School">
    <meta name="twitter:description" content="Admin interface for managing fees, debts, and payments with filtering and export options.">
    <title>Fees & Payments Dashboard - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #ffd700;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --danger-color: #dc3545;
            --light-bg: #f8f9fa;
            --border-radius: 12px;
            --box-shadow: 0 8px 24px rgba(255, 98, 0, 0.08);
            --transition: all 0.3s ease;
        }

        body {
            background: linear-gradient(135deg, #fff5f0 0%, #fff9f0 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #333;
            min-height: 100vh;
        }

        .dashboard-container {
            max-width: 100%;
            padding: 20px;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow);
            border-left: 5px solid var(--primary-color);
            animation: slideInDown 0.5s ease;
            background: linear-gradient(to right, white, #fff9f0);
        }

        .page-header h1 {
            color: #ff6200;
            font-weight: 700;
            margin: 0;
            font-size: 1.8rem;
        }

        .page-header .subtitle {
            color: #ff8c00;
            font-size: 0.95rem;
            margin: 0.25rem 0 0 0;
            font-weight: 500;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border: 1px solid #ffe8d6;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 28px rgba(255, 98, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }

        .stat-card.fees-charged::before {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }

        .stat-card.unpaid-fees::before {
            background: linear-gradient(to right, var(--danger-color), #ff6b6b);
        }

        .stat-card.fees-paid::before {
            background: linear-gradient(to right, var(--success-color), #4cd964);
        }

        .stat-card.partial-fees::before {
            background: linear-gradient(to right, var(--warning-color), #ffd166);
        }

        .stat-card.fees-counts::before {
            background: linear-gradient(to right, var(--info-color), #6f42c1);
        }

        .stat-card .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            color: white;
        }

        .stat-card.fees-charged .stat-icon {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.2);
        }

        .stat-card.unpaid-fees .stat-icon {
            background: linear-gradient(135deg, var(--danger-color), #ff6b6b);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.2);
        }

        .stat-card.fees-paid .stat-icon {
            background: linear-gradient(135deg, var(--success-color), #4cd964);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }

        .stat-card.partial-fees .stat-icon {
            background: linear-gradient(135deg, var(--warning-color), #ffd166);
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.2);
        }

        .stat-card.fees-counts .stat-icon {
            background: linear-gradient(135deg, var(--info-color), #6f42c1);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.2);
        }

        .stat-card .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: #ff6200;
            margin: 0.5rem 0;
            line-height: 1;
        }

        .stat-card.unpaid-fees .stat-value {
            color: var(--danger-color);
        }

        .stat-card.fees-paid .stat-value {
            color: var(--success-color);
        }

        .stat-card.partial-fees .stat-value {
            color: #e0a800;
        }

        .stat-card.fees-counts .stat-value {
            color: var(--info-color);
        }

        .stat-card .stat-label {
            color: #ff8c00;
            font-size: 0.9rem;
            margin: 0;
            font-weight: 600;
        }

        .stat-card.unpaid-fees .stat-label {
            color: var(--danger-color);
        }

        .stat-card.fees-paid .stat-label {
            color: var(--success-color);
        }

        .stat-card.partial-fees .stat-label {
            color: #e0a800;
        }

        .stat-card.fees-counts .stat-label {
            color: var(--info-color);
        }

        .filter-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
            border: 1px solid #ffe8d6;
            background: linear-gradient(to bottom, white, #fff9f0);
        }

        .filter-card .card-title {
            color: #ff6200;
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-card .form-label {
            font-weight: 500;
            color: #ff6200;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border: 2px solid #ffe8d6;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: var(--transition);
            background-color: white;
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 98, 0, 0.15);
        }

        .btn-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.2);
        }

        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 98, 0, 0.3);
            color: white;
            background: linear-gradient(135deg, #e55a00, #e08300);
        }

        .btn-custom.btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            box-shadow: none;
        }

        .btn-custom.btn-outline:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 8px 20px rgba(255, 98, 0, 0.3);
        }

        .btn-custom.btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #ff6b6b);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.2);
        }

        .btn-custom.btn-danger:hover {
            background: linear-gradient(135deg, #c82333, #ff5252);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        .btn-custom.btn-success {
            background: linear-gradient(135deg, var(--success-color), #4cd964);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }

        .btn-custom.btn-success:hover {
            background: linear-gradient(135deg, #218838, #2ecc71);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-custom.btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #ffd166);
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.2);
        }

        .btn-custom.btn-warning:hover {
            background: linear-gradient(135deg, #e0a800, #ffc107);
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .section-title {
            color: #ff6200;
            font-weight: 600;
            font-size: 1.3rem;
            margin: 2rem 0 1rem 0;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #ffe8d6;
        }

        .list-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
            border: 1px solid #ffe8d6;
        }

        .list-item {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #ffe8d6;
            transition: var(--transition);
            gap: 1rem;
        }

        .list-item:hover {
            background-color: #fff9f0;
            transform: translateX(2px);
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-item-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 1.25rem;
            border-bottom: 2px solid #ff8c00;
        }

        .list-item-header span {
            color: white;
            font-weight: 700;
        }

        .list-item-content {
            flex: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem 2rem;
            align-items: center;
        }

        .list-item-field {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 120px;
        }

        .list-item-field .field-label {
            font-size: 0.8rem;
            color: #ff8c00;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .list-item-field .field-value {
            font-size: 0.95rem;
            color: #333;
            font-weight: 500;
        }

        .list-item-field .field-value.fw-bold {
            color: #ff6200;
        }

        .list-item-field .field-value.text-danger {
            color: var(--danger-color) !important;
        }

        .list-item-field .field-value.text-success {
            color: var(--success-color) !important;
        }

        .list-item-actions {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        .badge-status {
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .badge-paid {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .badge-unpaid {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .badge-partial {
            background-color: rgba(255, 193, 7, 0.1);
            color: #e0a800;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        .badge-school {
            background: linear-gradient(135deg, rgba(255, 98, 0, 0.1), rgba(255, 140, 0, 0.1));
            color: var(--primary-color);
            border: 1px solid rgba(255, 98, 0, 0.2);
        }

        .badge-class {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 209, 102, 0.1));
            color: #e0a800;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        .badge-learner {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.1), rgba(72, 187, 210, 0.1));
            color: var(--info-color);
            border: 1px solid rgba(23, 162, 184, 0.2);
        }

        .badge-department {
            background-color: #fff9f0;
            color: #ff6200;
            border: 1px solid #ffe8d6;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .progress-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            min-width: 150px;
        }

        .progress {
            flex: 1;
            height: 8px;
            background-color: #ffe8d6;
            border-radius: 4px;
        }

        .progress-bar.bg-success {
            background-color: var(--success-color) !important;
        }

        .progress-bar.bg-warning {
            background-color: var(--warning-color) !important;
        }

        .progress-bar.bg-danger {
            background-color: var(--danger-color) !important;
        }

        .loading-spinner {
            display: none;
            margin-left: 0.5rem;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: #ff8c00;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #ffe8d6;
        }

        .alert-custom {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--box-shadow);
            padding: 1rem 1.5rem;
        }

        .alert-success {
            background: linear-gradient(to right, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-left: 4px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-danger {
            background: linear-gradient(to right, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            border-left: 4px solid var(--danger-color);
            color: var(--danger-color);
        }

        .alert-warning {
            background: linear-gradient(to right, rgba(255, 193, 7, 0.1), rgba(255, 193, 7, 0.05));
            border-left: 4px solid var(--warning-color);
            color: #e0a800;
        }

        .status-legend {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .status-legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: #666;
        }

        .status-legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
        }

        .status-legend-color.paid {
            background-color: var(--success-color);
        }

        .status-legend-color.partial {
            background-color: var(--warning-color);
        }

        .status-legend-color.unpaid {
            background-color: var(--danger-color);
        }

        .fee-counts {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
        }

        .fee-count-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
        }

        .fee-count-item.paid {
            color: var(--success-color);
        }

        .fee-count-item.partial {
            color: var(--warning-color);
        }

        .fee-count-item.unpaid {
            color: var(--danger-color);
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 10px;
            }

            .page-header {
                padding: 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn-custom {
                width: 100%;
                justify-content: center;
            }

            .list-item {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem;
            }

            .list-item-content {
                width: 100%;
                gap: 0.75rem;
            }

            .list-item-field {
                min-width: 100%;
                justify-content: space-between;
            }

            .list-item-actions {
                margin-left: 0;
                width: 100%;
                justify-content: flex-end;
            }

            .progress-container {
                min-width: 100%;
            }
        }

        @media (min-width: 769px) and (max-width: 1200px) {
            .list-item-field {
                min-width: 100px;
            }
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #fff5f0;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, #e55a00, #e08300);
        }

        .text-danger {
            color: var(--danger-color) !important;
        }

        .text-warning {
            color: #e0a800 !important;
        }

        .text-success {
            color: var(--success-color) !important;
        }

        .btn-group .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 4px;
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

    <div class="dashboard-container">
        <!-- Page Header -->
        <div class="page-header animate__animated animate__fadeIn">
            <h1><i class="fas fa-money-bill-wave" style="color: #ff6200;"></i> Fees & Payments Dashboard</h1>
            <p class="subtitle">Manage and monitor all fees, debts, and payments across the school</p>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($academic_years)): ?>
            <div class="alert alert-warning alert-custom">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No academic years found in the database. Please add academic years.
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card fees-charged animate__animated animate__fadeInLeft">
                <div class="stat-icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="stat-value" id="total_fees">₵<?php echo number_format($total_fees, 2); ?></div>
                <div class="stat-label">Total Fees Charged</div>
                <div class="fee-counts">
                    <div class="fee-count-item paid">
                        <i class="fas fa-circle"></i>
                        <span id="paid_count"><?php echo $paid_count; ?> Paid</span>
                    </div>
                    <div class="fee-count-item partial">
                        <i class="fas fa-circle"></i>
                        <span id="partial_count"><?php echo $partial_count; ?> Partial</span>
                    </div>
                    <div class="fee-count-item unpaid">
                        <i class="fas fa-circle"></i>
                        <span id="unpaid_count"><?php echo $unpaid_count; ?> Unpaid</span>
                    </div>
                </div>
            </div>

            <div class="stat-card unpaid-fees animate__animated animate__fadeInUp">
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value" id="total_debts">₵<?php echo number_format($total_debts, 2); ?></div>
                <div class="stat-label">Total Unpaid Fees</div>
                <small style="color: #dc3545;">Fees still owed by learners</small>
            </div>

            <div class="stat-card fees-paid animate__animated animate__fadeInRight">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value" id="total_payments">₵<?php echo number_format($total_payments, 2); ?></div>
                <div class="stat-label">Total Fees Paid</div>
                <small style="color: #28a745;">Payments received for the academic year</small>
            </div>
            
            <div class="stat-card partial-fees animate__animated animate__fadeInLeft">
                <div class="stat-icon">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <div class="stat-value" id="partial_payments">₵<?php echo number_format($partial_payments_total, 2); ?></div>
                <div class="stat-label">Partial Payments</div>
                <small style="color: #e0a800;">Partially paid fees (1-99% paid)</small>
            </div>
        </div>

        <!-- Status Legend -->
        <div class="status-legend animate__animated animate__fadeIn">
            <div class="status-legend-item">
                <span class="status-legend-color paid"></span>
                <span>Paid (100% or more paid)</span>
            </div>
            <div class="status-legend-item">
                <span class="status-legend-color partial"></span>
                <span>Partial (1-99% paid)</span>
            </div>
            <div class="status-legend-item">
                <span class="status-legend-color unpaid"></span>
                <span>Unpaid (0% paid)</span>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card animate__animated animate__fadeIn">
            <div class="card-title">
                <i class="fas fa-filter" style="color: #ff6200;"></i> Filter Data
            </div>
            <form method="POST" class="needs-validation" novalidate id="filter-form">
                <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="academic_year" class="form-label">Academic Year</label>
                        <select name="academic_year" id="academic_year" class="form-select" required>
                            <option value="">Select Academic Year</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $selected_year === $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select an academic year.</div>
                    </div>
                    <div class="col-md-3">
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
                    <div class="col-md-3">
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
                    <div class="col-md-3">
                        <label for="search_query" class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" id="search_query" class="form-control" placeholder="Search fees or students...">
                            <button class="btn btn-outline-secondary" type="button" id="clear_search">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn-custom" id="filter_btn">
                                <i class="fas fa-filter me-1"></i>Apply Filters
                                <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                            </button>
                            <button type="button" class="btn-custom btn-outline" id="print_btn">
                                <i class="fas fa-print me-1"></i>Print Report
                                <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                            </button>
                            <a href="?export=csv&academic_year=<?php echo urlencode($selected_year); ?>" class="btn-custom btn-success" id="export_btn">
                                <i class="fas fa-file-csv me-1"></i>Export CSV
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons animate__animated animate__fadeIn">
            <a href="add_fee.php" class="btn-custom">
                <i class="fas fa-plus me-1"></i>Add New Fee
            </a>
            <a href="bulk_reminder.php" class="btn-custom btn-warning">
                <i class="fas fa-sms me-1"></i>Send Bulk Reminders
            </a>
            <a href="view_debts.php" class="btn-custom btn-danger">
                <i class="fas fa-exclamation-triangle me-1"></i>View All Debts
            </a>
            <a href="view_payments.php" class="btn-custom btn-success">
                <i class="fas fa-list me-1"></i>View All Payments
            </a>
        </div>

        <!-- Fees Summary List -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-file-invoice-dollar me-2" style="color: #ff6200;"></i>Fees Summary
        </h3>
        
        <div class="list-container animate__animated animate__fadeIn" id="fees_list">
            <div class="list-item-header">
                <div class="row w-100">
                    <div class="col-md-2">Fee Type</div>
                    <div class="col-md-1">Target</div>
                    <div class="col-md-1">Class</div>
                    <div class="col-md-1">Learner</div>
                    <div class="col-md-1">Dept</div>
                    <div class="col-md-1">Amount</div>
                    <div class="col-md-1">Outstanding</div>
                    <div class="col-md-1">Paid</div>
                    <div class="col-md-1">Status</div>
                    <div class="col-md-1">Due Date</div>
                    <div class="col-md-1">Year</div>
                    <div class="col-md-1">Actions</div>
                </div>
            </div>
            <?php if (empty($fees)): ?>
                <div class="no-data">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <p>No fees found for the selected filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($fees as $fee): 
                    // CORRECTED: Determine status based on percentage paid
                    $status = '';
                    $status_class = '';
                    $status_icon = '';
                    if ($fee['amount'] > 0) {
                        $percentage_paid = ($fee['amount_paid'] / $fee['amount']) * 100;
                        if ($percentage_paid >= 100) {
                            $status = 'paid';
                            $status_class = 'badge-paid';
                            $status_icon = 'fa-check-circle';
                        } elseif ($percentage_paid > 0) {
                            $status = 'partial';
                            $status_class = 'badge-partial';
                            $status_icon = 'fa-clock';
                        } else {
                            $status = 'unpaid';
                            $status_class = 'badge-unpaid';
                            $status_icon = 'fa-times-circle';
                        }
                    } else {
                        $status = 'unpaid';
                        $status_class = 'badge-unpaid';
                        $status_icon = 'fa-times-circle';
                    }
                    
                    $target_class = $fee['target_type'] === 'School' ? 'badge-school' : 
                                   ($fee['target_type'] === 'Class' ? 'badge-class' : 'badge-learner');
                    
                    $due_date_class = '';
                    $due_date = new DateTime($fee['due_date']);
                    $today = new DateTime();
                    $interval = $today->diff($due_date);
                    $days_diff = $interval->days * ($interval->invert ? -1 : 1);
                    
                    if ($days_diff < 0) {
                        $due_date_class = 'text-danger';
                    } elseif ($days_diff == 0) {
                        $due_date_class = 'text-warning';
                    }
                ?>
                    <div class="list-item">
                        <div class="list-item-content">
                            <div class="list-item-field">
                                <span class="field-label">Type:</span>
                                <span class="field-value d-flex align-items-center gap-1">
                                    <i class="fas fa-file-invoice" style="color: #ff6200; font-size: 0.8rem;"></i>
                                    <?php echo htmlspecialchars($fee['fee_type']); ?>
                                </span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Target:</span>
                                <span class="field-value">
                                    <span class="badge-status <?php echo $target_class; ?>">
                                        <?php echo $fee['target_type']; ?>
                                    </span>
                                </span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Class:</span>
                                <span class="field-value"><?php echo htmlspecialchars($fee['class_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Learner:</span>
                                <span class="field-value"><?php echo htmlspecialchars($fee['learner_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Dept:</span>
                                <span class="field-value badge-department"><?php echo htmlspecialchars($fee['department']); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Amount:</span>
                                <span class="field-value fw-bold">₵<?php echo number_format($fee['amount'], 2); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Outstanding:</span>
                                <span class="field-value text-danger">₵<?php echo number_format($fee['outstanding_debt'], 2); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Paid:</span>
                                <span class="field-value text-success">₵<?php echo number_format($fee['amount_paid'], 2); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Status:</span>
                                <span class="field-value">
                                    <span class="badge-status <?php echo $status_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?>"></i>
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Due:</span>
                                <span class="field-value <?php echo $due_date_class; ?>"><?php echo htmlspecialchars($fee['due_date']); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Year:</span>
                                <span class="field-value"><?php echo htmlspecialchars($fee['academic_year']); ?></span>
                            </div>
                        </div>
                        <div class="list-item-actions">
                            <div class="btn-group" role="group">
                                <a href="pay_fee.php?fee_id=<?php echo $fee['id']; ?>" class="btn btn-sm btn-success" title="Pay Fee">
                                    <i class="fas fa-money-bill-wave"></i>
                                </a>
                                <a href="edit_fee.php?id=<?php echo $fee['id']; ?>" class="btn btn-sm btn-primary" title="Edit Fee">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button class="btn btn-sm btn-danger delete-fee" data-fee-id="<?php echo $fee['id']; ?>" title="Delete Fee">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Student Debt and Payment Details List -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-exclamation-triangle me-2" style="color: #ff6200;"></i>Student Debt and Payment Details
        </h3>
        
        <div class="list-container animate__animated animate__fadeIn" id="debts_list">
            <div class="list-item-header">
                <div class="row w-100">
                    <div class="col-md-2">Student</div>
                    <div class="col-md-2">Fee Type</div>
                    <div class="col-md-1">Dept</div>
                    <div class="col-md-1">Class</div>
                    <div class="col-md-1">Amount</div>
                    <div class="col-md-1">Outstanding</div>
                    <div class="col-md-1">Paid</div>
                    <div class="col-md-2">Progress</div>
                    <div class="col-md-1">Status</div>
                </div>
            </div>
            <?php if (empty($student_debts)): ?>
                <div class="no-data">
                    <i class="fas fa-user-graduate"></i>
                    <p>No student debts found for the selected filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($student_debts as $debt): 
                    $percentage = $debt['amount'] > 0 ? round(($debt['amount_paid'] / $debt['amount']) * 100) : 0;
                    $progress_class = $percentage >= 100 ? 'bg-success' : ($percentage >= 50 ? 'bg-warning' : 'bg-danger');
                    
                    // CORRECTED: Determine status for student debts
                    $status = '';
                    $status_class = '';
                    $status_icon = '';
                    if ($percentage >= 100) {
                        $status = 'paid';
                        $status_class = 'badge-paid';
                        $status_icon = 'fa-check-circle';
                    } elseif ($percentage > 0) {
                        $status = 'partial';
                        $status_class = 'badge-partial';
                        $status_icon = 'fa-clock';
                    } else {
                        $status = 'unpaid';
                        $status_class = 'badge-unpaid';
                        $status_icon = 'fa-times-circle';
                    }
                ?>
                    <div class="list-item">
                        <div class="list-item-content">
                            <div class="list-item-field">
                                <span class="field-label">Student:</span>
                                <span class="field-value d-flex align-items-center gap-1">
                                    <i class="fas fa-user-circle" style="color: #ff6200; font-size: 0.8rem;"></i>
                                    <?php echo htmlspecialchars($debt['student_name']); ?>
                                </span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Fee Type:</span>
                                <span class="field-value"><?php echo htmlspecialchars($debt['fee_type']); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Dept:</span>
                                <span class="field-value badge-department"><?php echo htmlspecialchars($debt['department']); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Class:</span>
                                <span class="field-value"><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Amount:</span>
                                <span class="field-value fw-bold">₵<?php echo number_format($debt['amount'], 2); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Outstanding:</span>
                                <span class="field-value text-danger">₵<?php echo number_format($debt['amount_due'], 2); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Paid:</span>
                                <span class="field-value text-success">₵<?php echo number_format($debt['amount_paid'], 2); ?></span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Progress:</span>
                                <span class="field-value progress-container">
                                    <div class="progress">
                                        <div class="progress-bar <?php echo $progress_class; ?>" 
                                             role="progressbar" 
                                             style="width: <?php echo min($percentage, 100); ?>%">
                                        </div>
                                    </div>
                                    <small class="text-muted"><?php echo $percentage; ?>%</small>
                                </span>
                            </div>
                            <div class="list-item-field">
                                <span class="field-label">Status:</span>
                                <span class="field-value">
                                    <span class="badge-status <?php echo $status_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?>"></i>
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Print Section (Hidden) -->
    <div class="print-section" style="display: none;">
        <div class="print-header">
            <h1>Queen's Paradise Montessori School Fees Report</h1>
            <p>Academic Year: <?php echo htmlspecialchars($selected_year); ?> - Generated on: <?php echo date('F j, Y, g:i A T'); ?></p>
        </div>
        
        <h3>Fees Summary</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Fee Type</th>
                    <th>Target</th>
                    <th>Class</th>
                    <th>Learner</th>
                    <th>Department</th>
                    <th>Amount (GH₵)</th>
                    <th>Outstanding (GH₵)</th>
                    <th>Paid (GH₵)</th>
                    <th>Payment Status</th>
                    <th>Due Date</th>
                    <th>Academic Year</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fees as $fee): 
                    // CORRECTED: Determine payment status for print
                    $payment_status = '';
                    if ($fee['amount'] > 0) {
                        $percentage_paid = ($fee['amount_paid'] / $fee['amount']) * 100;
                        if ($percentage_paid >= 100) {
                            $payment_status = 'Paid';
                        } elseif ($percentage_paid > 0) {
                            $payment_status = 'Partial';
                        } else {
                            $payment_status = 'Unpaid';
                        }
                    } else {
                        $payment_status = 'Unpaid';
                    }
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                        <td><?php echo htmlspecialchars($fee['target_type']); ?></td>
                        <td><?php echo htmlspecialchars($fee['class_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($fee['learner_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($fee['department']); ?></td>
                        <td><?php echo number_format($fee['amount'], 2); ?></td>
                        <td><?php echo number_format($fee['outstanding_debt'], 2); ?></td>
                        <td><?php echo number_format($fee['amount_paid'], 2); ?></td>
                        <td><?php echo $payment_status; ?></td>
                        <td><?php echo htmlspecialchars($fee['due_date']); ?></td>
                        <td><?php echo htmlspecialchars($fee['academic_year']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="page-break"></div>
        
        <h3>Student Debt and Payment Details</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Fee Type</th>
                    <th>Department</th>
                    <th>Class</th>
                    <th>Amount (GH₵)</th>
                    <th>Outstanding (GH₵)</th>
                    <th>Paid (GH₵)</th>
                    <th>Payment Status</th>
                    <th>Academic Year</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($student_debts as $debt): 
                    // CORRECTED: Determine payment status for print
                    $payment_status = '';
                    if ($debt['amount'] > 0) {
                        $percentage_paid = ($debt['amount_paid'] / $debt['amount']) * 100;
                        if ($percentage_paid >= 100) {
                            $payment_status = 'Paid';
                        } elseif ($percentage_paid > 0) {
                            $payment_status = 'Partial';
                        } else {
                            $payment_status = 'Unpaid';
                        }
                    } else {
                        $payment_status = 'Unpaid';
                    }
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                        <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                        <td><?php echo htmlspecialchars($debt['department']); ?></td>
                        <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                        <td><?php echo number_format($debt['amount'], 2); ?></td>
                        <td><?php echo number_format($debt['amount_due'], 2); ?></td>
                        <td><?php echo number_format($debt['amount_paid'], 2); ?></td>
                        <td><?php echo $payment_status; ?></td>
                        <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="print-footer">
            <p>Generated by Queen's Paradise Montessori School Management System</p>
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

                // Initialize tooltips
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
                tooltipTriggerList.forEach(function(tooltipTriggerEl) {
                    new bootstrap.Tooltip(tooltipTriggerEl);
                });

                // Clear search button
                $('#clear_search').click(function() {
                    $('#search_query').val('');
                    filterData();
                });

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
                                options += `<option value="${cls.id}" data-dept="${deptId}">${cls.class_name}</option>`;
                            });
                            $('#class_id').html(options);
                            filterData();
                        },
                        error: function(xhr, status, error) {
                            console.error('Class filter AJAX error:', status, error);
                            showAlert('Failed to load classes. Please try again.', 'danger');
                            logClientError('Class filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }

                // Filter fees and debts via AJAX
                function filterData() {
                    const academicYear = $('#academic_year').val();
                    const deptId = $('#department_id').val();
                    const classId = $('#class_id').val();
                    const searchQuery = $('#search_query').val();
                    
                    if (!academicYear) {
                        $('#fees_list .list-item:not(.list-item-header)').remove();
                        $('#fees_list').append(`
                            <div class="no-data">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <p>Please select an academic year to view fees.</p>
                            </div>
                        `);
                        $('#debts_list .list-item:not(.list-item-header)').remove();
                        $('#debts_list').append(`
                            <div class="no-data">
                                <i class="fas fa-user-graduate"></i>
                                <p>Please select an academic year to view debts.</p>
                            </div>
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
                            action: 'filter_data',
                            academic_year: academicYear,
                            department_id: deptId,
                            class_id: classId,
                            search_query: searchQuery,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            $('#filter_btn .loading-spinner').hide();
                            
                            if (response.error) {
                                console.error('Data filter error:', response.error);
                                showAlert('Error filtering data: ' + response.error, 'danger');
                                logClientError('Data filter AJAX error: ' + response.error);
                                return;
                            }
                            
                            // Update fees list
                            updateFeesList(response.fees);
                            
                            // Update debts list
                            updateDebtsList(response.student_debts);

                            // Update stats including partial payments and counts
                            updateStats(
                                response.total_fees, 
                                response.total_debts, 
                                response.total_payments, 
                                response.partial_payments_total || 0,
                                response.paid_count || 0,
                                response.partial_count || 0,
                                response.unpaid_count || 0
                            );

                            // Update export link
                            const exportUrl = `?export=csv&academic_year=${encodeURIComponent(academicYear)}&department_id=${deptId}&class_id=${classId}&search_query=${encodeURIComponent(searchQuery)}`;
                            $('#export_btn').attr('href', exportUrl);
                        },
                        error: function(xhr, status, error) {
                            $('#filter_btn .loading-spinner').hide();
                            console.error('Data filter AJAX error:', status, error);
                            showAlert('Failed to load data. Please try again.', 'danger');
                            logClientError('Data filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }

                // Update fees list
                function updateFeesList(fees) {
                    let feesHtml = '';
                    if (fees.length === 0) {
                        feesHtml = `
                            <div class="no-data">
                                <i class="fas fa-file-invoice-dollar"></i>
                                <p>No fees found for the selected filters.</p>
                            </div>`;
                    } else {
                        fees.forEach(fee => {
                            // CORRECTED: Get fee status based on percentage paid
                            const percentagePaid = fee.amount > 0 ? (fee.amount_paid / fee.amount) * 100 : 0;
                            let status, statusClass, statusIcon;
                            if (percentagePaid >= 100) {
                                status = 'paid';
                                statusClass = 'badge-paid';
                                statusIcon = 'fa-check-circle';
                            } else if (percentagePaid > 0) {
                                status = 'partial';
                                statusClass = 'badge-partial';
                                statusIcon = 'fa-clock';
                            } else {
                                status = 'unpaid';
                                statusClass = 'badge-unpaid';
                                statusIcon = 'fa-times-circle';
                            }
                            
                            const targetClass = fee.target_type === 'School' ? 'badge-school' : 
                                               (fee.target_type === 'Class' ? 'badge-class' : 'badge-learner');
                            
                            const dueDateClass = getDueDateClass(fee.due_date);
                            
                            feesHtml += `
                                <div class="list-item">
                                    <div class="list-item-content">
                                        <div class="list-item-field">
                                            <span class="field-label">Type:</span>
                                            <span class="field-value d-flex align-items-center gap-1">
                                                <i class="fas fa-file-invoice" style="color: #ff6200; font-size: 0.8rem;"></i>
                                                ${fee.fee_type}
                                            </span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Target:</span>
                                            <span class="field-value">
                                                <span class="badge-status ${targetClass}">
                                                    ${fee.target_type}
                                                </span>
                                            </span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Class:</span>
                                            <span class="field-value">${fee.class_name || 'N/A'}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Learner:</span>
                                            <span class="field-value">${fee.learner_name || 'N/A'}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Dept:</span>
                                            <span class="field-value badge-department">${fee.department}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Amount:</span>
                                            <span class="field-value fw-bold">₵${parseFloat(fee.amount).toFixed(2)}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Outstanding:</span>
                                            <span class="field-value text-danger">₵${parseFloat(fee.outstanding_debt).toFixed(2)}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Paid:</span>
                                            <span class="field-value text-success">₵${parseFloat(fee.amount_paid).toFixed(2)}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Status:</span>
                                            <span class="field-value">
                                                <span class="badge-status ${statusClass}">
                                                    <i class="fas ${statusIcon}"></i>
                                                    ${status.charAt(0).toUpperCase() + status.slice(1)}
                                                </span>
                                            </span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Due:</span>
                                            <span class="field-value ${dueDateClass}">${fee.due_date}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Year:</span>
                                            <span class="field-value">${fee.academic_year}</span>
                                        </div>
                                    </div>
                                    <div class="list-item-actions">
                                        <div class="btn-group" role="group">
                                            <a href="pay_fee.php?fee_id=${fee.id}" class="btn btn-sm btn-success" title="Pay Fee">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                            <a href="edit_fee.php?id=${fee.id}" class="btn btn-sm btn-primary" title="Edit Fee">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="btn btn-sm btn-danger delete-fee" data-fee-id="${fee.id}" title="Delete Fee">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>`;
                        });
                    }
                    $('#fees_list .list-item:not(.list-item-header)').remove();
                    $('#fees_list').append(feesHtml);
                }

                // Update debts list
                function updateDebtsList(debts) {
                    let debtsHtml = '';
                    if (debts.length === 0) {
                        debtsHtml = `
                            <div class="no-data">
                                <i class="fas fa-user-graduate"></i>
                                <p>No student debts found for the selected filters.</p>
                            </div>`;
                    } else {
                        debts.forEach(debt => {
                            const percentage = debt.amount > 0 ? Math.round((debt.amount_paid / debt.amount) * 100) : 0;
                            const progressClass = percentage >= 100 ? 'bg-success' : (percentage >= 50 ? 'bg-warning' : 'bg-danger');
                            
                            // CORRECTED: Determine status for student debts
                            let status, statusClass, statusIcon;
                            if (percentage >= 100) {
                                status = 'paid';
                                statusClass = 'badge-paid';
                                statusIcon = 'fa-check-circle';
                            } else if (percentage > 0) {
                                status = 'partial';
                                statusClass = 'badge-partial';
                                statusIcon = 'fa-clock';
                            } else {
                                status = 'unpaid';
                                statusClass = 'badge-unpaid';
                                statusIcon = 'fa-times-circle';
                            }
                            
                            debtsHtml += `
                                <div class="list-item">
                                    <div class="list-item-content">
                                        <div class="list-item-field">
                                            <span class="field-label">Student:</span>
                                            <span class="field-value d-flex align-items-center gap-1">
                                                <i class="fas fa-user-circle" style="color: #ff6200; font-size: 0.8rem;"></i>
                                                ${debt.student_name}
                                            </span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Fee Type:</span>
                                            <span class="field-value">${debt.fee_type}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Dept:</span>
                                            <span class="field-value badge-department">${debt.department}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Class:</span>
                                            <span class="field-value">${debt.class_name || 'N/A'}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Amount:</span>
                                            <span class="field-value fw-bold">₵${parseFloat(debt.amount).toFixed(2)}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Outstanding:</span>
                                            <span class="field-value text-danger">₵${parseFloat(debt.amount_due).toFixed(2)}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Paid:</span>
                                            <span class="field-value text-success">₵${parseFloat(debt.amount_paid).toFixed(2)}</span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Progress:</span>
                                            <span class="field-value progress-container">
                                                <div class="progress">
                                                    <div class="progress-bar ${progressClass}" 
                                                         role="progressbar" 
                                                         style="width: ${Math.min(percentage, 100)}%">
                                                    </div>
                                                </div>
                                                <small class="text-muted">${percentage}%</small>
                                            </span>
                                        </div>
                                        <div class="list-item-field">
                                            <span class="field-label">Status:</span>
                                            <span class="field-value">
                                                <span class="badge-status ${statusClass}">
                                                    <i class="fas ${statusIcon}"></i>
                                                    ${status.charAt(0).toUpperCase() + status.slice(1)}
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>`;
                        });
                    }
                    $('#debts_list .list-item:not(.list-item-header)').remove();
                    $('#debts_list').append(debtsHtml);
                }

                // Helper function to get due date class
                function getDueDateClass(dueDateStr) {
                    const dueDate = new Date(dueDateStr);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    const timeDiff = dueDate.getTime() - today.getTime();
                    const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
                    
                    if (daysDiff < 0) return 'text-danger';
                    if (daysDiff === 0) return 'text-warning';
                    return '';
                }

                // Update stats display including partial payments and counts
                function updateStats(fees, debts, payments, partialPayments = 0, paidCount = 0, partialCount = 0, unpaidCount = 0) {
                    $('#total_fees').text('₵' + parseFloat(fees).toFixed(2));
                    $('#total_debts').text('₵' + parseFloat(debts).toFixed(2));
                    $('#total_payments').text('₵' + parseFloat(payments).toFixed(2));
                    $('#partial_payments').text('₵' + parseFloat(partialPayments).toFixed(2));
                    $('#paid_count').text(paidCount + ' Paid');
                    $('#partial_count').text(partialCount + ' Partial');
                    $('#unpaid_count').text(unpaidCount + ' Unpaid');
                }

                // Show alert
                function showAlert(message, type) {
                    const alertHtml = `
                        <div class="alert alert-${type} alert-custom alert-dismissible fade show" role="alert">
                            <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'check-circle'} me-2"></i>
                            ${message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    $('.page-header').after(alertHtml);
                    
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

                // Handle filter button click
                $('#filter_btn').click(function() {
                    filterData();
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
                    searchTimeout = setTimeout(filterData, 500);
                });

                // Handle Enter key in search
                $('#search_query').on('keypress', function(e) {
                    if (e.which === 13) {
                        filterData();
                    }
                });

                // Initialize filters
                $('#department_id').change(filterClasses);
                $('#academic_year').change(function() {
                    filterClasses();
                    filterData();
                });
                $('#class_id').change(filterData);

                // Handle delete fee
                $(document).on('click', '.delete-fee', function() {
                    const feeId = $(this).data('fee-id');
                    if (confirm('Are you sure you want to delete this fee? This will also delete related debts and payments.')) {
                        $.ajax({
                            url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                action: 'delete_fee',
                                fee_id: feeId,
                                csrf_token: $('#csrf_token').val()
                            },
                            success: function(response) {
                                if (response.success) {
                                    showAlert('Fee deleted successfully.', 'success');
                                    filterData();
                                } else {
                                    showAlert('Error deleting fee: ' + (response.error || 'Unknown error'), 'danger');
                                    logClientError('Delete fee AJAX error: ' + (response.error || 'Unknown'));
                                }
                            },
                            error: function(xhr, status, error) {
                                showAlert('Failed to delete fee. Please try again.', 'danger');
                                logClientError('Delete fee AJAX failed: ' + status + ' - ' + error);
                            }
                        });
                    }
                });

                // Initialize with data
                <?php if ($selected_year): ?>
                    filterClasses();
                    filterData();
                <?php endif; ?>

            } catch (e) {
                console.error('JavaScript initialization error:', e);
                logClientError('JavaScript initialization error: ' + e.message);
            }
        });
    </script>
</body>
</html>