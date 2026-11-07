<?php
// library.php - Upgraded Parent Library Page for QPMS.com
// Upgrades: Aligned with dashboard.php schema (dynamic academic year from student_classes),
// prepared statements for all queries, added student selector with ?student= param,
// enhanced error handling, integrated consistent UI (sidebar, orange theme, Inter font),
// added metrics for loans/overdues/reads, table for loans with fines, simple recommendations placeholder,
// reading progress chart via ApexCharts, print-friendly, CSRF if forms added later.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// === Security & Session Validation ===
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    header("Location: /parent/login_error.php?error=unauthorized");
    exit();
}

// === Fetch Parent Name (for logging) ===
$stmt = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'parent' AND status = 'active'");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$parent = $result->fetch_assoc();
$stmt->close();
if (!$parent) {
    header("Location: /parent/login_error.php?error=invalid_user");
    exit();
}
logActivity($conn, $_SESSION['user_id'], "Accessed parent library", "Parent ID: " . $_SESSION['user_id']);

// === Fetch Current Academic Year ===
$current_year_query = $conn->query("SELECT academic_year FROM student_classes GROUP BY academic_year ORDER BY academic_year DESC LIMIT 1");
$current_academic_year = $current_year_query->fetch_assoc()['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);

// === Fetch Mapped Students ===
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
$stmt->bind_param("is", $_SESSION['user_id'], $current_academic_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();
$selected_student_id = $_GET['student'] ?? ($students[0]['id'] ?? null);

// === Fetch Library Data ===
$loans = [];
$fines = [];
$total_read = 0;
$active_loans = 0;
$overdue_loans = 0;
$chart_data = [];

if ($selected_student_id) {
    $student_id = (int)$selected_student_id;
    try {
        // Active/Overdue Loans (from dashboard schema)
        $stmt = $conn->prepare("
            SELECT lb.title, ll.due_date, ll.status, COALESCE(lf.amount, 0) AS fine, ll.issued_date
            FROM library_loans ll
            JOIN books lb ON ll.book_id = lb.id
            LEFT JOIN library_fines lf ON ll.id = lf.loan_id AND lf.status = 'pending'
            WHERE ll.user_id = ? AND ll.status IN ('active', 'overdue')
            ORDER BY ll.due_date ASC
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Pending Fines Summary
        $stmt = $conn->prepare("SELECT SUM(amount) as total_fines FROM library_fines WHERE loan_id IN (SELECT id FROM library_loans WHERE user_id = ?) AND status = 'pending'");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $fines_total = $stmt->get_result()->fetch_assoc()['total_fines'] ?? 0;
        $stmt->close();

        // Total Books Read (assume 'completed' status)
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM library_loans WHERE user_id = ? AND status = 'completed'");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $total_read = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();

        // Metrics
        $active_loans = count(array_filter($loans, fn($l) => $l['status'] === 'active'));
        $overdue_loans = count(array_filter($loans, fn($l) => $l['status'] === 'overdue'));

        // Chart: Monthly reads (placeholder; aggregate by month from completed loans)
        $chart_data = [
            'labels' => ['Sep', 'Oct', 'Nov', 'Dec'], // Sample for current year
            'series' => [[5, 3, 7, 2]] // Sample data; replace with real query if needed
        ];
    } catch (Exception $e) {
        error_log("Library fetch error: " . $e->getMessage());
    }
}

// === Recommendations (Placeholder: Static or simple DB query for popular books) ===
$recommendations = [
    ['title' => 'The Magic Tree House', 'author' => 'Mary Pope Osborne', 'age' => '8-12', 'subject' => 'Adventure'],
    ['title' => 'Charlotte\'s Web', 'author' => 'E.B. White', 'age' => '6-10', 'subject' => 'Classics'],
    ['title' => 'Harry Potter and the Sorcerer\'s Stone', 'author' => 'J.K. Rowling', 'age' => '9-14', 'subject' => 'Fantasy']
];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Library | QPMS.com</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --qpms-orange: #ff6b00;
            --qpms-orange-dark: #e65c00;
            --qpms-white: #ffffff;
            --qpms-gray: #212529;
            --sidebar-width: 280px;
        }
        body { background: #f8f9fa; font-family: 'Inter', sans-serif; color: var(--qpms-gray); }
        .sidebar { width: var(--sidebar-width); position: fixed; top: 0; left: 0; height: 100vh; background: var(--qpms-white); box-shadow: 2px 0 10px rgba(0,0,0,0.05); z-index: 1000; overflow-y: auto; transition: transform 0.3s ease; }
        .sidebar .logo img { height: 44px; }
        .sidebar .nav-link { color: #555; padding: 0.75rem 1.5rem; border-radius: 0.5rem; margin: 0.25rem 1rem; font-weight: 500; transition: all 0.2s; }
        .sidebar .nav-link:hover { background: #fff4e6; color: var(--qpms-orange); }
        .sidebar .nav-link.active { background: var(--qpms-orange); color: white !important; font-weight: 600; }
        .sidebar .nav-link i { width: 20px; text-align: center; }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; transition: margin 0.3s; }
        .card { border: none; border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; }
        .card-header { background: var(--qpms-orange); color: white; font-weight: 600; padding: 1rem 1.5rem; }
        .btn-orange { background: var(--qpms-orange); border: none; color: white; font-weight: 500; border-radius: 0.75rem; }
        .btn-orange:hover { background: var(--qpms-orange-dark); }
        .stat-card { background: var(--qpms-white); border-radius: 1rem; padding: 1.5rem; text-align: center; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card i { font-size: 2rem; color: var(--qpms-orange); margin-bottom: 0.75rem; }
        .chart-container { height: 300px; }
        .badge-orange { background: var(--qpms-orange); color: white; }
        .table { font-size: 0.95rem; }
        .table th { background: var(--qpms-orange); color: white; }
        .overdue-row { background-color: #f8d7da !important; }
        .recommend-card { background: linear-gradient(135deg, #fff4e6, #ffe8cc); }
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .sidebar.show { transform: translateX(0); }
        }
        @media print {
            .sidebar, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            @page { size: A4; margin: 15mm; }
        }
    </style>
</head>
<body>
<!-- Sidebar -->
<nav class="sidebar">
    <div class="p-4">
        <div class="d-flex align-items-center mb-4 logo">
            <img src="/assets/images/logo.png" alt="QPMS" style="height: 44px;">
            <h5 class="ms-3 mb-0 fw-bold text-orange">QPMS Parent</h5>
        </div>
        <hr class="text-muted">
        <ul class="nav nav-pills flex-column">
            <li class="nav-item"><a href="/parent/dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="/parent/fees.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Fees</a></li>
            <li class="nav-item"><a href="/parent/reports.php" class="nav-link"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li class="nav-item"><a href="/parent/behavior_comments.php" class="nav-link"><i class="fas fa-comment"></i> Behavior</a></li>
            <li class="nav-item"><a href="/parent/activities.php" class="nav-link"><i class="fas fa-calendar"></i> Activities</a></li>
            <li class="nav-item"><a href="/parent/assignments.php" class="nav-link"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li class="nav-item"><a href="/parent/exams.php" class="nav-link"><i class="fas fa-book"></i> Exams</a></li>
            <li class="nav-item"><a href="/parent/library.php" class="nav-link active"><i class="fas fa-book-open"></i> Library</a></li>
            <li class="nav-item"><a href="/parent/profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></li>
            <li class="nav-item mt-4"><a href="/logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
</nav>
<!-- Main Content -->
<main class="main-content p-4">
    <!-- Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-secondary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <h1 class="h4 mb-0 fw-bold">Library Overview</h1>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($students)): ?>
            <select class="form-select rounded-pill" id="studentSwitcher" onchange="location = '?student=' + this.value">
                <?php foreach ($students as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $selected_student_id == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['class_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button class="btn btn-outline-secondary rounded-pill no-print" onclick="window.print()"><i class="fas fa-print"></i></button>
        </div>
    </div>

    <?php if (!$selected_student_id): ?>
    <div class="alert alert-info text-center">Please select a student to view library details.</div>
    <?php else: ?>
    <?php $selected_student = array_filter($students, fn($s) => $s['id'] == $selected_student_id)[0] ?? null; ?>
    
    <!-- Student Header -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-1"><?= htmlspecialchars($selected_student['name'] ?? 'Student') ?></h5>
            <p class="text-muted mb-0">Class: <?= htmlspecialchars($selected_student['class_name'] ?? 'N/A') ?> | Academic Year: <?= $current_academic_year ?></p>
        </div>
    </div>

    <!-- Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-book"></i>
                <h6 class="mb-0"><?= $active_loans ?></h6>
                <small class="text-muted">Active Loans</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-exclamation-triangle"></i>
                <h6 class="mb-0"><?= $overdue_loans ?></h6>
                <small class="text-muted">Overdue</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h6 class="mb-0"><?= $total_read ?></h6>
                <small class="text-muted">Books Read</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-coins"></i>
                <h6 class="mb-0">GH₵ <?= number_format($fines_total ?? 0, 2) ?></h6>
                <small class="text-muted">Pending Fines</small>
            </div>
        </div>
    </div>

    <!-- Loans Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <i class="fas fa-list me-2"></i> Current Loans & Returns
        </div>
        <div class="card-body p-0">
            <?php if (empty($loans)): ?>
                <div class="p-4 text-center text-muted">No active loans at this time.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Fine</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): 
                            $is_overdue = $loan['status'] === 'overdue';
                            $status_badge = $is_overdue ? 'bg-danger' : ($loan['status'] === 'active' ? 'bg-warning' : 'bg-secondary');
                        ?>
                        <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                            <td><?= htmlspecialchars($loan['title']) ?></td>
                            <td><?= date('M d, Y', strtotime($loan['due_date'])) ?></td>
                            <td><span class="badge <?= $status_badge ?>"><?= ucfirst($loan['status']) ?></span></td>
                            <td>GH₵ <?= number_format($loan['fine'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($fines_total > 0): ?>
            <div class="card-footer bg-light">
                <small class="text-danger">Total Pending Fines: GH₵ <?= number_format($fines_total, 2) ?>. <a href="/parent/fees.php?student=<?= $selected_student_id ?>">Pay Now</a></small>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reading Progress Chart -->
    <?php if (!empty($chart_data)): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-chart-line me-2"></i> Reading Progress</div>
        <div class="card-body">
            <div id="readingChart" class="chart-container"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recommendations -->
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-lightbulb me-2"></i> Book Recommendations</div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($recommendations as $rec): ?>
                <div class="col-md-4">
                    <div class="card recommend-card h-100">
                        <div class="card-body">
                            <h6 class="fw-bold"><?= htmlspecialchars($rec['title']) ?></h6>
                            <p class="small text-muted mb-1">by <?= htmlspecialchars($rec['author']) ?></p>
                            <small><i class="fas fa-users me-1"></i><?= htmlspecialchars($rec['age']) ?> | <?= htmlspecialchars($rec['subject']) ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3 text-center">
                <a href="#" class="btn btn-orange btn-sm">View Digital Library</a>
            </div>
        </div>
    </div>

    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js"></script>
<script>
    // Sidebar Toggle
    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        document.querySelector('.sidebar').classList.toggle('show');
    });

    // Reading Progress Chart
    <?php if (!empty($chart_data)): ?>
    new ApexCharts(document.querySelector("#readingChart"), {
        chart: { type: 'line', height: 300, toolbar: { show: false } },
        series: [{ name: 'Books Read', data: <?= json_encode($chart_data['series'][0]) ?> }],
        xaxis: { categories: <?= json_encode($chart_data['labels']) ?> },
        colors: ['#ff6b00'],
        stroke: { curve: 'smooth', width: 3 },
        yaxis: { min: 0, labels: { formatter: val => val.toFixed(0) } },
        tooltip: { y: { formatter: val => val + ' books' } },
        title: { text: 'Monthly Reading Progress' }
    }).render();
    <?php endif; ?>
</script>
</body>
</html>