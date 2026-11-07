<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a student.";
    header("Location: /login.php");
    exit();
}

ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
error_reporting(E_ALL);

// ── DB ─────────────────────────────────────────────────────────────────────────
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];

// ── Validate student ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, name, profile_picture FROM users WHERE id = ? AND role = 'student'");
$student_name = 'Student';
$profile_picture = '/assets/default_avatar.png';
if ($stmt) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid student_id in session: $student_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $student_name = htmlspecialchars($user['name'] ?? 'Student');
    $profile_picture = !empty($user['profile_picture']) ? htmlspecialchars($user['profile_picture']) : '/assets/default_avatar.png';
    $stmt->close();
}

// ── Logging helper ───────────────────────────────────────────────────────────
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
} else {
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
}
logActivity($conn, $student_id, "Accessed View Grades", "Student accessed redesigned grade viewer");

// ── Academic year ────────────────────────────────────────────────────────────
$current_month = (int)date('m');
$current_year  = (int)date('Y');
$current_academic_year = $current_month >= 9
    ? "$current_year/" . ($current_year + 1)
    : ($current_year - 1) . "/$current_year";

// ── Filters ────────────────────────────────────────────────────────────────────
$class_id   = filter_input(INPUT_GET, 'class_id',   FILTER_VALIDATE_INT) ?: 0;
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: 0;
$term_id    = filter_input(INPUT_GET, 'term_id',    FILTER_VALIDATE_INT) ?: 0;

// ── Fetch filter options ───────────────────────────────────────────────────────
$classes = [];
$cs = $conn->prepare(
    "SELECT DISTINCT c.id, c.class_name
     FROM academic_progress_entries ape
     JOIN classes c ON ape.class_id = c.id
     WHERE ape.student_id = ? AND ape.academic_year = ?
     ORDER BY c.class_name"
);
if ($cs) { $cs->bind_param("is", $student_id, $current_academic_year); $cs->execute(); $classes = $cs->get_result()->fetch_all(MYSQLI_ASSOC); $cs->close(); }

$subjects = [];
$ss = $conn->prepare(
    "SELECT DISTINCT s.id, s.subject_name
     FROM academic_progress_entries ape
     JOIN subjects s ON ape.subject_id = s.id
     WHERE ape.student_id = ?
     ORDER BY s.subject_name"
);
if ($ss) { $ss->bind_param("i", $student_id); $ss->execute(); $subjects = $ss->get_result()->fetch_all(MYSQLI_ASSOC); $ss->close(); }

$terms = [];
$ts = $conn->prepare(
    "SELECT DISTINCT ac.id, ac.term_name
     FROM academic_progress_entries ape
     JOIN academic_calendar ac ON ape.term_id = ac.id
     WHERE ape.student_id = ? AND ac.year = ?
     ORDER BY ac.start_date"
);
if ($ts) { $ts->bind_param("is", $student_id, $current_academic_year); $ts->execute(); $terms = $ts->get_result()->fetch_all(MYSQLI_ASSOC); $ts->close(); }

// ── Fetch entries ──────────────────────────────────────────────────────────────
$sql = "
    SELECT
        ape.*,
        c.class_name,
        s.subject_name,
        ac.term_name,
        u.name AS teacher_name
    FROM academic_progress_entries ape
    JOIN classes c ON ape.class_id = c.id
    JOIN subjects s ON ape.subject_id = s.id
    JOIN academic_calendar ac ON ape.term_id = ac.id
    JOIN users u ON ape.teacher_id = u.id
    WHERE ape.student_id = ?
      AND ape.academic_year = ?
      AND ape.status IN ('submitted','reviewed')
";
$params = [$student_id, $current_academic_year];
$types  = "is";

if ($class_id > 0)   { $sql .= " AND ape.class_id = ?";   $params[] = $class_id;   $types .= "i"; }
if ($subject_id > 0) { $sql .= " AND ape.subject_id = ?"; $params[] = $subject_id; $types .= "i"; }
if ($term_id > 0)    { $sql .= " AND ape.term_id = ?";    $params[] = $term_id;    $types .= "i"; }

$sql .= " ORDER BY ape.period_start_date DESC, s.subject_name ASC";

$entries = [];
$es = $conn->prepare($sql);
if ($es) {
    $es->bind_param($types, ...$params);
    $es->execute();
    $entries = $es->get_result()->fetch_all(MYSQLI_ASSOC);
    $es->close();
}

// ── Group by period ───────────────────────────────────────────────────────────
$periods = [];
foreach ($entries as $e) {
    $key = $e['period_start_date'] . '|' . $e['period_end_date'] . '|' . $e['entry_period'];
    if (!isset($periods[$key])) {
        $sd = new DateTime($e['period_start_date']);
        $ed = new DateTime($e['period_end_date']);
        $periods[$key] = [
            'start_date' => $e['period_start_date'],
            'end_date'   => $e['period_end_date'],
            'period_type'=> $e['entry_period'],
            'label'      => sprintf('%s – %s', $sd->format('M d'), $ed->format('M d, Y')),
            'subjects'   => []
        ];
    }
    $scaled = json_decode($e['scaled_scores'] ?? '{}', true);
    $periods[$key]['subjects'][] = [
        'subject_id'          => (int)$e['subject_id'],
        'subject_name'        => $e['subject_name'],
        'teacher_name'        => $e['teacher_name'],
        'class_name'          => $e['class_name'],
        'term_name'           => $e['term_name'],
        'class_practices_raw' => (float)$e['class_practices_raw'],
        'class_practices_max' => (float)$e['class_practices_max'],
        'assignments_raw'     => (float)$e['assignments_raw'],
        'assignments_max'     => (float)$e['assignments_max'],
        'cat_raw'             => (float)$e['cat_raw'],
        'cat_max'             => (float)$e['cat_max'],
        'group_project_raw'   => (float)$e['group_project_raw'],
        'group_project_max'   => (float)$e['group_project_max'],
        'total_scaled'        => (float)$e['total_scaled'],
        'scaled'              => $scaled,
        'comments'            => $e['comments'] ?? '',
        'status'              => $e['status'],
    ];
}

// Encode for JS
$periods_json = json_encode(array_values($periods), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Grades · QueensPa School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
:root {
  --ink:#0d1117;--ink-2:#374151;--ink-3:#6b7280;--ink-4:#9ca3af;
  --line:#e5e7eb;--line-2:#f3f4f6;
  --surface:#ffffff;--surface-2:#f9fafb;--surface-3:#f3f4f6;
  --teal:#0d9488;--teal-l:#ccfbf1;--teal-d:#0f766e;
  --amber:#d97706;--amber-l:#fef3c7;
  --red:#dc2626;--red-l:#fee2e2;
  --blue:#2563eb;--blue-l:#dbeafe;
  --emerald:#059669;--emerald-l:#d1fae5;
  --radius:12px;--radius-sm:8px;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
  --shadow-md:0 4px 12px rgba(0,0,0,.08),0 2px 4px rgba(0,0,0,.04);
  --shadow-lg:0 12px 32px rgba(0,0,0,.1),0 4px 8px rgba(0,0,0,.06);
  --font-body:'DM Sans',system-ui,sans-serif;
  --font-head:'DM Serif Display',Georgia,serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--font-body);background:var(--surface-2);color:var(--ink);-webkit-font-smoothing:antialiased;line-height:1.6;}
.hidden{display:none!important;}
.topbar{background:var(--surface);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:50;}
.topbar-inner{max-width:1280px;margin:0 auto;padding:0 1.25rem;display:flex;align-items:center;justify-content:space-between;height:60px;}
.topbar-brand{display:flex;align-items:center;gap:.75rem;}
.topbar-avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid var(--teal-l);flex-shrink:0;}
.topbar-title{font-family:var(--font-head);font-size:1.1rem;color:var(--ink);line-height:1.2;}
.topbar-sub{font-size:.72rem;color:var(--ink-3);}
.page{max-width:1280px;margin:0 auto;padding:1.5rem 1.25rem 3rem;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);}
.section-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:.5rem;}
.section-title{font-family:var(--font-head);font-size:1rem;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
.section-title i{color:var(--teal);font-size:.85rem;}
.filter-grid{display:grid;grid-template-columns:1fr;gap:.75rem;padding:1rem 1.25rem;}
@media(min-width:768px){.filter-grid{grid-template-columns:repeat(3,1fr) auto;}}
.field{width:100%;padding:.5rem .875rem;border:1.5px solid var(--line);border-radius:var(--radius-sm);background:var(--surface);color:var(--ink);font-family:var(--font-body);font-size:.82rem;transition:border-color .2s,box-shadow .2s;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .7rem center;padding-right:2rem;}
.field:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.12);}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:var(--radius-sm);font-family:var(--font-body);font-size:.8rem;font-weight:600;border:1.5px solid transparent;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap;}
.btn-teal{background:var(--teal);color:white;border-color:var(--teal);}
.btn-teal:hover{background:var(--teal-d);}
.btn-outline{background:var(--surface);color:var(--teal);border-color:var(--teal);}
.btn-outline:hover{background:var(--teal-l);}
.btn-ghost{background:transparent;color:var(--ink-2);border-color:var(--line);}
.btn-ghost:hover{background:var(--surface-3);}
.chips{display:flex;flex-wrap:wrap;gap:.5rem;padding:1rem 1.25rem;}
.chip{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .9rem;border:1.5px solid var(--line);border-radius:999px;font-size:.78rem;font-weight:500;cursor:pointer;transition:all .18s;background:var(--surface);color:var(--ink-2);white-space:nowrap;}
.chip:hover{border-color:var(--teal);color:var(--teal-d);background:var(--teal-l);}
.chip.active{background:var(--teal);border-color:var(--teal);color:white;}
.stats-row{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem;margin-bottom:1.25rem;}
@media(min-width:640px){.stats-row{grid-template-columns:repeat(4,1fr);}}
.stat-tile{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:1rem;text-align:center;transition:box-shadow .2s,transform .2s;}
.stat-tile:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.stat-num{font-family:var(--font-head);font-size:1.6rem;line-height:1;margin-bottom:.25rem;}
.stat-lbl{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);}
.c-teal{color:var(--teal-d);}.c-emerald{color:var(--emerald);}.c-amber{color:var(--amber);}.c-red{color:var(--red);}.c-blue{color:var(--blue);}
.subjects-grid{display:grid;grid-template-columns:repeat(1,1fr);gap:.875rem;margin-bottom:1.25rem;}
@media(min-width:640px){.subjects-grid{grid-template-columns:repeat(2,1fr);}}
@media(min-width:1024px){.subjects-grid{grid-template-columns:repeat(3,1fr);}}
.subject-card{background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--line);border-radius:var(--radius);padding:1rem 1.1rem;cursor:pointer;transition:all .2s;}
.subject-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.subject-card.has-data{border-left-color:var(--teal);}
.subject-card.no-data{border-left-color:var(--line);opacity:.72;}
.subj-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.75rem;}
.subj-name{font-weight:600;font-size:.88rem;color:var(--ink);}
.subj-teacher{font-size:.75rem;color:var(--ink-3);margin-top:.15rem;}
.subj-mid{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;}
.subj-score{font-family:var(--font-head);font-size:2rem;line-height:1;color:var(--teal-d);}
.subj-grade{margin-left:auto;}
.badge{display:inline-flex;align-items:center;padding:.2rem .65rem;border-radius:999px;font-size:.72rem;font-weight:600;letter-spacing:.02em;white-space:nowrap;}
.badge-ok{background:var(--emerald-l);color:var(--emerald);}
.badge-good{background:var(--teal-l);color:var(--teal-d);}
.badge-warn{background:var(--amber-l);color:var(--amber);}
.badge-err{background:var(--red-l);color:var(--red);}
.badge-none{background:var(--surface-3);color:var(--ink-3);}
.badge-info{background:var(--blue-l);color:var(--blue);}
.subj-footer{display:flex;align-items:center;justify-content:space-between;font-size:.72rem;color:var(--ink-3);margin-top:.5rem;}
.status-pill{font-size:.65rem;padding:.1rem .45rem;border-radius:999px;font-weight:600;}
.status-submitted{background:var(--emerald-l);color:var(--emerald);}
.status-reviewed{background:var(--blue-l);color:var(--blue);}
.bar{height:5px;background:var(--line);border-radius:3px;overflow:hidden;margin:.4rem 0;}
.bar-fill{height:100%;border-radius:3px;transition:width .5s ease;}
.detail-section{margin-bottom:1.25rem;}
.detail-toolbar{display:flex;align-items:center;justify-content:space-between;padding:.875rem 1.25rem;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:.5rem;}
.detail-toolbar-title{font-family:var(--font-head);font-size:.95rem;display:flex;align-items:center;gap:.5rem;}
.tabs{display:flex;gap:0;border-bottom:1px solid var(--line);padding:0 1.25rem;}
.tab{padding:.625rem 1rem;font-size:.8rem;font-weight:600;cursor:pointer;border-bottom:2.5px solid transparent;color:var(--ink-3);transition:color .18s,border-color .18s;margin-bottom:-1px;}
.tab.active{color:var(--teal-d);border-bottom-color:var(--teal);}
.tab:hover:not(.active){color:var(--ink-2);}
.tbl-wrap{overflow-x:auto;padding:1rem 1.25rem;}
.ptbl{width:100%;border-collapse:collapse;font-size:.85rem;}
.ptbl th{background:var(--surface-3);padding:.65rem .875rem;text-align:left;font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);border-bottom:1px solid var(--line);}
.ptbl td{padding:.65rem .875rem;border-bottom:1px solid var(--line-2);}
.ptbl tr:hover{background:var(--surface-2);}
.score-cell{font-family:var(--font-head);font-size:.9rem;}
.raw-cell{font-size:.78rem;color:var(--ink-3);}
.chart-wrap{padding:1rem 1.25rem;position:relative;height:280px;}
.empty{text-align:center;padding:3.5rem 1.5rem;color:var(--ink-3);}
.empty i{font-size:2.5rem;display:block;margin-bottom:.875rem;opacity:.35;}
.empty p{font-size:.875rem;}
.empty small{font-size:.75rem;display:block;margin-top:.3rem;}
#toast-stack{position:fixed;bottom:1.25rem;right:1.25rem;z-index:200;display:flex;flex-direction:column;gap:.5rem;}
.toast{display:flex;align-items:center;gap:.625rem;padding:.75rem 1rem;border-radius:var(--radius-sm);background:var(--ink);color:white;font-size:.82rem;font-weight:500;box-shadow:var(--shadow-lg);animation:slideIn .3s ease;}
.toast.ok{background:var(--emerald);}.toast.warn{background:var(--amber);}.toast.err{background:var(--red);}
@keyframes slideIn{from{transform:translateX(110%);opacity:0;}to{transform:translateX(0);opacity:1;}}
@keyframes slideOut{to{transform:translateX(110%);opacity:0;}}
@media(max-width:480px){.topbar-title{font-size:.95rem;}.page{padding:1rem .875rem 2rem;}.chip{font-size:.72rem;padding:.28rem .7rem;}}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <div class="topbar-brand">
      <img src="<?= $profile_picture ?>" alt="" class="topbar-avatar">
      <div>
        <div class="topbar-title">My Grades</div>
        <div class="topbar-sub"><?= $student_name ?> · <?= htmlspecialchars($current_academic_year) ?></div>
      </div>
    </div>
    <a href="/student/dashboard.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Dashboard</a>
  </div>
</div>

<div class="page">

  <!-- Filters -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="section-header">
      <div class="section-title"><i class="fas fa-filter"></i> Filters</div>
    </div>
    <form class="filter-grid" method="GET" action="">
      <div>
        <label style="display:block;font-size:.75rem;font-weight:600;color:var(--ink-3);margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em;">Class</label>
        <select name="class_id" class="field">
          <option value="0">All Classes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="display:block;font-size:.75rem;font-weight:600;color:var(--ink-3);margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em;">Subject</label>
        <select name="subject_id" class="field">
          <option value="0">All Subjects</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $subject_id == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['subject_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="display:block;font-size:.75rem;font-weight:600;color:var(--ink-3);margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em;">Term</label>
        <select name="term_id" class="field">
          <option value="0">All Terms</option>
          <?php foreach ($terms as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $term_id == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['term_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;align-items:flex-end;gap:.5rem;">
        <button type="submit" class="btn btn-teal"><i class="fas fa-filter"></i> Apply</button>
        <a href="?" class="btn btn-outline"><i class="fas fa-undo"></i> Reset</a>
      </div>
    </form>
  </div>

  <!-- Period Chips -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="section-header">
      <div class="section-title"><i class="fas fa-calendar-alt"></i> Grading Periods</div>
      <span id="period-badge" style="font-size:.72rem;color:var(--ink-3);font-weight:500;"></span>
    </div>
    <div class="chips" id="period-chips">
      <div style="font-size:.82rem;color:var(--ink-3);">Loading periods…</div>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-row hidden" id="stats-row">
    <div class="stat-tile"><div class="stat-num c-blue" id="stat-subjects">—</div><div class="stat-lbl">Subjects</div></div>
    <div class="stat-tile"><div class="stat-num c-emerald" id="stat-avg">—</div><div class="stat-lbl">Period Avg</div></div>
    <div class="stat-tile"><div class="stat-num c-teal" id="stat-best" style="font-size:1.1rem;line-height:1.3;padding-top:.2rem;">—</div><div class="stat-lbl">Best Subject</div></div>
    <div class="stat-tile"><div class="stat-num c-amber" id="stat-entries">—</div><div class="stat-lbl">Entries</div></div>
  </div>

  <!-- Subject Cards -->
  <div class="subjects-grid" id="subjects-grid">
    <div style="grid-column:1/-1">
      <div class="empty">
        <i class="fas fa-arrow-up"></i>
        <p>Select a grading period above to view your scores</p>
      </div>
    </div>
  </div>

  <!-- Detail Panel -->
  <div class="card detail-section hidden" id="detail-section">
    <div class="detail-toolbar">
      <div class="detail-toolbar-title" id="detail-title">
        <i class="fas fa-chart-bar" style="color:var(--teal)"></i> Subject Breakdown
      </div>
      <div style="display:flex;gap:.5rem;">
        <button class="btn btn-ghost btn-sm" onclick="closeDetail()"><i class="fas fa-times"></i> Close</button>
      </div>
    </div>
    <div class="tabs">
      <div class="tab active" data-tab="table" onclick="switchTab(this,'table')">Score Breakdown</div>
      <div class="tab" data-tab="chart" onclick="switchTab(this,'chart')">Chart</div>
    </div>
    <div id="tab-table">
      <div class="tbl-wrap">
        <table class="ptbl">
          <thead>
            <tr>
              <th>Component</th>
              <th style="text-align:center">Raw / Max</th>
              <th style="text-align:center">Scaled (/25)</th>
              <th>Visual</th>
            </tr>
          </thead>
          <tbody id="detail-tbody"></tbody>
        </table>
        <div style="padding:1rem 1.25rem 0;border-top:1px solid var(--line);margin-top:.5rem;">
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;">
            <i class="fas fa-comment" style="color:var(--ink-3);font-size:.8rem;"></i>
            <span style="font-size:.78rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em;">Teacher Comment</span>
          </div>
          <p id="detail-comment" style="font-size:.85rem;color:var(--ink-2);line-height:1.6;background:var(--surface-2);padding:.75rem 1rem;border-radius:var(--radius-sm);"></p>
        </div>
        <div style="padding:.5rem 1.25rem 1rem;font-size:.75rem;color:var(--ink-4);text-align:right;">
          <span id="detail-meta"></span>
        </div>
      </div>
    </div>
    <div id="tab-chart" class="hidden">
      <div class="chart-wrap"><canvas id="detail-chart"></canvas></div>
    </div>
  </div>

</div>

<div id="toast-stack"></div>

<script>
(function(){
'use strict';

const periods = <?= $periods_json ?: '[]' ?>;
let currentPeriodIdx = null;
let chartInst = null;

const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];

function toast(msg, type='ok', ms=4000){
  const icons={ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-exclamation-circle'};
  const el=document.createElement('div');
  el.className='toast '+type;
  el.innerHTML=`<i class="fas ${icons[type]||'fa-info-circle'}"></i><span>${esc(msg)}</span>`;
  $('#toast-stack').appendChild(el);
  setTimeout(()=>{ el.style.animation='slideOut .35s ease forwards'; setTimeout(()=>el.remove(),380); },ms);
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function gradeLabel(v){
  if(v===null||v===undefined||isNaN(v)) return {txt:'—',cls:'badge-none'};
  if(v>=80) return {txt:'Excellent',cls:'badge-ok'};
  if(v>=65) return {txt:'Good',cls:'badge-good'};
  if(v>=50) return {txt:'Average',cls:'badge-warn'};
  return {txt:'Below Avg',cls:'badge-err'};
}
function barColor(v){
  if(v===null||v===undefined||isNaN(v)) return '#e5e7eb';
  if(v>=70) return '#059669';
  if(v>=50) return '#d97706';
  return '#dc2626';
}
function componentColor(idx){
  const colors=['#0d9488','#2563eb','#d97706','#7c3aed'];
  return colors[idx%colors.length];
}

function init(){
  const chipContainer = $('#period-chips');
  chipContainer.innerHTML = '';
  
  if(!periods.length){
    chipContainer.innerHTML = '<div style="font-size:.82rem;color:var(--ink-3);">No graded periods found for the selected filters.</div>';
    $('#stats-row').classList.add('hidden');
    $('#subjects-grid').innerHTML = `<div style="grid-column:1/-1"><div class="empty"><i class="fas fa-folder-open"></i><p>No grades available yet</p><small>Check back after your teachers submit scores</small></div></div>`;
    return;
  }

  periods.forEach((p, idx) => {
    const chip = document.createElement('button');
    chip.className = 'chip' + (idx === 0 ? ' active' : '');
    chip.innerHTML = `<i class="fas fa-calendar-day" style="font-size:.7rem;opacity:.6"></i> ${esc(p.label)}`;
    chip.onclick = () => selectPeriod(idx, chip);
    chipContainer.appendChild(chip);
  });

  // Select first period automatically
  const first = chipContainer.querySelector('.chip');
  if(first) first.click();
}

function selectPeriod(idx, chipEl){
  $$('.chip').forEach(c => c.classList.remove('active'));
  if(chipEl) chipEl.classList.add('active');
  currentPeriodIdx = idx;
  
  const p = periods[idx];
  $('#period-badge').textContent = `${p.subjects.length} subject${p.subjects.length!==1?'s':''}`;
  
  renderStats(p);
  renderSubjects(p);
  closeDetail();
}

function renderStats(p){
  $('#stats-row').classList.remove('hidden');
  const subjects = p.subjects || [];
  $('#stat-subjects').textContent = subjects.length;
  
  let total = 0, count = 0, best = '—', bestV = -1;
  subjects.forEach(s => {
    if(s.total_scaled !== null && !isNaN(s.total_scaled)){
      total += parseFloat(s.total_scaled); count++;
      if(parseFloat(s.total_scaled) > bestV){ bestV = parseFloat(s.total_scaled); best = s.subject_name; }
    }
  });
  
  $('#stat-avg').textContent = count ? (total/count).toFixed(1) + '%' : '—';
  const bestEl = $('#stat-best');
  bestEl.textContent = best.length > 14 ? best.substr(0,13)+'…' : best;
  bestEl.title = best;
  $('#stat-entries').textContent = subjects.length;
}

function renderSubjects(p){
  const grid = $('#subjects-grid');
  grid.innerHTML = '';
  
  if(!p.subjects || !p.subjects.length){
    grid.innerHTML = `<div style="grid-column:1/-1"><div class="empty"><i class="fas fa-inbox"></i><p>No subjects for this period</p></div></div>`;
    return;
  }

  p.subjects.forEach((subj, i) => {
    const hasData = subj.total_scaled !== null && !isNaN(subj.total_scaled);
    const score = hasData ? parseFloat(subj.total_scaled) : null;
    const g = gradeLabel(score);
    const statusCls = subj.status === 'submitted' ? 'status-submitted' : subj.status === 'reviewed' ? 'status-reviewed' : '';
    
    const card = document.createElement('div');
    card.className = 'subject-card ' + (hasData ? 'has-data' : 'no-data');
    card.style.animationDelay = (i * 0.05) + 's';
    
    // Mini bars for components
    const components = [
      {label:'Practices', raw:subj.class_practices_raw, max:subj.class_practices_max, scaled:subj.scaled?.class_practices||0},
      {label:'Assignments', raw:subj.assignments_raw, max:subj.assignments_max, scaled:subj.scaled?.assignments||0},
      {label:'CAT', raw:subj.cat_raw, max:subj.cat_max, scaled:subj.scaled?.cat||0},
      {label:'Project', raw:subj.group_project_raw, max:subj.group_project_max, scaled:subj.scaled?.group_project||0},
    ];
    
    let miniBars = '';
    components.forEach((c, ci) => {
      const pct = c.max > 0 ? Math.min((c.raw/c.max)*100, 100) : 0;
      const col = componentColor(ci);
      miniBars += `<div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.25rem;">
        <span style="font-size:.68rem;color:var(--ink-3);width:70px;flex-shrink:0;">${c.label}</span>
        <div class="bar" style="flex:1;height:4px;margin:0;"><div class="bar-fill" style="width:${pct}%;background:${col}"></div></div>
        <span style="font-size:.68rem;color:var(--ink-3);width:45px;text-align:right;">${c.max>0?(c.raw+'/'+c.max):'—'}</span>
      </div>`;
    });

    card.innerHTML = `
      <div class="subj-top">
        <div>
          <div class="subj-name">${esc(subj.subject_name)}</div>
          <div class="subj-teacher"><i class="fas fa-chalkboard-teacher" style="font-size:.7rem;margin-right:.2rem;opacity:.5"></i>${esc(subj.teacher_name)} · ${esc(subj.class_name)}</div>
        </div>
      </div>
      <div class="subj-mid">
        <div class="subj-score" style="color:${barColor(score)}">${score !== null ? score.toFixed(1) : '—'}</div>
        <div class="subj-grade"><span class="badge ${g.cls}">${g.txt}</span></div>
      </div>
      <div style="margin:.5rem 0;">${miniBars}</div>
      <div class="subj-footer">
        <span>${esc(subj.term_name)}</span>
        ${subj.status ? `<span class="status-pill ${statusCls}">${subj.status}</span>` : ''}
      </div>
      <div style="margin-top:.5rem;font-size:.75rem;color:var(--ink-3);font-style:italic;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        ${subj.comments ? '<i class="fas fa-comment" style="margin-right:.3rem;opacity:.5"></i>' + esc(subj.comments) : '<span style="opacity:.5">No comment</span>'}
      </div>
    `;
    
    card.onclick = () => showDetail(subj, p);
    grid.appendChild(card);
  });
}

function showDetail(subj, period){
  $('#detail-section').classList.remove('hidden');
  $('#detail-title').innerHTML = `<i class="fas fa-chart-bar" style="color:var(--teal)"></i> ${esc(subj.subject_name)} <small style="font-weight:400;font-size:.75rem;color:var(--ink-3);margin-left:.5rem">${period.label}</small>`;
  
  // Reset to table tab
  const tt = document.querySelector('.tab[data-tab="table"]');
  if(tt) switchTab(tt, 'table');
  
  renderDetailTable(subj);
  renderDetailChart(subj);
  
  $('#detail-section').scrollIntoView({behavior:'smooth', block:'start'});
}

function renderDetailTable(subj){
  const tbody = $('#detail-tbody');
  tbody.innerHTML = '';
  
  const components = [
    {name:'Class Practices', raw:subj.class_practices_raw, max:subj.class_practices_max, scaled:subj.scaled?.class_practices||0, idx:0},
    {name:'Assignments',     raw:subj.assignments_raw,     max:subj.assignments_max,     scaled:subj.scaled?.assignments||0,     idx:1},
    {name:'CAT',             raw:subj.cat_raw,             max:subj.cat_max,             scaled:subj.scaled?.cat||0,             idx:2},
    {name:'Group Project',   raw:subj.group_project_raw,   max:subj.group_project_max,   scaled:subj.scaled?.group_project||0,   idx:3},
  ];
  
  components.forEach(c => {
    const hasMax = c.max > 0;
    const pct = hasMax ? Math.min((c.raw/c.max)*100, 100) : 0;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="font-weight:500;">${c.name}</td>
      <td style="text-align:center" class="raw-cell">${hasMax ? c.raw + ' / ' + c.max : '—'}</td>
      <td style="text-align:center" class="score-cell">${hasMax ? parseFloat(c.scaled).toFixed(1) : '—'}</td>
      <td style="width:140px">
        ${hasMax ? `<div class="bar"><div class="bar-fill" style="width:${pct}%;background:${componentColor(c.idx)}"></div></div>` : '—'}
      </td>
    `;
    tbody.appendChild(tr);
  });
  
  // Total row
  const total = subj.total_scaled !== null ? parseFloat(subj.total_scaled) : null;
  const totalRow = document.createElement('tr');
  totalRow.style.background = 'var(--surface-2)';
  totalRow.style.fontWeight = '600';
  totalRow.innerHTML = `
    <td>Total Scaled Score</td>
    <td style="text-align:center">—</td>
    <td style="text-align:center" class="score-cell" style="color:${barColor(total)};font-size:1.1rem">${total !== null ? total.toFixed(1)+' / 100' : '—'}</td>
    <td>
      ${total !== null ? `<div class="bar"><div class="bar-fill" style="width:${Math.min(total,100)}%;background:${barColor(total)}"></div></div>` : '—'}
    </td>
  `;
  tbody.appendChild(totalRow);
  
  $('#detail-comment').textContent = subj.comments || 'No comment provided.';
  $('#detail-meta').innerHTML = `Graded by <strong>${esc(subj.teacher_name)}</strong> · ${esc(subj.class_name)} · ${esc(subj.term_name)}`;
}

function renderDetailChart(subj){
  const ctx = $('#detail-chart').getContext('2d');
  if(chartInst){ chartInst.destroy(); chartInst = null; }
  
  const labels = ['Practices','Assignments','CAT','Project'];
  const values = [
    subj.class_practices_max > 0 ? (parseFloat(subj.scaled?.class_practices)||0) : 0,
    subj.assignments_max > 0 ? (parseFloat(subj.scaled?.assignments)||0) : 0,
    subj.cat_max > 0 ? (parseFloat(subj.scaled?.cat)||0) : 0,
    subj.group_project_max > 0 ? (parseFloat(subj.scaled?.group_project)||0) : 0,
  ];
  const colors = values.map((v,i) => componentColor(i));
  
  chartInst = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Scaled Score (/25)',
        data: values,
        backgroundColor: colors,
        borderRadius: 6,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ' ' + c.parsed.y.toFixed(1) + ' / 25' } }
      },
      scales: {
        y: { min: 0, max: 25, grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 } } },
        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
      }
    }
  });
}

function switchTab(tabEl, name){
  if(!tabEl) return;
  $$('.tab').forEach(t => t.classList.remove('active'));
  tabEl.classList.add('active');
  $('#tab-table').classList.toggle('hidden', name !== 'table');
  $('#tab-chart').classList.toggle('hidden', name !== 'chart');
}

function closeDetail(){
  $('#detail-section').classList.add('hidden');
  if(chartInst){ chartInst.destroy(); chartInst = null; }
}

window.switchTab = switchTab;
window.closeDetail = closeDetail;

// Boot
init();
})();
</script>
</body>
</html>
<?php
if (isset($conn) && $conn) mysqli_close($conn);
ob_end_flush();
?>