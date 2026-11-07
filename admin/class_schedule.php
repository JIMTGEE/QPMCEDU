<?php
// /admin/class_schedules.php

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Connection failed: " . mysqli_connect_error());
}

date_default_timezone_set('Africa/Accra');

$department_filter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$view_mode = isset($_GET['view']) && $_GET['view'] === 'timetable' ? 'timetable' : 'list';
$class_filter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$success_msg = $error_msg = "";

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

$terms_sql = "SELECT id, term_name FROM academic_calendar ORDER BY term_name";
$terms_stmt = $conn->prepare($terms_sql);
$terms_stmt->execute();
$terms_result = $terms_stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
$terms_stmt->close();

$check_columns_sql = "SHOW COLUMNS FROM users";
$check_columns_stmt = $conn->prepare($check_columns_sql);
$check_columns_stmt->execute();
$columns_result = $check_columns_stmt->get_result();
$user_columns = [];
while ($column = $columns_result->fetch_assoc()) {
    $user_columns[] = $column['Field'];
}
$check_columns_stmt->close();

$role_column = '';
if (in_array('role', $user_columns)) $role_column = 'role';
elseif (in_array('user_role', $user_columns)) $role_column = 'user_role';
elseif (in_array('user_type', $user_columns)) $role_column = 'user_type';

if ($role_column) {
    $teachers_sql = "SELECT id, name FROM users WHERE $role_column = 'teacher' ORDER BY name";
} else {
    $teachers_sql = "SELECT id, name FROM users ORDER BY name";
}
$teachers_stmt = $conn->prepare($teachers_sql);
$teachers_stmt->execute();
$teachers_result = $teachers_stmt->get_result();
$teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);
$teachers_stmt->close();

$classes_sql = "SELECT id, class_name, department_id FROM classes ORDER BY class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// AJAX handlers
if (isset($_GET['action']) && $_GET['action'] === 'get_classes_by_department') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['classes' => [], 'error' => ''];
    if ($department_id > 0) {
        $sql = "SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $response['classes'][] = $row;
        $stmt->close();
        if (empty($response['classes'])) $response['error'] = 'No classes available.';
    } else { $response['error'] = 'Invalid department ID.'; }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_subjects_by_department') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['subjects' => [], 'error' => ''];
    if ($department_id > 0) {
        $sql = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $response['subjects'][] = $row;
        $stmt->close();
        if (empty($response['subjects'])) $response['error'] = 'No subjects available.';
    } else { $response['error'] = 'Invalid department ID.'; }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_schedule_by_class') {
    $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $term_id = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
    $response = ['schedules' => [], 'error' => ''];
    if ($class_id > 0) {
        $sql = "SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time, c.class_name, u.name AS teacher_name, s.subject_name, cs.term_id, cs.class_id, cs.subject_id, cs.teacher_id FROM class_schedules cs JOIN classes c ON cs.class_id = c.id JOIN users u ON cs.teacher_id = u.id JOIN subjects s ON cs.subject_id = s.id WHERE cs.class_id = ?";
        if ($term_id > 0) $sql .= " AND cs.term_id = ?";
        $sql .= " ORDER BY cs.day_of_week, cs.start_time";
        $stmt = $conn->prepare($sql);
        if ($term_id > 0) $stmt->bind_param("ii", $class_id, $term_id);
        else $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $response['schedules'][] = $row;
        $stmt->close();
    } else { $response['error'] = 'Invalid class ID.'; }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// POST handlers (unchanged logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_schedules'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
    } else {
        $schedules = $_POST['schedules'] ?? [];
        $errors = [];
        if (empty($schedules)) {
            $error_msg = "No schedules provided.";
        } else {
            $conn->begin_transaction();
            try {
                foreach ($schedules as $index => $schedule) {
                    $teacher_id = (int)($schedule['teacher_id'] ?? 0);
                    $department_id = (int)($schedule['department_id'] ?? 0);
                    $class_id = (int)($schedule['class_id'] ?? 0);
                    $subject_id = (int)($schedule['subject_id'] ?? 0);
                    $term_id = (int)($schedule['term_id'] ?? 0);
                    $day_of_week = $schedule['day_of_week'] ?? '';
                    $start_time = $schedule['start_time'] ?? '';
                    $end_time = $schedule['end_time'] ?? '';
                    $academic_year = $schedule['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);

                    if (!$teacher_id || !$department_id || !$class_id || !$subject_id || !$term_id ||
                        !in_array($day_of_week, $days_of_week) || !$start_time || !$end_time) {
                        $errors[] = "Schedule " . ($index + 1) . ": All fields are required."; continue;
                    }
                    if (strtotime($end_time) <= strtotime($start_time)) {
                        $errors[] = "Schedule " . ($index + 1) . ": End time must be after start time."; continue;
                    }

                    $teacher_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
                    $teacher_stmt->bind_param("i", $teacher_id); $teacher_stmt->execute();
                    $teacher_exists = $teacher_stmt->get_result()->num_rows > 0; $teacher_stmt->close();

                    $dept_stmt = $conn->prepare("SELECT id FROM departments WHERE id = ?");
                    $dept_stmt->bind_param("i", $department_id); $dept_stmt->execute();
                    $dept_exists = $dept_stmt->get_result()->num_rows > 0; $dept_stmt->close();

                    $class_stmt = $conn->prepare("SELECT department_id FROM classes WHERE id = ?");
                    $class_stmt->bind_param("i", $class_id); $class_stmt->execute();
                    $class_row = $class_stmt->get_result()->fetch_assoc();
                    $class_department_id = $class_row ? $class_row['department_id'] : null; $class_stmt->close();

                    $subj_stmt = $conn->prepare("SELECT department_id FROM subjects WHERE id = ?");
                    $subj_stmt->bind_param("i", $subject_id); $subj_stmt->execute();
                    $subj_row = $subj_stmt->get_result()->fetch_assoc();
                    $subject_department_id = $subj_row ? $subj_row['department_id'] : null; $subj_stmt->close();

                    $term_stmt = $conn->prepare("SELECT id FROM academic_calendar WHERE id = ?");
                    $term_stmt->bind_param("i", $term_id); $term_stmt->execute();
                    $term_exists = $term_stmt->get_result()->num_rows > 0; $term_stmt->close();

                    if (!$teacher_exists) { $errors[] = "Schedule " . ($index + 1) . ": Teacher does not exist."; continue; }
                    if (!$dept_exists) { $errors[] = "Schedule " . ($index + 1) . ": Department does not exist."; continue; }
                    if ($class_department_id !== $department_id) { $errors[] = "Schedule " . ($index + 1) . ": Class not in selected department."; continue; }
                    if ($subject_department_id !== $department_id) { $errors[] = "Schedule " . ($index + 1) . ": Subject not in selected department."; continue; }
                    if (!$term_exists) { $errors[] = "Schedule " . ($index + 1) . ": Term does not exist."; continue; }

                    $ov_stmt = $conn->prepare("SELECT id FROM class_schedules WHERE teacher_id = ? AND class_id = ? AND term_id = ? AND day_of_week = ? AND ((start_time < ? AND end_time > ?) OR (start_time < ? AND end_time > ?) OR (start_time >= ? AND end_time <= ?))");
                    $ov_stmt->bind_param("iiisssssss", $teacher_id, $class_id, $term_id, $day_of_week, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
                    $ov_stmt->execute();
                    if ($ov_stmt->get_result()->num_rows > 0) { $errors[] = "Schedule " . ($index + 1) . ": Conflicts with existing schedule."; $ov_stmt->close(); continue; }
                    $ov_stmt->close();

                    $ins_stmt = $conn->prepare("INSERT INTO class_schedules (class_id, teacher_id, subject_id, term_id, day_of_week, start_time, end_time, department_id, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins_stmt->bind_param("iiiisssis", $class_id, $teacher_id, $subject_id, $term_id, $day_of_week, $start_time, $end_time, $department_id, $academic_year);
                    $ins_stmt->execute();
                    if (function_exists('logActivity')) logActivity($conn, $_SESSION['user_id'], "Added Schedule", "Teacher ID: $teacher_id, Class ID: $class_id");
                    $ins_stmt->close();
                }
                if (!empty($errors)) { $error_msg = implode("<br>", $errors); $conn->rollback(); }
                else { $success_msg = "All schedules added successfully."; $conn->commit(); header("Location: /admin/class_schedules.php?department_id=$department_filter&class_id=$class_filter&view=$view_mode"); exit(); }
            } catch (Exception $e) { $conn->rollback(); $error_msg = "Error: " . $e->getMessage(); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_schedule'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
    } else {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $term_id = (int)($_POST['term_id'] ?? 0);
        $day_of_week = $_POST['day_of_week'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $academic_year = $_POST['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);

        if (!$schedule_id || !$teacher_id || !$department_id || !$class_id || !$subject_id || !$term_id || !in_array($day_of_week, $days_of_week) || !$start_time || !$end_time) {
            $error_msg = "All fields are required.";
        } elseif (strtotime($end_time) <= strtotime($start_time)) {
            $error_msg = "End time must be after start time.";
        } else {
            $conn->begin_transaction();
            try {
                $ov_stmt = $conn->prepare("SELECT id FROM class_schedules WHERE teacher_id = ? AND class_id = ? AND term_id = ? AND day_of_week = ? AND id != ? AND ((start_time < ? AND end_time > ?) OR (start_time < ? AND end_time > ?) OR (start_time >= ? AND end_time <= ?))");
                $ov_stmt->bind_param("iiisissssss", $teacher_id, $class_id, $term_id, $day_of_week, $schedule_id, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
                $ov_stmt->execute();
                if ($ov_stmt->get_result()->num_rows > 0) { $error_msg = "Updated schedule conflicts with existing."; }
                else {
                    $upd_stmt = $conn->prepare("UPDATE class_schedules SET class_id=?, teacher_id=?, subject_id=?, term_id=?, day_of_week=?, start_time=?, end_time=?, department_id=?, academic_year=? WHERE id=?");
                    $upd_stmt->bind_param("iiiisssisi", $class_id, $teacher_id, $subject_id, $term_id, $day_of_week, $start_time, $end_time, $department_id, $academic_year, $schedule_id);
                    $upd_stmt->execute();
                    if (function_exists('logActivity')) logActivity($conn, $_SESSION['user_id'], "Edited Schedule", "Schedule ID: $schedule_id");
                    $upd_stmt->close();
                    $success_msg = "Schedule updated successfully.";
                    $conn->commit();
                    header("Location: /admin/class_schedules.php?department_id=$department_filter&class_id=$class_filter&view=$view_mode"); exit();
                }
                $ov_stmt->close();
            } catch (Exception $e) { $conn->rollback(); $error_msg = "Error: " . $e->getMessage(); }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
    } else {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        if ($schedule_id > 0) {
            $conn->begin_transaction();
            try {
                $del_stmt = $conn->prepare("DELETE FROM class_schedules WHERE id = ?");
                $del_stmt->bind_param("i", $schedule_id); $del_stmt->execute();
                if ($del_stmt->affected_rows > 0) {
                    if (function_exists('logActivity')) logActivity($conn, $_SESSION['user_id'], "Deleted Schedule", "Schedule ID: $schedule_id");
                    $success_msg = "Schedule deleted."; $conn->commit();
                    header("Location: /admin/class_schedules.php?department_id=$department_filter&class_id=$class_filter&view=$view_mode"); exit();
                } else { $error_msg = "Schedule not found."; $conn->rollback(); }
                $del_stmt->close();
            } catch (Exception $e) { $conn->rollback(); $error_msg = "Error: " . $e->getMessage(); }
        } else { $error_msg = "Invalid schedule ID."; }
    }
}

$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

$schedules_sql = "SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time, c.class_name, u.name AS teacher_name, s.subject_name, cs.term_id, d.name AS department_name, cs.class_id, cs.subject_id, cs.department_id, cs.teacher_id, cs.academic_year FROM class_schedules cs JOIN classes c ON cs.class_id = c.id JOIN users u ON cs.teacher_id = u.id JOIN subjects s ON cs.subject_id = s.id LEFT JOIN departments d ON cs.department_id = d.id WHERE 1=1";
$params = []; $types = "";
if ($department_filter > 0) { $schedules_sql .= " AND cs.department_id = ?"; $params[] = $department_filter; $types .= "i"; }
if ($class_filter > 0) { $schedules_sql .= " AND cs.class_id = ?"; $params[] = $class_filter; $types .= "i"; }
$schedules_sql .= " ORDER BY cs.class_id, cs.day_of_week, cs.start_time";

if (!empty($params)) { $schedules_stmt = $conn->prepare($schedules_sql); $schedules_stmt->bind_param($types, ...$params); }
else { $schedules_stmt = $conn->prepare($schedules_sql); }
$schedules_stmt->execute();
$schedules_result = $schedules_stmt->get_result();
$schedules = $schedules_result->fetch_all(MYSQLI_ASSOC);
$schedules_stmt->close();

$schedules_by_class = [];
foreach ($schedules as $schedule) {
    $cid = $schedule['class_id'];
    if (!isset($schedules_by_class[$cid])) $schedules_by_class[$cid] = ['class_name' => $schedule['class_name'], 'schedules' => []];
    $schedules_by_class[$cid]['schedules'][] = $schedule;
}

$term_lookup = [];
foreach ($terms as $term) $term_lookup[$term['id']] = $term['term_name'];

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Class Schedules</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
/* ── Design Tokens ─────────────────────────────── */
:root {
  --ink:       #0d0f14;
  --ink-2:     #1c2030;
  --ink-3:     #2d3348;
  --surface:   #f5f6fa;
  --surface-2: #eceef5;
  --surface-3: #e1e4ef;
  --border:    #d4d8e8;
  --gold:      #f0a500;
  --gold-lt:   #ffd060;
  --gold-dk:   #b87a00;
  --teal:      #00c4a7;
  --rose:      #f05a7e;
  --sky:       #4a9eff;
  --text:      #2d3348;
  --text-2:    #5d6680;
  --text-3:    #8b90a8;
  --white:     #ffffff;
  --radius:    14px;
  --radius-sm: 8px;
  --shadow:    0 4px 24px rgba(13,15,20,.08);
  --shadow-lg: 0 8px 40px rgba(13,15,20,.14);
  --ff-head:   'Syne', sans-serif;
  --ff-body:   'Outfit', sans-serif;
}

/* ── Base Reset ─────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: var(--ff-body);
  background: var(--surface);
  color: var(--text);
  font-size: 14px;
  line-height: 1.6;
}

/* ── Page Layout ─────────────────────────────── */
.sched-page { padding: 32px 24px 60px; max-width: 1400px; margin: 0 auto; }

/* ── Page Hero ─────────────────────────────── */
.page-hero {
  background: var(--ink-2);
  border-radius: 20px;
  padding: 36px 40px;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}
.page-hero::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 60% 80% at 90% 50%, rgba(240,165,0,.18) 0%, transparent 70%),
              radial-gradient(ellipse 40% 60% at 10% 80%, rgba(0,196,167,.1) 0%, transparent 60%);
}
.page-hero::after {
  content: '';
  position: absolute;
  right: -60px; top: -60px;
  width: 280px; height: 280px;
  border-radius: 50%;
  border: 40px solid rgba(240,165,0,.06);
}
.hero-text { position: relative; z-index: 1; }
.hero-eyebrow {
  font-family: var(--ff-head);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--gold);
  margin-bottom: 8px;
  display: flex; align-items: center; gap: 8px;
}
.hero-eyebrow::before { content:''; width:24px; height:2px; background:var(--gold); border-radius:2px; }
.hero-title {
  font-family: var(--ff-head);
  font-size: 32px;
  font-weight: 800;
  color: var(--white);
  line-height: 1.15;
  letter-spacing: -.5px;
}
.hero-sub { color: rgba(255,255,255,.45); font-size: 13px; margin-top: 6px; }
.hero-actions { position: relative; z-index: 1; display: flex; gap: 10px; flex-shrink: 0; }

.view-pill {
  display: flex;
  background: rgba(255,255,255,.07);
  border-radius: 50px;
  padding: 4px;
  border: 1px solid rgba(255,255,255,.1);
}
.view-pill a {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 18px;
  border-radius: 50px;
  font-family: var(--ff-head);
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  color: rgba(255,255,255,.5);
  transition: all .25s;
  letter-spacing: .03em;
}
.view-pill a.active, .view-pill a:hover {
  background: var(--gold);
  color: var(--ink);
}

/* ── Stats Row ─────────────────────────────── */
.stats-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 28px;
}
.stat-card {
  background: var(--white);
  border-radius: var(--radius);
  padding: 20px 24px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  display: flex; align-items: center; gap: 16px;
  transition: transform .2s, box-shadow .2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
.stat-icon {
  width: 48px; height: 48px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
}
.stat-icon.gold  { background: #fff8e1; color: var(--gold-dk); }
.stat-icon.teal  { background: #e0faf5; color: #009b86; }
.stat-icon.rose  { background: #fde8ed; color: #c83d5e; }
.stat-label { font-size: 11px; color: var(--text-3); font-weight: 500; text-transform: uppercase; letter-spacing: .08em; }
.stat-value { font-family: var(--ff-head); font-size: 26px; font-weight: 800; color: var(--ink); line-height: 1.1; }

/* ── Section Card ─────────────────────────────── */
.section-card {
  background: var(--white);
  border-radius: 18px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  margin-bottom: 24px;
  overflow: hidden;
}
.section-head {
  padding: 20px 28px;
  border-bottom: 1px solid var(--surface-3);
  display: flex; align-items: center; gap: 12px;
}
.section-head-icon {
  width: 36px; height: 36px; border-radius: 10px;
  background: var(--ink-2); color: var(--gold);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; flex-shrink: 0;
}
.section-head h5 {
  font-family: var(--ff-head);
  font-size: 16px; font-weight: 700;
  color: var(--ink); margin: 0;
}
.section-head .section-meta { font-size: 12px; color: var(--text-3); margin-top: 1px; }
.section-body { padding: 24px 28px; }

/* ── Alerts ─────────────────────────────── */
.alert-custom {
  border-radius: var(--radius);
  padding: 14px 18px;
  margin-bottom: 20px;
  display: flex; align-items: flex-start; gap: 12px;
  font-size: 13px; font-weight: 500;
  border: none;
  animation: slideDown .3s ease;
}
@keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:none; } }
.alert-custom .alert-icon { font-size: 16px; flex-shrink:0; margin-top:1px; }
.alert-success-custom { background: #e8faf5; color: #0d6e50; }
.alert-success-custom .alert-icon { color: #10b981; }
.alert-danger-custom  { background: #fde8ed; color: #8b1a32; }
.alert-danger-custom  .alert-icon { color: var(--rose); }
.alert-custom .btn-close { margin-left: auto; opacity: .5; }

/* ── Filter Strip ─────────────────────────────── */
.filter-strip {
  display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;
  padding: 20px 28px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}
.filter-group { flex: 1; min-width: 180px; }
.filter-label {
  font-size: 11px; font-weight: 600; color: var(--text-3);
  text-transform: uppercase; letter-spacing: .08em;
  margin-bottom: 6px; display: block;
}
.filter-select {
  width: 100%;
  padding: 9px 36px 9px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238b90a8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 12px center;
  appearance: none; -webkit-appearance: none;
  font-family: var(--ff-body); font-size: 13px; color: var(--text);
  transition: border-color .2s, box-shadow .2s;
  cursor: pointer;
}
.filter-select:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px rgba(240,165,0,.12); }
.filter-actions { display: flex; gap: 8px; }

/* ── Buttons ─────────────────────────────── */
.btn-primary-custom {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 20px;
  background: var(--ink-2);
  color: var(--white);
  border: none; border-radius: var(--radius-sm);
  font-family: var(--ff-head); font-size: 13px; font-weight: 600;
  cursor: pointer; text-decoration: none;
  transition: background .2s, transform .15s;
  letter-spacing: .02em;
}
.btn-primary-custom:hover { background: var(--ink-3); transform: translateY(-1px); color: var(--white); }
.btn-primary-custom:active { transform: none; }

.btn-gold {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 20px;
  background: var(--gold);
  color: var(--ink);
  border: none; border-radius: var(--radius-sm);
  font-family: var(--ff-head); font-size: 13px; font-weight: 700;
  cursor: pointer; text-decoration: none;
  transition: background .2s, transform .15s, box-shadow .2s;
}
.btn-gold:hover { background: var(--gold-lt); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(240,165,0,.35); color: var(--ink); }

.btn-ghost {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px;
  background: transparent;
  color: var(--text-2);
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  font-family: var(--ff-body); font-size: 13px; font-weight: 500;
  cursor: pointer; text-decoration: none;
  transition: all .2s;
}
.btn-ghost:hover { background: var(--surface-2); color: var(--text); border-color: var(--text-3); }

.btn-icon {
  width: 30px; height: 30px;
  border: none; border-radius: 7px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12px; cursor: pointer; transition: all .2s;
  text-decoration: none;
}
.btn-icon-edit { background: #e8f0fe; color: #3b5bdb; }
.btn-icon-edit:hover { background: #3b5bdb; color: white; }
.btn-icon-del  { background: #fde8ed; color: var(--rose); }
.btn-icon-del:hover  { background: var(--rose); color: white; }

/* ── Schedule Form Builder ─────────────────────────────── */
.form-builder { padding: 24px 28px; }
.schedule-block {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px 22px 16px;
  margin-bottom: 16px;
  position: relative;
  transition: border-color .2s, box-shadow .2s;
  animation: blockIn .25s ease;
}
@keyframes blockIn { from { opacity:0; transform:translateY(6px) scale(.99); } to { opacity:1; transform:none; } }
.schedule-block:hover { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(240,165,0,.07); }
.block-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.block-badge {
  background: var(--ink-2); color: var(--gold);
  font-family: var(--ff-head); font-size: 11px; font-weight: 700;
  padding: 4px 10px; border-radius: 20px; letter-spacing: .05em;
}
.remove-block { background: none; border: none; color: var(--text-3); font-size: 16px; cursor: pointer; transition: color .2s; padding: 4px; }
.remove-block:hover { color: var(--rose); }

.form-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.form-row-times { display: grid; grid-template-columns: 1fr 1fr 1.2fr 2fr; gap: 14px; margin-top: 14px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-lbl {
  font-size: 11px; font-weight: 600; color: var(--text-3);
  text-transform: uppercase; letter-spacing: .08em;
}
.req { color: var(--rose); margin-left: 2px; }
.form-ctrl {
  width: 100%; padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-family: var(--ff-body); font-size: 13px; color: var(--text);
  background: var(--white);
  transition: border-color .2s, box-shadow .2s;
}
.form-ctrl:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px rgba(240,165,0,.12); }
.form-ctrl.is-invalid { border-color: var(--rose); }
.form-ctrl-select {
  background: var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238b90a8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 12px center;
  appearance: none; -webkit-appearance: none; cursor: pointer;
  padding-right: 36px;
}
.invalid-hint { font-size: 11px; color: var(--rose); display: none; }
.is-invalid ~ .invalid-hint { display: block; }

.form-footer-bar {
  display: flex; gap: 10px; padding: 16px 28px 24px;
  border-top: 1px solid var(--surface-3);
}

/* ── List Table ─────────────────────────────── */
.class-group { margin-bottom: 24px; }
.class-group-header {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 12px; padding-bottom: 12px;
  border-bottom: 2px solid var(--gold);
}
.class-group-name {
  font-family: var(--ff-head);
  font-size: 17px; font-weight: 700; color: var(--ink);
}
.class-badge {
  background: var(--gold); color: var(--ink);
  font-size: 11px; font-weight: 700; font-family: var(--ff-head);
  padding: 2px 9px; border-radius: 20px;
}

.sched-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.sched-table thead th {
  font-family: var(--ff-head);
  font-size: 11px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: var(--text-3);
  padding: 10px 14px; border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.sched-table tbody tr { transition: background .15s; }
.sched-table tbody tr:hover { background: var(--surface); }
.sched-table td {
  padding: 12px 14px;
  border-bottom: 1px solid var(--surface-2);
  vertical-align: middle; font-size: 13px;
}
.sched-table tbody tr:last-child td { border-bottom: none; }

.day-pill {
  display: inline-block;
  padding: 3px 10px; border-radius: 20px;
  font-size: 11px; font-weight: 700; font-family: var(--ff-head);
  text-transform: uppercase; letter-spacing: .04em;
}
.day-Monday    { background:#fff8e1; color:#9a6800; }
.day-Tuesday   { background:#e8f5e9; color:#2e7d32; }
.day-Wednesday { background:#e3f2fd; color:#1565c0; }
.day-Thursday  { background:#f3e5f5; color:#6a1b9a; }
.day-Friday    { background:#fce4ec; color:#880e4f; }

.time-chip {
  display: inline-flex; align-items: center; gap: 5px;
  background: var(--surface-2); color: var(--text-2);
  padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500;
}
.time-chip i { font-size: 10px; color: var(--text-3); }

.teacher-cell { display: flex; align-items: center; gap: 9px; }
.teacher-avatar {
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--ink-2); color: var(--gold);
  font-family: var(--ff-head); font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.subject-tag {
  background: var(--surface-2); color: var(--ink);
  padding: 3px 9px; border-radius: 6px; font-size: 12px; font-weight: 500;
}

/* ── Timetable Grid ─────────────────────────────── */
.tt-wrap { overflow-x: auto; }
.tt-table { width: 100%; border-collapse: separate; border-spacing: 6px; min-width: 700px; }
.tt-table th {
  font-family: var(--ff-head); font-size: 11px; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  color: var(--text-3); padding: 8px 14px;
  text-align: center;
}
.tt-day-header {
  text-align: left !important;
  width: 110px;
}
.tt-table td {
  padding: 0; border-radius: var(--radius-sm);
  vertical-align: top; min-width: 150px;
}
.tt-day-cell {
  background: var(--ink-2);
  padding: 12px 14px;
  border-radius: var(--radius-sm);
}
.tt-day-name {
  font-family: var(--ff-head); font-size: 12px; font-weight: 700;
  color: var(--gold); text-transform: uppercase; letter-spacing: .08em;
}
.tt-day-short { font-size: 10px; color: rgba(255,255,255,.35); margin-top: 2px; }
.tt-cell-empty { background: var(--surface-2); min-height: 90px; border-radius: var(--radius-sm); }
.tt-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 12px;
  min-height: 90px;
  position: relative;
  overflow: hidden;
  transition: box-shadow .2s, transform .15s;
}
.tt-card::before {
  content: '';
  position: absolute; left: 0; top: 0; bottom: 0;
  width: 3px;
}
.tt-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
.tt-card .subj-name {
  font-family: var(--ff-head); font-size: 13px; font-weight: 700;
  color: var(--ink); margin-bottom: 4px;
}
.tt-card .teacher-nm {
  font-size: 11px; color: var(--text-2); margin-bottom: 6px;
  display: flex; align-items: center; gap: 4px;
}
.tt-card .tt-time {
  font-size: 11px; color: var(--text-3);
  display: flex; align-items: center; gap: 4px;
}
.tt-card-actions {
  display: flex; gap: 5px; margin-top: 8px;
}
/* Stripe colors cycling */
.tt-card:nth-child(6n+1)::before { background: var(--gold); }
.tt-card:nth-child(6n+2)::before { background: var(--teal); }
.tt-card:nth-child(6n+3)::before { background: var(--sky); }
.tt-card:nth-child(6n+4)::before { background: var(--rose); }
.tt-card:nth-child(6n+5)::before { background: #9b59b6; }
.tt-card:nth-child(6n+6)::before { background: #e67e22; }

/* ── Empty State ─────────────────────────────── */
.empty-state {
  text-align: center; padding: 60px 24px;
}
.empty-state-icon {
  width: 64px; height: 64px; border-radius: 18px;
  background: var(--surface-2); color: var(--text-3);
  font-size: 26px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.empty-state h6 {
  font-family: var(--ff-head); font-size: 17px; font-weight: 700;
  color: var(--ink); margin-bottom: 6px;
}
.empty-state p { font-size: 13px; color: var(--text-3); }

/* ── Modal ─────────────────────────────── */
.modal-content {
  border: none; border-radius: 20px;
  box-shadow: 0 20px 60px rgba(0,0,0,.2);
  overflow: hidden;
}
.modal-header {
  background: var(--ink-2);
  border-bottom: none;
  padding: 22px 28px;
}
.modal-title {
  font-family: var(--ff-head); font-size: 18px; font-weight: 700;
  color: var(--white);
}
.modal-header .btn-close { filter: invert(1) opacity(.5); }
.modal-body { padding: 28px; }
.modal-footer-bar {
  padding: 16px 28px 24px;
  display: flex; gap: 10px;
  border-top: 1px solid var(--surface-3);
}

/* ── Loading Spinner ─────────────────────────────── */
.spinner-ring {
  display: inline-block; width: 14px; height: 14px;
  border: 2px solid rgba(255,255,255,.3);
  border-top-color: white; border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Responsive ─────────────────────────────── */
@media (max-width: 768px) {
  .sched-page { padding: 16px 12px 40px; }
  .page-hero { padding: 24px 20px; flex-direction: column; align-items: flex-start; }
  .stats-row { grid-template-columns: 1fr; }
  .form-row { grid-template-columns: 1fr; }
  .form-row-times { grid-template-columns: 1fr 1fr; }
  .filter-strip { flex-direction: column; }
}
</style>
</head>
<body>

<div class="sched-page" id="content">

  <!-- ── Page Hero ── -->
  <div class="page-hero">
    <div class="hero-text">
      <div class="hero-eyebrow">Admin Dashboard</div>
      <div class="hero-title">Class Schedules</div>
      <div class="hero-sub">Manage timetables, assign subjects & track teacher workloads</div>
    </div>
    <div class="hero-actions">
      <div class="view-pill">
        <a href="?department_id=<?= $department_filter ?>&class_id=<?= $class_filter ?>&view=list"
           class="<?= $view_mode==='list' ? 'active' : '' ?>">
          <i class="fas fa-list-ul"></i> List
        </a>
        <a href="?department_id=<?= $department_filter ?>&class_id=<?= $class_filter ?>&view=timetable"
           class="<?= $view_mode==='timetable' ? 'active' : '' ?>">
          <i class="fas fa-table-cells"></i> Grid
        </a>
      </div>
    </div>
  </div>

  <!-- ── Stats ── -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon gold"><i class="fas fa-calendar-check"></i></div>
      <div>
        <div class="stat-label">Total Schedules</div>
        <div class="stat-value"><?= count($schedules) ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon teal"><i class="fas fa-chalkboard-user"></i></div>
      <div>
        <div class="stat-label">Classes Active</div>
        <div class="stat-value"><?= count($schedules_by_class) ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon rose"><i class="fas fa-user-tie"></i></div>
      <div>
        <div class="stat-label">Teachers</div>
        <div class="stat-value"><?= count(array_unique(array_column($schedules, 'teacher_name'))) ?></div>
      </div>
    </div>
  </div>

  <!-- ── Alerts ── -->
  <?php if (!empty($success_msg)): ?>
  <div class="alert-custom alert-success-custom" role="alert">
    <span class="alert-icon"><i class="fas fa-circle-check"></i></span>
    <div><?= htmlspecialchars($success_msg) ?></div>
    <button type="button" class="btn-close ms-auto" onclick="this.closest('.alert-custom').remove()"></button>
  </div>
  <?php endif; ?>
  <?php if (!empty($error_msg)): ?>
  <div class="alert-custom alert-danger-custom" role="alert">
    <span class="alert-icon"><i class="fas fa-triangle-exclamation"></i></span>
    <div><?= $error_msg ?></div>
    <button type="button" class="btn-close ms-auto" onclick="this.closest('.alert-custom').remove()"></button>
  </div>
  <?php endif; ?>

  <!-- ── Filter Card ── -->
  <div class="section-card">
    <div class="section-head">
      <div class="section-head-icon"><i class="fas fa-sliders"></i></div>
      <div>
        <div class="d-flex align-items-center gap-2">
          <h5>Filter Schedules</h5>
        </div>
        <div class="section-meta">Narrow results by department or class</div>
      </div>
    </div>
    <form method="GET" id="filterForm">
      <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
      <div class="filter-strip">
        <div class="filter-group">
          <label class="filter-label">Department</label>
          <select class="filter-select" name="department_id" id="department_filter">
            <option value="0">All Departments</option>
            <?php foreach ($departments as $dept): ?>
              <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dept['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label class="filter-label">Class</label>
          <select class="filter-select" name="class_id" id="class_filter">
            <option value="0">All Classes</option>
            <?php foreach ($classes as $class): ?>
              <?php if ($department_filter == 0 || $class['department_id'] == $department_filter): ?>
                <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($class['class_name']) ?>
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-primary-custom">
            <i class="fas fa-magnifying-glass"></i> Apply
          </button>
          <a href="?view=<?= $view_mode ?>" class="btn-ghost">
            <i class="fas fa-rotate-left"></i> Clear
          </a>
        </div>
      </div>
    </form>
  </div>

 
  <!-- ── Schedules Display ── -->
  <div class="section-card">
    <div class="section-head">
      <div class="section-head-icon"><i class="fas fa-calendar-week"></i></div>
      <div>
        <h5>Class Timetables</h5>
        <div class="section-meta"><?= count($schedules) ?> schedule entries
          <?php if ($department_filter || $class_filter): ?>
            — filtered view
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div style="padding: 24px 28px;">

    <?php if (empty($schedules)): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-calendar-xmark"></i></div>
        <h6>No Schedules Found</h6>
        <p>Add schedules using the form above, or try adjusting your filters.</p>
      </div>

    <?php elseif ($view_mode === 'list'): ?>
      <!-- LIST VIEW -->
      <?php foreach ($schedules_by_class as $cid => $class_data): ?>
        <div class="class-group">
          <div class="class-group-header">
            <span class="class-group-name"><?= htmlspecialchars($class_data['class_name']) ?></span>
            <span class="class-badge"><?= count($class_data['schedules']) ?> periods</span>
          </div>
          <div class="table-responsive">
            <table class="sched-table">
              <thead>
                <tr>
                  <th>Day</th>
                  <th>Time</th>
                  <th>Subject</th>
                  <th>Teacher</th>
                  <th>Term</th>
                  <th>Acad. Year</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($class_data['schedules'] as $s): ?>
                <tr>
                  <td>
                    <span class="day-pill day-<?= $s['day_of_week'] ?>">
                      <?= htmlspecialchars($s['day_of_week']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="time-chip">
                      <i class="fas fa-clock"></i>
                      <?= htmlspecialchars(date('g:i A', strtotime($s['start_time']))) ?> – <?= htmlspecialchars(date('g:i A', strtotime($s['end_time']))) ?>
                    </span>
                  </td>
                  <td><span class="subject-tag"><?= htmlspecialchars($s['subject_name']) ?></span></td>
                  <td>
                    <div class="teacher-cell">
                      <div class="teacher-avatar"><?= strtoupper(substr($s['teacher_name'], 0, 1)) ?></div>
                      <?= htmlspecialchars($s['teacher_name']) ?>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($term_lookup[$s['term_id']] ?? '—') ?></td>
                  <td><?= htmlspecialchars($s['academic_year'] ?? '—') ?></td>
                  <td>
                    <div style="display:flex; gap:6px;">
                      <button class="btn-icon btn-icon-edit edit-schedule"
                        data-schedule-id="<?= $s['id'] ?>"
                        data-teacher-id="<?= $s['teacher_id'] ?>"
                        data-department-id="<?= $s['department_id'] ?>"
                        data-class-id="<?= $s['class_id'] ?>"
                        data-subject-id="<?= $s['subject_id'] ?>"
                        data-term-id="<?= $s['term_id'] ?>"
                        data-day="<?= $s['day_of_week'] ?>"
                        data-start-time="<?= $s['start_time'] ?>"
                        data-end-time="<?= $s['end_time'] ?>"
                        data-academic-year="<?= htmlspecialchars($s['academic_year'] ?? '') ?>"
                        data-bs-toggle="modal" data-bs-target="#editModal"
                        title="Edit">
                        <i class="fas fa-pen"></i>
                      </button>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this schedule?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>">
                        <button type="submit" name="delete_schedule" class="btn-icon btn-icon-del" title="Delete">
                          <i class="fas fa-trash-can"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

    <?php else: ?>
      <!-- TIMETABLE VIEW -->
      <?php foreach ($schedules_by_class as $cid => $class_data): ?>
        <div class="class-group">
          <div class="class-group-header">
            <span class="class-group-name"><?= htmlspecialchars($class_data['class_name']) ?></span>
            <span class="class-badge">Timetable</span>
          </div>
          <?php
            $tt = array_fill_keys($days_of_week, []);
            foreach ($class_data['schedules'] as $s) {
              $tt[$s['day_of_week']][] = $s;
            }
            foreach ($tt as $day => &$ds) {
              usort($ds, fn($a,$b) => strcmp($a['start_time'], $b['start_time']));
            }
            $max_p = 0;
            foreach ($tt as $ds) $max_p = max($max_p, count($ds));
          ?>
          <div class="tt-wrap">
            <table class="tt-table">
              <thead>
                <tr>
                  <th class="tt-day-header"></th>
                  <?php for ($i=1; $i<=$max_p; $i++): ?>
                    <th>Period <?= $i ?></th>
                  <?php endfor; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($days_of_week as $day): ?>
                <tr>
                  <td>
                    <div class="tt-day-cell">
                      <div class="tt-day-name"><?= substr($day,0,3) ?></div>
                      <div class="tt-day-short"><?= $day ?></div>
                    </div>
                  </td>
                  <?php for ($i=0; $i<$max_p; $i++): ?>
                  <td>
                    <?php if (isset($tt[$day][$i])):
                      $s = $tt[$day][$i]; ?>
                      <div class="tt-card">
                        <div class="subj-name"><?= htmlspecialchars($s['subject_name']) ?></div>
                        <div class="teacher-nm">
                          <i class="fas fa-user" style="color:var(--text-3); font-size:9px;"></i>
                          <?= htmlspecialchars($s['teacher_name']) ?>
                        </div>
                        <div class="tt-time">
                          <i class="fas fa-clock"></i>
                          <?= date('g:i A', strtotime($s['start_time'])) ?> – <?= date('g:i A', strtotime($s['end_time'])) ?>
                        </div>
                        <div class="tt-card-actions">
                          <button class="btn-icon btn-icon-edit edit-schedule"
                            data-schedule-id="<?= $s['id'] ?>" data-teacher-id="<?= $s['teacher_id'] ?>"
                            data-department-id="<?= $s['department_id'] ?>" data-class-id="<?= $s['class_id'] ?>"
                            data-subject-id="<?= $s['subject_id'] ?>" data-term-id="<?= $s['term_id'] ?>"
                            data-day="<?= $s['day_of_week'] ?>" data-start-time="<?= $s['start_time'] ?>"
                            data-end-time="<?= $s['end_time'] ?>" data-academic-year="<?= htmlspecialchars($s['academic_year'] ?? '') ?>"
                            data-bs-toggle="modal" data-bs-target="#editModal" title="Edit">
                            <i class="fas fa-pen"></i>
                          </button>
                          <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>">
                            <button type="submit" name="delete_schedule" class="btn-icon btn-icon-del" title="Delete">
                              <i class="fas fa-trash-can"></i>
                            </button>
                          </form>
                        </div>
                      </div>
                    <?php else: ?>
                      <div class="tt-cell-empty"></div>
                    <?php endif; ?>
                  </td>
                  <?php endfor; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    </div><!-- /padding -->
  </div><!-- /section-card -->
</div><!-- /sched-page -->

<!-- ── Edit Modal ── -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-pen-to-square me-2" style="color:var(--gold);"></i>Edit Schedule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="POST" id="editScheduleForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="schedule_id" id="edit_schedule_id">

          <div class="form-row">
            <div class="form-group">
              <label class="form-lbl">Teacher<span class="req">*</span></label>
              <select class="form-ctrl form-ctrl-select" id="edit_teacher_id" name="teacher_id" required>
                <option value="">Select Teacher</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-lbl">Department<span class="req">*</span></label>
              <select class="form-ctrl form-ctrl-select department-select" id="edit_department_id" name="department_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-lbl">Class<span class="req">*</span></label>
              <select class="form-ctrl form-ctrl-select class-select" id="edit_class_id" name="class_id" required>
                <option value="">Select Class</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-lbl">Subject<span class="req">*</span></label>
              <select class="form-ctrl form-ctrl-select subject-select" id="edit_subject_id" name="subject_id" required>
                <option value="">Select Subject</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-lbl">Term<span class="req">*</span></label>
              <select class="form-ctrl form-ctrl-select" id="edit_term_id" name="term_id" required>
                <option value="">Select Term</option>
                <?php foreach ($terms as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['term_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-lbl">Day<span class="req">*</span></label>
              <select class="form-ctrl form-ctrl-select" id="edit_day_of_week" name="day_of_week" required>
                <option value="">Select Day</option>
                <?php foreach ($days_of_week as $day): ?>
                  <option value="<?= $day ?>"><?= $day ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row-times" style="margin-top:16px;">
            <div class="form-group">
              <label class="form-lbl">Start Time<span class="req">*</span></label>
              <input type="time" class="form-ctrl" id="edit_start_time" name="start_time" required>
            </div>
            <div class="form-group">
              <label class="form-lbl">End Time<span class="req">*</span></label>
              <input type="time" class="form-ctrl" id="edit_end_time" name="end_time" required>
            </div>
            <div class="form-group">
              <label class="form-lbl">Academic Year</label>
              <input type="text" class="form-ctrl" id="edit_academic_year" name="academic_year" placeholder="2025/2026">
            </div>
          </div>

          <div class="modal-footer-bar" style="padding: 20px 0 0; border-top: 1px solid var(--surface-3); margin-top: 20px;">
            <button type="submit" name="edit_schedule" class="btn-gold">
              <i class="fas fa-floppy-disk"></i> Save Changes
            </button>
            <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
const BASE_URL = window.location.href.split('?')[0];
let scheduleIndex = 1;

// ── Toggle add form ──────────────────────────
function toggleAddForm() {
  const wrap = document.getElementById('addFormWrap');
  const chev = document.getElementById('addChevron');
  const isOpen = wrap.style.display !== 'none';
  wrap.style.display = isOpen ? 'none' : '';
  chev.style.transform = isOpen ? 'rotate(-90deg)' : '';
}

// ── AJAX helpers ──────────────────────────
function loadClasses(deptId, classSelect, selectedId) {
  classSelect.innerHTML = '<option value="">Loading…</option>';
  $.getJSON(BASE_URL, { action: 'get_classes_by_department', department_id: deptId }, function(r) {
    let opts = '<option value="">Select Class</option>';
    (r.classes || []).forEach(c => {
      opts += `<option value="${c.id}" ${c.id == selectedId ? 'selected' : ''}>${c.class_name}</option>`;
    });
    if (!r.classes?.length) opts = '<option value="">No classes found</option>';
    classSelect.innerHTML = opts;
  });
}

function loadSubjects(deptId, subjectSelect, selectedId) {
  subjectSelect.innerHTML = '<option value="">Loading…</option>';
  $.getJSON(BASE_URL, { action: 'get_subjects_by_department', department_id: deptId }, function(r) {
    let opts = '<option value="">Select Subject</option>';
    (r.subjects || []).forEach(s => {
      opts += `<option value="${s.id}" ${s.id == selectedId ? 'selected' : ''}>${s.subject_name}</option>`;
    });
    if (!r.subjects?.length) opts = '<option value="">No subjects found</option>';
    subjectSelect.innerHTML = opts;
  });
}

// ── Department change ──────────────────────────
$(document).on('change', '.department-select', function() {
  const deptId = $(this).val();
  const block  = $(this).closest('.schedule-block, form');
  const cs = block.find('.class-select')[0];
  const ss = block.find('.subject-select')[0];
  if (!deptId) {
    cs.innerHTML = '<option value="">Select Class</option>';
    ss.innerHTML = '<option value="">Select Subject</option>';
    return;
  }
  loadClasses(deptId, cs, null);
  loadSubjects(deptId, ss, null);
});

// ── Edit modal ──────────────────────────
$(document).on('click', '.edit-schedule', function() {
  const d = $(this).data();
  $('#edit_schedule_id').val(d.scheduleId);
  $('#edit_teacher_id').val(d.teacherId);
  $('#edit_department_id').val(d.departmentId);
  $('#edit_term_id').val(d.termId);
  $('#edit_day_of_week').val(d.day);
  $('#edit_start_time').val(d.startTime);
  $('#edit_end_time').val(d.endTime);
  $('#edit_academic_year').val(d.academicYear || '');

  if (d.departmentId) {
    loadClasses(d.departmentId, document.getElementById('edit_class_id'), d.classId);
    loadSubjects(d.departmentId, document.getElementById('edit_subject_id'), d.subjectId);
  }
});

// ── Add another block ──────────────────────────
$('#addAnotherSchedule').click(function() {
  scheduleIndex++;
  const teacherOpts = `<?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>`;
  const deptOpts    = `<?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>`;
  const termOpts    = `<?php foreach ($terms as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['term_name']) ?></option><?php endforeach; ?>`;
  const dayOpts     = `<?php foreach ($days_of_week as $day): ?><option value="<?= $day ?>"><?= $day ?></option><?php endforeach; ?>`;
  const yr          = `<?= date('Y') . '/' . (date('Y')+1) ?>`;
  const i = scheduleIndex;

  const html = `
    <div class="schedule-block" data-index="${i}">
      <div class="block-header">
        <span class="block-badge">Schedule ${i}</span>
        <button type="button" class="remove-block" title="Remove"><i class="fas fa-xmark"></i></button>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-lbl">Teacher<span class="req">*</span></label>
          <select class="form-ctrl form-ctrl-select" name="schedules[${i}][teacher_id]" required>
            <option value="">Select Teacher</option>${teacherOpts}
          </select>
        </div>
        <div class="form-group">
          <label class="form-lbl">Department<span class="req">*</span></label>
          <select class="form-ctrl form-ctrl-select department-select" name="schedules[${i}][department_id]" required>
            <option value="">Select Department</option>${deptOpts}
          </select>
        </div>
        <div class="form-group">
          <label class="form-lbl">Class<span class="req">*</span></label>
          <select class="form-ctrl form-ctrl-select class-select" name="schedules[${i}][class_id]" required>
            <option value="">Select Class</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-lbl">Subject<span class="req">*</span></label>
          <select class="form-ctrl form-ctrl-select subject-select" name="schedules[${i}][subject_id]" required>
            <option value="">Select Subject</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-lbl">Term<span class="req">*</span></label>
          <select class="form-ctrl form-ctrl-select" name="schedules[${i}][term_id]" required>
            <option value="">Select Term</option>${termOpts}
          </select>
        </div>
        <div class="form-group">
          <label class="form-lbl">Day<span class="req">*</span></label>
          <select class="form-ctrl form-ctrl-select" name="schedules[${i}][day_of_week]" required>
            <option value="">Select Day</option>${dayOpts}
          </select>
        </div>
      </div>
      <div class="form-row-times">
        <div class="form-group">
          <label class="form-lbl">Start Time<span class="req">*</span></label>
          <input type="time" class="form-ctrl" name="schedules[${i}][start_time]" id="start_time_${i}" required>
        </div>
        <div class="form-group">
          <label class="form-lbl">End Time<span class="req">*</span></label>
          <input type="time" class="form-ctrl" name="schedules[${i}][end_time]" id="end_time_${i}" required>
        </div>
        <div class="form-group">
          <label class="form-lbl">Academic Year</label>
          <input type="text" class="form-ctrl" name="schedules[${i}][academic_year]" placeholder="2025/2026" value="${yr}">
        </div>
      </div>
    </div>`;

  $('#scheduleForms').append(html);
  $('#scheduleForms .schedule-block').first().find('.remove-block').show();
});

// ── Remove block ──────────────────────────
$(document).on('click', '.remove-block', function() {
  $(this).closest('.schedule-block').fadeOut(200, function() { $(this).remove(); });
});

// ── Validate ──────────────────────────
function validateSchedulesForm() {
  let ok = true;
  $('.schedule-block').each(function() {
    const i = $(this).data('index');
    const st = $(`#start_time_${i}`).val();
    const et = $(`#end_time_${i}`).val();
    $(this).find('[required]').each(function() {
      if (!this.value.trim()) { ok = false; $(this).addClass('is-invalid'); }
      else $(this).removeClass('is-invalid');
    });
    if (st && et && et <= st) {
      ok = false;
      alert(`Schedule ${i}: End time must be after start time.`);
      $(`#end_time_${i}`).focus();
    }
  });
  if (ok) return confirm('Submit all schedules?');
  return false;
}

// ── Auto-dismiss alerts ──────────────────────────
setTimeout(() => {
  document.querySelectorAll('.alert-custom').forEach(el => {
    el.style.transition = 'opacity .5s'; el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  });
}, 5000);
</script>

<?php
// Helper function to render schedule block fields
function scheduleBlockFields($i, $teachers, $departments, $terms, $days_of_week) {
  $yr = date('Y') . '/' . (date('Y')+1);
  ob_start(); ?>
  <div class="form-row">
    <div class="form-group">
      <label class="form-lbl">Teacher<span class="req">*</span></label>
      <select class="form-ctrl form-ctrl-select" id="teacher_id_<?= $i ?>" name="schedules[<?= $i ?>][teacher_id]" required>
        <option value="">Select Teacher</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-lbl">Department<span class="req">*</span></label>
      <select class="form-ctrl form-ctrl-select department-select" id="department_id_<?= $i ?>" name="schedules[<?= $i ?>][department_id]" required>
        <option value="">Select Department</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-lbl">Class<span class="req">*</span></label>
      <select class="form-ctrl form-ctrl-select class-select" id="class_id_<?= $i ?>" name="schedules[<?= $i ?>][class_id]" required>
        <option value="">Select Class</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-lbl">Subject<span class="req">*</span></label>
      <select class="form-ctrl form-ctrl-select subject-select" id="subject_id_<?= $i ?>" name="schedules[<?= $i ?>][subject_id]" required>
        <option value="">Select Subject</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-lbl">Term<span class="req">*</span></label>
      <select class="form-ctrl form-ctrl-select" id="term_id_<?= $i ?>" name="schedules[<?= $i ?>][term_id]" required>
        <option value="">Select Term</option>
        <?php foreach ($terms as $t): ?>
          <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['term_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-lbl">Day<span class="req">*</span></label>
      <select class="form-ctrl form-ctrl-select" id="day_of_week_<?= $i ?>" name="schedules[<?= $i ?>][day_of_week]" required>
        <option value="">Select Day</option>
        <?php foreach ($days_of_week as $day): ?>
          <option value="<?= $day ?>"><?= $day ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-row-times">
    <div class="form-group">
      <label class="form-lbl">Start Time<span class="req">*</span></label>
      <input type="time" class="form-ctrl" id="start_time_<?= $i ?>" name="schedules[<?= $i ?>][start_time]" required>
    </div>
    <div class="form-group">
      <label class="form-lbl">End Time<span class="req">*</span></label>
      <input type="time" class="form-ctrl" id="end_time_<?= $i ?>" name="schedules[<?= $i ?>][end_time]" required>
    </div>
    <div class="form-group">
      <label class="form-lbl">Academic Year</label>
      <input type="text" class="form-ctrl" name="schedules[<?= $i ?>][academic_year]" placeholder="2025/2026" value="<?= $yr ?>">
    </div>
  </div>
  <?php return ob_get_clean();
}
?>

<?php mysqli_close($conn); ?>