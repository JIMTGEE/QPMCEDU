<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/helpers.php');

// Check database connection
if (!$conn || $conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . ($conn->connect_error ?? 'Connection is null')]));
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$fee_id = isset($_GET['fee_id']) ? (int)$_GET['fee_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$fee_details = null;
$student_details = null;
$outstanding_amount = 0;
$paid_amount = 0;
$parent_details = null;

// Fetch fee details
if ($fee_id > 0) {
    // Get fee information
    $fee_query = "SELECT f.*, 
                  c.class_name, 
                  u.name as student_name, 
                  u.phone as student_phone,
                  u.email as student_email,
                  sc.academic_year
                  FROM fees f
                  LEFT JOIN classes c ON f.class_id = c.id
                  LEFT JOIN users u ON f.user_id = u.id
                  LEFT JOIN student_classes sc ON (f.user_id = sc.user_id AND f.class_id = sc.class_id AND f.academic_year = sc.academic_year)
                  WHERE f.id = ?";
    
    if ($stmt = $conn->prepare($fee_query)) {
        $stmt->bind_param("i", $fee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $fee_details = $result->fetch_assoc();
        $stmt->close();
    }
    
    // If student ID is provided separately, fetch that student's details
    if ($student_id > 0) {
        $student_query = "SELECT u.*, sc.academic_year, c.class_name 
                         FROM users u 
                         LEFT JOIN student_classes sc ON u.id = sc.user_id 
                         LEFT JOIN classes c ON sc.class_id = c.id 
                         WHERE u.id = ? AND u.role = 'student' AND u.status = 'active'
                         LIMIT 1";
        
        if ($stmt = $conn->prepare($student_query)) {
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $student_details = $result->fetch_assoc();
            $stmt->close();
        }
    } elseif ($fee_details && $fee_details['user_id']) {
        // Use student from fee record
        $student_id = $fee_details['user_id'];
        $student_details = [
            'id' => $fee_details['user_id'],
            'name' => $fee_details['student_name'],
            'phone' => $fee_details['student_phone'],
            'email' => $fee_details['student_email'],
            'class_name' => $fee_details['class_name'],
            'academic_year' => $fee_details['academic_year']
        ];
    }
    
    // Calculate outstanding amount
    if ($student_id > 0) {
        // Get debt for this student and fee
        $debt_query = "SELECT amount_due FROM debts 
                      WHERE user_id = ? AND fee_id = ?";
        
        if ($stmt = $conn->prepare($debt_query)) {
            $stmt->bind_param("ii", $student_id, $fee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $outstanding_amount = (float)$row['amount_due'];
            }
            $stmt->close();
        }
        
        // Get total paid amount for this fee and student
        $paid_query = "SELECT COALESCE(SUM(amount_paid), 0) as total_paid 
                      FROM payments 
                      WHERE user_id = ? AND fee_id = ?";
        
        if ($stmt = $conn->prepare($paid_query)) {
            $stmt->bind_param("ii", $student_id, $fee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $paid_amount = (float)$row['total_paid'];
            }
            $stmt->close();
        }
    }
    
    // Calculate payment status
    $original_amount = $fee_details['amount'] ?? 0;
    $payment_status = '';
    $payment_status_class = '';
    
    if ($paid_amount == 0) {
        $payment_status = 'Unpaid';
        $payment_status_class = 'danger';
    } elseif ($paid_amount > 0 && $paid_amount < $original_amount) {
        $payment_status = 'Partial';
        $payment_status_class = 'warning';
    } elseif ($paid_amount >= $original_amount) {
        $payment_status = 'Paid';
        $payment_status_class = 'success';
    }
    
    // Fetch parent details for SMS notification
    if ($student_id > 0) {
        $parent_query = "SELECT p.*, u.phone as parent_phone, u.email as parent_email
                        FROM parents p
                        JOIN users u ON p.user_id = u.id
                        JOIN parent_student_mapping psm ON p.id = psm.parent_id
                        WHERE psm.student_id = ?
                        LIMIT 1";
        
        if ($stmt = $conn->prepare($parent_query)) {
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $parent_details = $result->fetch_assoc();
            $stmt->close();
        }
        
        // If no parent found, try to get from users table with parent role
        if (!$parent_details) {
            $parent_query = "SELECT u.id, u.name, u.phone, u.email
                            FROM users u
                            WHERE u.role = 'parent' AND u.id IN (
                                SELECT parent_id FROM parent_student_mapping WHERE student_id = ?
                            )
                            LIMIT 1";
            
            if ($stmt = $conn->prepare($parent_query)) {
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $parent_details = $result->fetch_assoc();
                $stmt->close();
            }
        }
    }
}

// Handle form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token. Please try again.";
    } else {
        // Collect and validate form data
        $fee_id = (int)($_POST['fee_id'] ?? 0);
        $student_id = (int)($_POST['student_id'] ?? 0);
        $payment_amount = isset($_POST['payment_amount']) ? (float)$_POST['payment_amount'] : 0.00;
        $payment_method = trim($_POST['payment_method'] ?? '');
        $payment_date = trim($_POST['payment_date'] ?? '');
        $receipt_number = trim($_POST['receipt_number'] ?? '');
        $send_sms = 1; // Always send SMS as per requirement
        $sms_message = trim($_POST['sms_message'] ?? '');
        
        // Validation
        $errors = [];
        
        if ($fee_id <= 0 || !$fee_details) {
            $errors[] = "Invalid fee selected.";
        }
        
        if ($student_id <= 0 || !$student_details) {
            $errors[] = "Invalid student selected.";
        }
        
        if ($payment_amount <= 0 || $payment_amount > $outstanding_amount) {
            $errors[] = "Payment amount must be greater than 0 and not exceed outstanding amount.";
        }
        
        if (empty($payment_method) || !in_array($payment_method, ['Cash', 'Mobile Money', 'Bank Transfer', 'Cheque', 'Online'])) {
            $errors[] = "Please select a valid payment method.";
        }
        
        if (empty($payment_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $payment_date)) {
            $errors[] = "Please select a valid payment date.";
        }
        
        if (empty($receipt_number)) {
            $errors[] = "Please enter a receipt number.";
        }
        
        if (empty($errors)) {
            $conn->begin_transaction();
            
            try {
                // Insert payment record (without notes column as it doesn't exist)
                $insert_query = "INSERT INTO payments (user_id, fee_id, amount_paid, payment_date, payment_method, receipt_number, class_id, recorded_by, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                if ($stmt = $conn->prepare($insert_query)) {
                    $recorded_by = $_SESSION['user_id'];
                    $class_id = $fee_details['class_id'] ?? 0;
                    
                    $stmt->bind_param("iidsssii", 
                        $student_id,
                        $fee_id,
                        $payment_amount,
                        $payment_date,
                        $payment_method,
                        $receipt_number,
                        $class_id,
                        $recorded_by
                    );
                    
                    if ($stmt->execute()) {
                        $payment_id = $stmt->insert_id;
                        
                        // Update debt amount
                        $update_debt_query = "UPDATE debts 
                                            SET amount_due = amount_due - ?,
                                            updated_at = NOW()
                                            WHERE user_id = ? AND fee_id = ?";
                        
                        if ($update_stmt = $conn->prepare($update_debt_query)) {
                            $update_stmt->bind_param("dii", $payment_amount, $student_id, $fee_id);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }
                        
                        // Send SMS notification (always)
                        if ($parent_details && !empty($parent_details['parent_phone'])) {
                            $phone_number = $parent_details['parent_phone'];
                            
                            // Prepare SMS message
                            if (empty($sms_message)) {
                                $sms_message = "Payment Received: GH₵" . number_format($payment_amount, 2) . 
                                               " for " . htmlspecialchars($fee_details['fee_type']) . 
                                               ". Student: " . htmlspecialchars($student_details['name']) . 
                                               ". Receipt: " . $receipt_number . 
                                               ". Balance: GH₵" . number_format(($outstanding_amount - $payment_amount), 2);
                            }
                            
                            // Here you would integrate with your SMS gateway
                            // For example: sendSMS($phone_number, $sms_message);
                            
                            // Log SMS activity
                            $sms_log_query = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                                             VALUES (?, 'sms_notification', ?, NOW())";
                            
                            if ($sms_log_stmt = $conn->prepare($sms_log_query)) {
                                $sms_description = "SMS sent to parent for payment: " . $sms_message;
                                $sms_log_stmt->bind_param("is", $_SESSION['user_id'], $sms_description);
                                $sms_log_stmt->execute();
                                $sms_log_stmt->close();
                            }
                        } elseif (!$parent_details) {
                            // Log that no parent contact was found
                            $log_query = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                                         VALUES (?, 'sms_notification_error', ?, NOW())";
                            
                            if ($log_stmt = $conn->prepare($log_query)) {
                                $log_description = "No parent contact found for student ID: " . $student_id . ". SMS not sent.";
                                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_description);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                        }
                        
                        $conn->commit();
                        
                        // Regenerate CSRF token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                        $success_msg = "Payment recorded successfully! Receipt #: " . $receipt_number;
                        
                        // Redirect to success page or clear form
                        if (isset($_POST['redirect_to']) && $_POST['redirect_to'] === 'dashboard') {
                            $_SESSION['success_msg'] = $success_msg;
                            header('Location: /admin/fees_payments.php');
                            exit;
                        }
                        
                        // Refresh data
                        $outstanding_amount -= $payment_amount;
                        $paid_amount += $payment_amount;
                        
                        // Recalculate payment status after payment
                        $original_amount = $fee_details['amount'] ?? 0;
                        if ($paid_amount == 0) {
                            $payment_status = 'Unpaid';
                            $payment_status_class = 'danger';
                        } elseif ($paid_amount > 0 && $paid_amount < $original_amount) {
                            $payment_status = 'Partial';
                            $payment_status_class = 'warning';
                        } elseif ($paid_amount >= $original_amount) {
                            $payment_status = 'Paid';
                            $payment_status_class = 'success';
                        }
                        
                    } else {
                        throw new Exception("Failed to record payment: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    throw new Exception("Failed to prepare payment query: " . $conn->error);
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Failed to record payment: " . $e->getMessage();
            }
        } else {
            $error_msg = implode("<br>", $errors);
        }
    }
}

// Fetch recent payments for this fee
$recent_payments = [];
if ($fee_id > 0 && $student_id > 0) {
    $payments_query = "SELECT p.*, u.name as recorded_by_name 
                      FROM payments p
                      LEFT JOIN users u ON p.recorded_by = u.id
                      WHERE p.fee_id = ? AND p.user_id = ?
                      ORDER BY p.payment_date DESC, p.created_at DESC
                      LIMIT 10";
    
    if ($stmt = $conn->prepare($payments_query)) {
        $stmt->bind_param("ii", $fee_id, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recent_payments[] = $row;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Fee Payment - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #ffd700;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f9fafb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            border: none;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            border: none;
            padding: 1.5rem;
        }
        
        .card-header h3 {
            margin-bottom: 0.25rem;
            font-weight: 700;
        }
        
        .card-header .opacity-75 {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid rgba(255, 98, 0, 0.1);
        }
        
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .required-field::after {
            content: " *";
            color: var(--danger-color);
        }
        
        .form-control, .form-select {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 98, 0, 0.25);
        }
        
        .btn {
            border-radius: 8px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
            background: transparent;
        }
        
        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
            transform: translateY(-2px);
        }
        
        .amount-display {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            background: rgba(255, 98, 0, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            display: inline-block;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 1rem 1.5rem;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
        }
        
        .loading-spinner {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        .invalid-feedback {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.875rem;
            color: var(--danger-color);
        }
        
        .is-invalid {
            border-color: var(--danger-color);
        }
        
        .is-invalid:focus {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        
        .payment-history-table {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        
        .table th {
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            padding: 1rem;
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .sms-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid #dee2e6;
        }
        
        .amount-card {
            background: linear-gradient(135deg, rgba(255, 98, 0, 0.05) 0%, rgba(255, 140, 0, 0.05) 100%);
            border-radius: 12px;
            padding: 1.5rem;
            border: 2px solid rgba(255, 98, 0, 0.1);
            text-align: center;
            height: 100%;
        }
        
        .amount-card h6 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .amount-card .amount {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0.5rem 0;
        }
        
        .amount-card .label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .info-badge {
            background: rgba(255, 98, 0, 0.1);
            color: var(--primary-color);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid rgba(255, 98, 0, 0.2);
        }
        
        .payment-status-badge {
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
        }
        
        .payment-status-badge.danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .payment-status-badge.warning {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .payment-status-badge.success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .progress {
            height: 10px;
            border-radius: 5px;
            margin-top: 0.5rem;
        }
        
        .progress-bar {
            border-radius: 5px;
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 1.5rem;
            }
            
            .filter-section {
                padding: 1.5rem;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .d-flex.justify-content-between {
                flex-direction: column;
            }
            
            .d-flex.justify-content-between > div {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (file_exists($header_path)) {
        include $header_path;
    } else {
        echo '<nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="/">Queen\'s Paradise Montessori School</a></div></nav>';
    }
    ?>
    
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted" id="loadingMessage">Processing your request...</p>
        </div>
    </div>
    
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-money-check-alt me-2"></i>Record Fee Payment</h3>
                        <p class="mb-0 opacity-75">Record payment for student fees and send SMS notification to parents</p>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($success_msg): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo $success_msg; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error_msg): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php echo $error_msg; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$fee_details): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No fee selected. Please select a fee from the fees dashboard.
                                <div class="mt-2">
                                    <a href="/admin/fees_payments.php" class="btn btn-sm btn-primary">
                                        <i class="fas fa-arrow-left me-1"></i> Back to Fees Dashboard
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Fee Information Cards -->
                            <div class="filter-section">
                                <h5 class="section-title"><i class="fas fa-info-circle me-2"></i>Fee Information</h5>
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-12">
                                        <div class="d-flex align-items-center justify-content-between p-3" style="background: rgba(255, 98, 0, 0.05); border-radius: 10px;">
                                            <div>
                                                <h6 class="mb-1"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i><?php echo htmlspecialchars($fee_details['fee_type']); ?></h6>
                                                <p class="mb-0 text-muted">
                                                    <i class="fas fa-user-graduate me-1"></i>
                                                    <?php echo htmlspecialchars($student_details['name'] ?? 'N/A'); ?> 
                                                    • <?php echo htmlspecialchars($student_details['class_name'] ?? ''); ?>
                                                </p>
                                            </div>
                                            <div class="text-end">
                                                <span class="info-badge me-2">
                                                    <i class="fas fa-calendar-alt me-1"></i>
                                                    <?php echo htmlspecialchars($fee_details['academic_year'] ?? $student_details['academic_year'] ?? 'N/A'); ?>
                                                </span>
                                                <span class="payment-status-badge <?php echo $payment_status_class; ?>">
                                                    <i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i>
                                                    <?php echo $payment_status; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Progress Bar -->
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Payment Progress</span>
                                        <span class="fw-bold"><?php echo number_format(($paid_amount / $original_amount) * 100, 1); ?>%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar 
                                            <?php 
                                            if ($payment_status == 'Unpaid') echo 'bg-danger';
                                            elseif ($payment_status == 'Partial') echo 'bg-warning';
                                            else echo 'bg-success';
                                            ?>" 
                                            role="progressbar" 
                                            style="width: <?php echo number_format(($paid_amount / $original_amount) * 100, 1); ?>%"
                                            aria-valuenow="<?php echo number_format(($paid_amount / $original_amount) * 100, 1); ?>" 
                                            aria-valuemin="0" 
                                            aria-valuemax="100">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small class="text-muted">GH₵0.00</small>
                                        <small class="text-muted">GH₵<?php echo number_format($original_amount, 2); ?></small>
                                    </div>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <div class="amount-card">
                                            <h6><i class="fas fa-tag me-2"></i>Original Amount</h6>
                                            <div class="amount">GH₵ <?php echo number_format($original_amount, 2); ?></div>
                                            <div class="label">Total fee amount</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="amount-card">
                                            <h6><i class="fas fa-money-bill-wave me-2"></i>Paid Amount</h6>
                                            <div class="amount 
                                                <?php 
                                                if ($paid_amount == 0) echo 'text-danger';
                                                elseif ($paid_amount < $original_amount) echo 'text-warning';
                                                else echo 'text-success';
                                                ?>">
                                                GH₵ <?php echo number_format($paid_amount, 2); ?>
                                            </div>
                                            <div class="label">Amount already paid</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="amount-card">
                                            <h6><i class="fas fa-exclamation-triangle me-2"></i>Outstanding Balance</h6>
                                            <div class="amount text-danger">GH₵ <?php echo number_format($outstanding_amount, 2); ?></div>
                                            <div class="label">Remaining to pay</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="amount-card">
                                            <h6><i class="fas fa-chart-pie me-2"></i>Payment Status</h6>
                                            <div class="amount">
                                                <span class="payment-status-badge <?php echo $payment_status_class; ?>">
                                                    <i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i>
                                                    <?php echo $payment_status; ?>
                                                </span>
                                            </div>
                                            <div class="label">
                                                <?php 
                                                if ($payment_status == 'Unpaid') echo 'No payments made';
                                                elseif ($payment_status == 'Partial') echo 'Partially paid';
                                                else echo 'Fully paid';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Payment Form -->
                            <form method="POST" class="needs-validation" novalidate id="payment-form" onsubmit="return validateForm()">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="submit_payment" value="1">
                                <input type="hidden" name="fee_id" value="<?php echo $fee_id; ?>">
                                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                <input type="hidden" name="redirect_to" id="redirect_to" value="">
                                
                                <div class="filter-section">
                                    <h5 class="section-title"><i class="fas fa-credit-card me-2"></i>Payment Details</h5>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="payment_amount" class="form-label required-field">Payment Amount (GH₵)</label>
                                            <div class="input-group">
                                                <span class="input-group-text">GH₵</span>
                                                <input type="number" id="payment_amount" name="payment_amount" 
                                                       class="form-control" step="0.01" min="0.01" 
                                                       max="<?php echo number_format($outstanding_amount, 2); ?>" 
                                                       value="<?php echo number_format($outstanding_amount, 2); ?>" 
                                                       required oninput="updateBalance()">
                                            </div>
                                            <small class="text-muted d-block mt-1">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Maximum: GH₵ <?php echo number_format($outstanding_amount, 2); ?>
                                            </small>
                                            <div class="invalid-feedback">Please enter a valid payment amount.</div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="payment_method" class="form-label required-field">Payment Method</label>
                                            <select name="payment_method" id="payment_method" class="form-select" required>
                                                <option value="">Select Payment Method</option>
                                                <option value="Cash">Cash</option>
                                                <option value="Mobile Money" selected>Mobile Money</option>
                                                <option value="Bank Transfer">Bank Transfer</option>
                                                <option value="Cheque">Cheque</option>
                                                <option value="Online">Online Payment</option>
                                            </select>
                                            <div class="invalid-feedback">Please select a payment method.</div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="payment_date" class="form-label required-field">Payment Date</label>
                                            <input type="date" id="payment_date" name="payment_date" 
                                                   class="form-control" 
                                                   value="<?php echo date('Y-m-d'); ?>" 
                                                   max="<?php echo date('Y-m-d'); ?>" required>
                                            <div class="invalid-feedback">Please select a payment date.</div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="receipt_number" class="form-label required-field">Receipt Number</label>
                                            <input type="text" id="receipt_number" name="receipt_number" 
                                                   class="form-control" 
                                                   value="RCPT-<?php echo strtoupper(uniqid()); ?>" 
                                                   required maxlength="50">
                                            <div class="invalid-feedback">Please enter a receipt number.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SMS Notification Section -->
                                <div class="filter-section">
                                    <div class="row align-items-center mb-3">
                                        <div class="col">
                                            <h5 class="section-title mb-0"><i class="fas fa-sms me-2"></i>SMS Notification</h5>
                                        </div>
                                        <div class="col-auto">
                                            <span class="info-badge">
                                                <i class="fas fa-check-circle me-1"></i> Always Active
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <?php if ($parent_details): ?>
                                                <div class="alert alert-info">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    <strong>Parent Contact:</strong>
                                                    SMS will be sent to <?php echo htmlspecialchars($parent_details['parent_name'] ?? 'parent'); ?> 
                                                    at <?php echo htmlspecialchars($parent_details['parent_phone'] ?? 'N/A'); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-warning">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                                    <strong>No Parent Contact Found:</strong>
                                                    SMS cannot be sent because no parent contact information was found for this student.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="col-12">
                                            <label for="sms_message" class="form-label">SMS Message</label>
                                            <textarea id="sms_message" name="sms_message" class="form-control" rows="3" 
                                                      placeholder="Customize the SMS message..." 
                                                      oninput="updateSmsPreview()">
Payment Received: GH₵[amount] for [fee_type]. 
Student: [student_name]
Receipt: [receipt_number]
Balance: GH₵[balance]
Thank you for your payment.</textarea>
                                            <small class="text-muted d-block mt-1">
                                                <i class="fas fa-lightbulb me-1"></i>
                                                Available variables: [amount], [fee_type], [student_name], [receipt_number], [balance], [date]
                                            </small>
                                        </div>
                                        
                                        <div class="col-12">
                                            <div class="sms-preview">
                                                <p class="mb-1"><strong>SMS Preview:</strong></p>
                                                <p id="smsPreviewText" class="mb-1"></p>
                                                <small class="text-muted">Characters: <span id="smsChars">0</span> (SMS limit: 160 characters)</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recent Payments -->
                                <?php if (!empty($recent_payments)): ?>
                                <div class="filter-section">
                                    <h5 class="section-title"><i class="fas fa-history me-2"></i>Recent Payments</h5>
                                    
                                    <div class="payment-history-table">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Amount</th>
                                                    <th>Method</th>
                                                    <th>Receipt #</th>
                                                    <th>Recorded By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_payments as $payment): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                                    <td class="fw-bold text-success">GH₵ <?php echo number_format($payment['amount_paid'], 2); ?></td>
                                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($payment['payment_method']); ?></span></td>
                                                    <td><code><?php echo htmlspecialchars($payment['receipt_number']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($payment['recorded_by_name']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Action Buttons -->
                                <div class="d-flex justify-content-between mt-4">
                                    <div>
                                        <a href="/admin/fees_payments.php" class="btn btn-secondary btn-lg">
                                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                        </a>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-outline-secondary btn-lg me-2" onclick="recordAndStay()">
                                            <i class="fas fa-save me-2"></i>Record & Add Another
                                        </button>
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-check-circle me-2"></i>Record Payment
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        echo '<footer class="bg-dark text-white py-3 mt-4"><div class="container text-center"><p>&copy; ' . date('Y') . ' Queen\'s Paradise Montessori School</p></div></footer>';
    }
    ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // Initialize form
        updateBalance();
        updateSmsPreview();
        
        // Validate payment amount on input
        $('#payment_amount').on('input', function() {
            const maxAmount = parseFloat(<?php echo $outstanding_amount; ?>);
            const currentAmount = parseFloat($(this).val()) || 0;
            
            if (currentAmount > maxAmount) {
                $(this).val(maxAmount.toFixed(2));
                updateBalance();
            }
        });
        
        // Auto-generate receipt number if empty
        $('#receipt_number').on('focus', function() {
            if (!$(this).val()) {
                $(this).val('RCPT-' + Math.random().toString(36).substr(2, 9).toUpperCase());
            }
        });
    });
    
    // Show loading spinner
    function showLoading(message = 'Processing...') {
        $('#loadingMessage').text(message);
        $('#loadingSpinner').css('display', 'flex');
    }
    
    // Hide loading spinner
    function hideLoading() {
        $('#loadingSpinner').hide();
    }
    
    // Update balance display
    function updateBalance() {
        const paymentAmount = parseFloat($('#payment_amount').val()) || 0;
        const outstandingAmount = parseFloat(<?php echo $outstanding_amount; ?>);
        const newBalance = Math.max(0, outstandingAmount - paymentAmount);
        
        // Update SMS preview
        updateSmsPreview();
    }
    
    // Update SMS preview
    function updateSmsPreview() {
        const paymentAmount = parseFloat($('#payment_amount').val()) || 0;
        const feeType = '<?php echo htmlspecialchars($fee_details['fee_type'] ?? ''); ?>';
        const studentName = '<?php echo htmlspecialchars($student_details['name'] ?? ''); ?>';
        const receiptNumber = $('#receipt_number').val() || '[receipt_number]';
        const outstandingAmount = parseFloat(<?php echo $outstanding_amount; ?>);
        const newBalance = Math.max(0, outstandingAmount - paymentAmount);
        const paymentDate = $('#payment_date').val() || '<?php echo date('Y-m-d'); ?>';
        
        let message = $('#sms_message').val();
        if (!message) {
            message = `Payment Received: GH₵[amount] for [fee_type]. 
Student: [student_name]
Receipt: [receipt_number]
Balance: GH₵[balance]
Thank you for your payment.`;
        }
        
        // Replace variables
        message = message
            .replace(/\[amount\]/g, paymentAmount.toFixed(2))
            .replace(/\[fee_type\]/g, feeType)
            .replace(/\[student_name\]/g, studentName)
            .replace(/\[receipt_number\]/g, receiptNumber)
            .replace(/\[balance\]/g, newBalance.toFixed(2))
            .replace(/\[date\]/g, paymentDate);
        
        $('#smsPreviewText').text(message);
        $('#smsChars').text(message.length);
        
        // Highlight if over SMS limit
        if (message.length > 160) {
            $('#smsChars').addClass('text-danger');
        } else {
            $('#smsChars').removeClass('text-danger');
        }
    }
    
    // Record payment and stay on page
    function recordAndStay() {
        $('#redirect_to').val('');
        if (validateForm()) {
            $('#payment-form').submit();
        }
    }
    
    // Form validation
    function validateForm() {
        let isValid = true;
        
        // Reset validation
        $('.is-invalid').removeClass('is-invalid');
        
        // Validate payment amount
        const paymentAmount = parseFloat($('#payment_amount').val()) || 0;
        const outstandingAmount = parseFloat(<?php echo $outstanding_amount; ?>);
        
        if (!paymentAmount || paymentAmount <= 0) {
            $('#payment_amount').addClass('is-invalid');
            isValid = false;
        }
        
        if (paymentAmount > outstandingAmount) {
            $('#payment_amount').addClass('is-invalid');
            alert('Payment amount cannot exceed outstanding balance.');
            isValid = false;
        }
        
        // Validate payment method
        if (!$('#payment_method').val()) {
            $('#payment_method').addClass('is-invalid');
            isValid = false;
        }
        
        // Validate payment date
        if (!$('#payment_date').val()) {
            $('#payment_date').addClass('is-invalid');
            isValid = false;
        }
        
        // Validate receipt number
        if (!$('#receipt_number').val()) {
            $('#receipt_number').addClass('is-invalid');
            isValid = false;
        }
        
        // Check SMS character limit
        const messageLength = $('#smsChars').text();
        if (parseInt(messageLength) > 160) {
            if (!confirm('SMS message exceeds 160 characters. It may be split into multiple messages. Continue?')) {
                return false;
            }
        }
        
        // Final confirmation
        if (isValid) {
            const paymentAmount = parseFloat($('#payment_amount').val()) || 0;
            const receiptNumber = $('#receipt_number').val();
            
            if (!confirm(`Record payment of GH₵${paymentAmount.toFixed(2)} with receipt #${receiptNumber}?`)) {
                return false;
            }
            
            // Show loading spinner
            showLoading('Recording payment and sending SMS...');
            $('button[type="submit"]').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');
        }
        
        return isValid;
    }
    
    // Auto-set redirect for submit button
    $(function() {
        $('button[type="submit"]').click(function() {
            $('#redirect_to').val('dashboard');
        });
    });
    </script>
</body>
</html>