<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Get fee ID from query parameter
$fee_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fee_id <= 0) {
    header("Location: fees_payments.php");
    exit();
}

// Fetch fee details
$fee_sql = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, 
                   CASE WHEN f.class_id IS NOT NULL THEN 'Class' WHEN f.user_id IS NOT NULL THEN 'Learner' ELSE 'School' END as target_type,
                   c.class_name, u.name as learner_name
            FROM fees f
            LEFT JOIN classes c ON f.class_id = c.id
            LEFT JOIN users u ON f.user_id = u.id
            WHERE f.id = ?";
$fee_stmt = $conn->prepare($fee_sql);
$fee_stmt->bind_param("i", $fee_id);
$fee_stmt->execute();
$fee_result = $fee_stmt->get_result();
$fee = $fee_result->fetch_assoc();
$fee_stmt->close();

if (!$fee) {
    header("Location: fees_payments.php");
    exit();
}

// Fetch associated debts
$debts_sql = "SELECT d.id, d.amount_due, d.academic_year, u.name as student_name, c.class_name
              FROM debts d
              JOIN users u ON d.user_id = u.id
              JOIN fees f ON d.fee_id = f.id
              JOIN class_enrollments ce ON f.class_id = ce.class_id AND ce.student_id = d.user_id
              LEFT JOIN classes c ON f.class_id = c.id
              WHERE d.fee_id = ?";
$debts_stmt = $conn->prepare($debts_sql);
$debts_stmt->bind_param("i", $fee_id);
$debts_stmt->execute();
$debts_result = $debts_stmt->get_result();
$debts = [];
while ($row = $debts_result->fetch_assoc()) {
    $debts[] = $row;
}
$debts_stmt->close();

// Fetch associated payments
$payments_sql = "SELECT p.id, p.amount_paid, p.payment_date, p.payment_method, p.receipt_number, u.name as student_name
                FROM payments p
                JOIN users u ON p.user_id = u.id
                JOIN fees f ON p.fee_id = f.id
                JOIN class_enrollments ce ON f.class_id = ce.class_id AND ce.student_id = p.user_id
                WHERE p.fee_id = ?";
$payments_stmt = $conn->prepare($payments_sql);
$payments_stmt->bind_param("i", $fee_id);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();
$payments = [];
while ($row = $payments_result->fetch_assoc()) {
    $payments[] = $row;
}
$payments_stmt->close();

// Log action
if (function_exists('log_activity')) {
    log_activity($_SESSION['user_id'], 'Viewed Fee', "Fee ID: $fee_id, Type: {$fee['fee_type']}, Academic Year: {$fee['academic_year']}", $conn);
} else {
    error_log("log_activity function is undefined in view_fee.php");
}

?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View fee details for QueensPa School, including associated debts and payments.">
    <meta name="keywords" content="QueensPa School, view fee, admin dashboard, school management, fees">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School View Fee">
    <meta property="og:description" content="Admin interface for viewing fee details in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://queenspa.com/admin/view_fee.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School View Fee">
    <meta name="twitter:description" content="Admin interface for viewing fee details in QueensPa School.">
    <title>View Fee - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-secondary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-secondary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .table thead {
            background: var(--primary-color);
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .print-section {
            visibility: hidden;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
        }
        @media (max-width: 767.98px) {
            .table th, .table td {
                font-size: 0.9rem;
            }
        }
        @media print {
            body * {
                visibility: hidden;
            }
            .print-section, .print-section * {
                visibility: visible;
                display: block;
            }
            .print-section {
                position: static;
                font-size: 12pt;
            }
            .print-header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #ff4500;
                padding-bottom: 10px;
            }
            .print-header img {
                max-width: 100px;
            }
            .print-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            .print-table th, .print-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            .print-table th {
                background: linear-gradient(to right, #ff4500, #ff6b00);
                color: white;
            }
            .print-footer {
                text-align: center;
                margin-top: 20px;
                font-size: 10pt;
                color: #555;
            }
            @page {
                size: A4;
                margin: 20mm;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">💰 Fee Details</h3>
                </div>
                <div class="card-body">
                    <div class="print-section">
                        <div class="print-header">
                            <img src="/queenspa/assets/images/logo.png" alt="QueensPa School Logo">
                            <h2>QueensPa School</h2>
                            <p>P.O. Box 123, Accra, Ghana | info@queenspa.com</p>
                            <p>Fee Details - <?php echo date('F j, Y'); ?></p>
                        </div>
                        <table class="print-table">
                            <tr><th>Fee Type</th><td><?php echo htmlspecialchars($fee['fee_type']); ?></td></tr>
                            <tr><th>Target</th><td><?php echo htmlspecialchars($fee['target_type']); ?></td></tr>
                            <tr><th>Class</th><td><?php echo htmlspecialchars($fee['class_name'] ?? 'N/A'); ?></td></tr>
                            <tr><th>Learner</th><td><?php echo htmlspecialchars($fee['learner_name'] ?? 'N/A'); ?></td></tr>
                            <tr><th>Amount</th><td>₵<?php echo number_format($fee['amount'], 2); ?></td></tr>
                            <tr><th>Due Date</th><td><?php echo htmlspecialchars($fee['due_date']); ?></td></tr>
                            <tr><th>Academic Year</th><td><?php echo htmlspecialchars($fee['academic_year']); ?></td></tr>
                        </table>
                        <div class="print-footer">
                            <p>Generated by QueensPa School Management System</p>
                        </div>
                    </div>
                    <button class="btn btn-primary mb-3" onclick="printContent()"><i class="fas fa-print"></i> Print Details</button>
                    <a href="fees_payments.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Back to Fees</a>
                    <h4>Fee Information</h4>
                    <table class="table table-bordered">
                        <tr><th>Fee Type</th><td><?php echo htmlspecialchars($fee['fee_type']); ?></td></tr>
                        <tr><th>Target</th><td><?php echo htmlspecialchars($fee['target_type']); ?></td></tr>
                        <tr><th>Class</th><td><?php echo htmlspecialchars($fee['class_name'] ?? 'N/A'); ?></td></tr>
                        <tr><th>Learner</th><td><?php echo htmlspecialchars($fee['learner_name'] ?? 'N/A'); ?></td></tr>
                        <tr><th>Amount</th><td>₵<?php echo number_format($fee['amount'], 2); ?></td></tr>
                        <tr><th>Due Date</th><td><?php echo htmlspecialchars($fee['due_date']); ?></td></tr>
                        <tr><th>Academic Year</th><td><?php echo htmlspecialchars($fee['academic_year']); ?></td></tr>
                    </table>
                </div>
            </section>
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">Associated Debts</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($debts)): ?>
                        <p>No debts found for this fee.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Amount Due</th>
                                        <th>Academic Year</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($debts as $debt): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                                            <td>₵<?php echo number_format($debt['amount_due'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">Associated Payments</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($payments)): ?>
                        <p>No payments found for this fee.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Amount Paid</th>
                                        <th>Payment Date</th>
                                        <th>Payment Method</th>
                                        <th>Receipt Number</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                            <td>₵<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['receipt_number'] ?: 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <?php // Include database connection and header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
 ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        function printContent() {
            const printSection = document.querySelector('.print-section');
            printSection.style.visibility = 'visible';
            printSection.style.display = 'block';
            window.print();
            printSection.style.visibility = 'hidden';
            printSection.style.display = 'none';
        }
    </script>
</body>
</html>