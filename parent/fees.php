<?php
// fees.php - Upgraded Parent Fees Dashboard for QPMCEDU.com
// Upgrades: Aligned with shared sidebar (parent_header.php),
// fixed SQL injection risks, improved error handling, consistent orange theme,
// integrated logging, graceful fallbacks for missing data/tables,
// enhanced UI with metrics cards, print-friendly report, Chart.js,
// CSRF validation, UTF-8 for GH₵, responsive design.

session_start();
// Restrict to parents only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    header("Location: /parent/login.php");
    exit();
}
// Include database connection and functions
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!isset($conn) || !$conn) {
    error_log("Database connection failed");
    http_response_code(500);
    die("System error: Database connection failed.");
}
// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
// Fetch current academic year (aligned with dashboard)
$current_year_query = $conn->query("SELECT academic_year FROM student_classes GROUP BY academic_year ORDER BY academic_year DESC LIMIT 1");
$current_academic_year = $current_year_query->fetch_assoc()['academic_year'] ?? '2025/2026';
// Fetch current term
$current_term = ['id' => 0, 'term_name' => 'N/A', 'year' => $current_academic_year];
$stmt = $conn->prepare("SELECT id, term_name, year, start_date, end_date FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $current_term = $row;
}
$stmt->close();
// Handle student filter (GET/POST with validation)
$parent_id = (int)$_SESSION['user_id'];
$selected_student_id = 0;
if (isset($_GET['student']) && is_numeric($_GET['student'])) {
    $selected_student_id = (int)$_GET['student'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token && isset($_POST['student_id']) && is_numeric($_POST['student_id'])) {
    $selected_student_id = (int)$_POST['student_id'];
}
// Fetch students associated with parent (prepared)
$students = [];
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.name, c.class_name
    FROM users u
    JOIN parent_student_mapping psm ON u.id = psm.student_id
    JOIN student_classes sc ON u.id = sc.user_id
    JOIN classes c ON sc.class_id = c.id
    WHERE psm.parent_id = ? AND sc.academic_year = ?
    ORDER BY u.name
");
$stmt->bind_param("is", $parent_id, $current_academic_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();
$valid_student_ids = array_column($students, 'id');
if ($selected_student_id > 0 && !in_array($selected_student_id, $valid_student_ids)) {
    $selected_student_id = 0;
}
// Fetch debts (prepared, with student filter)
$debts = [];
try {
    $student_filter = $selected_student_id > 0 ? "AND d.user_id = ?" : "";
    $sql = "SELECT d.id, d.user_id, d.amount_due, d.academic_year, f.fee_type, f.amount, f.due_date, f.department, f.target_type, f.is_recurring, u.name AS student_name, c.class_name
            FROM debts d
            JOIN fees f ON d.fee_id = f.id
            JOIN users u ON d.user_id = u.id
            LEFT JOIN classes c ON f.class_id = c.id
            WHERE d.user_id IN (SELECT student_id FROM parent_student_mapping WHERE parent_id = ?)
            AND d.academic_year = ? $student_filter
            ORDER BY f.due_date";
    $stmt = $conn->prepare($sql);
    if ($selected_student_id > 0) {
        $stmt->bind_param("iis", $parent_id, $current_academic_year, $selected_student_id);
    } else {
        $stmt->bind_param("is", $parent_id, $current_academic_year);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['is_overdue'] = strtotime($row['due_date']) < time();
        $debts[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching debts: " . $e->getMessage());
}
// Fetch payments (prepared)
$payments = [];
try {
    $student_filter = $selected_student_id > 0 ? "AND p.user_id = ?" : "";
    $sql = "SELECT p.id, p.user_id, p.amount_paid, p.payment_date, p.payment_method, p.receipt_number, f.fee_type, u.name AS student_name, c.class_name
            FROM payments p
            JOIN fees f ON p.fee_id = f.id
            JOIN users u ON p.user_id = u.id
            LEFT JOIN classes c ON f.class_id = c.id
            WHERE p.user_id IN (SELECT student_id FROM parent_student_mapping WHERE parent_id = ?)
            AND f.academic_year = ? $student_filter
            ORDER BY p.payment_date DESC";
    $stmt = $conn->prepare($sql);
    if ($selected_student_id > 0) {
        $stmt->bind_param("iis", $parent_id, $current_academic_year, $selected_student_id);
    } else {
        $stmt->bind_param("is", $parent_id, $current_academic_year);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching payments: " . $e->getMessage());
}
// Calculate metrics
$total_debt = array_sum(array_column($debts, 'amount_due'));
$overdue_debts = array_sum(array_column(array_filter($debts, fn($d) => $d['is_overdue']), 'amount_due'));
$total_paid = array_sum(array_column($payments, 'amount_paid'));
// Prepare chart data
$chart_labels = $selected_student_id > 0 ? ['Debt', 'Paid'] : array_column($students, 'name');
$chart_debts = [];
$chart_payments = [];
if ($selected_student_id > 0) {
    $chart_debts[] = $total_debt;
    $chart_payments[] = $total_paid;
} else {
    foreach ($students as $student) {
        $sid = $student['id'];
        $chart_debts[] = array_sum(array_column(array_filter($debts, fn($d) => $d['user_id'] == $sid), 'amount_due'));
        $chart_payments[] = array_sum(array_column(array_filter($payments, fn($p) => $p['user_id'] == $sid), 'amount_paid'));
    }
}
// Log access
logActivity($conn, $parent_id, "Accessed Fees Page", "Viewed fees for year: $current_academic_year" . ($selected_student_id > 0 ? ", student: $selected_student_id" : ""));
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Fees | QPMCEDU Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" defer></script>
    <style>
        /* Additional page-specific styles that complement the shared navbar */
        :root {
            --qpms-orange: #ff6b00;
            --qpms-orange-dark: #e65c00;
            --qpms-gray: #212529;
        }
        body {
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
            color: var(--qpms-gray);
            overflow-x: hidden;
        }
        /* Override any potential conflicts with navbar margins */
        #qpmc-content {
            padding: 1.5rem 2rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        @media (max-width: 768px) {
            #qpmc-content {
                padding: 1rem;
            }
        }
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: var(--qpms-orange);
            color: white;
            font-weight: 600;
            padding: 1rem 1.5rem;
            border-bottom: none;
        }
        .card-header h3, .card-header h5 {
            margin: 0;
        }
        .btn-orange {
            background: var(--qpms-orange);
            border: none;
            color: white;
            border-radius: 0.75rem;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-orange:hover {
            background: var(--qpms-orange-dark);
            color: white;
            transform: translateY(-1px);
        }
        .btn-outline-orange {
            border: 1px solid var(--qpms-orange);
            color: var(--qpms-orange);
            background: transparent;
            border-radius: 0.75rem;
        }
        .btn-outline-orange:hover {
            background: var(--qpms-orange);
            color: white;
        }
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: transform 0.2s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-card i {
            font-size: 2rem;
            color: var(--qpms-orange);
            margin-bottom: 0.75rem;
        }
        .stat-card h6 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0.5rem 0;
            color: var(--qpms-gray);
        }
        .table {
            font-size: 0.9rem;
        }
        .table thead th {
            background: #fff3e6;
            color: var(--qpms-orange);
            font-weight: 600;
            border-bottom: 2px solid var(--qpms-orange);
        }
        .overdue {
            color: #dc3545;
            font-weight: bold;
        }
        .badge-overdue {
            background: #dc3545;
            color: white;
            padding: 0.35rem 0.65rem;
            border-radius: 2rem;
            font-size: 0.7rem;
        }
        .badge-due {
            background: #ffc107;
            color: #212529;
            padding: 0.35rem 0.65rem;
            border-radius: 2rem;
            font-size: 0.7rem;
        }
        .chart-container {
            height: 320px;
            position: relative;
        }
        @media print {
            #qpmc-sidebar, #qpmc-topbar, #qpmc-overlay, .no-print, .btn, .card-header .btn, form, .sidebar-toggle {
                display: none !important;
            }
            #qpmc-content {
                margin: 0 !important;
                padding: 0 !important;
            }
            .print-section {
                display: block !important;
            }
            .card, .stat-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            body {
                background: white;
            }
        }
        .form-select:focus {
            border-color: var(--qpms-orange);
            box-shadow: 0 0 0 0.2rem rgba(255,107,0,0.25);
        }
        footer {
            text-align: center;
            padding: 1.5rem;
            color: #6c757d;
            font-size: 0.85rem;
            border-top: 1px solid #dee2e6;
            margin-top: 2rem;
        }
    </style>
</head>
<body>

<?php 
// Include the shared parent sidebar/navbar
// IMPORTANT: This file opens <div id="qpmc-content"> which we will close at the end of THIS file.
require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php'; 
?>

<!-- MAIN CONTENT AREA (inside #qpmc-content which is opened by navbar.php) -->
<div class="container-fluid px-0">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1 fw-semibold" style="color: var(--qpms-gray);">
                <i class="fas fa-money-bill-wave text-orange me-2" style="color: var(--qpms-orange);"></i>Fees Management
            </h2>
            <p class="text-muted mb-0">
                <i class="fas fa-calendar-alt me-1"></i> Academic Year: <?= htmlspecialchars($current_academic_year) ?> 
                | Term: <?= htmlspecialchars($current_term['term_name']) ?>
            </p>
        </div>
        <div class="mt-2 mt-sm-0">
            <button onclick="window.print()" class="btn btn-outline-orange no-print me-2">
                <i class="fas fa-print"></i> Print Report
            </button>
            <a href="/parent/dashboard.php" class="btn btn-orange no-print">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Student Filter Card -->
    <div class="card">
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label for="student_id" class="form-label fw-semibold">
                        <i class="fas fa-user-graduate me-1"></i> Filter by Student
                    </label>
                    <select name="student_id" id="student_id" class="form-select" onchange="this.form.submit()">
                        <option value="0" <?= $selected_student_id == 0 ? 'selected' : '' ?>>-- All Students --</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= $student['id'] ?>" <?= $selected_student_id == $student['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['class_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <button type="submit" class="btn btn-orange w-100">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                </div>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            </form>
        </div>
    </div>

    <!-- Metrics Cards Row -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h6><?= count($students) ?></h6>
                <small class="text-muted">Enrolled Students</small>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-coins"></i>
                <h6>GH₵ <?= number_format($total_debt, 2) ?></h6>
                <small class="text-muted">Total Outstanding</small>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-exclamation-triangle"></i>
                <h6 class="text-danger">GH₵ <?= number_format($overdue_debts, 2) ?></h6>
                <small class="text-muted">Overdue Balance</small>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h6 class="text-success">GH₵ <?= number_format($total_paid, 2) ?></h6>
                <small class="text-muted">Total Payments Made</small>
            </div>
        </div>
    </div>

    <!-- Outstanding Debts Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i> Outstanding Debts</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Student Name</th><th>Class</th><th>Fee Type</th><th>Amount Due</th><th>Due Date</th><th>Status</th><th class="no-print">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($debts)): ?>
                            <tr class="text-center"><td colspan="7" class="text-muted py-4">✅ No outstanding debts. Good standing!</td></tr>
                        <?php else: ?>
                            <?php foreach ($debts as $debt): ?>
                                <tr class="<?= $debt['is_overdue'] ? 'table-danger' : '' ?>">
                                    <td class="fw-semibold"><?= htmlspecialchars($debt['student_name']) ?></td>
                                    <td><?= htmlspecialchars($debt['class_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($debt['fee_type']) ?></td>
                                    <td>GH₵ <?= number_format($debt['amount_due'], 2) ?></td>
                                    <td><?= date('M d, Y', strtotime($debt['due_date'])) ?></td>
                                    <td>
                                        <?php if ($debt['is_overdue']): ?>
                                            <span class="badge-overdue">Overdue</span>
                                        <?php elseif ($debt['is_recurring']): ?>
                                            <span class="badge-due">Recurring</span>
                                        <?php else: ?>
                                            <span class="badge-due">Due</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print">
                                        <a href="/parent/pay_fee.php?debt_id=<?= $debt['id'] ?>" class="btn btn-sm btn-orange">
                                            <i class="fas fa-credit-card"></i> Pay Now
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment History Table -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i> Recent Payment History</h5>
            <span class="badge bg-light text-dark">Last 10 transactions</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Student</th><th>Fee Type</th><th>Amount Paid</th><th>Payment Date</th><th>Method</th><th>Receipt #</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr class="text-center"><td colspan="6" class="text-muted py-4">No payment records found.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_slice($payments, 0, 10) as $payment): ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($payment['student_name']) ?></td>
                                    <td><?= htmlspecialchars($payment['fee_type']) ?></td>
                                    <td class="text-success fw-semibold">GH₵ <?= number_format($payment['amount_paid'], 2) ?></td>
                                    <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($payment['payment_method']) ?></span></td>
                                    <td><code><?= htmlspecialchars($payment['receipt_number'] ?? 'N/A') ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Chart Visualization -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i> Debt vs Paid Overview</h5>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="debtChart"></canvas>
            </div>
            <div class="text-muted small text-center mt-3">
                <i class="fas fa-info-circle"></i> Chart shows comparison between outstanding debt and total paid amount 
                <?= $selected_student_id > 0 ? 'for selected student' : 'per student' ?>.
            </div>
        </div>
    </div>

    <!-- Footer Note -->
    <footer class="no-print">
        <p class="mb-0">&copy; <?= date('Y') ?> QueensPa School. All fees are in Ghana Cedis (GH₵). For enquiries, contact finance office.</p>
    </footer>
</div>

<!-- PRINT SECTION (Hidden on screen, visible when printing) -->
<div class="print-section d-none">
    <div style="text-align: center; margin-bottom: 30px; border-bottom: 3px solid #ff6b00; padding-bottom: 15px;">
        <img src="/assets/images/logo.png" alt="QPMCEDU" style="max-width: 100px;" onerror="this.style.display='none'">
        <h2 style="margin: 10px 0 0;">QueensPa School - Fees Report</h2>
        <p style="margin: 5px 0;">Academic Year: <?= htmlspecialchars($current_academic_year) ?> | Term: <?= htmlspecialchars($current_term['term_name']) ?></p>
        <p>Generated: <?= date('F j, Y g:i A') ?></p>
    </div>
    
    <h4 style="margin-top: 25px;">Outstanding Debts Summary</h4>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px;">
        <thead>
            <tr style="background: #ff6b00; color: white; text-align: left;">
                <th style="padding: 8px;">Student</th><th style="padding: 8px;">Class</th><th style="padding: 8px;">Fee Type</th><th style="padding: 8px;">Amount Due</th><th style="padding: 8px;">Due Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($debts as $debt): ?>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="padding: 8px;"><?= htmlspecialchars($debt['student_name']) ?></td>
                    <td style="padding: 8px;"><?= htmlspecialchars($debt['class_name'] ?? 'N/A') ?></td>
                    <td style="padding: 8px;"><?= htmlspecialchars($debt['fee_type']) ?></td>
                    <td style="padding: 8px;">GH₵ <?= number_format($debt['amount_due'], 2) ?></td>
                    <td style="padding: 8px;"><?= htmlspecialchars($debt['due_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($debts)): ?>
                <tr><td colspan="5" style="padding: 15px; text-align: center;">No outstanding debts.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h4>Payment History (Recent)</h4>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px;">
        <thead>
            <tr style="background: #ff6b00; color: white;">
                <th style="padding: 8px;">Student</th><th style="padding: 8px;">Fee Type</th><th style="padding: 8px;">Amount Paid</th><th style="padding: 8px;">Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_slice($payments, 0, 20) as $payment): ?>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="padding: 8px;"><?= htmlspecialchars($payment['student_name']) ?></td>
                    <td style="padding: 8px;"><?= htmlspecialchars($payment['fee_type']) ?></td>
                    <td style="padding: 8px;">GH₵ <?= number_format($payment['amount_paid'], 2) ?></td>
                    <td style="padding: 8px;"><?= htmlspecialchars($payment['payment_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($payments)): ?>
                <tr><td colspan="4" style="padding: 15px; text-align: center;">No payment records.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="text-align: center; margin-top: 30px; border-top: 1px solid #ccc; padding-top: 15px; font-size: 0.85rem;">
        <p><strong>Total Outstanding:</strong> GH₵ <?= number_format($total_debt, 2) ?> | 
           <strong>Overdue:</strong> GH₵ <?= number_format($overdue_debts, 2) ?> | 
           <strong>Total Paid:</strong> GH₵ <?= number_format($total_paid, 2) ?>
        </p>
        <p>This is an official fees statement from QueensPa School Parent Portal.</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Chart.js if canvas exists
        const canvas = document.getElementById('debtChart');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [
                        {
                            label: 'Outstanding Debt (GH₵)',
                            data: <?= json_encode($chart_debts) ?>,
                            backgroundColor: 'rgba(220, 53, 69, 0.7)',
                            borderColor: '#dc3545',
                            borderWidth: 1,
                            borderRadius: 6
                        },
                        {
                            label: 'Total Paid (GH₵)',
                            data: <?= json_encode($chart_payments) ?>,
                            backgroundColor: 'rgba(40, 167, 69, 0.7)',
                            borderColor: '#28a745',
                            borderWidth: 1,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Amount (GH₵)',
                                font: { weight: 'bold' }
                            }
                        },
                        x: {
                            ticks: { autoSkip: false, maxRotation: 45, minRotation: 30 }
                        }
                    },
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 10 } },
                        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: GH₵ ${ctx.raw.toFixed(2)}` } }
                    }
                }
            });
        }

        // Close sidebar when a nav link is clicked on mobile (optional but good UX)
        const sidebar = document.getElementById('qpmc-sidebar');
        const overlay = document.getElementById('qpmc-overlay');
        if (sidebar && overlay) {
            const navLinks = sidebar.querySelectorAll('a');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (window.innerWidth <= 991) {
                        closeSidebar();
                    }
                });
            });
        }
    });
</script>

<?php
// Close the #qpmc-content div that was opened by navbar.php
// This is required as per the navbar.php documentation.
// No extra whitespace after closing to prevent output issues.
?>
</div> <!-- end #qpmc-content -->
</body>
</html>