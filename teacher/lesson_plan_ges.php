<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) die("Database configuration file missing.");
require_once $db_path;
if (!$conn || mysqli_connect_error()) die("Database connection failed.");

$teacher_id = (int)$_SESSION['user_id'];

// CSRF token
$csrf_token = bin2hex(random_bytes(32));
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
$cs = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))");
$cs->bind_param("is", $teacher_id, $csrf_token);
$cs->execute();
$cs->close();

function verify_csrf($conn, $teacher_id) {
    $token = $_POST['csrf_token'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $s = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $s->bind_param("is", $teacher_id, $token);
    $s->execute();
    $ok = $s->get_result()->num_rows > 0;
    $s->close();
    return $ok;
}

// =====================================================================================
// AJAX HANDLERS
// =====================================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // ---- Fetch a single plan for view/edit ----
    if ($action === 'get_plan') {
        $type = $_GET['type'] ?? '';
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Missing id']); exit(); }
        $table = $type === 'annual' ? 'annual_plans' : ($type === 'termly' ? 'termly_plans' : ($type === 'weekly' ? 'teacher_notes' : ''));
        if (!$table) { echo json_encode(['success'=>false,'error'=>'Invalid type']); exit(); }

        // Join class/subject/term names for display
        if ($type === 'annual') {
            $sql = "SELECT ap.*, c.class_name, s.subject_name
                    FROM annual_plans ap
                    JOIN classes c ON ap.class_id = c.id
                    LEFT JOIN subjects s ON ap.subject_id = s.id
                    WHERE ap.id = ? AND ap.teacher_id = ? AND ap.format = 'ges'";
        } elseif ($type === 'termly') {
            $sql = "SELECT tp.*, c.class_name, s.subject_name, ac.term_name, ac.year AS year_label
                    FROM termly_plans tp
                    JOIN classes c ON tp.class_id = c.id
                    LEFT JOIN subjects s ON tp.subject_id = s.id
                    LEFT JOIN academic_calendar ac ON tp.term_id = ac.id
                    WHERE tp.id = ? AND tp.teacher_id = ? AND tp.format = 'ges'";
        } else {
            $sql = "SELECT tn.*, c.class_name, s.subject_name, ac.term_name, ac.year AS year_label
                    FROM teacher_notes tn
                    JOIN classes c ON tn.class_id = c.id
                    LEFT JOIN subjects s ON tn.subject_id = s.id
                    LEFT JOIN academic_calendar ac ON tn.term_id = ac.id
                    WHERE tn.id = ? AND tn.teacher_id = ? AND tn.format = 'ges'";
        }
        $s = $conn->prepare($sql);
        $s->bind_param("ii", $id, $teacher_id);
        $s->execute();
        $data = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$data) { echo json_encode(['success'=>false,'error'=>'Plan not found']); exit(); }
        echo json_encode(['success'=>true,'plan'=>$data]);
        exit();
    }

    // ---- Update a plan (any field, including schedule/class/subject) ----
    if ($action === 'update_plan') {
        if (!verify_csrf($conn, $teacher_id)) { echo json_encode(['success'=>false,'error'=>'Invalid or expired session. Please refresh.']); exit(); }
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Missing id']); exit(); }

        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0) ?: null;
        $term_id = (int)($_POST['term_id'] ?? 0);
        $week = (int)($_POST['week'] ?? 0);

        if ($class_id <= 0) { echo json_encode(['success'=>false,'error'=>'Please choose a class.']); exit(); }
        if ($week < 1 || $week > 16) { echo json_encode(['success'=>false,'error'=>'Week must be between 1 and 16.']); exit(); }

        // Ownership check
        $table = $type === 'annual' ? 'annual_plans' : ($type === 'termly' ? 'termly_plans' : ($type === 'weekly' ? 'teacher_notes' : ''));
        if (!$table) { echo json_encode(['success'=>false,'error'=>'Invalid type']); exit(); }
        $own = $conn->prepare("SELECT id FROM $table WHERE id = ? AND teacher_id = ? AND format = 'ges'");
        $own->bind_param("ii", $id, $teacher_id);
        $own->execute();
        if ($own->get_result()->num_rows === 0) { echo json_encode(['success'=>false,'error'=>'You do not have permission to edit this plan.']); exit(); }
        $own->close();

        if ($type === 'annual') {
            $term_1 = trim($_POST['term_1'] ?? '');
            $term_2 = trim($_POST['term_2'] ?? '');
            $term_3 = trim($_POST['term_3'] ?? '');
            $s = $conn->prepare("UPDATE annual_plans SET class_id=?, subject_id=?, week=?, term_1=?, term_2=?, term_3=?, updated_at=NOW() WHERE id=? AND teacher_id=? AND format='ges'");
            $s->bind_param("iiisssii", $class_id, $subject_id, $week, $term_1, $term_2, $term_3, $id, $teacher_id);
        } elseif ($type === 'termly') {
            if ($term_id <= 0) { echo json_encode(['success'=>false,'error'=>'Please choose a term.']); exit(); }
            $unit = trim($_POST['unit'] ?? '');
            $topic = trim($_POST['topic'] ?? '');
            $lesson = trim($_POST['lesson'] ?? '');
            // Derive term_number
            $term_number = 1;
            $tn = $conn->prepare("SELECT term_name FROM academic_calendar WHERE id = ?");
            $tn->bind_param("i", $term_id);
            $tn->execute();
            $trow = $tn->get_result()->fetch_assoc();
            $tn->close();
            if ($trow && preg_match('/\d+/', $trow['term_name'], $m)) $term_number = (int)$m[0];

            $s = $conn->prepare("UPDATE termly_plans SET class_id=?, subject_id=?, term_id=?, term_number=?, week=?, unit=?, topic=?, lesson=?, updated_at=NOW() WHERE id=? AND teacher_id=? AND format='ges'");
            $s->bind_param("iiiiisssii", $class_id, $subject_id, $term_id, $term_number, $week, $unit, $topic, $lesson, $id, $teacher_id);
        } else {
            if ($term_id <= 0) { echo json_encode(['success'=>false,'error'=>'Please choose a term.']); exit(); }
            $strand = trim($_POST['strand'] ?? '');
            $sub_strand = trim($_POST['sub_strand'] ?? '');
            $content_standard = trim($_POST['content_standard'] ?? '');
            $indicator = trim($_POST['indicator'] ?? '');
            $objectives = trim($_POST['objectives'] ?? '');
            $reference = trim($_POST['reference'] ?? '');
            $resources = trim($_POST['resources'] ?? '');
            $keywords = trim($_POST['keywords'] ?? '');
            $starter = trim($_POST['starter'] ?? '');
            $presentation = trim($_POST['presentation'] ?? '');
            $summary = trim($_POST['summary'] ?? '');
            $s = $conn->prepare("UPDATE teacher_notes SET class_id=?, subject_id=?, term_id=?, week=?, strand=?, sub_strand=?, content_standard=?, indicator=?, objectives=?, reference=?, resources=?, keywords=?, starter=?, presentation=?, summary=?, updated_at=NOW() WHERE id=? AND teacher_id=? AND format='ges'");
            $s->bind_param("iiiissssssssssssii",
                $class_id, $subject_id, $term_id, $week,
                $strand, $sub_strand, $content_standard, $indicator, $objectives,
                $reference, $resources, $keywords, $starter, $presentation, $summary,
                $id, $teacher_id
            );
        }

        if ($s->execute()) {
            echo json_encode(['success'=>true, 'message'=>'Plan updated successfully.']);
        } else {
            echo json_encode(['success'=>false, 'error'=>'Save failed: ' . $conn->error]);
        }
        $s->close();
        exit();
    }

    // ---- Delete a plan ----
    if ($action === 'delete_plan') {
        if (!verify_csrf($conn, $teacher_id)) { echo json_encode(['success'=>false,'error'=>'Invalid session.']); exit(); }
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Missing id']); exit(); }
        $table = $type === 'annual' ? 'annual_plans' : ($type === 'termly' ? 'termly_plans' : ($type === 'weekly' ? 'teacher_notes' : ''));
        if (!$table) { echo json_encode(['success'=>false,'error'=>'Invalid type']); exit(); }
        $s = $conn->prepare("DELETE FROM $table WHERE id=? AND teacher_id=? AND format='ges'");
        $s->bind_param("ii", $id, $teacher_id);
        if ($s->execute()) echo json_encode(['success'=>true]);
        else echo json_encode(['success'=>false,'error'=>$conn->error]);
        $s->close();
        exit();
    }

    // ---- Get classes for a department (edit modal) ----
    if ($action === 'get_classes') {
        $dept = (int)($_GET['department_id'] ?? 0);
        $out = [];
        if ($dept > 0) {
            $s = $conn->prepare("SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name");
            $s->bind_param("i", $dept);
            $s->execute();
            $out = $s->get_result()->fetch_all(MYSQLI_ASSOC);
            $s->close();
        }
        echo json_encode(['classes'=>$out]);
        exit();
    }

    // ---- Get subjects for a department ----
    if ($action === 'get_subjects') {
        $dept = (int)($_GET['department_id'] ?? 0);
        $out = [];
        if ($dept > 0) {
            $s = $conn->prepare("SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name");
            $s->bind_param("i", $dept);
            $s->execute();
            $out = $s->get_result()->fetch_all(MYSQLI_ASSOC);
            $s->close();
        }
        echo json_encode(['subjects'=>$out]);
        exit();
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit();
}

// =====================================================================================
// PAGE DATA
// =====================================================================================

// Determine current or next upcoming term
$default_term_id = 0;
$default_year = '';
$default_year_start = null;
$default_year_end = null;

$cur = $conn->query("SELECT id, year, start_date, end_date FROM academic_calendar WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1");
if ($cur && $cur->num_rows > 0) {
    $r = $cur->fetch_assoc();
    $default_term_id = (int)$r['id'];
    $default_year = $r['year'];
    $default_year_start = $r['start_date'];
    $default_year_end = $r['end_date'];
}
if (!$default_term_id) {
    $nxt = $conn->query("SELECT id, year, start_date, end_date FROM academic_calendar WHERE start_date > CURDATE() ORDER BY start_date ASC LIMIT 1");
    if ($nxt && $nxt->num_rows > 0) {
        $r = $nxt->fetch_assoc();
        $default_term_id = (int)$r['id'];
        $default_year = $r['year'];
        $default_year_start = $r['start_date'];
        $default_year_end = $r['end_date'];
    }
}
if (!$default_term_id) {
    $last = $conn->query("SELECT id, year, start_date, end_date FROM academic_calendar ORDER BY start_date DESC LIMIT 1");
    if ($last && $last->num_rows > 0) {
        $r = $last->fetch_assoc();
        $default_term_id = (int)$r['id'];
        $default_year = $r['year'];
        $default_year_start = $r['start_date'];
        $default_year_end = $r['end_date'];
    }
}

// All terms for the edit modal
$terms = [];
$tq = $conn->query("SELECT id, term_name, year FROM academic_calendar ORDER BY year DESC, start_date ASC");
while ($tq && $row = $tq->fetch_assoc()) $terms[] = $row;

// Departments (for edit modal cascading)
$departments = [];
$dq = $conn->query("SELECT id, name FROM departments WHERE name LIKE '%Upper Primary%' OR name LIKE '%Primary%' OR name LIKE '%Junior High%' OR name LIKE '%JHS%' ORDER BY name");
while ($dq && $row = $dq->fetch_assoc()) $departments[] = $row;

// ---- Annual plans (all for teacher, restricted by academic year if available) ----
$annual_plans = [];
if ($default_year_start && $default_year_end) {
    $s = $conn->prepare("SELECT ap.id, ap.week, ap.class_id, ap.subject_id, ap.created_at, c.class_name, s.subject_name
                         FROM annual_plans ap
                         JOIN classes c ON ap.class_id = c.id
                         LEFT JOIN subjects s ON ap.subject_id = s.id
                         WHERE ap.teacher_id = ? AND ap.format = 'ges'
                         AND DATE(ap.created_at) BETWEEN ? AND ?
                         ORDER BY ap.created_at DESC LIMIT 100");
    $s->bind_param("iss", $teacher_id, $default_year_start, $default_year_end);
} else {
    $s = $conn->prepare("SELECT ap.id, ap.week, ap.class_id, ap.subject_id, ap.created_at, c.class_name, s.subject_name
                         FROM annual_plans ap
                         JOIN classes c ON ap.class_id = c.id
                         LEFT JOIN subjects s ON ap.subject_id = s.id
                         WHERE ap.teacher_id = ? AND ap.format = 'ges'
                         ORDER BY ap.created_at DESC LIMIT 100");
    $s->bind_param("i", $teacher_id);
}
$s->execute();
$annual_plans = $s->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$s->close();

// ---- Termly plans (filtered by current term) ----
$termly_plans = [];
if ($default_term_id > 0) {
    $s = $conn->prepare("SELECT tp.id, tp.week, tp.class_id, tp.subject_id, tp.term_id, tp.created_at, c.class_name, s.subject_name, ac.term_name, ac.year AS year_label
                         FROM termly_plans tp
                         JOIN classes c ON tp.class_id = c.id
                         LEFT JOIN subjects s ON tp.subject_id = s.id
                         LEFT JOIN academic_calendar ac ON tp.term_id = ac.id
                         WHERE tp.teacher_id = ? AND tp.format = 'ges' AND tp.term_id = ?
                         ORDER BY tp.created_at DESC LIMIT 100");
    $s->bind_param("ii", $teacher_id, $default_term_id);
    $s->execute();
    $termly_plans = $s->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $s->close();
}

// ---- Weekly plans (filtered by current term) ----
$weekly_plans = [];
if ($default_term_id > 0) {
    $s = $conn->prepare("SELECT tn.id, tn.week, tn.class_id, tn.subject_id, tn.term_id, tn.created_at, c.class_name, s.subject_name, ac.term_name, ac.year AS year_label
                         FROM teacher_notes tn
                         JOIN classes c ON tn.class_id = c.id
                         LEFT JOIN subjects s ON tn.subject_id = s.id
                         LEFT JOIN academic_calendar ac ON tn.term_id = ac.id
                         WHERE tn.teacher_id = ? AND tn.format = 'ges' AND tn.term_id = ?
                         ORDER BY tn.created_at DESC LIMIT 100");
    $s->bind_param("ii", $teacher_id, $default_term_id);
    $s->execute();
    $weekly_plans = $s->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $s->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GES Lesson Plans Dashboard - QueensPa School</title>
<style>
    /* -------------------------------------------------------------
       Design tokens matching teacher_header.php
       - Poppins font (already loaded by header)
       - Primary blue #0ea5e9 / #0284c7
       - Glassmorphism cards
       - Light: #f1f5f9 bg / Dark: #0f172a bg
       ------------------------------------------------------------- */
    :root {
        --primary: #0ea5e9;
        --primary-hover: #0284c7;
        --primary-soft: rgba(14, 165, 233, 0.1);
        --bg: #f1f5f9;
        --card-bg: rgba(255, 255, 255, 0.75);
        --card-border: rgba(255, 255, 255, 0.4);
        --text: #0f172a;
        --muted: #64748b;
        --border: #e2e8f0;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --radius: 14px;
    }
    .dark {
        --bg: #0f172a;
        --card-bg: rgba(15, 23, 42, 0.6);
        --card-border: rgba(255, 255, 255, 0.08);
        --text: #e2e8f0;
        --muted: #94a3b8;
        --border: rgba(255, 255, 255, 0.08);
    }
    * { box-sizing: border-box; }
    body {
        font-family: 'Poppins', ui-sans-serif, system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--text);
        margin: 0;
        line-height: 1.55;
        transition: background 0.3s, color 0.3s;
        -webkit-font-smoothing: antialiased;
    }

    /* Main container (header already opens .main-content, we just wrap) */
    .page-wrap {
        max-width: 1280px;
        margin: 0 auto;
        padding: 1.5rem 1rem 3rem;
    }

    /* Page title */
    .page-title {
        font-size: 1.35rem;
        font-weight: 600;
        margin: 0 0 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .page-title i { color: var(--primary); }
    .page-subtitle {
        color: var(--muted);
        font-size: 0.9rem;
        margin: 0 0 1.25rem;
    }

    /* Context pill (current year/term) */
    .ctx-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--primary-soft);
        color: var(--primary-hover);
        border: 1px solid rgba(14, 165, 233, 0.25);
        padding: 0.35rem 0.85rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 500;
        margin-bottom: 1.25rem;
    }
    .dark .ctx-pill { color: #7dd3fc; }

    /* Create buttons row */
    .create-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .create-btn {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 1rem 1.15rem;
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: var(--radius);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        text-decoration: none;
        color: var(--text);
        transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        cursor: pointer;
    }
    .create-btn:hover {
        transform: translateY(-2px);
        border-color: var(--primary);
        box-shadow: 0 10px 24px -12px rgba(14, 165, 233, 0.35);
    }
    .create-btn .cb-icon {
        width: 42px; height: 42px;
        border-radius: 12px;
        background: var(--primary-soft);
        color: var(--primary-hover);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.05rem;
        flex-shrink: 0;
    }
    .create-btn .cb-text { display: flex; flex-direction: column; min-width: 0; }
    .create-btn .cb-text strong { font-size: 0.95rem; font-weight: 600; }
    .create-btn .cb-text span { font-size: 0.78rem; color: var(--muted); }

    /* Card / glass panel */
    .glass-card {
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: var(--radius);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }
    .glass-card .card-head {
        padding: 0.85rem 1.15rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .glass-card .card-head h2 {
        font-size: 0.95rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .glass-card .card-head h2 i { color: var(--primary); }
    .count-badge {
        background: var(--primary-soft);
        color: var(--primary-hover);
        border-radius: 999px;
        padding: 0.15rem 0.6rem;
        font-size: 0.72rem;
        font-weight: 600;
    }
    .dark .count-badge { color: #7dd3fc; }

    /* Table */
    .table-wrap { overflow-x: auto; }
    table.plan-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
    }
    table.plan-table th, table.plan-table td {
        padding: 0.75rem 0.85rem;
        text-align: left;
        white-space: nowrap;
    }
    table.plan-table thead th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--muted);
        font-weight: 600;
        border-bottom: 1px solid var(--border);
        background: rgba(241, 245, 249, 0.5);
    }
    .dark table.plan-table thead th { background: rgba(30, 41, 59, 0.5); }
    table.plan-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background 0.15s;
    }
    table.plan-table tbody tr:last-child { border-bottom: none; }
    table.plan-table tbody tr:hover { background: var(--primary-soft); }
    .id-pill {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 0.72rem;
        color: var(--muted);
    }
    .week-pill {
        display: inline-block;
        background: var(--primary-soft);
        color: var(--primary-hover);
        padding: 0.15rem 0.55rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 600;
    }
    .dark .week-pill { color: #7dd3fc; }

    /* Row actions */
    .row-actions { display: flex; gap: 0.35rem; justify-content: flex-end; }
    .icon-btn {
        width: 32px; height: 32px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: transparent;
        color: var(--muted);
        display: inline-flex; align-items: center; justify-content: center;
        cursor: pointer;
        font-size: 0.8rem;
        transition: all 0.15s;
    }
    .icon-btn:hover { color: var(--text); background: rgba(241, 245, 249, 0.7); }
    .dark .icon-btn:hover { background: rgba(255, 255, 255, 0.05); }
    .icon-btn.view:hover { color: var(--primary); border-color: var(--primary); }
    .icon-btn.edit:hover { color: var(--warning); border-color: var(--warning); }
    .icon-btn.delete:hover { color: var(--danger); border-color: var(--danger); }

    /* Empty state */
    .empty {
        padding: 2.5rem 1rem;
        text-align: center;
        color: var(--muted);
        font-size: 0.875rem;
    }
    .empty i {
        display: block;
        font-size: 1.8rem;
        margin-bottom: 0.6rem;
        color: var(--border);
    }

    /* Skeleton */
    .sk {
        height: 0.85rem;
        border-radius: 5px;
        background: linear-gradient(90deg, rgba(148,163,184,0.15) 25%, rgba(148,163,184,0.3) 50%, rgba(148,163,184,0.15) 75%);
        background-size: 200% 100%;
        animation: shimmer 1.4s infinite linear;
    }
    .sk.short { width: 40%; }
    .sk.mid { width: 65%; }
    .sk.full { width: 100%; }
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }

    /* Progress bar (top of page during save) */
    #progressBar {
        position: fixed;
        top: 0; left: 0;
        height: 3px;
        background: var(--primary);
        width: 0%;
        z-index: 3000;
        transition: width 0.25s ease;
    }
    #progressBar.active { display: block; }

    /* ============= MODALS ============= */
    .modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        z-index: 2000;
        justify-content: center;
        align-items: flex-start;
        padding: 2rem 1rem;
        overflow-y: auto;
    }
    .modal-backdrop.show { display: flex; }
    .modal-box {
        background: #ffffff;
        border-radius: var(--radius);
        border: 1px solid var(--border);
        width: 100%;
        max-width: 780px;
        margin: auto;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }
    .dark .modal-box { background: #1e293b; }
    .modal-box.wide { max-width: 900px; }
    .modal-head {
        padding: 0.9rem 1.15rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-head h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .modal-head h3 i { color: var(--primary); }
    .modal-close {
        background: none;
        border: none;
        font-size: 1.3rem;
        cursor: pointer;
        color: var(--muted);
        padding: 0.15rem 0.5rem;
        border-radius: 8px;
        line-height: 1;
    }
    .modal-close:hover { background: rgba(148,163,184,0.15); color: var(--text); }
    .modal-body { padding: 1.15rem; max-height: 70vh; overflow-y: auto; }
    .modal-foot {
        padding: 0.85rem 1.15rem;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
    }

    /* Buttons */
    .btn {
        padding: 0.55rem 1.1rem;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        border: 1px solid var(--border);
        background: transparent;
        color: var(--text);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: all 0.15s;
        font-family: inherit;
    }
    .btn:hover { background: rgba(241, 245, 249, 0.7); }
    .dark .btn:hover { background: rgba(255, 255, 255, 0.05); }
    .btn-primary {
        background: var(--primary);
        border-color: var(--primary);
        color: #ffffff;
    }
    .btn-primary:hover {
        background: var(--primary-hover);
        border-color: var(--primary-hover);
    }
    .btn-danger {
        background: var(--danger);
        border-color: var(--danger);
        color: #ffffff;
    }
    .btn-danger:hover {
        background: #dc2626;
        border-color: #dc2626;
    }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }

    /* Form fields */
    .field-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.85rem;
    }
    .field { display: flex; flex-direction: column; gap: 0.3rem; }
    .field.full { grid-column: 1 / -1; }
    .field label {
        font-size: 0.78rem;
        font-weight: 500;
        color: var(--text);
    }
    .field label .req { color: var(--danger); }
    .field input, .field select, .field textarea {
        width: 100%;
        padding: 0.55rem 0.75rem;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 0.875rem;
        font-family: inherit;
        background: transparent;
        color: var(--text);
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .field input:focus, .field select:focus, .field textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.18);
    }
    .field textarea { resize: vertical; min-height: 68px; }

    .section-label {
        grid-column: 1 / -1;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
        padding-top: 0.6rem;
        margin-top: 0.3rem;
        border-top: 1px dashed var(--border);
    }
    .section-label:first-child { border-top: none; margin-top: 0; padding-top: 0; }

    /* View rows */
    .view-row {
        display: grid;
        grid-template-columns: 150px 1fr;
        gap: 0.85rem;
        padding: 0.55rem 0;
        border-bottom: 1px dashed var(--border);
        font-size: 0.875rem;
    }
    .view-row:last-child { border-bottom: none; }
    .view-row .vr-label {
        color: var(--muted);
        font-weight: 500;
        font-size: 0.8rem;
    }
    .view-row .vr-value { white-space: pre-wrap; word-break: break-word; }
    .view-row .vr-value:empty::before { content: "—"; color: var(--muted); }

    /* Toast */
    #toastWrap {
        position: fixed;
        top: 1rem; right: 1rem;
        z-index: 3000;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .toast-item {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.75rem 1rem;
        border-radius: 12px;
        background: #ffffff;
        border: 1px solid var(--border);
        box-shadow: 0 12px 30px -10px rgba(0,0,0,0.15);
        font-size: 0.85rem;
        min-width: 240px;
        animation: slideIn 0.22s ease-out;
    }
    .dark .toast-item { background: #1e293b; }
    .toast-item.success { border-left: 3px solid var(--success); }
    .toast-item.error { border-left: 3px solid var(--danger); }
    .toast-item.success i { color: var(--success); }
    .toast-item.error i { color: var(--danger); }
    @keyframes slideIn {
        from { opacity: 0; transform: translateX(20px); }
        to { opacity: 1; transform: translateX(0); }
    }

    /* Responsive */
    @media (max-width: 900px) {
        .create-row { grid-template-columns: 1fr; }
        .field-grid { grid-template-columns: 1fr; }
        .view-row { grid-template-columns: 1fr; gap: 0.2rem; }
    }
    @media (max-width: 640px) {
        .page-wrap { padding: 1rem 0.6rem 3rem; }
        table.plan-table th, table.plan-table td { padding: 0.55rem 0.5rem; font-size: 0.8rem; }
        .modal-backdrop { padding: 1rem 0.5rem; }
    }
</style>
</head>
<body>

<div id="progressBar"></div>
<div id="toastWrap"></div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

<div class="page-wrap">

    <h1 class="page-title"><i class="fas fa-book-open"></i> GES Lesson Plans</h1>
    <p class="page-subtitle">Manage annual, termly, and weekly lesson plans. View and edit any plan inline.</p>

    <?php if ($default_year || $default_term_id): ?>
    <div class="ctx-pill">
        <i class="fas fa-calendar-alt"></i>
        <?php
            $ctx_parts = [];
            if ($default_year) $ctx_parts[] = 'Academic Year: ' . htmlspecialchars($default_year);
            foreach ($terms as $t) {
                if ((int)$t['id'] === $default_term_id) {
                    $ctx_parts[] = $t['term_name'] . ' ' . $t['year'];
                    break;
                }
            }
            echo htmlspecialchars(implode('  •  ', $ctx_parts));
        ?>
    </div>
    <?php endif; ?>

    <!-- Create buttons -->
    <div class="create-row">
        <a href="/teacher/annual_plan_ges.php" class="create-btn">
            <div class="cb-icon"><i class="fas fa-calendar-alt"></i></div>
            <div class="cb-text">
                <strong>Create Annual Plan</strong>
                <span>Term 1–3 strands per week</span>
            </div>
        </a>
        <a href="/teacher/termly_plan_ges.php" class="create-btn">
            <div class="cb-icon"><i class="fas fa-calendar-week"></i></div>
            <div class="cb-text">
                <strong>Create Termly Plan</strong>
                <span>Unit, topic, lesson per week</span>
            </div>
        </a>
        <a href="/teacher/weekly_plan_ges.php" class="create-btn">
            <div class="cb-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="cb-text">
                <strong>Create Weekly Plan</strong>
                <span>Full lesson plan per week</span>
            </div>
        </a>
    </div>

    <!-- ==================== ANNUAL PLANS ==================== -->
    <div class="glass-card">
        <div class="card-head">
            <h2><i class="fas fa-calendar-alt"></i> Annual Plans <span class="count-badge"><?= count($annual_plans) ?></span></h2>
        </div>
        <?php if (empty($annual_plans)): ?>
            <div class="empty"><i class="fas fa-inbox"></i>No annual plans yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="plan-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Week</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($annual_plans as $p): ?>
                            <tr data-type="annual" data-id="<?= (int)$p['id'] ?>">
                                <td><span class="id-pill">#<?= (int)$p['id'] ?></span></td>
                                <td><?= htmlspecialchars($p['class_name']) ?></td>
                                <td><?= htmlspecialchars($p['subject_name'] ?? '—') ?></td>
                                <td><span class="week-pill">W<?= (int)$p['week'] ?></span></td>
                                <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button class="icon-btn view" title="View" onclick="viewPlan('annual',<?= (int)$p['id'] ?>)"><i class="fas fa-eye"></i></button>
                                        <button class="icon-btn edit" title="Edit" onclick="editPlan('annual',<?= (int)$p['id'] ?>)"><i class="fas fa-pen"></i></button>
                                        <button class="icon-btn delete" title="Delete" onclick="deletePlan('annual',<?= (int)$p['id'] ?>)"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== TERMLY PLANS ==================== -->
    <div class="glass-card">
        <div class="card-head">
            <h2><i class="fas fa-calendar-week"></i> Termly Plans (<?= htmlspecialchars($default_year ?: 'current') ?>) <span class="count-badge"><?= count($termly_plans) ?></span></h2>
        </div>
        <?php if (empty($termly_plans)): ?>
            <div class="empty"><i class="fas fa-inbox"></i>No termly plans for the current term yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="plan-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Term</th>
                            <th>Week</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($termly_plans as $p): ?>
                            <tr data-type="termly" data-id="<?= (int)$p['id'] ?>">
                                <td><span class="id-pill">#<?= (int)$p['id'] ?></span></td>
                                <td><?= htmlspecialchars($p['class_name']) ?></td>
                                <td><?= htmlspecialchars($p['subject_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($p['term_name'] ?? '—') ?></td>
                                <td><span class="week-pill">W<?= (int)$p['week'] ?></span></td>
                                <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button class="icon-btn view" title="View" onclick="viewPlan('termly',<?= (int)$p['id'] ?>)"><i class="fas fa-eye"></i></button>
                                        <button class="icon-btn edit" title="Edit" onclick="editPlan('termly',<?= (int)$p['id'] ?>)"><i class="fas fa-pen"></i></button>
                                        <button class="icon-btn delete" title="Delete" onclick="deletePlan('termly',<?= (int)$p['id'] ?>)"><i class="fas fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== WEEKLY PLANS ==================== -->
    <div class="glass-card">
        <div class="card-head">
            <h2><i class="fas fa-chalkboard-teacher"></i> Weekly Plans (<?= htmlspecialchars($default_year ?: 'current') ?>) <span class="count-badge"><?= count($weekly_plans) ?></span></h2>
        </div>
        <?php if (empty($weekly_plans)): ?>
            <div class="empty"><i class="fas fa-inbox"></i>No weekly plans for the current term yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="plan-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Term</th>
                            <th>Week</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weekly_plans as $p): ?>
                            <tr data-type="weekly" data-id="<?= (int)$p['id'] ?>">
                                <td><span class="id-pill">#<?= (int)$p['id'] ?></span></td>
                                <td><?= htmlspecialchars($p['class_name']) ?></td>
                                <td><?= htmlspecialchars($p['subject_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($p['term_name'] ?? '—') ?></td>
                                <td><span class="week-pill">W<?= (int)$p['week'] ?></span></td>
                                <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button class="icon-btn view" title="View" onclick="viewPlan('weekly',<?= (int)$p['id'] ?>)"><i class="fas fa-eye"></i></button>
                                        <button class="icon-btn edit" title="Edit" onclick="editPlan('weekly',<?= (int)$p['id'] ?>)"><i class="fas fa-pen"></i></button>
                                        <button class="icon-btn delete" title="Delete" onclick="deletePlan('weekly',<?= (int)$p['id'] ?>)"><i class="fas fa-trash"></i></button>
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

<!-- ==================== VIEW MODAL ==================== -->
<div class="modal-backdrop" id="viewModal">
    <div class="modal-box wide">
        <div class="modal-head">
            <h3 id="viewTitle"><i class="fas fa-eye"></i> Plan Details</h3>
            <button class="modal-close" onclick="closeModal('viewModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="viewBody">
            <div class="sk mid" style="margin-bottom:.5rem;"></div>
            <div class="sk full" style="margin-bottom:.5rem;"></div>
            <div class="sk short"></div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- ==================== EDIT MODAL ==================== -->
<div class="modal-backdrop" id="editModal">
    <div class="modal-box wide">
        <div class="modal-head">
            <h3 id="editTitle"><i class="fas fa-pen"></i> Edit Plan</h3>
            <button class="modal-close" onclick="closeModal('editModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editForm" onsubmit="return false;">
                <input type="hidden" id="editType" value="">
                <input type="hidden" id="editId" value="">
                <div class="field-grid" id="editFields">
                    <div class="sk full" style="grid-column:1/-1;margin-bottom:.5rem;"></div>
                    <div class="sk mid"></div>
                    <div class="sk short"></div>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('editModal')">Cancel</button>
            <button class="btn btn-primary" id="editSaveBtn" onclick="saveEdit()"><i class="fas fa-check"></i> Save Changes</button>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

<script>
(function() {
    'use strict';
    const CSRF = <?= json_encode($csrf_token) ?>;
    const DEPARTMENTS = <?= json_encode($departments) ?>;
    const TERMS = <?= json_encode($terms) ?>;
    let currentEdit = { type: '', id: 0, plan: null };

    // ---------- Progress bar ----------
    function progressStart() {
        const p = document.getElementById('progressBar');
        p.style.width = '0%';
        let w = 0;
        const t = setInterval(() => { w += 10; if (w > 88) clearInterval(t); p.style.width = Math.min(w, 88) + '%'; }, 100);
        return t;
    }
    function progressDone(t) {
        clearInterval(t);
        const p = document.getElementById('progressBar');
        p.style.width = '100%';
        setTimeout(() => { p.style.width = '0%'; }, 350);
    }

    // ---------- Toast ----------
    function toast(msg, type) {
        const wrap = document.getElementById('toastWrap');
        const el = document.createElement('div');
        el.className = 'toast-item ' + (type || 'success');
        el.innerHTML = `<i class="fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}"></i><span></span>`;
        el.querySelector('span').textContent = msg;
        wrap.appendChild(el);
        setTimeout(() => {
            el.style.transition = 'opacity .25s, transform .25s';
            el.style.opacity = '0';
            el.style.transform = 'translateX(20px)';
            setTimeout(() => el.remove(), 260);
        }, 2800);
    }

    // ---------- Modal helpers ----------
    function openModal(id) { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }
    document.querySelectorAll('.modal-backdrop').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.show').forEach(m => m.classList.remove('show'));
    });

    function esc(s) {
        if (s === null || s === undefined) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ---------- VIEW ----------
    function viewPlan(type, id) {
        openModal('viewModal');
        document.getElementById('viewBody').innerHTML =
            '<div class="sk mid" style="margin-bottom:.5rem;"></div>' +
            '<div class="sk full" style="margin-bottom:.5rem;"></div>' +
            '<div class="sk short"></div>';
        const titles = { annual: 'Annual Plan', termly: 'Termly Plan', weekly: 'Weekly Plan' };
        document.getElementById('viewTitle').innerHTML = `<i class="fas fa-eye"></i> ${titles[type]} Details`;

        fetch(`?action=get_plan&type=${encodeURIComponent(type)}&id=${id}`)
            .then(r => r.json())
            .then(res => {
                if (!res.success) { toast(res.error || 'Failed to load', 'error'); closeModal('viewModal'); return; }
                renderView(type, res.plan);
            })
            .catch(() => { toast('Network error', 'error'); closeModal('viewModal'); });
    }

    function renderView(type, p) {
        const row = (label, val) => `<div class="view-row"><div class="vr-label">${esc(label)}</div><div class="vr-value">${esc(val)}</div></div>`;
        let html = '';
        html += row('Record ID', '#' + p.id);
        html += row('Class', p.class_name || p.class_id);
        html += row('Subject', p.subject_name || p.subject_id);
        if (type !== 'annual') html += row('Term', p.term_name ? p.term_name + ' ' + (p.year_label || '') : p.term_id);
        html += row('Week', 'Week ' + p.week);
        if (type === 'annual') {
            html += row('Term 1 Strands', p.term_1);
            html += row('Term 2 Strands', p.term_2);
            html += row('Term 3 Strands', p.term_3);
        } else if (type === 'termly') {
            html += row('Unit / Strand', p.unit);
            html += row('Topic / Sub-Strand', p.topic);
            html += row('Lesson Details', p.lesson);
        } else {
            html += row('Strand', p.strand);
            html += row('Sub-Strand', p.sub_strand);
            html += row('Content Standard', p.content_standard);
            html += row('Indicator', p.indicator);
            html += row('Objectives', p.objectives);
            html += row('Reference', p.reference);
            html += row('Resources', p.resources);
            html += row('Keywords', p.keywords);
            html += row('Starter', p.starter);
            html += row('Presentation', p.presentation);
            html += row('Summary', p.summary);
        }
        html += row('Created', p.created_at);
        document.getElementById('viewBody').innerHTML = html;
    }

    // ---------- EDIT ----------
    function editPlan(type, id) {
        openModal('editModal');
        const titles = { annual: 'Edit Annual Plan', termly: 'Edit Termly Plan', weekly: 'Edit Weekly Plan' };
        document.getElementById('editTitle').innerHTML = `<i class="fas fa-pen"></i> ${titles[type]}`;
        document.getElementById('editFields').innerHTML =
            '<div class="sk full" style="grid-column:1/-1;margin-bottom:.5rem;"></div>' +
            '<div class="sk mid"></div><div class="sk short"></div>';

        fetch(`?action=get_plan&type=${encodeURIComponent(type)}&id=${id}`)
            .then(r => r.json())
            .then(res => {
                if (!res.success) { toast(res.error || 'Failed to load', 'error'); closeModal('editModal'); return; }
                currentEdit = { type, id, plan: res.plan };
                buildEditForm(type, res.plan);
            })
            .catch(() => { toast('Network error', 'error'); closeModal('editModal'); });
    }

    function buildEditForm(type, p) {
        document.getElementById('editType').value = type;
        document.getElementById('editId').value = p.id;

        const wrap = document.getElementById('editFields');
        wrap.innerHTML = '';
        const addField = (label, html, full) => {
            const div = document.createElement('div');
            div.className = 'field' + (full ? ' full' : '');
            div.innerHTML = `<label>${label}</label>${html}`;
            wrap.appendChild(div);
        };

        // Class + Subject + Week
        addField('Class <span class="req">*</span>', `<select id="editClass"><option value="">Loading classes…</option></select>`);
        addField('Subject', `<select id="editSubject"><option value="">Loading subjects…</option></select>`);
        addField('Week <span class="req">*</span>', `<input type="number" id="editWeek" min="1" max="16" value="${esc(p.week)}" required>`);

        if (type !== 'annual') {
            addField('Term <span class="req">*</span>',
                `<select id="editTerm">${TERMS.map(t => `<option value="${t.id}">${esc(t.term_name + ' ' + t.year)}</option>`).join('')}</select>`);
        } else {
            addField('Term (not applicable)', `<input type="text" disabled value="Annual plan covers all terms" />`);
        }

        // Type-specific content
        if (type === 'annual') {
            const lbl = document.createElement('div'); lbl.className = 'section-label'; lbl.textContent = 'Term Strands'; wrap.appendChild(lbl);
            addField('Term 1 Strands', `<textarea id="editTerm1">${esc(p.term_1 || '')}</textarea>`, true);
            addField('Term 2 Strands', `<textarea id="editTerm2">${esc(p.term_2 || '')}</textarea>`, true);
            addField('Term 3 Strands', `<textarea id="editTerm3">${esc(p.term_3 || '')}</textarea>`, true);
        } else if (type === 'termly') {
            const lbl = document.createElement('div'); lbl.className = 'section-label'; lbl.textContent = 'Termly Content'; wrap.appendChild(lbl);
            addField('Unit / Strand', `<input type="text" id="editUnit" value="${esc(p.unit || '')}">`, true);
            addField('Topic / Sub-Strand', `<input type="text" id="editTopic" value="${esc(p.topic || '')}">`, true);
            addField('Lesson Details', `<textarea id="editLesson">${esc(p.lesson || '')}</textarea>`, true);
        } else {
            const lbl = document.createElement('div'); lbl.className = 'section-label'; lbl.textContent = 'Curriculum'; wrap.appendChild(lbl);
            addField('Strand', `<input type="text" id="editStrand" value="${esc(p.strand || '')}">`);
            addField('Sub-Strand', `<input type="text" id="editSubStrand" value="${esc(p.sub_strand || '')}">`);
            addField('Content Standard', `<input type="text" id="editContentStandard" value="${esc(p.content_standard || '')}">`, true);
            addField('Indicator', `<input type="text" id="editIndicator" value="${esc(p.indicator || '')}">`, true);
            addField('Objectives', `<textarea id="editObjectives">${esc(p.objectives || '')}</textarea>`, true);
            addField('Reference', `<textarea id="editReference">${esc(p.reference || '')}</textarea>`, true);
            addField('Resources', `<textarea id="editResources">${esc(p.resources || '')}</textarea>`, true);
            addField('Keywords', `<input type="text" id="editKeywords" value="${esc(p.keywords || '')}">`, true);
            addField('Starter', `<textarea id="editStarter">${esc(p.starter || '')}</textarea>`, true);
            addField('Presentation', `<textarea id="editPresentation">${esc(p.presentation || '')}</textarea>`, true);
            addField('Summary', `<textarea id="editSummary">${esc(p.summary || '')}</textarea>`, true);
        }

        // Prefill class/subject — need to find department from class
        prefillClassAndSubject(type, p.class_id, p.subject_id);

        // Prefill term
        if (type !== 'annual' && p.term_id) {
            const termSel = document.getElementById('editTerm');
            if (termSel) termSel.value = p.term_id;
        }
    }

    function prefillClassAndSubject(type, classId, subjectId) {
        // Try each department until we find the class
        let found = false;
        if (!DEPARTMENTS.length) return;
        let checked = 0;
        DEPARTMENTS.forEach(d => {
            fetch(`?action=get_classes&department_id=${d.id}`)
                .then(r => r.json())
                .then(res => {
                    checked++;
                    if (found) return;
                    const has = (res.classes || []).some(c => parseInt(c.id) === parseInt(classId));
                    if (has) {
                        found = true;
                        populateClassAndSubject(d.id, classId, subjectId);
                    } else if (checked === DEPARTMENTS.length && !found) {
                        // Fallback: load without preselect
                        populateClassAndSubject(d.id, null, null);
                    }
                })
                .catch(() => {});
        });
    }

    function populateClassAndSubject(deptId, preselectClass, preselectSubject) {
        Promise.all([
            fetch(`?action=get_classes&department_id=${deptId}`).then(r => r.json()),
            fetch(`?action=get_subjects&department_id=${deptId}`).then(r => r.json())
        ]).then(([c, s]) => {
            const cSel = document.getElementById('editClass');
            const sSel = document.getElementById('editSubject');
            if (!cSel || !sSel) return;
            cSel.innerHTML = '<option value="">Select class</option>' +
                (c.classes || []).map(x => `<option value="${x.id}">${esc(x.class_name)}</option>`).join('');
            sSel.innerHTML = '<option value="">Select subject</option>' +
                (s.subjects || []).map(x => `<option value="${x.id}">${esc(x.subject_name)}</option>`).join('');
            if (preselectClass) cSel.value = preselectClass;
            if (preselectSubject) sSel.value = preselectSubject;
        }).catch(() => {});
    }

    // ---------- SAVE ----------
    function saveEdit() {
        const type = document.getElementById('editType').value;
        const id = parseInt(document.getElementById('editId').value);
        const classId = parseInt(document.getElementById('editClass').value);
        const subjectId = parseInt(document.getElementById('editSubject').value) || 0;
        const week = parseInt(document.getElementById('editWeek').value);

        if (!classId) { toast('Please select a class.', 'error'); return; }
        if (!week || week < 1 || week > 16) { toast('Week must be between 1 and 16.', 'error'); return; }

        const fd = new FormData();
        fd.append('action', 'update_plan');
        fd.append('csrf_token', CSRF);
        fd.append('type', type);
        fd.append('id', id);
        fd.append('class_id', classId);
        fd.append('subject_id', subjectId);
        fd.append('week', week);

        const termEl = document.getElementById('editTerm');
        if (type !== 'annual' && termEl) fd.append('term_id', termEl.value);

        if (type === 'annual') {
            fd.append('term_1', document.getElementById('editTerm1').value);
            fd.append('term_2', document.getElementById('editTerm2').value);
            fd.append('term_3', document.getElementById('editTerm3').value);
        } else if (type === 'termly') {
            fd.append('unit', document.getElementById('editUnit').value);
            fd.append('topic', document.getElementById('editTopic').value);
            fd.append('lesson', document.getElementById('editLesson').value);
        } else {
            fd.append('strand', document.getElementById('editStrand').value);
            fd.append('sub_strand', document.getElementById('editSubStrand').value);
            fd.append('content_standard', document.getElementById('editContentStandard').value);
            fd.append('indicator', document.getElementById('editIndicator').value);
            fd.append('objectives', document.getElementById('editObjectives').value);
            fd.append('reference', document.getElementById('editReference').value);
            fd.append('resources', document.getElementById('editResources').value);
            fd.append('keywords', document.getElementById('editKeywords').value);
            fd.append('starter', document.getElementById('editStarter').value);
            fd.append('presentation', document.getElementById('editPresentation').value);
            fd.append('summary', document.getElementById('editSummary').value);
        }

        const btn = document.getElementById('editSaveBtn');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        const t = progressStart();

        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                progressDone(t);
                btn.disabled = false;
                btn.innerHTML = orig;
                if (res.success) {
                    toast('Plan updated successfully');
                    closeModal('editModal');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast(res.error || 'Update failed', 'error');
                }
            })
            .catch(() => {
                progressDone(t);
                btn.disabled = false;
                btn.innerHTML = orig;
                toast('Network error. Please try again.', 'error');
            });
    }

    // ---------- DELETE ----------
    function deletePlan(type, id) {
        if (!confirm('Delete this plan permanently? This cannot be undone.')) return;

        const fd = new FormData();
        fd.append('action', 'delete_plan');
        fd.append('csrf_token', CSRF);
        fd.append('type', type);
        fd.append('id', id);

        const t = progressStart();
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                progressDone(t);
                if (res.success) {
                    toast('Plan deleted');
                    const row = document.querySelector(`tr[data-type="${type}"][data-id="${id}"]`);
                    if (row) row.remove();
                } else {
                    toast(res.error || 'Delete failed', 'error');
                }
            })
            .catch(() => { progressDone(t); toast('Network error', 'error'); });
    }

    // Expose to inline onclick handlers
    window.viewPlan = viewPlan;
    window.editPlan = editPlan;
    window.deletePlan = deletePlan;
    window.closeModal = closeModal;
    window.saveEdit = saveEdit;
})();
</script>
</body>
</html>