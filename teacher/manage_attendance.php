<?php
// Start session only if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify teacher authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit();
    }
    $_SESSION['error_msg'] = "Please log in as a teacher to access this page.";
    header("Location: /login.php");
    exit();
}

// Determine if marking is locked for today (≥ 10:00 AM Accra time)
$tz              = new DateTimeZone('Africa/Accra');
$currentDateTime = new DateTime('now', $tz);
$currentTime     = $currentDateTime->format('H:i');
$todayDate       = $currentDateTime->format('Y-m-d');
$marking_locked_today = ($currentTime >= '10:00');

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

if (!$conn) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit();
    }
    $_SESSION['error_msg'] = "Database connection failed.";
    header("Location: /login.php");
    exit();
}

// ── Helper: resolve profile picture path ──────────────────────────────────────
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

// ── Current academic year / term from academic_calendar ───────────────────────
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
    $fb_stmt = $conn->prepare("SELECT `year`, `term_name` FROM academic_calendar WHERE start_date >= ? ORDER BY start_date ASC LIMIT 1");
    $fb_stmt->bind_param("s", $current_date);
    $fb_stmt->execute();
    $fb_row = $fb_stmt->get_result()->fetch_assoc();
    $fb_stmt->close();
    if ($fb_row) {
        $current_academic_year = $fb_row['year'];
        $current_term_name     = $fb_row['term_name'];
    } else {
        $m = (int)date('n');
        $y = (int)date('Y');
        if ($m >= 9)       { $current_academic_year = "$y/" . ($y+1); $current_term_name = 'Term 1'; }
        elseif ($m <= 4)   { $current_academic_year = ($y-1) . "/$y"; $current_term_name = 'Term 2'; }
        else               { $current_academic_year = ($y-1) . "/$y"; $current_term_name = 'Term 3'; }
    }
}

// ── All academic years & terms ────────────────────────────────────────────────
$terms_stmt = $conn->prepare("SELECT DISTINCT `year`, `term_name`, `start_date`, `end_date` FROM academic_calendar ORDER BY `year` DESC, CASE WHEN term_name='Term 1' THEN 1 WHEN term_name='Term 2' THEN 2 ELSE 3 END ASC");
$terms_stmt->execute();
$terms_res   = $terms_stmt->get_result();
$all_terms   = [];
$academic_years = [];
while ($r = $terms_res->fetch_assoc()) {
    $all_terms[] = $r;
    if (!in_array($r['year'], $academic_years)) $academic_years[] = $r['year'];
}
$terms_stmt->close();

$selected_academic_year = $_GET['academic_year'] ?? $current_academic_year;
$selected_term_name     = $_GET['term_name']     ?? $current_term_name;
if (!in_array($selected_academic_year, $academic_years) && !empty($academic_years)) {
    $selected_academic_year = $academic_years[0] ?? $current_academic_year;
}

// ── AJAX: get students ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_students') {
    ob_start();
    header('Content-Type: application/json');

    $teacher_id      = $_SESSION['user_id'];
    $class_id        = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $attendance_date = isset($_GET['date'])     ? trim($_GET['date'])     : '';
    $ajax_year       = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : $selected_academic_year;

    if (!$class_id || !$attendance_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters']);
        exit();
    }
    if ($attendance_date === $todayDate && $marking_locked_today) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Attendance marking is locked after 10:00 AM. Contact the admin.']);
        exit();
    }

    // Only present/absent — treat any existing 'late' record as 'absent' for display
    $sql = "SELECT u.id, u.name, u.profile_picture,
                   CASE WHEN COALESCE(a.status,'absent') = 'late' THEN 'absent'
                        ELSE COALESCE(a.status,'absent') END AS current_status,
                   a.notes AS current_notes
            FROM users u
            JOIN student_classes sc ON u.id = sc.user_id AND sc.class_id = ? AND sc.academic_year = ?
            LEFT JOIN attendance a ON a.user_id = u.id AND a.class_id = ? AND a.date = ?
            WHERE u.role = 'student' AND u.status = 'active'
            ORDER BY u.name";
    $st = $conn->prepare($sql);
    $students = [];
    if ($st) {
        $st->bind_param("isis", $class_id, $ajax_year, $class_id, $attendance_date);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $students[] = [
                'id'             => (int)$row['id'],
                'name'           => $row['name'],
                'profile_picture'=> resolveProfilePicture($row['profile_picture']),
                'current_status' => $row['current_status'],
                'current_notes'  => $row['current_notes'] ?? '',
            ];
        }
        $st->close();
    }

    $cn_stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $class_name = 'Unknown Class';
    if ($cn_stmt) {
        $cn_stmt->bind_param("i", $class_id);
        $cn_stmt->execute();
        $cn = $cn_stmt->get_result()->fetch_assoc();
        $class_name = $cn['class_name'] ?? 'Unknown Class';
        $cn_stmt->close();
    }

    ob_end_clean();
    echo json_encode([
        'success'        => true,
        'students'       => $students,
        'class_name'     => $class_name,
        'total_students' => count($students),
        'academic_year'  => $ajax_year,
    ]);
    exit();
}

// ── Page starts here ──────────────────────────────────────────────────────────
ob_start();

$teacher_id   = $_SESSION['user_id'];
$t_stmt       = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'teacher'");
$t_stmt->bind_param("i", $teacher_id);
$t_stmt->execute();
$teacher      = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$teacher) {
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}
$teacher_profile_img = resolveProfilePicture($teacher['profile_picture']);

// ── Fetch assigned classes ────────────────────────────────────────────────────
$classes_sql = "
    SELECT DISTINCT c.id, c.class_name, c.department_id, c.teacher_id, c.class_teacher_id,
                    d.name AS department_name,
                    ct.name AS assigned_teacher_name,
                    COUNT(DISTINCT sc.user_id) AS student_count
    FROM classes c
    LEFT JOIN departments d ON c.department_id = d.id
    LEFT JOIN users ct ON c.teacher_id = ct.id AND ct.role = 'teacher'
    LEFT JOIN class_schedules cs ON c.id = cs.class_id AND cs.academic_year = ? AND cs.teacher_id = ?
    LEFT JOIN student_classes sc ON c.id = sc.class_id AND sc.academic_year = ?
    WHERE (c.teacher_id = ? OR c.class_teacher_id = ? OR cs.teacher_id = ?)
    GROUP BY c.id, c.class_name, c.department_id, d.name, c.teacher_id, c.class_teacher_id, ct.name
    ORDER BY d.name, c.class_name";
$c_stmt = $conn->prepare($classes_sql);
$classes = [];
$class_count = $student_count = 0;
if ($c_stmt) {
    $c_stmt->bind_param("sssiii", $selected_academic_year, $teacher_id, $selected_academic_year, $teacher_id, $teacher_id, $teacher_id);
    $c_stmt->execute();
    $c_res = $c_stmt->get_result();
    while ($row = $c_res->fetch_assoc()) {
        $classes[$row['id']] = $row;
        $class_count++;
        $student_count += $row['student_count'];
    }
    $c_stmt->close();
}

if (empty($classes)) {
    $dc_sql = "SELECT DISTINCT c.id, c.class_name, c.department_id, c.teacher_id, c.class_teacher_id,
                               d.name AS department_name, ct.name AS assigned_teacher_name,
                               COUNT(DISTINCT u2.id) AS student_count
               FROM classes c
               LEFT JOIN departments d ON c.department_id = d.id
               LEFT JOIN users ct ON c.teacher_id = ct.id AND ct.role = 'teacher'
               LEFT JOIN student_classes sc ON c.id = sc.class_id AND sc.academic_year = ?
               LEFT JOIN users u2 ON sc.user_id = u2.id AND u2.role = 'student' AND u2.status = 'active'
               WHERE (c.teacher_id = ? OR c.class_teacher_id = ?)
               GROUP BY c.id, c.class_name, c.department_id, d.name, c.teacher_id, c.class_teacher_id, ct.name
               ORDER BY d.name, c.class_name";
    $dc_stmt = $conn->prepare($dc_sql);
    if ($dc_stmt) {
        $dc_stmt->bind_param("sii", $selected_academic_year, $teacher_id, $teacher_id);
        $dc_stmt->execute();
        $dc_res = $dc_stmt->get_result();
        $class_count = $student_count = 0;
        while ($row = $dc_res->fetch_assoc()) {
            $classes[$row['id']] = $row;
            $class_count++;
            $student_count += $row['student_count'];
        }
        $dc_stmt->close();
    }
}

// ── Handle POST: save attendance ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $class_id        = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $attendance_date = htmlspecialchars(trim($_POST['attendance_date'] ?? ''));
    $attendance_data = $_POST['attendance'] ?? [];

    if ($attendance_date === $todayDate && $marking_locked_today) {
        $_SESSION['error_msg'] = "Attendance is locked after 10:00 AM. Contact the admin.";
        header("Location: manage_attendance.php?academic_year=" . urlencode($selected_academic_year) . "&term_name=" . urlencode($selected_term_name));
        exit();
    }

    if ($class_id && $attendance_date && !empty($attendance_data)) {
        $conn->begin_transaction();
        try {
            $saved = 0;
            foreach ($attendance_data as $student_id => $data) {
                $student_id = (int)$student_id;
                // Only accept present or absent
                $raw_status = $data['status'] ?? 'absent';
                $status = in_array($raw_status, ['present', 'absent']) ? $raw_status : 'absent';
                $notes  = isset($data['notes']) ? trim(substr($data['notes'], 0, 255)) : null;
                $notes  = ($notes === '') ? null : $notes;

                $chk = $conn->prepare("SELECT id FROM attendance WHERE class_id=? AND user_id=? AND date=?");
                $chk->bind_param("iis", $class_id, $student_id, $attendance_date);
                $chk->execute();
                $exists = $chk->get_result()->num_rows > 0;
                $chk->close();

                if ($exists) {
                    $upd = $conn->prepare("UPDATE attendance SET status=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE class_id=? AND user_id=? AND date=?");
                    $upd->bind_param("ssiis", $status, $notes, $class_id, $student_id, $attendance_date);
                    $upd->execute();
                    $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO attendance (class_id, user_id, date, status, notes, created_at) VALUES (?,?,?,?,?,NOW())");
                    $ins->bind_param("iisss", $class_id, $student_id, $attendance_date, $status, $notes);
                    $ins->execute();
                    $ins->close();
                }
                $saved++;
            }
            $conn->commit();
            $_SESSION['success_msg'] = "Attendance saved for $saved student" . ($saved !== 1 ? 's' : '') . ".";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_msg'] = "Failed to save attendance. Please try again.";
        }
    } else {
        $_SESSION['error_msg'] = "Nothing to save — check your inputs.";
    }

    header("Location: manage_attendance.php?academic_year=" . urlencode($selected_academic_year) . "&term_name=" . urlencode($selected_term_name));
    exit();
}

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
    <title>Attendance — QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ── Design tokens ─────────────────────────────── */
        :root {
            --brand:        #ff4500;
            --brand-dark:   #d93b00;
            --brand-light:  #fff0eb;
            --present:      #16a34a;
            --present-bg:   #dcfce7;
            --present-ring: #86efac;
            --absent:       #dc2626;
            --absent-bg:    #fee2e2;
            --absent-ring:  #fca5a5;
            --surface:      #ffffff;
            --bg:           #f4f6f9;
            --border:       #e2e8f0;
            --text:         #1e293b;
            --muted:        #64748b;
            --radius:       14px;
            --shadow:       0 2px 12px rgba(0,0,0,.08);
            --shadow-lg:    0 8px 32px rgba(0,0,0,.12);
        }
        [data-bs-theme="dark"] {
            --brand:        #ff6b35;
            --brand-dark:   #ff4500;
            --brand-light:  #2a1a10;
            --present:      #4ade80;
            --present-bg:   #052e16;
            --present-ring: #166534;
            --absent:       #f87171;
            --absent-bg:    #2d0a0a;
            --absent-ring:  #7f1d1d;
            --surface:      #1e2432;
            --bg:           #141820;
            --border:       #2d3348;
            --text:         #e2e8f0;
            --muted:        #94a3b8;
        }

        /* ── Base ──────────────────────────────────────── */
        body { background: var(--bg); color: var(--text); font-family: 'Inter', system-ui, sans-serif; min-height: 100vh; }
        a { color: var(--brand); }

        /* ── Cards ─────────────────────────────────────── */
        .qp-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 1.25rem; overflow: hidden; }
        .qp-card-header { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; padding: 1rem 1.25rem; display: flex; align-items: center; gap: .75rem; }
        .qp-card-header h3, .qp-card-header h5 { margin: 0; font-weight: 700; font-size: 1rem; }
        .qp-card-body { padding: 1.25rem; }

        /* ── Stat pills ────────────────────────────────── */
        .stat-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .75rem; border-radius: 999px; font-size: .8rem; font-weight: 600; background: rgba(255,255,255,.18); color: #fff; }

        /* ── Class table ───────────────────────────────── */
        .class-table { width: 100%; border-collapse: collapse; }
        .class-table thead th { background: var(--brand); color: #fff; padding: .65rem 1rem; font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .class-table thead th:first-child { border-radius: 8px 0 0 0; }
        .class-table thead th:last-child  { border-radius: 0 8px 0 0; }
        .class-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        .class-table tbody tr:hover { background: var(--brand-light); }
        .class-table td { padding: .75rem 1rem; vertical-align: middle; font-size: .88rem; }
        .dept-badge { display: inline-block; background: var(--brand-light); color: var(--brand); border-radius: 6px; padding: .15rem .5rem; font-size: .75rem; font-weight: 600; }
        .count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 26px; background: var(--brand); color: #fff; border-radius: 999px; font-size: .78rem; font-weight: 700; }

        /* ── Buttons ───────────────────────────────────── */
        .btn-brand { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; border: none; border-radius: 8px; padding: .4rem .9rem; font-size: .82rem; font-weight: 600; cursor: pointer; transition: opacity .2s, transform .15s; }
        .btn-brand:hover { opacity: .9; transform: translateY(-1px); color: #fff; }
        .btn-outline-brand { background: transparent; color: var(--brand); border: 1.5px solid var(--brand); border-radius: 8px; padding: .38rem .9rem; font-size: .82rem; font-weight: 600; cursor: pointer; transition: all .2s; }
        .btn-outline-brand:hover { background: var(--brand); color: #fff; }

        /* ── Lock banner ───────────────────────────────── */
        .lock-banner { background: #fffbeb; border: 1.5px solid #f59e0b; border-radius: var(--radius); padding: .85rem 1.1rem; display: flex; align-items: center; gap: .75rem; font-size: .88rem; margin-bottom: 1rem; }
        [data-bs-theme="dark"] .lock-banner { background: #2d1a00; border-color: #92400e; color: #fde68a; }

        /* ── Teacher avatar ────────────────────────────── */
        .teacher-avatar { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,.5); flex-shrink: 0; }

        /* ── Modal ─────────────────────────────────────── */
        .modal-content { background: var(--surface); color: var(--text); border-radius: var(--radius); border: 1px solid var(--border); }
        .modal-header { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; border-radius: var(--radius) var(--radius) 0 0; border: none; padding: 1rem 1.25rem; }
        .modal-header .btn-close { filter: invert(1); }

        /* ── Progress bar ──────────────────────────────── */
        .attendance-progress { border-radius: var(--radius); background: var(--bg); border: 1px solid var(--border); padding: 1rem 1.25rem; margin-bottom: 1.1rem; }
        .progress-bar-wrap { height: 10px; background: var(--absent-bg); border-radius: 999px; overflow: hidden; margin-top: .6rem; }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--present), #22c55e); border-radius: 999px; transition: width .35s ease; }
        .progress-stats { display: flex; justify-content: space-between; font-size: .8rem; margin-top: .45rem; }
        .prog-present { color: var(--present); font-weight: 700; }
        .prog-absent  { color: var(--absent);  font-weight: 700; }
        .prog-total   { color: var(--muted); }

        /* ── Bulk action bar ───────────────────────────── */
        .bulk-bar { display: flex; gap: .5rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: .6rem .85rem; }
        .bulk-bar-label { font-size: .78rem; color: var(--muted); font-weight: 600; margin-right: .1rem; }
        .bulk-btn { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .9rem; border-radius: 7px; border: 1.5px solid transparent; font-size: .8rem; font-weight: 700; cursor: pointer; transition: all .15s; }
        .bulk-btn-present { background: var(--present-bg); color: var(--present); border-color: var(--present-ring); }
        .bulk-btn-present:hover { background: var(--present); color: #fff; }
        .bulk-btn-absent  { background: var(--absent-bg);  color: var(--absent);  border-color: var(--absent-ring); }
        .bulk-btn-absent:hover  { background: var(--absent); color: #fff; }

        /* ── Student rows with toggle ──────────────────── */
        .student-grid { display: flex; flex-direction: column; gap: .55rem; }
        .student-row {
            display: flex; align-items: center; gap: .85rem;
            background: var(--bg); border: 1.5px solid var(--border);
            border-radius: 10px; padding: .6rem .9rem;
            transition: border-color .2s, background .2s, box-shadow .15s;
        }
        .student-row.is-present { border-color: var(--present-ring); background: var(--present-bg); }
        .student-row.is-absent  { border-color: var(--absent-ring);  background: var(--absent-bg); }
        .student-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }

        .s-num  { font-size: .72rem; color: var(--muted); min-width: 20px; text-align: right; flex-shrink: 0; }
        .s-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid var(--border); }
        .s-name { font-weight: 600; font-size: .87rem; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* status badge on the left of toggle */
        .s-status-badge { font-size: .7rem; font-weight: 700; padding: .18rem .5rem; border-radius: 5px; text-transform: uppercase; letter-spacing: .04em; flex-shrink: 0; min-width: 52px; text-align: center; }
        .badge-present { background: var(--present-bg); color: var(--present); border: 1px solid var(--present-ring); }
        .badge-absent  { background: var(--absent-bg);  color: var(--absent);  border: 1px solid var(--absent-ring); }

        /* ── Toggle switch ─────────────────────────────── */
        .att-toggle { position: relative; display: inline-block; width: 60px; height: 28px; flex-shrink: 0; cursor: pointer; }
        .att-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
        .att-slider {
            position: absolute; inset: 0;
            background: var(--absent);
            border-radius: 28px;
            transition: background .25s;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 6px; font-size: .6rem; font-weight: 800; color: rgba(255,255,255,.75);
            user-select: none;
        }
        .att-slider::before {
            content: '';
            position: absolute;
            left: 3px;
            width: 22px; height: 22px;
            background: #fff;
            border-radius: 50%;
            transition: transform .25s;
            box-shadow: 0 1px 4px rgba(0,0,0,.25);
        }
        .att-toggle input:checked + .att-slider { background: var(--present); }
        .att-toggle input:checked + .att-slider::before { transform: translateX(32px); }
        .slider-label-on  { margin-left: auto; }
        .slider-label-off { }

        /* ── Theme FAB ─────────────────────────────────── */
        .theme-fab { position: fixed; bottom: 1.5rem; right: 1.5rem; width: 44px; height: 44px; border-radius: 50%; background: var(--brand); color: #fff; border: none; cursor: pointer; box-shadow: var(--shadow-lg); display: flex; align-items: center; justify-content: center; font-size: 1rem; z-index: 1100; transition: background .2s, transform .2s; }
        .theme-fab:hover { background: var(--brand-dark); transform: scale(1.1); }

        /* ── Quick links ───────────────────────────────── */
        .quick-links { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .25rem; }

        /* ── Responsive ────────────────────────────────── */
        @media (max-width: 576px) {
            .class-table thead th:nth-child(3) { display: none; }
            .class-table td:nth-child(3)       { display: none; }
            .s-name { max-width: 110px; }
        }
    </style>
</head>
<body>
    <?php include $header_path; ?>

    <div class="container py-4">
        <button class="theme-fab" id="themeToggle" aria-label="Toggle theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error_msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if ($marking_locked_today): ?>
        <div class="lock-banner">
            <i class="fas fa-lock fa-lg" style="color:#d97706"></i>
            <div><strong>Marking locked for today (<?= htmlspecialchars($todayDate) ?>).</strong> Attendance is closed after 10:00 AM — an admin can still make changes.</div>
        </div>
        <?php endif; ?>

        <!-- ── Header card ── -->
        <div class="qp-card">
            <div class="qp-card-header" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                <div style="display:flex;align-items:center;gap:.85rem;">
                    <img src="<?= htmlspecialchars($teacher_profile_img) ?>"
                         class="teacher-avatar"
                         onerror="this.src='/assets/images/default_profile.png'"
                         alt="Profile">
                    <div>
                        <h3 style="font-size:1.05rem;">Attendance Register</h3>
                        <p style="margin:0;font-size:.82rem;opacity:.8;">Welcome, <?= htmlspecialchars($teacher['name']) ?></p>
                    </div>
                </div>
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                    <span class="stat-pill"><i class="fas fa-door-open"></i> <?= $class_count ?> Classes</span>
                    <span class="stat-pill"><i class="fas fa-users"></i> <?= $student_count ?> Students</span>
                </div>
            </div>
        </div>

        <!-- ── Filter card ── -->
        <div class="qp-card">
            <div class="qp-card-header">
                <i class="fas fa-sliders"></i>
                <h5>Academic Period</h5>
            </div>
            <div class="qp-card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-sm-5">
                        <label class="form-label fw-semibold" style="font-size:.83rem;">Academic Year</label>
                        <select name="academic_year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($academic_years as $yr): ?>
                                <option value="<?= htmlspecialchars($yr) ?>" <?= $selected_academic_year===$yr?'selected':'' ?>>
                                    <?= htmlspecialchars($yr) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label fw-semibold" style="font-size:.83rem;">Term</label>
                        <select name="term_name" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($all_terms as $t): ?>
                                <?php if ($t['year'] === $selected_academic_year): ?>
                                    <option value="<?= htmlspecialchars($t['term_name']) ?>" <?= $selected_term_name===$t['term_name']?'selected':'' ?>>
                                        <?= htmlspecialchars($t['term_name']) ?>
                                        (<?= date('M d', strtotime($t['start_date'])) ?> – <?= date('M d, Y', strtotime($t['end_date'])) ?>)
                                        <?= ($current_term_name===$t['term_name'] && $current_academic_year===$t['year']) ? ' ✓' : '' ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <?php if ($current_term_name): ?>
                            <span class="badge bg-success" style="font-size:.75rem;padding:.4rem .7rem;">
                                <i class="fas fa-circle me-1" style="font-size:.5rem;"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark" style="font-size:.75rem;">No Term</span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Classes card ── -->
        <div class="qp-card">
            <div class="qp-card-header">
                <i class="fas fa-chalkboard-teacher"></i>
                <h5>Your Classes — <?= htmlspecialchars($selected_academic_year) ?></h5>
            </div>
            <div class="qp-card-body" style="padding:0 0 .25rem;">
                <?php if (empty($classes)): ?>
                    <div class="alert alert-info m-3">
                        <i class="fas fa-info-circle me-2"></i>No classes assigned for <?= htmlspecialchars($selected_academic_year) ?>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="class-table">
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Department</th>
                                    <th>Teacher</th>
                                    <th style="text-align:center;">Students</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $cls): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cls['class_name']) ?></strong></td>
                                    <td>
                                        <?php if ($cls['department_name']): ?>
                                            <span class="dept-badge"><?= htmlspecialchars($cls['department_name']) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--muted)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--muted);font-size:.82rem;">
                                        <?= $cls['assigned_teacher_name'] ? '<i class="fas fa-user-tie me-1" style="color:var(--brand)"></i>' . htmlspecialchars($cls['assigned_teacher_name']) : '—' ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="count-badge"><?= (int)$cls['student_count'] ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;gap:.4rem;justify-content:center;flex-wrap:wrap;">
                                            <button class="btn-brand mark-btn"
                                                    data-class-id="<?= $cls['id'] ?>"
                                                    data-class-name="<?= htmlspecialchars($cls['class_name']) ?>"
                                                    data-academic-year="<?= htmlspecialchars($selected_academic_year) ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#attendanceModal">
                                                <i class="fas fa-pen me-1"></i>Mark
                                            </button>
                                            <a href="view_attendance.php?class_id=<?= $cls['id'] ?>&academic_year=<?= urlencode($selected_academic_year) ?>&term_name=<?= urlencode($selected_term_name) ?>"
                                               class="btn-outline-brand" style="text-decoration:none;">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Quick links ── -->
        <div class="quick-links">
            <a href="dashboard.php"    class="btn-brand" style="text-decoration:none;"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a>
            <a href="submit_grades.php" class="btn-brand" style="text-decoration:none;"><i class="fas fa-chart-line me-1"></i>Grades</a>
            <a href="view_evaluations.php?teacher_id=<?= $teacher_id ?>" class="btn-brand" style="text-decoration:none;"><i class="fas fa-clipboard-check me-1"></i>Evaluations</a>
            <a href="manage_assignments.php" class="btn-brand" style="text-decoration:none;"><i class="fas fa-tasks me-1"></i>Assignments</a>
        </div>
    </div>

    <!-- ══ Attendance Modal ══════════════════════════════════════════════════ -->
    <div class="modal fade" id="attendanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0"><i class="fas fa-clipboard-list me-2"></i>Mark Attendance</h5>
                        <p class="mb-0" style="font-size:.78rem;opacity:.8;" id="modal-subtitle">Select a date to begin</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body" style="padding:1.1rem 1.25rem;">

                    <!-- Date picker -->
                    <div style="display:flex;align-items:flex-end;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:160px;">
                            <label class="form-label fw-semibold" style="font-size:.82rem;margin-bottom:.3rem;">
                                <i class="fas fa-calendar-day me-1" style="color:var(--brand)"></i>Date
                            </label>
                            <input type="text" class="form-control form-control-sm" id="attendanceDate" placeholder="Pick a date" readonly>
                        </div>
                        <div id="lock-notice" class="d-none" style="flex:1;color:#d97706;font-size:.82rem;font-weight:600;">
                            <i class="fas fa-lock me-1"></i>Locked for today — admin only
                        </div>
                    </div>

                    <!-- Error alert -->
                    <div id="modal-error" class="alert alert-danger d-none" style="font-size:.85rem;"></div>

                    <!-- Spinner -->
                    <div id="modal-loading" class="text-center py-4">
                        <div class="spinner-border text-danger" style="width:2rem;height:2rem;" role="status"></div>
                        <p class="mt-2" style="font-size:.85rem;color:var(--muted);">Loading students…</p>
                    </div>

                    <!-- Main content -->
                    <div id="modal-content" class="d-none">

                        <!-- Progress bar summary -->
                        <div class="attendance-progress">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-size:.82rem;font-weight:700;color:var(--text);">
                                    <i class="fas fa-chart-bar me-1" style="color:var(--brand)"></i>Class Summary
                                </span>
                                <span style="font-size:.75rem;color:var(--muted);" id="prog-pct">0% present</span>
                            </div>
                            <div class="progress-bar-wrap">
                                <div class="progress-bar-fill" id="prog-bar" style="width:0%"></div>
                            </div>
                            <div class="progress-stats">
                                <span class="prog-present"><i class="fas fa-check-circle me-1"></i><span id="s-present">0</span> Present</span>
                                <span class="prog-absent"><i class="fas fa-times-circle me-1"></i><span id="s-absent">0</span> Absent</span>
                                <span class="prog-total"><span id="s-total">0</span> Total</span>
                            </div>
                        </div>

                        <!-- Bulk actions -->
                        <div class="bulk-bar">
                            <span class="bulk-bar-label">Mark all as:</span>
                            <button type="button" class="bulk-btn bulk-btn-present" onclick="markAll('present')">
                                <i class="fas fa-check-circle"></i> All Present
                            </button>
                            <button type="button" class="bulk-btn bulk-btn-absent" onclick="markAll('absent')">
                                <i class="fas fa-times-circle"></i> All Absent
                            </button>
                        </div>

                        <form method="POST" id="attendanceForm">
                            <input type="hidden" name="mark_attendance" value="1">
                            <input type="hidden" name="class_id" id="modal-class-id">
                            <input type="hidden" name="attendance_date" id="modal-date">

                            <div class="student-grid" id="students-list"></div>

                            <div style="display:flex;justify-content:flex-end;gap:.6rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border);">
                                <button type="button" class="btn-outline-brand" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i>Cancel
                                </button>
                                <button type="submit" class="btn-brand" id="saveBtn" disabled>
                                    <i class="fas fa-save me-1"></i>Save Register
                                </button>
                            </div>
                        </form>
                    </div><!-- /modal-content -->
                </div><!-- /modal-body -->
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
    // ── Theme ────────────────────────────────────────────────────────
    const html = document.documentElement;
    const fab  = document.getElementById('themeToggle');
    const icon = document.getElementById('themeIcon');
    function setTheme(t) {
        html.setAttribute('data-bs-theme', t);
        localStorage.setItem('qp_theme', t);
        icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    setTheme(localStorage.getItem('qp_theme') || 'light');
    fab.addEventListener('click', () => setTheme(html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

    // ── PHP constants ────────────────────────────────────────────────
    const LOCKED_TODAY = <?= $marking_locked_today ? 'true' : 'false' ?>;
    const TODAY        = '<?= $todayDate ?>';

    // ── State ────────────────────────────────────────────────────────
    let currentClassId   = null;
    let currentYear      = null;
    let studentStatuses  = {};   // { student_id: 'present'|'absent' }

    // ── Open modal ───────────────────────────────────────────────────
    document.querySelectorAll('.mark-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            currentClassId = this.dataset.classId;
            currentYear    = this.dataset.academicYear;
            document.getElementById('modal-class-id').value = currentClassId;
            document.getElementById('modal-subtitle').textContent = this.dataset.className;
            document.getElementById('modal-error').classList.add('d-none');
            document.getElementById('modal-content').classList.add('d-none');
            document.getElementById('modal-loading').classList.remove('d-none');
            document.getElementById('saveBtn').disabled = true;

            const dateInput = document.getElementById('attendanceDate');
            dateInput.value = TODAY;
            document.getElementById('modal-date').value = TODAY;
            checkLockAndLoad(TODAY);
        });
    });

    // ── Flatpickr ────────────────────────────────────────────────────
    flatpickr('#attendanceDate', {
        dateFormat: 'Y-m-d',
        maxDate: 'today',
        defaultDate: TODAY,
        onChange: function(_, dateStr) {
            document.getElementById('modal-date').value = dateStr;
            if (currentClassId) checkLockAndLoad(dateStr);
        }
    });

    function checkLockAndLoad(dateStr) {
        const lockNotice = document.getElementById('lock-notice');
        if (LOCKED_TODAY && dateStr === TODAY) {
            lockNotice.classList.remove('d-none');
            document.getElementById('modal-loading').classList.add('d-none');
            document.getElementById('modal-content').classList.add('d-none');
            document.getElementById('modal-error').classList.remove('d-none');
            document.getElementById('modal-error').textContent = 'Attendance for today is locked after 10:00 AM. An admin can still mark.';
            document.getElementById('saveBtn').disabled = true;
            return;
        }
        lockNotice.classList.add('d-none');
        document.getElementById('modal-error').classList.add('d-none');
        loadStudents(currentClassId, dateStr, currentYear);
    }

    // ── Load students via AJAX ───────────────────────────────────────
    function loadStudents(classId, date, year) {
        document.getElementById('modal-loading').classList.remove('d-none');
        document.getElementById('modal-content').classList.add('d-none');
        studentStatuses = {};

        fetch(`manage_attendance.php?ajax=get_students&class_id=${classId}&date=${date}&academic_year=${encodeURIComponent(year)}`)
            .then(r => r.json())
            .then(data => {
                document.getElementById('modal-loading').classList.add('d-none');
                if (!data.success) {
                    document.getElementById('modal-error').classList.remove('d-none');
                    document.getElementById('modal-error').textContent = data.error || 'Could not load students.';
                    return;
                }
                renderStudents(data.students);
                document.getElementById('modal-content').classList.remove('d-none');
                document.getElementById('saveBtn').disabled = data.students.length === 0;
            })
            .catch(() => {
                document.getElementById('modal-loading').classList.add('d-none');
                document.getElementById('modal-error').classList.remove('d-none');
                document.getElementById('modal-error').textContent = 'Network error — please try again.';
            });
    }

    // ── Render student rows ──────────────────────────────────────────
    function renderStudents(students) {
        const list = document.getElementById('students-list');
        list.innerHTML = '';

        if (!students || students.length === 0) {
            list.innerHTML = '<p style="text-align:center;color:var(--muted);padding:1rem;">No active students in this class.</p>';
            document.getElementById('s-total').textContent = 0;
            updateSummary();
            return;
        }

        document.getElementById('s-total').textContent = students.length;

        students.forEach((s, idx) => {
            // Normalize: treat 'late' (legacy) as 'absent'
            const status = (s.current_status === 'present') ? 'present' : 'absent';
            studentStatuses[s.id] = status;

            const checked = status === 'present' ? 'checked' : '';
            const row = document.createElement('div');
            row.className = `student-row is-${status}`;
            row.id = `row-${s.id}`;

            row.innerHTML = `
                <span class="s-num">${idx + 1}</span>
                <img src="${escHtml(s.profile_picture || '/assets/images/default_profile.png')}"
                     class="s-avatar"
                     onerror="this.src='/assets/images/default_profile.png'"
                     alt="">
                <span class="s-name">${escHtml(s.name)}</span>
                <span class="s-status-badge ${status === 'present' ? 'badge-present' : 'badge-absent'}" id="badge-${s.id}">
                    ${status === 'present' ? '✓ Present' : '✗ Absent'}
                </span>
                <input type="hidden" name="attendance[${s.id}][status]" id="status-input-${s.id}" value="${status}">
                <label class="att-toggle" title="Toggle attendance">
                    <input type="checkbox" id="toggle-${s.id}" ${checked} onchange="onToggle(${s.id}, this.checked)">
                    <span class="att-slider">
                        <span class="slider-label-off">✗</span>
                        <span class="slider-label-on">✓</span>
                    </span>
                </label>`;

            list.appendChild(row);
        });

        updateSummary();
    }

    // ── Toggle handler ───────────────────────────────────────────────
    window.onToggle = function(id, isPresent) {
        const status = isPresent ? 'present' : 'absent';
        studentStatuses[id] = status;
        document.getElementById(`status-input-${id}`).value = status;

        const row   = document.getElementById(`row-${id}`);
        const badge = document.getElementById(`badge-${id}`);

        row.className = `student-row is-${status}`;
        badge.className = `s-status-badge ${status === 'present' ? 'badge-present' : 'badge-absent'}`;
        badge.textContent = status === 'present' ? '✓ Present' : '✗ Absent';

        updateSummary();
    };

    // ── Bulk mark all ────────────────────────────────────────────────
    window.markAll = function(status) {
        Object.keys(studentStatuses).forEach(id => {
            const intId = parseInt(id);
            const toggle = document.getElementById(`toggle-${intId}`);
            if (toggle) toggle.checked = (status === 'present');
            onToggle(intId, status === 'present');
        });
    };

    // ── Update summary & progress bar ───────────────────────────────
    function updateSummary() {
        let present = 0, absent = 0;
        Object.values(studentStatuses).forEach(s => {
            if (s === 'present') present++; else absent++;
        });
        const total = present + absent;
        const pct   = total > 0 ? Math.round((present / total) * 100) : 0;

        document.getElementById('s-present').textContent = present;
        document.getElementById('s-absent').textContent  = absent;
        document.getElementById('s-total').textContent   = total;
        document.getElementById('prog-bar').style.width  = pct + '%';
        document.getElementById('prog-pct').textContent  = pct + '% present';
    }

    // ── Form submit guard ────────────────────────────────────────────
    document.getElementById('attendanceForm').addEventListener('submit', function(e) {
        if (Object.keys(studentStatuses).length === 0) {
            e.preventDefault();
            alert('No students to save.');
            return;
        }
        if (!confirm('Save this attendance register?')) e.preventDefault();
    });

    // ── HTML escape helper ───────────────────────────────────────────
    function escHtml(t) {
        if (!t) return '';
        const d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>