<?php
// exams.php - Upgraded Parent Exams Page for QPMS.com
// Upgrades: Aligned with dashboard.php and assignments.php schema (dynamic academic year from student_classes),
// prepared statements for all queries, added student selector with ?student= param,
// enhanced error handling, integrated consistent UI (sidebar, orange theme, Inter font),
// switched to ApexCharts for consistency, added progress metrics (e.g., upcoming count),
// improved print functionality, CSRF validation on form submits, better logging,
// responsive design updates, fixed hardcoded year to dynamic, added badges/highlights for upcoming.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// === Security & Session Validation ===
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    header("Location: /parent/login_error.php?error=unauthorized");
    exit();
}

// Generate/Validate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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
logActivity($conn, $_SESSION['user_id'], "Accessed parent exams", "Parent ID: " . $_SESSION['user_id']);

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

// === Fetch Academic Terms for Current Year ===
$terms = [];
try {
    $stmt = $conn->prepare("SELECT id, term_name, year, start_date, end_date FROM academic_calendar WHERE year = ? ORDER BY start_date");
    $stmt->bind_param("s", $current_academic_year);
    $stmt->execute();
    $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Terms fetch error: " . $e->getMessage());
}

// === Handle Term Selection ===
$selected_term_id = $current_term_id = null;
if (!empty($terms)) {
    // Default to current term
    foreach ($terms as $term) {
        if (strtotime($term['start_date']) <= time() && time() <= strtotime($term['end_date'])) {
            $current_term_id = $term['id'];
            break;
        }
    }
    $current_term_id = $current_term_id ?? $terms[0]['id'];
    
    // Override with POST if valid
    if (isset($_POST['term_id']) && is_numeric($_POST['term_id']) && $_POST['csrf_token'] === $csrf_token) {
        $selected_term_id = (int)$_POST['term_id'];
        if (!in_array($selected_term_id, array_column($terms, 'id'))) {
            $selected_term_id = $current_term_id;
        }
    } else {
        $selected_term_id = $current_term_id;
    }
}
$selected_term = array_filter($terms, fn($t) => $t['id'] == $selected_term_id)[0] ?? ['term_name' => 'N/A', 'year' => $current_academic_year];

// === Fetch Exams Data ===
$exams = [];
$total_exams = 0;
$upcoming_exams = 0;
$chart_labels = [];
$chart_series = [];
$current_date = date('Y-m-d');
$upcoming_date = date('Y-m-d', strtotime('+7 days'));

if ($selected_student_id && !empty($terms)) {
    $student_id = (int)$selected_student_id;
    $student = array_filter($students, fn($s) => $s['id'] == $student_id)[0] ?? null;
    
    try {
        $stmt = $conn->prepare("
            SELECT es.id, es.exam_date, es.start_time, es.end_time, es.room, es.instructions,
                   s.subject_name, ac.term_name
            FROM exam_schedules es
            JOIN classes c ON es.class_id = c.id
            JOIN subjects s ON es.subject_id = s.id
            JOIN academic_calendar ac ON es.term_id = ac.id
            JOIN student_classes sc ON c.id = sc.class_id AND sc.user_id = ? AND sc.academic_year = ?
            WHERE es.term_id = ?
            ORDER BY es.exam_date, es.start_time
        ");
        $stmt->bind_param("isi", $student_id, $current_academic_year, $selected_term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $exams = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $total_exams = count($exams);
        $upcoming_exams = count(array_filter($exams, fn($e) => $e['exam_date'] >= $current_date && $e['exam_date'] <= $upcoming_date));
        
        // Chart data: exams per subject
        $subject_counts = array_count_values(array_column($exams, 'subject_name'));
        $chart_labels = array_keys($subject_counts);
        $chart_series = [[
            'name' => 'Exams',
            'data' => array_values($subject_counts)
        ]];
    } catch (Exception $e) {
        error_log("Exams fetch error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exams | QPMS.com</title>
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
        .upcoming-row { background-color: #fff3cd !important; }
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
            <li class="nav-item"><a href="/parent/exams.php" class="nav-link active"><i class="fas fa-book"></i> Exams</a></li>
            <li class="nav-item"><a href="/parent/library.php" class="nav-link"><i class="fas fa-book-open"></i> Library</a></li>
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
            <h1 class="h4 mb-0 fw-bold">Exam Schedules</h1>
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
    <div class="alert alert-info text-center">Please select a student to view exam schedules.</div>
    <?php else: ?>
    <?php $selected_student = array_filter($students, fn($s) => $s['id'] == $selected_student_id)[0] ?? null; ?>
    
    <!-- Student Header -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-1"><?= htmlspecialchars($selected_student['name'] ?? 'Student') ?></h5>
            <p class="text-muted mb-0">Class: <?= htmlspecialchars($selected_student['class_name'] ?? 'N/A') ?> | Term: <?= htmlspecialchars($selected_term['term_name']) ?> <?= $current_academic_year ?></p>
        </div>
    </div>

    <!-- Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-book"></i>
                <h6 class="mb-0"><?= $total_exams ?></h6>
                <small class="text-muted">Total</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-clock"></i>
                <h6 class="mb-0"><?= $upcoming_exams ?></h6>
                <small class="text-muted">Upcoming (7 days)</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-chart-line"></i>
                <h6 class="mb-0"><?= count(array_unique(array_column($exams, 'subject_name'))) ?></h6>
                <small class="text-muted">Subjects</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <i class="fas fa-calendar-alt"></i>
                <h6 class="mb-0"><?= $selected_term['term_name'] ?></h6>
                <small class="text-muted">Current Term</small>
            </div>
        </div>
    </div>

    <!-- Term Selection Form -->
    <form method="POST" class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Select Term</label>
                    <select name="term_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= $term['id'] ?>" <?= $selected_term_id == $term['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name'] . ' ' . $term['year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        </div>
    </form>

    <!-- Exams Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <i class="fas fa-list me-2"></i> Exam Details
        </div>
        <div class="card-body p-0">
            <?php if (empty($exams)): ?>
                <div class="p-4 text-center text-muted">No exams found for this term.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Date & Time</th>
                            <th>Location</th>
                            <th>Instructions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exams as $exam): 
                            $is_upcoming = $exam['exam_date'] >= $current_date && $exam['exam_date'] <= $upcoming_date;
                            $date_time = $exam['exam_date'] ? date('M d, Y', strtotime($exam['exam_date'])) : 'Not Set';
                            if ($exam['start_time'] && $exam['end_time']) {
                                $date_time .= ' (' . date('H:i', strtotime($exam['start_time'])) . ' - ' . date('H:i', strtotime($exam['end_time'])) . ')';
                            }
                        ?>
                        <tr class="<?= $is_upcoming ? 'upcoming-row' : '' ?>">
                            <td><strong><?= htmlspecialchars($exam['subject_name']) ?></strong></td>
                            <td><?= $date_time ?></td>
                            <td><?= htmlspecialchars($exam['room'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($exam['instructions'] ?? 'N/A') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Exams Chart -->
    <?php if (!empty($chart_labels)): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-bar me-2"></i> Exams by Subject</div>
        <div class="card-body">
            <div id="examChart" class="chart-container"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js"></script>
<script>
    // Sidebar Toggle
    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        document.querySelector('.sidebar').classList.toggle('show');
    });

    // Exams Chart
    <?php if (!empty($chart_labels)): ?>
    new ApexCharts(document.querySelector("#examChart"), {
        chart: { type: 'bar', height: 300, toolbar: { show: false } },
        series: <?= json_encode($chart_series) ?>,
        xaxis: { categories: <?= json_encode($chart_labels) ?>, labels: { rotate: -45 } },
        colors: ['#ff6b00'],
        plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
        yaxis: { min: 0, labels: { formatter: val => val.toFixed(0) } },
        tooltip: { y: { formatter: val => val + ' exam(s)' } },
        title: { text: 'Exams Distribution by Subject' }
    }).render();
    <?php endif; ?>
</script>
</body>
</html>