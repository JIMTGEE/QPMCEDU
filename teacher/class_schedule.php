<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: /teacher/login.php");
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
if (!$conn) die("Connection failed: " . mysqli_connect_error());

$teacher_id = (int)$_SESSION['user_id'];
date_default_timezone_set('Africa/Accra');

function jsonOut($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// ---------- AJAX Endpoints ----------
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action) {

    // Get classes for department
    if ($action === 'get_classes') {
        $dept_id = (int)($_GET['department_id'] ?? 0);
        $out = [];
        if ($dept_id > 0) {
            $stmt = $conn->prepare("SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name");
            $stmt->bind_param("i", $dept_id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $out[] = $row;
            $stmt->close();
        }
        jsonOut(['classes' => $out]);
    }

    // Get subjects for department
    if ($action === 'get_subjects') {
        $dept_id = (int)($_GET['department_id'] ?? 0);
        $out = [];
        if ($dept_id > 0) {
            $stmt = $conn->prepare("SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name");
            $stmt->bind_param("i", $dept_id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) $out[] = $row;
            $stmt->close();
        }
        jsonOut(['subjects' => $out]);
    }

    // Save a new schedule entry
    if ($action === 'save_schedule') {
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $day = trim($_POST['day'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $end = trim($_POST['end_time'] ?? '');
        $term_id = (int)($_POST['term_id'] ?? 0);
        $academic_year = trim($_POST['academic_year'] ?? '');

        if (!$class_id || !$subject_id || !$department_id || !$day || !$start || !$end || !$academic_year) {
            jsonOut(['success' => false, 'error' => 'Please fill in all fields.']);
        }
        if (strtotime($end) <= strtotime($start)) {
            jsonOut(['success' => false, 'error' => 'End time must be after start time.']);
        }

        $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
        if (!in_array($day, $valid_days)) {
            jsonOut(['success' => false, 'error' => 'Invalid day.']);
        }

        $stmt = $conn->prepare("INSERT INTO class_schedules (class_id, day_of_week, start_time, end_time, subject_id, term_id, department_id, teacher_id, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssiiiis", $class_id, $day, $start, $end, $subject_id, $term_id, $department_id, $teacher_id, $academic_year);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $cn = $conn->query("SELECT class_name FROM classes WHERE id=$class_id")->fetch_assoc()['class_name'] ?? '';
            $sn = $conn->query("SELECT subject_name FROM subjects WHERE id=$subject_id")->fetch_assoc()['subject_name'] ?? '';
            $dn = $conn->query("SELECT name FROM departments WHERE id=$department_id")->fetch_assoc()['name'] ?? '';
            jsonOut(['success' => true, 'id' => $new_id, 'class_name' => $cn, 'subject_name' => $sn, 'department_name' => $dn]);
        } else {
            jsonOut(['success' => false, 'error' => $conn->error]);
        }
    }

    // Update existing schedule
    if ($action === 'update_schedule') {
        $id = (int)($_POST['id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $day = trim($_POST['day'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $end = trim($_POST['end_time'] ?? '');

        if (!$id || !$class_id || !$subject_id || !$department_id || !$day || !$start || !$end) {
            jsonOut(['success' => false, 'error' => 'Please fill in all fields.']);
        }
        if (strtotime($end) <= strtotime($start)) {
            jsonOut(['success' => false, 'error' => 'End time must be after start time.']);
        }

        // Verify ownership
        $check = $conn->prepare("SELECT id FROM class_schedules WHERE id = ? AND teacher_id = ?");
        $check->bind_param("ii", $id, $teacher_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            jsonOut(['success' => false, 'error' => 'Entry not found or not yours.']);
        }
        $check->close();

        $stmt = $conn->prepare("UPDATE class_schedules SET class_id=?, day_of_week=?, start_time=?, end_time=?, subject_id=?, department_id=?, updated_at=NOW() WHERE id=? AND teacher_id=?");
        $stmt->bind_param("isssiiii", $class_id, $day, $start, $end, $subject_id, $department_id, $id, $teacher_id);

        if ($stmt->execute()) {
            jsonOut(['success' => true]);
        } else {
            jsonOut(['success' => false, 'error' => $conn->error]);
        }
    }

    // Delete schedule
    if ($action === 'delete_schedule') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonOut(['success' => false, 'error' => 'Missing id.']);

        $stmt = $conn->prepare("DELETE FROM class_schedules WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("ii", $id, $teacher_id);
        if ($stmt->execute()) {
            jsonOut(['success' => true]);
        } else {
            jsonOut(['success' => false, 'error' => $conn->error]);
        }
    }

    jsonOut(['error' => 'Unknown action']);
}

// ---------- Page Data ----------
$days_of_week = ['Monday','Tuesday','Wednesday','Thursday','Friday'];

// Academic year
$ay_q = $conn->query("SELECT `year` FROM academic_calendar WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY created_at DESC LIMIT 1");
$academic_year = ($ay_q && $ay_q->num_rows > 0) ? $ay_q->fetch_assoc()['year'] : date('Y').'/'.(date('Y')+1);

// Current term (fallback to first available)
$term_id = 0;
$tq = $conn->query("SELECT id FROM academic_terms WHERE is_active = 1 AND CURDATE() BETWEEN start_date AND end_date LIMIT 1");
if ($tq && $tq->num_rows > 0) {
    $term_id = (int)$tq->fetch_assoc()['id'];
} else {
    $tq2 = $conn->query("SELECT id FROM academic_terms ORDER BY id ASC LIMIT 1");
    if ($tq2 && $tq2->num_rows > 0) $term_id = (int)$tq2->fetch_assoc()['id'];
}

// Departments
$departments = [];
$d = $conn->query("SELECT id, name FROM departments ORDER BY name");
while ($d && $row = $d->fetch_assoc()) $departments[] = $row;

// Teacher's schedules for current academic year
$schedules = [];
$sql = "SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time, cs.class_id, cs.subject_id, cs.department_id,
               c.class_name, s.subject_name, d.name AS department_name
        FROM class_schedules cs
        JOIN classes c ON cs.class_id = c.id
        LEFT JOIN subjects s ON cs.subject_id = s.id
        LEFT JOIN departments d ON cs.department_id = d.id
        WHERE cs.teacher_id = ? AND cs.academic_year = ?
        ORDER BY cs.start_time ASC, cs.day_of_week ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $teacher_id, $academic_year);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $schedules[] = $row;
$stmt->close();

// Build time slots + grid
$time_slots = [];
foreach ($schedules as $s) {
    $key = substr($s['start_time'], 0, 5) . '-' . substr($s['end_time'], 0, 5);
    if (!isset($time_slots[$key])) {
        $time_slots[$key] = [
            'start' => substr($s['start_time'], 0, 5),
            'end' => substr($s['end_time'], 0, 5),
            'sort' => $s['start_time']
        ];
    }
}
uasort($time_slots, fn($a, $b) => strcmp($a['sort'], $b['sort']));

$grid = [];
foreach ($schedules as $s) {
    $key = substr($s['start_time'], 0, 5) . '-' . substr($s['end_time'], 0, 5);
    $grid[$s['day_of_week']][$key][] = $s;
}

function fmtTime($t) {
    return date('g:i A', strtotime($t));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Timetable – QueensPa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
    :root {
        --bg: #f7f8fa;
        --card: #ffffff;
        --border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
        --accent: #0f766e;
        --accent-soft: #ccfbf1;
        --accent-hover: #115e59;
        --danger: #dc2626;
        --warning: #b45309;
        --radius: 4px;
    }
    * { box-sizing: border-box; }
    body {
        background: var(--bg);
        color: var(--text);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        line-height: 1.5;
        padding-bottom: 3rem;
    }
    .page-wrap { max-width: 1400px; margin: 0 auto; padding: 1.5rem 1rem; }

    /* Toolbar */
    .toolbar {
        display: flex; flex-wrap: wrap; justify-content: space-between;
        align-items: center; gap: 1rem;
        background: var(--card); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 1rem 1.25rem;
        margin-bottom: 1rem;
    }
    .toolbar h1 { font-size: 1.25rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .toolbar h1 i { color: var(--accent); }
    .year-badge {
        display: inline-block; font-size: 0.75rem; font-weight: 500;
        background: var(--accent-soft); color: var(--accent-hover);
        padding: 0.2rem 0.6rem; border-radius: 999px; margin-left: 0.5rem;
    }
    .toolbar-right { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .btn-accent {
        background: var(--accent); color: #fff; border: none;
        padding: 0.5rem 1rem; border-radius: var(--radius);
        font-size: 0.875rem; font-weight: 500; cursor: pointer;
        display: inline-flex; align-items: center; gap: 0.4rem;
        transition: background 0.15s;
    }
    .btn-accent:hover { background: var(--accent-hover); }
    .btn-outline {
        background: transparent; color: var(--text);
        border: 1px solid var(--border);
        padding: 0.5rem 1rem; border-radius: var(--radius);
        font-size: 0.875rem; cursor: pointer; display: inline-flex;
        align-items: center; gap: 0.4rem;
    }
    .btn-outline:hover { background: #f3f4f6; }

    /* Status bar */
    #statusBar {
        display: none; align-items: center; gap: 0.5rem;
        background: #f0fdf4; color: #166534;
        border: 1px solid #bbf7d0; padding: 0.5rem 0.9rem;
        border-radius: var(--radius); margin-bottom: 0.75rem;
        font-size: 0.875rem;
    }
    #statusBar.error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    #statusBar.show { display: flex; }

    /* Timetable */
    .timetable-card {
        background: var(--card); border: 1px solid var(--border);
        border-radius: var(--radius); overflow: hidden;
    }
    .timetable-scroll { overflow-x: auto; }
    table.timetable {
        width: 100%; border-collapse: collapse; min-width: 780px;
        table-layout: fixed;
    }
    table.timetable th, table.timetable td {
        border: 1px solid var(--border); padding: 0.5rem;
        vertical-align: top; font-size: 0.875rem;
    }
    table.timetable thead th {
        background: #f1f5f9; font-weight: 600;
        text-align: center; color: var(--text);
        padding: 0.75rem 0.5rem; white-space: nowrap;
    }
    .day-col {
        width: 110px; background: #f8fafc !important;
        font-weight: 600; text-align: center !important;
        vertical-align: middle !important;
    }
    .cell-slot {
        min-height: 90px; position: relative;
        background: #fff; transition: background 0.1s;
    }
    .cell-slot:hover { background: #fafbfc; }

    /* Entry card inside cell */
    .entry {
        background: var(--accent-soft);
        border: 1px solid #5eead4;
        border-radius: var(--radius);
        padding: 0.5rem 0.6rem;
        margin-bottom: 0.35rem;
        cursor: pointer; position: relative;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .entry:hover {
        border-color: var(--accent);
        box-shadow: 0 1px 3px rgba(15,118,110,0.15);
    }
    .entry .entry-class {
        font-weight: 600; font-size: 0.85rem;
        color: var(--accent-hover); display: block;
        line-height: 1.25;
    }
    .entry .entry-subject {
        font-size: 0.78rem; color: #475569;
        display: block; margin-top: 0.1rem;
        line-height: 1.25;
    }
    .entry .entry-id {
        font-size: 0.68rem; color: #94a3b8;
        display: block; margin-top: 0.3rem;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .entry .entry-actions {
        position: absolute; top: 0.25rem; right: 0.25rem;
        display: flex; gap: 0.15rem;
        opacity: 0; transition: opacity 0.15s;
    }
    .entry:hover .entry-actions { opacity: 1; }
    .entry .icon-btn {
        background: #fff; border: 1px solid var(--border);
        width: 22px; height: 22px; border-radius: 3px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.65rem; cursor: pointer; color: var(--muted);
        padding: 0;
    }
    .entry .icon-btn:hover { color: var(--text); background: #f9fafb; }
    .entry .icon-btn.danger:hover { color: var(--danger); border-color: #fca5a5; }

    /* Inline add button */
    .add-inline {
        width: 100%; background: transparent;
        border: 1px dashed #d1d5db; color: #9ca3af;
        border-radius: var(--radius); padding: 0.4rem;
        font-size: 0.85rem; cursor: pointer;
        transition: all 0.15s;
    }
    .add-inline:hover {
        border-color: var(--accent); color: var(--accent);
        background: #f0fdfa;
    }

    /* Empty state */
    .empty-state {
        text-align: center; padding: 3rem 1rem;
        color: var(--muted);
    }
    .empty-state i {
        font-size: 2.5rem; color: #cbd5e1; margin-bottom: 1rem;
        display: block;
    }
    .empty-state h3 { font-size: 1.05rem; color: var(--text); margin-bottom: 0.35rem; }
    .empty-state p { font-size: 0.9rem; margin-bottom: 1.25rem; }

    /* Modal */
    .modal-content { border-radius: var(--radius); border: 1px solid var(--border); }
    .modal-header {
        background: var(--accent); color: #fff;
        border-bottom: none; padding: 0.9rem 1.25rem;
    }
    .modal-header .btn-close { filter: invert(1); opacity: 0.85; }
    .modal-title { font-size: 1rem; font-weight: 600; }
    .modal-body { padding: 1.25rem; }
    .form-label { font-size: 0.8rem; font-weight: 500; color: #374151; margin-bottom: 0.3rem; }
    .form-control, .form-select {
        border-radius: var(--radius); border: 1px solid #d1d5db;
        font-size: 0.9rem; padding: 0.45rem 0.65rem;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(15,118,110,0.12);
    }

    /* Toast */
    #toastWrap {
        position: fixed; top: 1rem; right: 1rem;
        z-index: 2000; display: flex; flex-direction: column;
        gap: 0.5rem; pointer-events: none;
    }
    .toast-item {
        background: #fff; color: var(--text);
        border-left: 3px solid var(--accent);
        border-radius: var(--radius); padding: 0.6rem 0.9rem;
        font-size: 0.875rem; box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        display: flex; align-items: center; gap: 0.5rem;
        pointer-events: auto; animation: slideIn 0.2s ease-out;
        min-width: 220px;
    }
    .toast-item.error { border-left-color: var(--danger); }
    .toast-item i { font-size: 0.9rem; }
    .toast-item.success i { color: var(--accent); }
    .toast-item.error i { color: var(--danger); }
    @keyframes slideIn {
        from { opacity: 0; transform: translateX(20px); }
        to { opacity: 1; transform: translateX(0); }
    }

    /* Small screens */
    @media (max-width: 768px) {
        .page-wrap { padding: 1rem 0.5rem; }
        .toolbar { padding: 0.85rem; }
        .toolbar h1 { font-size: 1.05rem; }
        table.timetable { min-width: 640px; }
        table.timetable th, table.timetable td { padding: 0.35rem; font-size: 0.8rem; }
        .day-col { width: 80px; }
    }
</style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

<div class="page-wrap">

    <!-- Toolbar -->
    <div class="toolbar">
        <div>
            <h1>
                <i class="fas fa-calendar-alt"></i>
                My Timetable
                <span class="year-badge"><?= htmlspecialchars($academic_year) ?></span>
            </h1>
        </div>
        <div class="toolbar-right">
            <button class="btn-outline" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button class="btn-accent" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add Class
            </button>
        </div>
    </div>

    <!-- Status bar -->
    <div id="statusBar"><i class="fas fa-info-circle"></i><span id="statusText"></span></div>

    <!-- Timetable -->
    <div class="timetable-card">
        <?php if (empty($time_slots)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-plus"></i>
                <h3>No classes scheduled yet</h3>
                <p>Add your first class to build your weekly timetable.</p>
                <button class="btn-accent" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add Your First Class
                </button>
            </div>
        <?php else: ?>
            <div class="timetable-scroll">
                <table class="timetable">
                    <thead>
                        <tr>
                            <th class="day-col">Day / Time</th>
                            <?php foreach ($time_slots as $key => $slot): ?>
                                <th>
                                    <?= fmtTime($slot['start']) ?><br>
                                    <span style="font-weight:400;font-size:0.75rem;color:var(--muted);">to <?= fmtTime($slot['end']) ?></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($days_of_week as $day): ?>
                            <tr>
                                <td class="day-col"><?= $day ?></td>
                                <?php foreach ($time_slots as $key => $slot): ?>
                                    <?php
                                        $entries = $grid[$day][$key] ?? [];
                                    ?>
                                    <td class="cell-slot" data-day="<?= $day ?>" data-slot="<?= $key ?>">
                                        <?php foreach ($entries as $entry): ?>
                                            <div class="entry" data-id="<?= (int)$entry['id'] ?>" onclick="openEditModal(<?= (int)$entry['id'] ?>)">
                                                <div class="entry-actions" onclick="event.stopPropagation()">
                                                    <button class="icon-btn" title="Edit" onclick="openEditModal(<?= (int)$entry['id'] ?>)"><i class="fas fa-pen"></i></button>
                                                    <button class="icon-btn danger" title="Delete" onclick="deleteEntry(<?= (int)$entry['id'] ?>, this)"><i class="fas fa-trash"></i></button>
                                                </div>
                                                <span class="entry-class"><?= htmlspecialchars($entry['class_name']) ?></span>
                                                <span class="entry-subject"><?= htmlspecialchars($entry['subject_name'] ?? '—') ?></span>
                                                <span class="entry-id">ID: <?= (int)$entry['id'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                        <button class="add-inline" onclick="openAddModal('<?= $day ?>', '<?= $slot['start'] ?>', '<?= $slot['end'] ?>')" title="Add class in this slot">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus-circle me-1"></i> Add Class</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId" value="">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Department *</label>
                        <select class="form-select" id="modalDepartment">
                            <option value="">Select department</option>
                            <?php foreach ($departments as $dep): ?>
                                <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Class *</label>
                        <select class="form-select" id="modalClass">
                            <option value="">Select department first</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Subject *</label>
                        <select class="form-select" id="modalSubject">
                            <option value="">Select department first</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Day *</label>
                        <select class="form-select" id="modalDay">
                            <?php foreach ($days_of_week as $d): ?>
                                <option value="<?= $d ?>"><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label">Start Time *</label>
                        <input type="time" class="form-control" id="modalStart">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label">End Time *</label>
                        <input type="time" class="form-control" id="modalEnd">
                    </div>
                </div>
                <div id="modalError" class="mt-3" style="display:none;color:var(--danger);font-size:0.85rem;">
                    <i class="fas fa-exclamation-circle"></i> <span id="modalErrorText"></span>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);padding:0.75rem 1.25rem;">
                <button type="button" class="btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-accent" id="modalSaveBtn" onclick="submitSchedule()">
                    <i class="fas fa-check"></i> <span id="modalSaveText">Save</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toastWrap"></div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ACADEMIC_YEAR = <?= json_encode($academic_year) ?>;
const TERM_ID = <?= (int)$term_id ?>;
const EXISTING_SCHEDULES = <?= json_encode($schedules) ?>;
let scheduleModal;

document.addEventListener('DOMContentLoaded', function() {
    scheduleModal = new bootstrap.Modal(document.getElementById('scheduleModal'));

    document.getElementById('modalDepartment').addEventListener('change', function() {
        loadClassesAndSubjects(this.value);
    });
});

// -------- Modal helpers --------
function openAddModal(day, start, end) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle me-1"></i> Add Class';
    document.getElementById('editId').value = '';
    document.getElementById('modalSaveText').textContent = 'Save';
    document.getElementById('modalDepartment').value = '';
    document.getElementById('modalClass').innerHTML = '<option value="">Select department first</option>';
    document.getElementById('modalSubject').innerHTML = '<option value="">Select department first</option>';
    document.getElementById('modalDay').value = day || 'Monday';
    document.getElementById('modalStart').value = start || '';
    document.getElementById('modalEnd').value = end || '';
    document.getElementById('modalError').style.display = 'none';
    scheduleModal.show();
}

function openEditModal(id) {
    const s = EXISTING_SCHEDULES.find(x => parseInt(x.id) === id);
    if (!s) return;

    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen me-1"></i> Edit Class';
    document.getElementById('editId').value = s.id;
    document.getElementById('modalSaveText').textContent = 'Update';
    document.getElementById('modalDay').value = s.day_of_week;
    document.getElementById('modalStart').value = s.start_time.substring(0, 5);
    document.getElementById('modalEnd').value = s.end_time.substring(0, 5);
    document.getElementById('modalError').style.display = 'none';

    // Populate department, then classes + subjects, then select correct ones
    const deptSelect = document.getElementById('modalDepartment');
    deptSelect.value = s.department_id;

    loadClassesAndSubjects(s.department_id).then(() => {
        document.getElementById('modalClass').value = s.class_id;
        document.getElementById('modalSubject').value = s.subject_id;
    });

    scheduleModal.show();
}

// -------- Load classes & subjects --------
function loadClassesAndSubjects(departmentId) {
    const classSelect = document.getElementById('modalClass');
    const subjectSelect = document.getElementById('modalSubject');

    if (!departmentId) {
        classSelect.innerHTML = '<option value="">Select department first</option>';
        subjectSelect.innerHTML = '<option value="">Select department first</option>';
        return Promise.resolve();
    }

    classSelect.innerHTML = '<option value="">Loading...</option>';
    subjectSelect.innerHTML = '<option value="">Loading...</option>';

    return Promise.all([
        fetch('?action=get_classes&department_id=' + departmentId).then(r => r.json()),
        fetch('?action=get_subjects&department_id=' + departmentId).then(r => r.json())
    ]).then(([cData, sData]) => {
        classSelect.innerHTML = '<option value="">Select class</option>';
        (cData.classes || []).forEach(c => {
            classSelect.insertAdjacentHTML('beforeend', `<option value="${c.id}">${escapeHtml(c.class_name)}</option>`);
        });
        subjectSelect.innerHTML = '<option value="">Select subject</option>';
        (sData.subjects || []).forEach(s => {
            subjectSelect.insertAdjacentHTML('beforeend', `<option value="${s.id}">${escapeHtml(s.subject_name)}</option>`);
        });
    }).catch(err => {
        console.error(err);
        classSelect.innerHTML = '<option value="">Error loading</option>';
        subjectSelect.innerHTML = '<option value="">Error loading</option>';
    });
}

// -------- Submit schedule (create or update) --------
function submitSchedule() {
    const id = document.getElementById('editId').value;
    const department_id = document.getElementById('modalDepartment').value;
    const class_id = document.getElementById('modalClass').value;
    const subject_id = document.getElementById('modalSubject').value;
    const day = document.getElementById('modalDay').value;
    const start = document.getElementById('modalStart').value;
    const end = document.getElementById('modalEnd').value;

    const errBox = document.getElementById('modalError');
    const errText = document.getElementById('modalErrorText');

    if (!department_id || !class_id || !subject_id || !day || !start || !end) {
        errText.textContent = 'Please fill in all fields.';
        errBox.style.display = 'block';
        return;
    }
    if (end <= start) {
        errText.textContent = 'End time must be after start time.';
        errBox.style.display = 'block';
        return;
    }

    const btn = document.getElementById('modalSaveBtn');
    const oldText = document.getElementById('modalSaveText').textContent;
    btn.disabled = true;
    document.getElementById('modalSaveText').textContent = 'Saving...';

    const fd = new FormData();
    fd.append('class_id', class_id);
    fd.append('subject_id', subject_id);
    fd.append('department_id', department_id);
    fd.append('day', day);
    fd.append('start_time', start);
    fd.append('end_time', end);

    let url;
    if (id) {
        fd.append('id', id);
        url = '?action=update_schedule';
    } else {
        fd.append('term_id', TERM_ID);
        fd.append('academic_year', ACADEMIC_YEAR);
        url = '?action=save_schedule';
    }

    fetch(url, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                scheduleModal.hide();
                showToast(id ? 'Class updated' : 'Class added', 'success');
                // Reload shortly to rebuild grid with new column if needed
                setTimeout(() => location.reload(), 500);
            } else {
                errText.textContent = res.error || 'Failed to save.';
                errBox.style.display = 'block';
                btn.disabled = false;
                document.getElementById('modalSaveText').textContent = oldText;
            }
        })
        .catch(err => {
            errText.textContent = 'Network error. Please try again.';
            errBox.style.display = 'block';
            btn.disabled = false;
            document.getElementById('modalSaveText').textContent = oldText;
        });
}

// -------- Delete --------
function deleteEntry(id, btn) {
    if (!confirm('Remove this class from your timetable?')) return;

    const entryEl = btn.closest('.entry');
    if (entryEl) entryEl.style.opacity = '0.4';

    const fd = new FormData();
    fd.append('id', id);

    fetch('?action=delete_schedule', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast('Class removed', 'success');
                // Remove from EXISTING_SCHEDULES array + DOM
                const idx = EXISTING_SCHEDULES.findIndex(x => parseInt(x.id) === id);
                if (idx > -1) EXISTING_SCHEDULES.splice(idx, 1);
                if (entryEl) entryEl.remove();
            } else {
                if (entryEl) entryEl.style.opacity = '1';
                showToast(res.error || 'Delete failed', 'error');
            }
        })
        .catch(() => {
            if (entryEl) entryEl.style.opacity = '1';
            showToast('Network error', 'error');
        });
}

// -------- Toast --------
function showToast(msg, type) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast-item ' + (type || 'success');
    el.innerHTML = `<i class="fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}"></i><span></span>`;
    el.querySelector('span').textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => {
        el.style.transition = 'opacity 0.3s, transform 0.3s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        setTimeout(() => el.remove(), 300);
    }, 2600);
}

// -------- Utilities --------
function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}
</script>
</body>
</html>