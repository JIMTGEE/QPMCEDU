<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher to access this page.";
    header("Location: /login.php");
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

if (!$conn) {
    $_SESSION['error_msg'] = "Database connection failed.";
    header("Location: /login.php");
    exit();
}

function resolveProfilePicture($profile_picture) {
    $default = '/assets/images/default_profile.png';
    if (empty($profile_picture)) return $default;
    $clean = ltrim(str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $profile_picture), '/');
    $full  = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean;
    if (file_exists($full) && filesize($full) > 100) return '/Uploads/profiles/' . $clean;
    $alt = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($profile_picture, '/');
    if (file_exists($alt) && filesize($alt) > 100) return '/' . ltrim($profile_picture, '/');
    return $default;
}

// ── Teacher ───────────────────────────────────────────────────────────────────
$teacher_id   = $_SESSION['user_id'];
$t_stmt       = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'teacher'");
$t_stmt->bind_param("i", $teacher_id);
$t_stmt->execute();
$teacher = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$teacher) {
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}
$teacher_profile_img = resolveProfilePicture($teacher['profile_picture']);

// ── Current academic year / term ──────────────────────────────────────────────
$current_date = date('Y-m-d');
$acad_stmt    = $conn->prepare("SELECT `year`, `term_name`, start_date, end_date FROM academic_calendar WHERE ? BETWEEN start_date AND end_date ORDER BY start_date ASC LIMIT 1");
$acad_stmt->bind_param("s", $current_date);
$acad_stmt->execute();
$acad_row = $acad_stmt->get_result()->fetch_assoc();
$acad_stmt->close();

if ($acad_row) {
    $current_academic_year = $acad_row['year'];
    $current_term_name     = $acad_row['term_name'];
} else {
    $m = (int)date('n'); $y = (int)date('Y');
    if ($m >= 9)      { $current_academic_year = "$y/".($y+1); $current_term_name = 'Term 1'; }
    elseif ($m <= 4)  { $current_academic_year = ($y-1)."/$y"; $current_term_name = 'Term 2'; }
    else              { $current_academic_year = ($y-1)."/$y"; $current_term_name = 'Term 3'; }
}

// ── Academic years list ───────────────────────────────────────────────────────
$yr_stmt = $conn->prepare("SELECT DISTINCT `year` FROM academic_calendar ORDER BY `year` DESC");
$yr_stmt->execute();
$yr_res  = $yr_stmt->get_result();
$academic_years = [];
while ($r = $yr_res->fetch_assoc()) $academic_years[] = $r['year'];
$yr_stmt->close();

// ── Params ────────────────────────────────────────────────────────────────────
$class_id      = isset($_GET['class_id'])      ? (int)$_GET['class_id']      : null;
$academic_year = $_GET['academic_year'] ?? $current_academic_year;
$term_name     = $_GET['term_name']     ?? $current_term_name;

if (!$class_id || !$academic_year) {
    $_SESSION['error_msg'] = "Invalid request. Please select a class.";
    header("Location: manage_attendance.php");
    exit();
}

// ── Term details ──────────────────────────────────────────────────────────────
$term_stmt = $conn->prepare("SELECT `year`, `term_name`, `start_date`, `end_date` FROM academic_calendar WHERE `year` = ? AND `term_name` = ? LIMIT 1");
$term_stmt->bind_param("ss", $academic_year, $term_name);
$term_stmt->execute();
$term = $term_stmt->get_result()->fetch_assoc();
$term_stmt->close();

if (!$term) {
    // fallback to first term of that year
    $term_stmt = $conn->prepare("SELECT `year`, `term_name`, `start_date`, `end_date` FROM academic_calendar WHERE `year` = ? ORDER BY start_date ASC LIMIT 1");
    $term_stmt->bind_param("s", $academic_year);
    $term_stmt->execute();
    $term = $term_stmt->get_result()->fetch_assoc();
    $term_stmt->close();
    if (!$term) {
        $_SESSION['error_msg'] = "Term information not found.";
        header("Location: manage_attendance.php");
        exit();
    }
    $term_name = $term['term_name'];
}

$term_start = new DateTime($term['start_date']);
$term_end   = new DateTime($term['end_date']);
$today_dt   = new DateTime($current_date);
// Don't show future days beyond today
if ($term_end > $today_dt) $term_end = $today_dt;

// ── Verify teacher owns this class ────────────────────────────────────────────
$cls_stmt = $conn->prepare("
    SELECT c.id, c.class_name, c.department_id, d.name AS department_name
    FROM classes c
    LEFT JOIN departments d      ON c.department_id = d.id
    LEFT JOIN class_schedules cs ON c.id = cs.class_id AND cs.academic_year = ?
    WHERE c.id = ?
      AND (cs.teacher_id = ? OR c.class_teacher_id = ? OR c.teacher_id = ?)");
$cls_stmt->bind_param("siiii", $academic_year, $class_id, $teacher_id, $teacher_id, $teacher_id);
$cls_stmt->execute();
$class = $cls_stmt->get_result()->fetch_assoc();
$cls_stmt->close();

if (!$class) {
    $_SESSION['error_msg'] = "You are not authorised to view attendance for this class.";
    header("Location: manage_attendance.php");
    exit();
}
$class_name      = $class['class_name'];
$department_name = $class['department_name'] ?? 'General';

// ── Build a flat list of every school day (Mon–Fri) in the term ──────────────
// Grouped by week for the register layout
$all_school_days = []; // flat: ['2026-01-05' => ['date'=>..,'day'=>'Mon','week'=>1], ...]
$weeks_map       = []; // week_num => ['label'=>'Week 1', 'days'=>[dates...], 'mon_date'=>...]

$cursor   = clone $term_start;
$week_num = 0;
$prev_week_monday = null;

while ($cursor <= $term_end) {
    $dow = (int)$cursor->format('N'); // 1=Mon … 7=Sun
    if ($dow >= 1 && $dow <= 5) {    // Mon–Fri only
        // Find this day's Monday to group into weeks
        $this_monday = (clone $cursor)->modify('-' . ($dow - 1) . ' days');
        $mon_str     = $this_monday->format('Y-m-d');

        if ($prev_week_monday !== $mon_str) {
            $week_num++;
            $prev_week_monday = $mon_str;
            $weeks_map[$week_num] = [
                'number'   => $week_num,
                'mon_date' => $mon_str,
                'label'    => 'Week ' . $week_num,
                'dates'    => [],
            ];
        }

        $date_str = $cursor->format('Y-m-d');
        $day_abbr = $cursor->format('D'); // Mon, Tue …

        $all_school_days[$date_str] = [
            'date'     => $date_str,
            'day'      => $day_abbr,
            'day_short'=> strtoupper(substr($day_abbr, 0, 1)), // M T W T F
            'week'     => $week_num,
        ];
        $weeks_map[$week_num]['dates'][] = $date_str;
    }
    $cursor->modify('+1 day');
}

$total_school_days = count($all_school_days);

// Week filter
$selected_week = isset($_GET['week']) && $_GET['week'] !== 'all' ? (int)$_GET['week'] : 'all';

// Which days to display
if ($selected_week !== 'all' && isset($weeks_map[$selected_week])) {
    $display_dates = $weeks_map[$selected_week]['dates'];
} else {
    $display_dates = array_keys($all_school_days);
}

// ── Students ──────────────────────────────────────────────────────────────────
$stu_stmt = $conn->prepare("
    SELECT u.id, u.name, u.email, u.profile_picture
    FROM users u
    JOIN student_classes sc ON u.id = sc.user_id
    WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student' AND u.status = 'active'
    ORDER BY u.name");
$stu_stmt->bind_param("is", $class_id, $academic_year);
$stu_stmt->execute();
$stu_res  = $stu_stmt->get_result();
$students = [];
$student_ids = [];
while ($row = $stu_res->fetch_assoc()) {
    $row['profile_picture'] = resolveProfilePicture($row['profile_picture']);
    $students[$row['id']] = [
        'id'              => $row['id'],
        'name'            => $row['name'],
        'email'           => $row['email'],
        'profile_picture' => $row['profile_picture'],
        'records'         => [], // date => status
    ];
    $student_ids[] = $row['id'];
}
$stu_stmt->close();

// ── Attendance records ────────────────────────────────────────────────────────
$daily_summary = []; // date => [present, absent, total]

if (!empty($student_ids) && !empty($all_school_days)) {
    $start_str = $term_start->format('Y-m-d');
    $end_str   = $term_end->format('Y-m-d');
    $ph        = implode(',', array_fill(0, count($student_ids), '?'));
    $types     = 'iss' . str_repeat('i', count($student_ids));
    $params    = array_merge([$class_id, $start_str, $end_str], $student_ids);

    $att_stmt  = $conn->prepare("
        SELECT user_id, date, status
        FROM attendance
        WHERE class_id = ? AND date BETWEEN ? AND ?
          AND user_id IN ($ph)
        ORDER BY date");
    $att_stmt->bind_param($types, ...$params);
    $att_stmt->execute();
    $att_res = $att_stmt->get_result();
    while ($row = $att_res->fetch_assoc()) {
        $sid  = $row['user_id'];
        $dt   = $row['date'];
        $stat = $row['status'];
        // Normalise 'late' → 'absent' for display (teacher UI is present/absent only)
        if ($stat === 'late') $stat = 'absent';
        if (isset($students[$sid])) {
            $students[$sid]['records'][$dt] = $stat;
        }
        if (!isset($daily_summary[$dt])) $daily_summary[$dt] = ['present' => 0, 'absent' => 0, 'total' => 0];
        $daily_summary[$dt][$stat]++;
        $daily_summary[$dt]['total']++;
    }
    $att_stmt->close();
}

// ── Per-student totals (over ALL school days that have a record) ──────────────
$grand_present = 0;
$grand_absent  = 0;
$grand_total   = 0;

foreach ($students as &$stu) {
    $p = 0; $a = 0;
    foreach ($stu['records'] as $stat) {
        if ($stat === 'present') $p++; else $a++;
    }
    $stu['total_present'] = $p;
    $stu['total_absent']  = $a;
    $stu['total_marked']  = $p + $a;
    $stu['pct']           = ($p + $a) > 0 ? round($p / ($p + $a) * 100, 1) : 0;
    $grand_present += $p;
    $grand_absent  += $a;
    $grand_total   += ($p + $a);
}
unset($stu);

$class_pct = $grand_total > 0 ? round($grand_present / $grand_total * 100, 1) : 0;
$student_count = count($students);

// ── All terms for this year (for term switcher) ───────────────────────────────
$all_terms_stmt = $conn->prepare("SELECT `year`, `term_name`, start_date, end_date FROM academic_calendar WHERE `year` = ? ORDER BY start_date ASC");
$all_terms_stmt->bind_param("s", $academic_year);
$all_terms_stmt->execute();
$all_terms_res = $all_terms_stmt->get_result();
$all_terms = [];
while ($r = $all_terms_res->fetch_assoc()) $all_terms[] = $r;
$all_terms_stmt->close();

$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
if (!file_exists($header_path)) {
    $_SESSION['error_msg'] = "Teacher header template missing.";
    header("Location: /login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Register — <?= htmlspecialchars($class_name) ?> | QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        /* ══════════════════════════════════════════════════
           DESIGN TOKENS
           Brand: QueensPa orange #ff4500
           Register feel: crisp ledger lines, official look
        ══════════════════════════════════════════════════ */
        :root {
            --brand:       #ff4500;
            --brand-dark:  #d93b00;
            --brand-light: #fff0eb;
            --present:     #16a34a;
            --present-bg:  #dcfce7;
            --present-bdr: #86efac;
            --absent:      #dc2626;
            --absent-bg:   #fee2e2;
            --absent-bdr:  #fca5a5;
            --none:        #94a3b8;
            --none-bg:     #f1f5f9;
            --surface:     #ffffff;
            --bg:          #f0f2f5;
            --border:      #e2e8f0;
            --text:        #1e293b;
            --muted:       #64748b;
            --radius:      14px;
            --shadow:      0 2px 12px rgba(0,0,0,.08);
            --shadow-lg:   0 8px 32px rgba(0,0,0,.14);
            /* Register ledger colours */
            --ledger-head-bg:   #1e293b;
            --ledger-head-text: #ffffff;
            --ledger-alt:       #f8fafc;
            --ledger-week-sep:  3px solid #cbd5e1;
            --ledger-day-sep:   1px solid #e2e8f0;
            --mon-col:  #fafafa;
            --tue-col:  #fafafa;
            --wed-col:  #f0f9ff;
            --thu-col:  #fafafa;
            --fri-col:  #fafafa;
        }
        [data-bs-theme="dark"] {
            --brand:       #ff6b35;
            --brand-dark:  #ff4500;
            --brand-light: #2a1a10;
            --present:     #4ade80;
            --present-bg:  #052e16;
            --present-bdr: #166534;
            --absent:      #f87171;
            --absent-bg:   #2d0a0a;
            --absent-bdr:  #7f1d1d;
            --none:        #475569;
            --none-bg:     #1e2432;
            --surface:     #1e2432;
            --bg:          #141820;
            --border:      #2d3348;
            --text:        #e2e8f0;
            --muted:       #94a3b8;
            --ledger-head-bg:   #0f172a;
            --ledger-head-text: #e2e8f0;
            --ledger-alt:       #1a2035;
            --ledger-week-sep:  3px solid #334155;
            --ledger-day-sep:   1px solid #2d3348;
            --mon-col:  #1e2432;
            --wed-col:  #1a2535;
            --fri-col:  #1e2432;
        }

        /* ── Base ── */
        *, *::before, *::after { box-sizing: border-box; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', system-ui, sans-serif; min-height: 100vh; }

        /* ── Cards ── */
        .qp-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 1.25rem; overflow: hidden; }
        .qp-card-header { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; padding: 1rem 1.4rem; display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
        .qp-card-header h5 { margin: 0; font-weight: 700; font-size: .98rem; }
        .qp-card-body  { padding: 1.25rem; }

        /* ── Header card ── */
        .page-header { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; border-radius: var(--radius); padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; box-shadow: var(--shadow-lg); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
        .page-header h2 { margin: 0; font-size: 1.15rem; font-weight: 800; letter-spacing: -.01em; }
        .page-header p  { margin: 0; font-size: .82rem; opacity: .85; }
        .teacher-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2.5px solid rgba(255,255,255,.5); flex-shrink: 0; }

        /* ── Stat cards ── */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        .stat-box { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.1rem 1.25rem; text-align: center; box-shadow: var(--shadow); }
        .stat-box .val { font-size: 2rem; font-weight: 800; line-height: 1; }
        .stat-box .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); margin-top: .3rem; }
        .val-brand   { color: var(--brand); }
        .val-present { color: var(--present); }
        .val-absent  { color: var(--absent); }
        .val-muted   { color: var(--muted); }

        /* ── Progress bar ── */
        .class-progress-wrap { background: var(--absent-bg); border-radius: 999px; height: 12px; overflow: hidden; }
        .class-progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--present), #22c55e); transition: width .6s ease; }

        /* ── Filters ── */
        .filters { display: flex; gap: .75rem; flex-wrap: wrap; align-items: flex-end; }
        .filters label  { font-size: .78rem; font-weight: 700; color: var(--muted); display: block; margin-bottom: .25rem; text-transform: uppercase; letter-spacing: .05em; }
        .filters select { background: var(--surface); border: 1.5px solid var(--border); color: var(--text); border-radius: 8px; padding: .4rem .75rem; font-size: .83rem; cursor: pointer; }
        .filters select:focus { outline: none; border-color: var(--brand); }
        .filters input  { background: var(--surface); border: 1.5px solid var(--border); color: var(--text); border-radius: 8px; padding: .4rem .75rem; font-size: .83rem; }
        .filters input:focus { outline: none; border-color: var(--brand); }

        /* ══════════════════════════════════════════════════
           THE REGISTER TABLE — core of this page
        ══════════════════════════════════════════════════ */
        .register-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        table.register {
            border-collapse: collapse;
            width: 100%;
            min-width: max-content;
            font-size: .8rem;
        }

        /* Frozen left column (student name) */
        table.register td.col-student,
        table.register th.col-student {
            position: sticky;
            left: 0;
            z-index: 5;
            background: var(--surface);
            min-width: 180px;
            max-width: 210px;
        }
        table.register thead th.col-student { background: var(--ledger-head-bg); z-index: 10; }
        table.register tbody tr:nth-child(even) td.col-student { background: var(--ledger-alt); }

        /* ── Head row 1: Week banners ── */
        thead tr.row-weeks th {
            background: var(--ledger-head-bg);
            color: var(--ledger-head-text);
            padding: .5rem .4rem;
            text-align: center;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            border-right: var(--ledger-week-sep);
            white-space: nowrap;
        }
        thead tr.row-weeks th:last-child { border-right: none; }
        thead tr.row-weeks th.col-student { text-align: left; padding-left: .85rem; font-size: .78rem; }
        thead tr.row-weeks th.col-totals  { border-left: var(--ledger-week-sep); border-right: none; }

        /* ── Head row 2: Day columns ── */
        thead tr.row-days th {
            background: var(--brand);
            color: #fff;
            text-align: center;
            padding: 0;
            border-right: var(--ledger-day-sep);
            white-space: nowrap;
        }
        thead tr.row-days th:last-child  { border-right: none; }
        thead tr.row-days th.week-last   { border-right: var(--ledger-week-sep); }
        thead tr.row-days th.col-student { background: var(--brand); text-align: left; padding-left: .85rem; }
        thead tr.row-days th.col-totals  { border-left: var(--ledger-week-sep); background: var(--brand-dark); }

        .day-head { display: flex; flex-direction: column; align-items: center; padding: .35rem .3rem; min-width: 38px; }
        .day-head .day-letter { font-size: .65rem; font-weight: 800; letter-spacing: .05em; opacity: .85; }
        .day-head .day-date   { font-size: .72rem; font-weight: 700; }

        /* ── Body cells ── */
        table.register tbody td {
            padding: 0;
            border-right: var(--ledger-day-sep);
            border-bottom: 1px solid var(--border);
            text-align: center;
            vertical-align: middle;
        }
        table.register tbody td.week-last   { border-right: var(--ledger-week-sep); }
        table.register tbody td.col-student {
            padding: .45rem .85rem;
            text-align: left;
            font-weight: 600;
            font-size: .82rem;
            border-right: var(--ledger-week-sep);
        }
        table.register tbody td.col-total   { border-left: var(--ledger-week-sep); font-weight: 700; font-size: .8rem; }
        table.register tbody tr:nth-child(even) { background: var(--ledger-alt); }
        table.register tbody tr:hover { background: var(--brand-light) !important; }

        /* ── Status cells ── */
        .cell-inner {
            display: flex; align-items: center; justify-content: center;
            height: 38px; width: 100%;
            font-size: .9rem; font-weight: 800;
            transition: background .12s;
        }
        .cell-present { background: var(--present-bg); color: var(--present); }
        .cell-absent  { background: var(--absent-bg);  color: var(--absent);  }
        .cell-none    { background: transparent; color: var(--border); }

        /* ── Total cells ── */
        .total-cell { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 38px; gap: 0; padding: 0 .4rem; }
        .total-p  { color: var(--present); font-size: .72rem; font-weight: 800; }
        .total-a  { color: var(--absent);  font-size: .72rem; font-weight: 800; }
        .pct-pill { display: inline-block; border-radius: 4px; padding: .1rem .35rem; font-size: .68rem; font-weight: 800; margin-top: .1rem; }
        .pct-good { background: var(--present-bg); color: var(--present); }
        .pct-warn { background: #fef3c7; color: #92400e; }
        .pct-bad  { background: var(--absent-bg);  color: var(--absent);  }

        /* ── Column footer (daily totals) ── */
        tfoot tr td {
            background: var(--ledger-head-bg) !important;
            color: var(--ledger-head-text);
            padding: 0;
            border-right: var(--ledger-day-sep);
            border-top: 2px solid var(--brand);
            text-align: center;
        }
        tfoot tr td.week-last  { border-right: var(--ledger-week-sep); }
        tfoot tr td.col-student { padding: .45rem .85rem; text-align: left; font-size: .75rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; border-right: var(--ledger-week-sep); }
        tfoot tr td.col-total  { border-left: var(--ledger-week-sep); }
        .foot-inner { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 36px; font-size: .68rem; font-weight: 700; line-height: 1.1; }
        .foot-p { color: #86efac; }
        .foot-a { color: #fca5a5; }

        /* ── Student name cell ── */
        .stu-cell { display: flex; align-items: center; gap: .55rem; }
        .stu-num  { font-size: .68rem; color: var(--muted); min-width: 18px; text-align: right; flex-shrink: 0; }
        .stu-avatar { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 1.5px solid var(--border); }
        .stu-name   { font-weight: 600; font-size: .82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }

        /* ── Week badge in head row ── */
        .week-badge { display: inline-flex; align-items: center; gap: .3rem; background: rgba(255,255,255,.15); border-radius: 5px; padding: .15rem .5rem; font-size: .7rem; font-weight: 700; }

        /* ── Charts ── */
        .chart-box { height: 220px; position: relative; }

        /* ── Legend ── */
        .legend { display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: .8rem; }
        .legend-item { display: flex; align-items: center; gap: .4rem; font-weight: 600; }
        .legend-dot  { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }

        /* ── Buttons ── */
        .btn-brand { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; border: none; border-radius: 8px; padding: .4rem .9rem; font-size: .82rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: .35rem; transition: opacity .2s, transform .15s; }
        .btn-brand:hover { opacity: .88; transform: translateY(-1px); color: #fff; }
        .btn-ghost { background: transparent; color: var(--text); border: 1.5px solid var(--border); border-radius: 8px; padding: .38rem .85rem; font-size: .82rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: .35rem; transition: all .2s; }
        .btn-ghost:hover { border-color: var(--brand); color: var(--brand); }

        /* ── Theme FAB ── */
        .theme-fab { position: fixed; bottom: 1.5rem; right: 1.5rem; width: 44px; height: 44px; border-radius: 50%; background: var(--brand); color: #fff; border: none; cursor: pointer; box-shadow: var(--shadow-lg); display: flex; align-items: center; justify-content: center; font-size: 1rem; z-index: 1100; transition: background .2s, transform .2s; }
        .theme-fab:hover { background: var(--brand-dark); transform: scale(1.1); }

        /* ── Hidden rows (search filter) ── */
        tr.hidden-row { display: none; }

        /* ── Print ── */
        @media print {
            .theme-fab, .no-print { display: none !important; }
            body { background: #fff; }
            .qp-card { box-shadow: none; break-inside: avoid; }
            .register-wrap { overflow: visible; }
            table.register { font-size: .7rem; }
        }

        @media (max-width: 600px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .day-head { min-width: 30px; }
        }
    </style>
</head>
<body>
    <?php include $header_path; ?>

    <div class="container-fluid py-3 px-3 px-md-4">
        <button class="theme-fab" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon" id="themeIcon"></i></button>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-dismissible fade show no-print">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error_msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

        <!-- ═══ PAGE HEADER ════════════════════════════════════════════════════ -->
        <div class="page-header no-print-keep">
            <div style="display:flex;align-items:center;gap:.9rem;">
                <img src="<?= htmlspecialchars($teacher_profile_img) ?>" class="teacher-avatar"
                     onerror="this.src='/assets/images/default_profile.png'" alt="">
                <div>
                    <h2><?= htmlspecialchars($class_name) ?> — Attendance Register</h2>
                    <p><?= htmlspecialchars($department_name) ?>
                       &middot; <?= htmlspecialchars($term['term_name']) ?>
                       &middot; <?= htmlspecialchars($academic_year) ?>
                       &middot; <?= $total_school_days ?> school days recorded
                    </p>
                </div>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;" class="no-print">
                <button onclick="window.print()" class="btn-ghost" style="color:#fff;border-color:rgba(255,255,255,.4);">
                    <i class="fas fa-print"></i> Print Register
                </button>
                <button onclick="exportCSV()" class="btn-ghost" style="color:#fff;border-color:rgba(255,255,255,.4);">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
            </div>
        </div>

        <!-- ═══ SUMMARY STATS ══════════════════════════════════════════════════ -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="val val-brand"><?= $student_count ?></div>
                <div class="lbl">Students</div>
            </div>
            <div class="stat-box">
                <div class="val val-muted"><?= count($weeks_map) ?></div>
                <div class="lbl">Weeks</div>
            </div>
            <div class="stat-box">
                <div class="val val-muted"><?= $total_school_days ?></div>
                <div class="lbl">School Days</div>
            </div>
            <div class="stat-box">
                <div class="val val-present"><?= $grand_present ?></div>
                <div class="lbl">Present</div>
            </div>
            <div class="stat-box">
                <div class="val val-absent"><?= $grand_absent ?></div>
                <div class="lbl">Absent</div>
            </div>
            <div class="stat-box">
                <div class="val <?= $class_pct >= 80 ? 'val-present' : ($class_pct >= 60 ? '' : 'val-absent') ?>" style="<?= ($class_pct >= 60 && $class_pct < 80) ? 'color:#d97706' : '' ?>"><?= $class_pct ?>%</div>
                <div class="lbl">Class Average</div>
                <div style="margin-top:.5rem;">
                    <div class="class-progress-wrap">
                        <div class="class-progress-fill" style="width:<?= min($class_pct,100) ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ CHARTS ROW ══════════════════════════════════════════════════════ -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;" class="no-print">
            <div class="qp-card">
                <div class="qp-card-header" style="justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:.5rem;"><i class="fas fa-chart-line"></i><h5>Daily Attendance Trend</h5></div>
                </div>
                <div class="qp-card-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div>
            </div>
            <div class="qp-card">
                <div class="qp-card-header"><i class="fas fa-chart-bar"></i><h5>Student Attendance Distribution</h5></div>
                <div class="qp-card-body"><div class="chart-box"><canvas id="distChart"></canvas></div></div>
            </div>
        </div>

        <!-- ═══ FILTERS ═════════════════════════════════════════════════════════ -->
        <div class="qp-card no-print">
            <div class="qp-card-header"><i class="fas fa-sliders"></i><h5>Filter & Search</h5></div>
            <div class="qp-card-body">
                <form method="GET" class="filters">
                    <input type="hidden" name="class_id" value="<?= $class_id ?>">

                    <div>
                        <label>Academic Year</label>
                        <select name="academic_year" onchange="this.form.submit()">
                            <?php foreach ($academic_years as $yr): ?>
                                <option value="<?= htmlspecialchars($yr) ?>" <?= $academic_year===$yr?'selected':'' ?>><?= htmlspecialchars($yr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Term</label>
                        <select name="term_name" onchange="this.form.submit()">
                            <?php foreach ($all_terms as $t): ?>
                                <option value="<?= htmlspecialchars($t['term_name']) ?>" <?= $term_name===$t['term_name']?'selected':'' ?>>
                                    <?= htmlspecialchars($t['term_name']) ?> (<?= date('M d', strtotime($t['start_date'])) ?> – <?= date('M d', strtotime($t['end_date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Jump to Week</label>
                        <select name="week" onchange="this.form.submit()">
                            <option value="all" <?= $selected_week==='all'?'selected':'' ?>>All Weeks</option>
                            <?php foreach ($weeks_map as $wn => $wk): ?>
                                <option value="<?= $wn ?>" <?= $selected_week===$wn?'selected':'' ?>>
                                    Week <?= $wn ?> (<?= date('M d', strtotime($wk['mon_date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Search Student</label>
                        <input type="text" id="stuSearch" placeholder="Type a name…" oninput="filterStudents(this.value)">
                    </div>
                </form>

                <div class="legend" style="margin-top:1rem;">
                    <div class="legend-item"><div class="legend-dot" style="background:var(--present-bg);border:1.5px solid var(--present-bdr)"></div><span style="color:var(--present)">✓ Present</span></div>
                    <div class="legend-item"><div class="legend-dot" style="background:var(--absent-bg);border:1.5px solid var(--absent-bdr)"></div><span style="color:var(--absent)">✗ Absent</span></div>
                    <div class="legend-item"><div class="legend-dot" style="background:var(--none-bg);border:1.5px solid var(--border)"></div><span style="color:var(--muted)">— Not Marked</span></div>
                </div>
            </div>
        </div>

        <!-- ═══ THE REGISTER ════════════════════════════════════════════════════ -->
        <div class="qp-card">
            <div class="qp-card-header" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                <div style="display:flex;align-items:center;gap:.6rem;">
                    <i class="fas fa-table-list"></i>
                    <h5>Official Attendance Register</h5>
                </div>
                <div style="font-size:.78rem;opacity:.85;">
                    <?= htmlspecialchars($class_name) ?> &middot; <?= htmlspecialchars($term['term_name']) ?> <?= htmlspecialchars($academic_year) ?>
                </div>
            </div>
            <div class="qp-card-body" style="padding:0;">
                <?php if (empty($students)): ?>
                    <div class="alert alert-info m-3 text-center">
                        <i class="fas fa-info-circle me-2"></i>No students enrolled for this class and academic year.
                    </div>
                <?php elseif (empty($display_dates)): ?>
                    <div class="alert alert-warning m-3 text-center">
                        <i class="fas fa-calendar-xmark me-2"></i>No school days found for the selected period.
                    </div>
                <?php else: ?>
                    <div class="register-wrap">
                    <?php
                    // Group display_dates by week
                    $display_by_week = [];
                    foreach ($display_dates as $dt) {
                        $wn = $all_school_days[$dt]['week'];
                        $display_by_week[$wn][] = $dt;
                    }
                    // Find which date is the last in each week (for border styling)
                    $week_last_dates = [];
                    foreach ($display_by_week as $wn => $dates) {
                        $week_last_dates[end($dates)] = true;
                    }
                    ?>
                    <table class="register" id="registerTable">
                        <thead>
                            <!-- ROW 1: Week banners -->
                            <tr class="row-weeks">
                                <th class="col-student" rowspan="1">
                                    <?= htmlspecialchars($class_name) ?>
                                </th>
                                <?php foreach ($display_by_week as $wn => $dates): ?>
                                    <th colspan="<?= count($dates) ?>">
                                        <span class="week-badge">
                                            <i class="fas fa-calendar-week" style="font-size:.6rem;"></i>
                                            Week <?= $wn ?>
                                            <span style="opacity:.7;font-weight:400;">
                                                <?= date('d M', strtotime($dates[0])) ?>–<?= date('d M', strtotime(end($dates))) ?>
                                            </span>
                                        </span>
                                    </th>
                                <?php endforeach; ?>
                                <th class="col-totals" colspan="3">Term Totals</th>
                            </tr>
                            <!-- ROW 2: Individual day columns -->
                            <tr class="row-days">
                                <th class="col-student">Student Name</th>
                                <?php foreach ($display_dates as $dt):
                                    $di = $all_school_days[$dt];
                                    $is_week_last = isset($week_last_dates[$dt]);
                                    $cls = $is_week_last ? 'week-last' : '';
                                ?>
                                    <th class="<?= $cls ?>">
                                        <div class="day-head">
                                            <span class="day-letter"><?= strtoupper(substr($di['day'], 0, 1)) ?></span>
                                            <span class="day-date"><?= date('d', strtotime($dt)) ?></span>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                                <th class="col-totals text-center" style="min-width:42px;padding:.2rem;">P</th>
                                <th class="col-totals text-center" style="min-width:42px;padding:.2rem;">A</th>
                                <th class="col-totals text-center" style="min-width:52px;padding:.2rem;">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $row_n = 0; foreach ($students as $stu): $row_n++; ?>
                            <tr class="stu-row" data-name="<?= strtolower(htmlspecialchars($stu['name'])) ?>">
                                <!-- Name cell -->
                                <td class="col-student">
                                    <div class="stu-cell">
                                        <span class="stu-num"><?= $row_n ?></span>
                                        <img src="<?= htmlspecialchars($stu['profile_picture']) ?>"
                                             class="stu-avatar"
                                             onerror="this.src='/assets/images/default_profile.png'" alt="">
                                        <span class="stu-name" title="<?= htmlspecialchars($stu['name']) ?>"><?= htmlspecialchars($stu['name']) ?></span>
                                    </div>
                                </td>
                                <!-- One cell per displayed day -->
                                <?php foreach ($display_dates as $dt):
                                    $status = $stu['records'][$dt] ?? null;
                                    $is_week_last = isset($week_last_dates[$dt]);
                                    $cell_cls = $is_week_last ? 'week-last' : '';
                                    if ($status === 'present') {
                                        $inner_cls = 'cell-present'; $mark = '✓';
                                    } elseif ($status === 'absent') {
                                        $inner_cls = 'cell-absent';  $mark = '✗';
                                    } else {
                                        $inner_cls = 'cell-none';    $mark = '—';
                                    }
                                ?>
                                    <td class="<?= $cell_cls ?>" title="<?= htmlspecialchars($stu['name']) ?> — <?= date('D d M', strtotime($dt)) ?> — <?= $status ?? 'not marked' ?>">
                                        <div class="cell-inner <?= $inner_cls ?>"><?= $mark ?></div>
                                    </td>
                                <?php endforeach; ?>
                                <!-- Totals -->
                                <?php
                                // If a week filter is active, compute per-week totals for display
                                if ($selected_week !== 'all' && isset($weeks_map[$selected_week])) {
                                    $disp_p = 0; $disp_a = 0;
                                    foreach ($display_dates as $dt) {
                                        $s = $stu['records'][$dt] ?? null;
                                        if ($s === 'present') $disp_p++;
                                        elseif ($s === 'absent') $disp_a++;
                                    }
                                    $disp_total = $disp_p + $disp_a;
                                    $disp_pct   = $disp_total > 0 ? round($disp_p / $disp_total * 100) : 0;
                                } else {
                                    $disp_p     = $stu['total_present'];
                                    $disp_a     = $stu['total_absent'];
                                    $disp_pct   = $stu['pct'];
                                }
                                $pct_cls = $disp_pct >= 80 ? 'pct-good' : ($disp_pct >= 60 ? 'pct-warn' : 'pct-bad');
                                ?>
                                <td class="col-total" style="min-width:42px;">
                                    <div class="total-cell"><span class="total-p"><?= $disp_p ?></span></div>
                                </td>
                                <td class="col-total" style="min-width:42px;">
                                    <div class="total-cell"><span class="total-a"><?= $disp_a ?></span></div>
                                </td>
                                <td class="col-total" style="min-width:52px;">
                                    <div class="total-cell">
                                        <span class="pct-pill <?= $pct_cls ?>"><?= $disp_pct ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="col-student">Daily Totals</td>
                                <?php foreach ($display_dates as $dt):
                                    $ds = $daily_summary[$dt] ?? ['present'=>0,'absent'=>0,'total'=>0];
                                    $is_week_last = isset($week_last_dates[$dt]);
                                    $fcls = $is_week_last ? 'week-last' : '';
                                ?>
                                    <td class="<?= $fcls ?>">
                                        <div class="foot-inner">
                                            <span class="foot-p"><?= $ds['present'] ?>P</span>
                                            <span class="foot-a"><?= $ds['absent'] ?>A</span>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                                <td class="col-total" style="color:#86efac;font-weight:800;font-size:.8rem;"><div class="total-cell"><span class="total-p"><?= $grand_present ?></span></div></td>
                                <td class="col-total" style="color:#fca5a5;font-weight:800;font-size:.8rem;"><div class="total-cell"><span class="total-a"><?= $grand_absent ?></span></div></td>
                                <td class="col-total"><div class="total-cell"><span class="pct-pill <?= $class_pct>=80?'pct-good':($class_pct>=60?'pct-warn':'pct-bad') ?>"><?= $class_pct ?>%</span></div></td>
                            </tr>
                        </tfoot>
                    </table>
                    </div><!-- /register-wrap -->
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ STUDENT BREAKDOWN CARDS ════════════════════════════════════════ -->
        <?php if (!empty($students)): ?>
        <div class="qp-card no-print">
            <div class="qp-card-header"><i class="fas fa-user-group"></i><h5>Student Breakdown</h5></div>
            <div class="qp-card-body" style="padding:.75rem;">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;" id="breakdownGrid">
                    <?php foreach ($students as $stu):
                        $p = $stu['total_present']; $a = $stu['total_absent']; $tot = $p+$a;
                        $pct = $stu['pct'];
                        $bar_w = min($pct, 100);
                        $bar_color = $pct >= 80 ? 'var(--present)' : ($pct >= 60 ? '#d97706' : 'var(--absent)');
                    ?>
                    <div class="breakdown-card" data-name="<?= strtolower(htmlspecialchars($stu['name'])) ?>"
                         style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:.75rem;">
                        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                            <img src="<?= htmlspecialchars($stu['profile_picture']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:1.5px solid var(--border);" onerror="this.src='/assets/images/default_profile.png'" alt="">
                            <span style="font-size:.82rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($stu['name']) ?></span>
                        </div>
                        <div style="height:6px;background:var(--border);border-radius:999px;overflow:hidden;margin-bottom:.4rem;">
                            <div style="width:<?= $bar_w ?>%;height:100%;background:<?= $bar_color ?>;border-radius:999px;"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.72rem;font-weight:700;">
                            <span style="color:var(--present)">✓ <?= $p ?></span>
                            <span style="color:var(--muted);"><?= $tot ?> days</span>
                            <span style="color:var(--absent)">✗ <?= $a ?></span>
                        </div>
                        <div style="text-align:center;margin-top:.3rem;">
                            <span style="font-size:.78rem;font-weight:800;color:<?= $bar_color ?>;"><?= $pct ?>% attendance</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ QUICK LINKS ══════════════════════════════════════════════════════ -->
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:1.5rem;" class="no-print">
            <a href="manage_attendance.php?academic_year=<?= urlencode($academic_year) ?>&term_name=<?= urlencode($term_name) ?>" class="btn-brand">
                <i class="fas fa-arrow-left"></i> Back to Manage
            </a>
            <a href="dashboard.php"            class="btn-brand"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="submit_grades.php"         class="btn-brand"><i class="fas fa-chart-line"></i> Grades</a>
            <a href="manage_assignments.php"    class="btn-brand"><i class="fas fa-tasks"></i> Assignments</a>
        </div>
    </div>

    <?php
    $fp = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (file_exists($fp)) include $fp;
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ── Theme ────────────────────────────────────────────────────────
    const html = document.documentElement;
    const fab  = document.getElementById('themeToggle');
    const icon = document.getElementById('themeIcon');
    function setTheme(t) {
        html.setAttribute('data-bs-theme', t);
        localStorage.setItem('qp_theme', t);
        icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        if (trendChart) updateChartTheme();
    }
    setTheme(localStorage.getItem('qp_theme') || 'light');
    fab.addEventListener('click', () => setTheme(html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

    // ── Student search: filters register rows AND breakdown cards ────
    function filterStudents(q) {
        q = q.toLowerCase();
        document.querySelectorAll('.stu-row').forEach(r => {
            r.classList.toggle('hidden-row', !r.dataset.name.includes(q));
        });
        document.querySelectorAll('.breakdown-card').forEach(c => {
            c.style.display = c.dataset.name.includes(q) ? '' : 'none';
        });
    }

    // ── CSV Export ───────────────────────────────────────────────────
    function exportCSV() {
        const tbl = document.getElementById('registerTable');
        if (!tbl) return;
        const rows = tbl.querySelectorAll('tr');
        const csv  = [];
        rows.forEach(row => {
            const cols = row.querySelectorAll('th, td');
            const data = Array.from(cols).map(c => {
                let t = c.textContent.trim().replace(/\s+/g, ' ');
                return t.includes(',') || t.includes('"') ? '"' + t.replace(/"/g, '""') + '"' : t;
            });
            csv.push(data.join(','));
        });
        const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = 'register_<?= addslashes($class_name) ?>_<?= addslashes($term_name) ?>_<?= addslashes($academic_year) ?>.csv';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // ── Charts ───────────────────────────────────────────────────────
    let trendChart, distChart;

    const dailyData  = <?= json_encode($daily_summary) ?>;
    const studentData = <?= json_encode(array_values(array_map(function($s){ return ['name' => $s['name'], 'pct' => $s['pct']]; }, $students))) ?>;

    function chartColors() {
        const dark = html.getAttribute('data-bs-theme') === 'dark';
        return {
            text: dark ? '#e2e8f0' : '#1e293b',
            grid: dark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)',
        };
    }

    function initCharts() {
        const c = chartColors();

        // 1. Daily trend line
        const dates    = Object.keys(dailyData).sort();
        const presents = dates.map(d => dailyData[d].present || 0);
        const absents  = dates.map(d => dailyData[d].absent  || 0);

        const tCtx = document.getElementById('trendChart');
        if (tCtx) {
            trendChart = new Chart(tCtx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [
                        { label: 'Present', data: presents, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.12)', borderWidth: 2, tension: .35, fill: true, pointRadius: 3 },
                        { label: 'Absent',  data: absents,  borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.08)',  borderWidth: 2, tension: .35, fill: true, pointRadius: 3 },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: c.text, font: { size: 11 } } }, tooltip: { mode: 'index', intersect: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, color: c.text, font: { size: 10 } }, grid: { color: c.grid } },
                        x: { ticks: { color: c.text, maxRotation: 60, font: { size: 9 } }, grid: { color: c.grid } }
                    }
                }
            });
        }

        // 2. Distribution bar chart (students sorted by pct)
        const sorted = [...studentData].sort((a,b) => b.pct - a.pct);
        const dCtx   = document.getElementById('distChart');
        if (dCtx) {
            distChart = new Chart(dCtx, {
                type: 'bar',
                data: {
                    labels: sorted.map(s => s.name.split(' ')[0]), // first name only for brevity
                    datasets: [{
                        label: 'Attendance %',
                        data: sorted.map(s => s.pct),
                        backgroundColor: sorted.map(s => s.pct >= 80 ? 'rgba(22,163,74,.75)' : s.pct >= 60 ? 'rgba(217,119,6,.75)' : 'rgba(220,38,38,.75)'),
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.parsed.y + '%' } } },
                    scales: {
                        y: { min: 0, max: 100, ticks: { color: c.text, font: { size: 10 }, callback: v => v+'%' }, grid: { color: c.grid } },
                        x: { ticks: { color: c.text, font: { size: 9 }, maxRotation: 60 }, grid: { color: c.grid } }
                    }
                }
            });
        }
    }

    function updateChartTheme() {
        const c = chartColors();
        [trendChart, distChart].forEach(ch => {
            if (!ch) return;
            ['x', 'y'].forEach(ax => {
                if (ch.options.scales[ax]) {
                    ch.options.scales[ax].ticks.color = c.text;
                    ch.options.scales[ax].grid.color  = c.grid;
                }
            });
            if (ch.options.plugins?.legend?.labels) ch.options.plugins.legend.labels.color = c.text;
            ch.update();
        });
    }

    document.addEventListener('DOMContentLoaded', initCharts);
    </script>
</body>
</html>
<?php
ob_end_flush();
$conn->close();
?>