<?php
// dashboard.php - Parent Dashboard for QPMCEDU.com
// Updated: Automatically detects current academic year and term based on current date

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// === Helper function to get current academic term and year based on date ===
function getCurrentTermAndYear($conn) {
    $current_date = date('Y-m-d');
    
    // Query to find which term the current date falls into
    $stmt = $conn->prepare("
        SELECT year, term_name, start_date, end_date, id as term_id
        FROM academic_calendar 
        WHERE ? BETWEEN start_date AND end_date
        ORDER BY start_date DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_term = $result->fetch_assoc();
    $stmt->close();
    
    // If no active term found (e.g., between terms), get the next upcoming term
    if (!$current_term) {
        $stmt = $conn->prepare("
            SELECT year, term_name, start_date, end_date, id as term_id
            FROM academic_calendar 
            WHERE start_date > ?
            ORDER BY start_date ASC
            LIMIT 1
        ");
        $stmt->bind_param("s", $current_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $current_term = $result->fetch_assoc();
        $stmt->close();
    }
    
    // If still no term found, fallback to the latest term
    if (!$current_term) {
        $stmt = $conn->prepare("
            SELECT year, term_name, start_date, end_date, id as term_id
            FROM academic_calendar 
            ORDER BY start_date DESC
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $current_term = $result->fetch_assoc();
        $stmt->close();
    }
    
    return $current_term;
}

// === Helper function to get current academic year from academic_calendar ===
function getCurrentAcademicYear($conn) {
    $current_term = getCurrentTermAndYear($conn);
    return $current_term ? $current_term['year'] : date('Y') . '/' . (date('Y') + 1);
}

// === Helper function to get current term ID ===
function getCurrentTermId($conn) {
    $current_term = getCurrentTermAndYear($conn);
    return $current_term ? $current_term['term_id'] : null;
}

// === generateInsights (DB-aligned: attendance is INT in student_reports) ===
function generateInsights($conn, $student_id, $metrics, $current_term_id) {
    $insights = [];
    // student_reports.attendance is INT; use created_at for ordering (confirmed in schema)
    $stmt = $conn->prepare(
        "SELECT attendance FROM student_reports WHERE user_id = ? AND term_id = ? ORDER BY created_at DESC LIMIT 1 OFFSET 1"
    );
    $stmt->bind_param("ii", $student_id, $current_term_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $prev = isset($row['attendance']) ? (int)$row['attendance'] : null;
    $stmt->close();

    if ($prev !== null && (int)$metrics['attendance'] < $prev - 5) {
        $drop = $prev - (int)$metrics['attendance'];
        $insights[] = [
            'icon'  => 'fa-exclamation-triangle',
            'title' => 'Attendance Drop',
            'desc'  => "Down {$drop}% from last report.",
        ];
    }
    if (!empty($metrics['debts'])) {
        $total = array_sum(array_column($metrics['debts'], 'amount_due'));
        $count = count($metrics['debts']);
        $insights[] = [
            'icon'  => 'fa-money-bill-wave',
            'title' => 'Outstanding Fees',
            'desc'  => "{$count} fee(s) due totalling GH₵ " . number_format($total, 2),
        ];
    }
    return $insights;
}

// === Security & Session Validation ===
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    header("Location: /parent/login_error.php?error=unauthorized");
    exit();
}

// === Fetch Parent Name (users table confirmed) ===
$stmt = $conn->prepare(
    "SELECT name FROM users WHERE id = ? AND role = 'parent' AND status = 'active'"
);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$parent) {
    header("Location: /parent/login_error.php?error=invalid_user");
    exit();
}

// === Get Current Academic Term and Year dynamically ===
$current_term_data = getCurrentTermAndYear($conn);
$current_academic_year = $current_term_data['year'] ?? date('Y') . '/' . (date('Y') + 1);
$current_term_id = $current_term_data['term_id'] ?? null;
$current_term_name = $current_term_data['term_name'] ?? 'Current Term';
$term_start_date = $current_term_data['start_date'] ?? null;
$term_end_date = $current_term_data['end_date'] ?? null;

// Log the current term for debugging
logActivity($conn, $_SESSION['user_id'], "Accessed parent dashboard", "Current Term: {$current_term_name} ({$current_academic_year})");

// === Fetch Mapped Students for current academic year ===
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

// === Smart Greeting with term context ===
$full_name  = trim($parent['name']);
$words      = explode(' ', $full_name);
$first_name = $words[0] ?? 'Parent';
$title      = '';
if (count($words) >= 2) {
    $possible_title = strtoupper($words[0]);
    if (in_array($possible_title, ['MR', 'MRS', 'MS', 'DR', 'PROF'])) {
        $title      = $words[0];
        $first_name = $words[1];
    }
}
$parent_greeting = $title ? "$title $first_name" : "Dear $first_name";

// === Fetch Student Metrics (using current term and year) ===
function fetchStudentMetrics($conn, $student_id, $current_academic_year, $current_term_id) {
    $data = [
        'attendance'       => 0,
        'term'             => 'N/A',
        'grades'           => [],
        'badges'           => [],
        'debts'            => [],
        'payments'         => [],
        'behavior'         => [],
        'assignments'      => [],
        'exams'            => [],
        'library'          => [],
        'progress_comments'=> [],
    ];

    try {
        // --- Attendance + Term - Now filtered by current term ID ---
        $stmt = $conn->prepare("
            SELECT sr.attendance, ac.term_name
            FROM student_reports sr
            JOIN academic_calendar ac ON sr.term_id = ac.id
            WHERE sr.user_id = ? AND sr.term_id = ?
            ORDER BY sr.created_at DESC
            LIMIT 1
        ");
        $stmt->bind_param("ii", $student_id, $current_term_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $data['attendance'] = (int)($row['attendance'] ?? 0);
            $data['term']       = $row['term_name'] ?? 'N/A';
        }
        $stmt->close();

        // --- Recent Grades from academic_progress_entries (filtered by current term) ---
        $stmt = $conn->prepare("
            SELECT s.subject_name,
                   ape.total_scaled AS score,
                   ac.term_name
            FROM academic_progress_entries ape
            JOIN subjects s ON ape.subject_id = s.id
            JOIN academic_calendar ac ON ape.term_id = ac.id
            WHERE ape.student_id = ?
              AND ape.status = 'submitted'
              AND ape.academic_year = ?
              AND ape.term_id = ?
            ORDER BY ape.updated_at DESC
            LIMIT 6
        ");
        $stmt->bind_param("isi", $student_id, $current_academic_year, $current_term_id);
        $stmt->execute();
        $data['grades'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // --- Recent Badges ---
        $stmt = $conn->prepare("
            SELECT b.name, b.description, sp.created_at
            FROM student_progress sp
            JOIN badges b ON sp.badge_id = b.id
            WHERE sp.user_id = ?
              AND sp.badge_id IS NOT NULL
            ORDER BY sp.created_at DESC
            LIMIT 3
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $data['badges'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // --- Outstanding Debts for current academic year ---
        try {
            $stmt = $conn->prepare("
                SELECT d.amount_due, f.fee_type, f.due_date
                FROM debts d
                JOIN fees f ON d.fee_id = f.id
                WHERE d.user_id = ?
                  AND d.academic_year = ?
                ORDER BY f.due_date ASC
                LIMIT 5
            ");
            $stmt->bind_param("is", $student_id, $current_academic_year);
            $stmt->execute();
            $data['debts'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Exception $e) {
            error_log("Debts fetch error: " . $e->getMessage());
        }

        // --- Recent Payments for current academic year ---
        try {
            $stmt = $conn->prepare("
                SELECT p.amount_paid, p.payment_date, f.fee_type
                FROM payments p
                JOIN fees f ON p.fee_id = f.id
                WHERE p.user_id = ?
                  AND f.academic_year = ?
                ORDER BY p.payment_date DESC
                LIMIT 3
            ");
            $stmt->bind_param("is", $student_id, $current_academic_year);
            $stmt->execute();
            $data['payments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Exception $e) {
            error_log("Payments fetch error: " . $e->getMessage());
        }

        // --- Recent Behavior Comments (filtered by current term if term field exists) ---
        $stmt = $conn->prepare("
            SELECT bc.comment, bc.term, bc.created_at, u.name AS teacher_name
            FROM behavior_comments bc
            JOIN users u ON bc.created_by = u.id
            WHERE bc.user_id = ?
              AND (bc.term = ? OR bc.term IS NULL)
            ORDER BY bc.created_at DESC
            LIMIT 3
        ");
        $stmt->bind_param("is", $student_id, $data['term']);
        $stmt->execute();
        $data['behavior'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // --- Upcoming Assignments ---
        $stmt = $conn->prepare("
            SELECT a.title, a.due_date, s.subject_name
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            WHERE a.class_id IN (
                SELECT class_id FROM student_classes WHERE user_id = ?
            )
              AND a.due_date >= CURDATE()
              AND a.status = 'published'
            ORDER BY a.due_date ASC
            LIMIT 3
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $data['assignments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // --- Upcoming Exams ---
        $stmt = $conn->prepare("
            SELECT es.exam_date, es.start_time, s.subject_name, es.room
            FROM exam_schedules es
            JOIN subjects s ON es.subject_id = s.id
            WHERE es.class_id IN (
                SELECT class_id FROM student_classes WHERE user_id = ?
            )
              AND es.exam_date >= CURDATE()
            ORDER BY es.exam_date ASC
            LIMIT 3
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $data['exams'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // --- Library Loans ---
        $stmt = $conn->prepare("
            SELECT lb.title, ll.due_date, ll.status,
                   COALESCE(lf.amount, 0) AS fine
            FROM library_loans ll
            JOIN library_books lb ON ll.book_id = lb.id
            LEFT JOIN library_fines lf ON ll.id = lf.loan_id AND lf.status = 'pending'
            WHERE ll.user_id = ?
              AND ll.status IN ('active', 'overdue')
            ORDER BY ll.due_date ASC
            LIMIT 2
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $data['library'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // --- Progress Comments from academic_progress_entries (filtered by current term) ---
        $stmt = $conn->prepare("
            SELECT ape.comments, ape.period_start_date, ape.updated_at, u.name AS teacher_name
            FROM academic_progress_entries ape
            JOIN users u ON ape.teacher_id = u.id
            WHERE ape.student_id = ?
              AND ape.comments IS NOT NULL
              AND ape.comments != ''
              AND ape.academic_year = ?
              AND ape.term_id = ?
            ORDER BY ape.updated_at DESC
            LIMIT 3
        ");
        $stmt->bind_param("isi", $student_id, $current_academic_year, $current_term_id);
        $stmt->execute();
        $data['progress_comments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

    } catch (Exception $e) {
        error_log("Metrics fetch error: " . $e->getMessage());
    }

    return $data;
}

$student_metrics = $selected_student_id
    ? fetchStudentMetrics($conn, $selected_student_id, $current_academic_year, $current_term_id)
    : null;

// === Generate Insights ===
$insights = $selected_student_id && $current_term_id
    ? generateInsights($conn, $selected_student_id, $student_metrics, $current_term_id)
    : [];

// === Upcoming Events ===
$events = [];
try {
    // School events in next 30 days
    $stmt = $conn->prepare("
        SELECT title, description, event_date, event_type AS type,
               COALESCE(location, 'Campus') AS location, start_time
        FROM school_events
        WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY event_date ASC
        LIMIT 5
    ");
    $stmt->execute();
    $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Supplement with upcoming academic calendar term dates if events list is short
    if (count($events) < 3) {
        $stmt = $conn->prepare("
            SELECT term_name AS title,
                   'Term date' AS description,
                   start_date AS event_date,
                   'academic' AS type,
                   'School' AS location,
                   NULL AS start_time
            FROM academic_calendar
            WHERE start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
               OR end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
            ORDER BY start_date ASC
            LIMIT 3
        ");
        $stmt->execute();
        $cal_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $events = array_slice(array_merge($events, $cal_events), 0, 5);
    }
} catch (Exception $e) {
    error_log("Events fetch error: " . $e->getMessage());
}

// === Event type label helper ===
function eventTypeLabel(string $type): string {
    return match(strtolower($type)) {
        'sports'      => 'Sports',
        'cultural'    => 'Cultural',
        'academic'    => 'Academic',
        'holiday'     => 'Holiday',
        'club'        => 'Club',
        default       => ucfirst($type),
    };
}

// Calculate days remaining in current term
$days_remaining = null;
if ($term_end_date) {
    $today = new DateTime();
    $end = new DateTime($term_end_date);
    if ($today <= $end) {
        $days_remaining = $today->diff($end)->days;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parent Dashboard | QPMCEDU.com</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --qpmc-orange: #ff6b00;
            --qpmc-orange-dark: #e65c00;
            --qpmc-white: #ffffff;
            --qpmc-gray: #212529;
        }
        body { background: #f8f9fa; font-family: 'Inter', sans-serif; color: var(--qpmc-gray); }
        .card { border: none; border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; }
        .card-header { background: var(--qpmc-orange); color: white; font-weight: 600; padding: 1rem 1.5rem; }
        .btn-orange { background: var(--qpmc-orange); border: none; color: white; font-weight: 500; border-radius: 0.75rem; }
        .btn-orange:hover { background: var(--qpmc-orange-dark); color: white; }
        .stat-card { background: var(--qpmc-white); border-radius: 1rem; padding: 1.5rem; text-align: center; transition: transform 0.2s; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card i { font-size: 2rem; color: var(--qpmc-orange); margin-bottom: 0.75rem; }
        .chart-container { height: 260px; }
        .badge-orange { background: var(--qpmc-orange); color: white; }
        .list-group-item { border: none; padding: 0.75rem 0; }
        .text-orange { color: var(--qpmc-orange); }
        .insight-card { background: linear-gradient(135deg, #fff4e6, #ffe8cc); border-left: 4px solid var(--qpmc-orange); }
        .insight-icon { color: var(--qpmc-orange); font-size: 1.5rem; }
        .term-badge { background: var(--qpmc-orange); color: white; padding: 0.25rem 1rem; border-radius: 2rem; font-size: 0.85rem; }
        /* Dark mode overrides */
        [data-bs-theme="dark"] .stat-card { background: #2a2a3d; }
        [data-bs-theme="dark"] body { background: #151521; }
        [data-bs-theme="dark"] .insight-card { background: linear-gradient(135deg, #2a1a00, #3d2800); }
    </style>
</head>
<body>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php'; ?>

<!-- Dashboard content (inside #qpmc-content opened by navbar include) -->
<div class="container-fluid px-4 py-4">

    <!-- Welcome + Term Info + Student Switcher row -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="h4 mb-0 fw-bold">Welcome, <?= htmlspecialchars($parent_greeting) ?>!</h1>
            <div class="mt-2">
                <span class="term-badge">
                    <i class="fas fa-calendar-alt me-1"></i> <?= htmlspecialchars($current_term_name) ?> - <?= htmlspecialchars($current_academic_year) ?>
                </span>
                <?php if ($days_remaining !== null && $days_remaining >= 0): ?>
                <span class="badge bg-secondary ms-2">
                    <i class="fas fa-hourglass-half me-1"></i> <?= $days_remaining ?> days remaining
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (!empty($students)): ?>
            <select class="form-select rounded-pill" style="max-width:260px;"
                    id="studentSwitcher" onchange="location = '?student=' + this.value">
                <?php foreach ($students as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $selected_student_id == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['class_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button class="btn btn-outline-secondary rounded-pill" id="darkModeToggle" title="Toggle dark mode">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </div>

    <?php if ($selected_student_id && $student_metrics):
        $idx = array_search($selected_student_id, array_column($students, 'id'));
        $selected_student_name = $students[$idx]['name'] ?? 'Student';
        $total_debt = array_sum(array_column($student_metrics['debts'], 'amount_due'));
        $avg_grade  = !empty($student_metrics['grades'])
            ? round(array_sum(array_column($student_metrics['grades'], 'score')) / count($student_metrics['grades']), 1)
            : 0;
    ?>

    <!-- Student Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card h-100">
                <i class="fas fa-user-graduate"></i>
                <h6 class="mb-0 small"><?= htmlspecialchars($selected_student_name) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($student_metrics['term']) ?></small>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card h-100">
                <i class="fas fa-calendar-check"></i>
                <h6 class="mb-0"><?= $student_metrics['attendance'] ?>%</h6>
                <small class="text-muted">Attendance</small>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card h-100">
                <i class="fas fa-coins"></i>
                <h6 class="mb-0 <?= $total_debt > 0 ? 'text-danger' : 'text-success' ?>">
                    GH₵ <?= number_format($total_debt, 2) ?>
                </h6>
                <small class="text-muted">Amount Due</small>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card h-100">
                <i class="fas fa-trophy"></i>
                <h6 class="mb-0"><?= count($student_metrics['badges']) ?></h6>
                <small class="text-muted">Badges</small>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card h-100">
                <i class="fas fa-chart-line"></i>
                <h6 class="mb-0"><?= $avg_grade ?></h6>
                <small class="text-muted">Avg Score</small>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card h-100">
                <i class="fas fa-book-open"></i>
                <h6 class="mb-0"><?= count($student_metrics['library']) ?></h6>
                <small class="text-muted">Active Loans</small>
            </div>
        </div>
    </div>

    <!-- Insight Cards -->
    <?php if (!empty($insights)): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($insights as $insight): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card insight-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="fas <?= htmlspecialchars($insight['icon']) ?> insight-icon me-3 mt-1"></i>
                        <div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($insight['title']) ?></h6>
                            <p class="mb-0 text-muted small"><?= htmlspecialchars($insight['desc']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Dashboard Grid -->
    <div class="row g-4">

        <!-- Fees & Payments -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-money-bill-wave me-2"></i> Fees &amp; Payments</div>
                <div class="card-body">
                    <?php if (!empty($student_metrics['debts'])): ?>
                        <h6 class="text-danger fw-bold mb-2"><i class="fas fa-exclamation-circle me-1"></i>Outstanding</h6>
                        <ul class="list-group list-group-flush mb-3">
                            <?php foreach ($student_metrics['debts'] as $d): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($d['fee_type'] ?? 'Fee') ?></span>
                                    <div class="text-end">
                                        <strong class="text-danger">GH₵ <?= number_format((float)$d['amount_due'], 2) ?></strong>
                                        <?php if (!empty($d['due_date'])): ?>
                                        <br><small class="text-muted">Due: <?= date('M d, Y', strtotime($d['due_date'])) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/parent/fees.php?student=<?= (int)$selected_student_id ?>" class="btn btn-orange w-100">
                            <i class="fas fa-credit-card me-2"></i>Pay Now
                        </a>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                            <p class="text-success fw-bold mb-0">No outstanding fees!</p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($student_metrics['payments'])): ?>
                        <hr class="my-3">
                        <h6 class="fw-bold mb-2"><i class="fas fa-receipt me-1 text-orange"></i>Recent Payments</h6>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($student_metrics['payments'] as $p): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="small"><?= htmlspecialchars($p['fee_type'] ?? 'Payment') ?></span>
                                    <span class="text-success small fw-semibold">
                                        GH₵ <?= number_format((float)$p['amount_paid'], 2) ?>
                                        <br><span class="text-muted fw-normal"><?= date('M d, Y', strtotime($p['payment_date'])) ?></span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Behaviour & Progress Comments -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-comment-dots me-2"></i> Recent Comments</div>
                <div class="card-body">
                    <?php
                    // Merge behavior_comments and progress_comments; sort by date descending
                    $all_comments = array_merge(
                        $student_metrics['behavior'],
                        $student_metrics['progress_comments']
                    );
                    usort($all_comments, function($a, $b) {
                        $da = strtotime($a['created_at'] ?? $a['updated_at'] ?? $a['period_start_date'] ?? '1970-01-01');
                        $db = strtotime($b['created_at'] ?? $b['updated_at'] ?? $b['period_start_date'] ?? '1970-01-01');
                        return $db <=> $da;
                    });
                    $displayed = array_slice($all_comments, 0, 4);
                    ?>
                    <?php if (!empty($displayed)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($displayed as $comment):
                            $comment_text = $comment['comment'] ?? $comment['comments'] ?? '';
                            $term_label   = $comment['term'] ?? '';
                            $date_raw     = $comment['created_at'] ?? $comment['updated_at'] ?? $comment['period_start_date'] ?? null;
                        ?>
                            <li class="list-group-item border-bottom pb-3">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <span class="fw-semibold small"><?= htmlspecialchars($comment['teacher_name'] ?? 'Teacher') ?></span>
                                    <?php if ($term_label): ?>
                                    <span class="badge bg-light text-dark border small"><?= htmlspecialchars($term_label) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="mb-1 small text-wrap"><?= htmlspecialchars(mb_strimwidth($comment_text, 0, 160, '…')) ?></p>
                                <?php if ($date_raw): ?>
                                <small class="text-muted"><?= date('M d, Y', strtotime($date_raw)) ?></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/parent/behavior_comments.php?student=<?= (int)$selected_student_id ?>" class="btn btn-orange btn-sm mt-3 w-100">
                        View All Comments
                    </a>
                    <?php else: ?>
                        <p class="text-muted text-center py-4">No recent comments.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Grades Chart -->
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-chart-bar me-2"></i> Academic Progress — <?= htmlspecialchars($current_term_name) ?> <?= htmlspecialchars($current_academic_year) ?></span>
                    <?php if (!empty($student_metrics['grades'])): ?>
                    <a href="/parent/reports.php?student=<?= (int)$selected_student_id ?>&term=<?= $current_term_id ?>" class="btn btn-sm btn-light fw-semibold">Full Report</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($student_metrics['grades'])): ?>
                        <div id="gradesChart" class="chart-container"></div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-chart-bar fa-3x mb-3 opacity-25"></i>
                            <p>No grade data available yet for <?= htmlspecialchars($current_term_name) ?>.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Events -->
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-calendar-alt me-2"></i> Upcoming Events</div>
                <div class="card-body p-0">
                    <?php if (!empty($events)): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($events as $e):
                                $edate = !empty($e['event_date']) ? date('M d', strtotime($e['event_date'])) : '';
                            ?>
                                <a href="/parent/activities.php?student=<?= (int)$selected_student_id ?>"
                                   class="list-group-item list-group-item-action px-4 py-3 border-0">
                                    <div class="d-flex w-100 justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1 fw-semibold small"><?= htmlspecialchars($e['title']) ?></h6>
                                            <small class="text-muted">
                                                <?= eventTypeLabel($e['type'] ?? '') ?>
                                                <?php if (!empty($e['location'])): ?> &bull; <?= htmlspecialchars($e['location']) ?><?php endif; ?>
                                            </small>
                                        </div>
                                        <small class="text-orange fw-bold ms-2 text-nowrap"><?= $edate ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="p-4 text-muted text-center">No upcoming events.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row g-2">
                <div class="col-6">
                    <a href="/parent/assignments.php?student=<?= (int)$selected_student_id ?>" class="text-decoration-none">
                        <div class="stat-card text-center p-3">
                            <i class="fas fa-tasks fa-2x text-orange mb-2"></i>
                            <h6 class="fw-bold small mb-0">Assignments</h6>
                            <small class="text-muted"><?= count($student_metrics['assignments']) ?> Due</small>
                        </div>
                    </a>
                </div>
                <div class="col-6">
                    <a href="/parent/exams.php?student=<?= (int)$selected_student_id ?>" class="text-decoration-none">
                        <div class="stat-card text-center p-3">
                            <i class="fas fa-book fa-2x text-orange mb-2"></i>
                            <h6 class="fw-bold small mb-0">Exams</h6>
                            <small class="text-muted"><?= count($student_metrics['exams']) ?> Soon</small>
                        </div>
                    </a>
                </div>
                <div class="col-6">
                    <a href="/parent/library.php?student=<?= (int)$selected_student_id ?>" class="text-decoration-none">
                        <div class="stat-card text-center p-3">
                            <i class="fas fa-book-open fa-2x text-orange mb-2"></i>
                            <h6 class="fw-bold small mb-0">Library</h6>
                            <small class="text-muted"><?= count($student_metrics['library']) ?> Loan<?= count($student_metrics['library']) !== 1 ? 's' : '' ?></small>
                        </div>
                    </a>
                </div>
                <div class="col-6">
                    <a href="/parent/wellness.php?student=<?= (int)$selected_student_id ?>" class="text-decoration-none">
                        <div class="stat-card text-center p-3">
                            <i class="fas fa-heart fa-2x text-orange mb-2"></i>
                            <h6 class="fw-bold small mb-0">Wellness</h6>
                            <small class="text-muted">Check-in</small>
                        </div>
                    </a>
                </div>
            </div>
        </div>

    </div><!-- /row -->

    <?php else: ?>
    <div class="alert alert-info text-center mt-4">
        <i class="fas fa-info-circle me-2"></i>
        <?php if (empty($students)): ?>
            No students are linked to your account for <?= htmlspecialchars($current_academic_year) ?>. Please contact the school office.
        <?php else: ?>
            Please select a student above to view dashboard details.
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- end .container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js"></script>
<script>
    // Dark Mode Toggle
    (function () {
        const btn  = document.getElementById('darkModeToggle');
        const html = document.documentElement;
        function applyTheme(theme) {
            html.setAttribute('data-bs-theme', theme);
            if (btn) btn.innerHTML = theme === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        }
        applyTheme(localStorage.getItem('theme') || 'light');
        btn?.addEventListener('click', () => {
            const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            localStorage.setItem('theme', next);
        });
    })();

    // Grades Chart
    <?php if ($student_metrics && !empty($student_metrics['grades'])): ?>
    (function () {
        // total_scaled is the composite score; display as-is (already scaled)
        const scores   = <?= json_encode(array_map(fn($g) => (float)$g['score'], $student_metrics['grades'])) ?>;
        const subjects = <?= json_encode(array_column($student_metrics['grades'], 'subject_name')) ?>;
        const isDark   = document.documentElement.getAttribute('data-bs-theme') === 'dark';

        const chart = new ApexCharts(document.querySelector('#gradesChart'), {
            chart:   { type: 'bar', height: 260, toolbar: { show: false } },
            series:  [{ name: 'Score', data: scores }],
            xaxis:   { categories: subjects, labels: { rotate: -35, style: { fontSize: '11px' } } },
            yaxis:   { min: 0, labels: { formatter: v => v.toFixed(1) } },
            colors:  ['#ff6b00'],
            plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
            dataLabels: { enabled: false },
            tooltip: { y: { formatter: v => v.toFixed(2) } },
            grid:    { borderColor: isDark ? '#333' : '#f1f1f1' },
            theme:   { mode: isDark ? 'dark' : 'light' },
        });
        chart.render();
    })();
    <?php endif; ?>
</script>
</body>
</html>