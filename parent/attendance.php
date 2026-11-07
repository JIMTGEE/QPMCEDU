<?php
ob_start();
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Ensure user is logged in and is a parent
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    header('Location: /login.php');
    exit;
}

// ── Helper function: Count school days (Mon-Fri) in a date range ────────────
function countSchoolDays($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end   = new DateTime($end_date);
    $end->modify('+1 day');
    $interval = DateInterval::createFromDateString('1 day');
    $period   = new DatePeriod($start, $interval, $end);
    $count = 0;
    foreach ($period as $dt) {
        $dow = (int)$dt->format('N');
        if ($dow >= 1 && $dow <= 5) {
            $count++;
        }
    }
    return $count;
}

// ── Helper function: Get current academic year ────────────────────────────────
function getCurrentAcademicYear() {
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    
    if ($current_month >= 9) {
        return $current_year . '/' . ($current_year + 1);
    } else {
        return ($current_year - 1) . '/' . $current_year;
    }
}

// ── Helper function to resolve profile picture path ─────────────────────────
function resolveProfilePicture($profile_picture) {
    $default = '/assets/images/default_avatar.png';
    if (empty($profile_picture)) {
        return $default;
    }
    $clean_path = str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $profile_picture);
    $clean_path = ltrim($clean_path, '/');
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean_path;
    if (file_exists($full_path) && filesize($full_path) > 100) {
        return '/Uploads/profiles/' . $clean_path;
    }
    $alt_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($profile_picture, '/');
    if (file_exists($alt_path) && filesize($alt_path) > 100) {
        return '/' . ltrim($profile_picture, '/');
    }
    return $default;
}

// ── Parent data ──────────────────────────────────────────────────────────────
$parent_id = (int)$_SESSION['user_id'];

// Get parent name
$stmt = $conn->prepare("SELECT name, profile_picture, email, phone FROM users WHERE id = ? AND role = 'parent'");
if (!$stmt) { 
    error_log("Parent query prepare failed: " . $conn->error); 
    die("System error. Please contact administrator.");
}
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();
if (!$parent) { 
    header('Location: /login.php'); 
    exit; 
}
$parent_name    = htmlspecialchars($parent['name'] ?? 'Parent');
$parent_email   = htmlspecialchars($parent['email'] ?? '');
$profile_picture = $parent['profile_picture']
    ? htmlspecialchars($parent['profile_picture'])
    : '/assets/images/default_profile.png';
$stmt->close();

// ── Fetch children (students linked to this parent) ─────────────────────────
$children = [];

$stmt = $conn->prepare("
    SELECT 
        u.id as student_id,
        u.name as student_name,
        u.email as student_email,
        u.profile_picture as student_profile
    FROM users u
    INNER JOIN parent_student_mapping psm ON u.id = psm.student_id
    WHERE psm.parent_id = ? AND u.role = 'student' AND u.status = 'active'
    ORDER BY u.name ASC
");
if ($stmt) {
    $stmt->bind_param("i", $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $children[] = $row;
    }
    $stmt->close();
}

// If no children found, show error
if (empty($children)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>No Children Found | Parent Portal</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                font-family: 'Inter', sans-serif;
            }
            .error-card {
                background: white;
                border-radius: 20px;
                padding: 3rem;
                text-align: center;
                max-width: 500px;
                margin: 100px auto;
                box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            }
            .error-icon {
                font-size: 4rem;
                color: #764ba2;
                margin-bottom: 1rem;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="error-card">
                <div class="error-icon">
                    <i class="fas fa-child"></i>
                </div>
                <h3>No Children Linked</h3>
                <p class="text-muted">You don't have any children linked to your account yet.</p>
                <p class="text-muted small">Please contact the school administrator to link your children to this account.</p>
                <a href="/parent/dashboard.php" class="btn btn-primary mt-3">Go to Dashboard</a>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// ── Get selected child from URL ──────────────────────────────────────────────
$selected_child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : $children[0]['student_id'];

// Verify selected child belongs to parent
$valid_child = false;
$selected_child = null;
foreach ($children as $child) {
    if ($child['student_id'] == $selected_child_id) {
        $valid_child = true;
        $selected_child = $child;
        break;
    }
}
if (!$valid_child) {
    $selected_child_id = $children[0]['student_id'];
    $selected_child = $children[0];
}

// ── Get student's enrolled classes ───────────────────────────────────────────
$student_classes = [];
$stmt = $conn->prepare("
    SELECT c.id, c.class_name, c.teacher_id
    FROM classes c
    INNER JOIN student_classes sc ON c.id = sc.class_id
    WHERE sc.user_id = ?
");
if ($stmt) {
    $stmt->bind_param("i", $selected_child_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $student_classes[$row['id']] = $row;
    }
    $stmt->close();
}
$class_ids = array_keys($student_classes);
$class_ids_str = empty($class_ids) ? '0' : implode(',', $class_ids);

// ── Get current term based on date ───────────────────────────────────────────
$current_date = date('Y-m-d');
$current_term = null;
$stmt = $conn->prepare("
    SELECT id, term_name, start_date, end_date
    FROM academic_calendar
    WHERE start_date <= ? AND end_date >= ?
    ORDER BY start_date DESC
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("ss", $current_date, $current_date);
    $stmt->execute();
    $current_term = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$current_term_id = $current_term ? (int)$current_term['id'] : 0;
$current_term_name = $current_term ? htmlspecialchars($current_term['term_name']) : 'Current Term';
$term_start_date = $current_term ? $current_term['start_date'] : date('Y-m-d', strtotime('-30 days'));
$term_end_date = $current_term ? $current_term['end_date'] : $current_date;

// ── All terms for filter dropdown ─────────────────────────────────────────────
$terms = [];
$stmt = $conn->prepare("
    SELECT id, term_name, start_date, end_date
    FROM academic_calendar
    ORDER BY start_date DESC
");
if ($stmt) {
    $stmt->execute();
    $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ── Resolve filter inputs ─────────────────────────────────────────────────────
$class_id         = isset($_GET['class_id']) ? (int)$_GET['class_id']  : 0;
$selected_term_id = isset($_GET['term_id'])  ? (int)$_GET['term_id']   : $current_term_id;

// Resolve date range for the selected term
$filter_term_start = $term_start_date;
$filter_term_end   = $term_end_date;
$selected_term_name = $current_term_name;

if ($selected_term_id > 0) {
    foreach ($terms as $t) {
        if ((int)$t['id'] === $selected_term_id) {
            $filter_term_start  = $t['start_date'];
            $filter_term_end    = $t['end_date'];
            $selected_term_name = htmlspecialchars($t['term_name']);
            break;
        }
    }
}
if ($filter_term_end > $current_date) $filter_term_end = $current_date;

// ── Fetch attendance records for the selected child ─────────────────────────
$attendance_records = [];

if (!empty($class_ids)) {
    $query = "
        SELECT a.id, a.class_id, a.date, a.status, a.type, a.created_at
        FROM attendance a
        WHERE a.user_id = ?
          AND a.class_id IN ($class_ids_str)
          AND a.date BETWEEN ? AND ?
        ORDER BY a.date ASC
    ";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("iss", $selected_child_id, $filter_term_start, $filter_term_end);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $attendance_records[] = $row;
        }
        $stmt->close();
    }
}

// Filter by specific class if selected
if ($class_id > 0 && !empty($attendance_records)) {
    $attendance_records = array_filter($attendance_records, function($rec) use ($class_id) {
        return $rec['class_id'] == $class_id;
    });
}

// ── Index records by date ────────────────────────────────────────────────────
$records_by_date = [];
foreach ($attendance_records as $rec) {
    if (!isset($records_by_date[$rec['date']])) {
        $records_by_date[$rec['date']] = $rec;
    }
}

// ── Calculate Term Totals ────────────────────────────────────────────────────
$term_total_sessions = count($attendance_records);
$term_present = 0;
$term_absent  = 0;
$term_late    = 0;

foreach ($attendance_records as $rec) {
    switch ($rec['status']) {
        case 'present': $term_present++; break;
        case 'absent':  $term_absent++;  break;
        case 'late':    $term_late++;    break;
    }
}

// Count actual school days (Mon-Fri) in the term
$total_school_days = countSchoolDays($filter_term_start, $filter_term_end);

// Count days where student had attendance
$days_attended = 0;
$days_absent = 0;
$days_late_only = 0;
$days_with_records = [];

foreach ($attendance_records as $rec) {
    if (!isset($days_with_records[$rec['date']])) {
        $days_with_records[$rec['date']] = [];
    }
    $days_with_records[$rec['date']][] = $rec['status'];
}

foreach ($days_with_records as $date => $statuses) {
    if (in_array('present', $statuses)) {
        $days_attended++;
    } elseif (in_array('late', $statuses) && !in_array('present', $statuses)) {
        $days_attended++;
        $days_late_only++;
    } elseif (in_array('absent', $statuses)) {
        $days_absent++;
    }
}

$attendance_percentage = $total_school_days > 0 
    ? round(($days_attended / $total_school_days) * 100) 
    : 0;

// ── Build weeks anchored to term start Monday ────────────────────────────────
$term_start_dt = new DateTime($filter_term_start);
$dow = (int)$term_start_dt->format('N');
$first_monday = clone $term_start_dt;
if ($dow > 1) {
    $first_monday->modify('-' . ($dow - 1) . ' days');
}

$weeks = [];
$week_cursor = clone $first_monday;
$today_dt = new DateTime($current_date);
$term_end_dt = new DateTime($filter_term_end);
$limit_dt = ($term_end_dt < $today_dt) ? clone $term_end_dt : clone $today_dt;
$week_num = 1;

while ($week_cursor <= $limit_dt) {
    $week_monday = clone $week_cursor;
    $week_friday = clone $week_cursor;
    $week_friday->modify('+4 days');
    
    $week_start_display = ($week_monday < $term_start_dt) ? clone $term_start_dt : clone $week_monday;
    $week_end_display = ($week_friday > $term_end_dt) ? clone $term_end_dt : clone $week_friday;
    $week_end_display = ($week_end_display > $today_dt) ? clone $today_dt : $week_end_display;
    
    if ($week_start_display <= $week_end_display && $week_start_display <= $limit_dt) {
        $weeks[] = [
            'number'    => $week_num,
            'monday'    => clone $week_cursor,
            'friday'    => $week_friday,
            'label'     => $week_cursor->format('M j') . ' – ' . $week_friday->format('M j, Y'),
            'display_start' => $week_start_display->format('Y-m-d'),
            'display_end'   => $week_end_display->format('Y-m-d'),
        ];
        $week_num++;
    }
    $week_cursor->modify('+7 days');
}

$total_weeks = count($weeks);
$selected_week = isset($_GET['week']) 
    ? max(1, min((int)$_GET['week'], $total_weeks))
    : $total_weeks;
$week_index = $selected_week - 1;

// ── Build day slots for the selected week ────────────────────────────────────
$week_records = [];
$week_stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'no_record' => 0];

if (!empty($weeks) && $week_index >= 0 && $week_index < count($weeks)) {
    $wk = $weeks[$week_index];
    $day_dt = clone $wk['monday'];
    
    for ($d = 0; $d < 5; $d++) {
        $ds = $day_dt->format('Y-m-d');
        $is_in_term = ($ds >= $filter_term_start && $ds <= $filter_term_end);
        $is_future = $day_dt > $today_dt;
        $record = $records_by_date[$ds] ?? null;
        
        $status = 'no_record';
        if ($record) {
            $status = $record['status'];
            if ($status === 'present') $week_stats['present']++;
            elseif ($status === 'absent') $week_stats['absent']++;
            elseif ($status === 'late') $week_stats['late']++;
        } elseif (!$is_in_term) {
            $status = 'not_in_term';
        } elseif ($is_future && $is_in_term) {
            $status = 'future';
            $week_stats['no_record']++;
        } elseif ($is_in_term) {
            $status = 'no_record';
            $week_stats['no_record']++;
        } else {
            $status = 'not_in_term';
        }
        
        $class_display = '';
        if ($record && isset($student_classes[$record['class_id']])) {
            $class_display = $student_classes[$record['class_id']]['class_name'];
        }
        
        $week_records[] = [
            'date'      => $ds,
            'day'       => $day_dt->format('D'),
            'day_num'   => $day_dt->format('j'),
            'month'     => $day_dt->format('M'),
            'year'      => $day_dt->format('Y'),
            'status'    => $status,
            'record'    => $record,
            'class_name'=> $class_display,
            'is_future' => $is_future,
            'is_in_term'=> $is_in_term,
        ];
        $day_dt->modify('+1 day');
    }
}

// ── Calculate attendance by class ────────────────────────────────────────────
$class_attendance = [];
foreach ($attendance_records as $rec) {
    $cid = $rec['class_id'];
    $class_name = $student_classes[$cid]['class_name'] ?? 'Unknown Class';
    if (!isset($class_attendance[$cid])) {
        $class_attendance[$cid] = [
            'class_name' => $class_name,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'total' => 0
        ];
    }
    $class_attendance[$cid][$rec['status']]++;
    $class_attendance[$cid]['total']++;
}

foreach ($class_attendance as $cid => $data) {
    $class_attendance[$cid]['percentage'] = $data['total'] > 0 
        ? round(($data['present'] / $data['total']) * 100, 1)
        : 0;
}

$chart_labels = [];
$chart_data = [];
foreach ($class_attendance as $data) {
    $chart_labels[] = $data['class_name'];
    $chart_data[] = $data['percentage'];
}

// ── Generate pagination URLs ─────────────────────────────────────────────────
$base_qs = http_build_query(array_filter([
    'child_id' => $selected_child_id,
    'class_id' => $class_id ?: null,
    'term_id' => $selected_term_id ?: null
]));
$prev_url = '?' . $base_qs . ($base_qs ? '&' : '') . 'week=' . ($selected_week - 1);
$next_url = '?' . $base_qs . ($base_qs ? '&' : '') . 'week=' . ($selected_week + 1);
$first_url = '?' . $base_qs . ($base_qs ? '&' : '') . 'week=1';
$last_url = '?' . $base_qs . ($base_qs ? '&' : '') . 'week=' . $total_weeks;

require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Attendance Monitor - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --accent: #4f46e5;
            --accent-dark: #4338ca;
            --accent-light: #eef2ff;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
            --info: #3b82f6;
            --radius: 16px;
            --shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .attendance-hero {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border-radius: var(--radius);
            color: white;
            padding: 2rem 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(79, 70, 229, 0.3);
            margin-bottom: 1.5rem;
        }
        
        .attendance-hero h1 {
            font-size: clamp(1.5rem, 4vw, 2.2rem);
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        
        .attendance-hero .mascot {
            position: absolute;
            right: 20px;
            bottom: -5px;
            width: 100px;
            opacity: 0.9;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        
        .child-selector {
            background: white;
            border-radius: var(--radius);
            padding: 1rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }
        
        .child-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .child-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 1.25rem;
            border-radius: 12px;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .child-btn:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
        }
        
        .child-btn.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            border-color: var(--accent);
        }
        
        .child-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.25rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .stat-icon {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        
        .stat-present .stat-value { color: var(--success); }
        .stat-absent .stat-value { color: var(--danger); }
        .stat-late .stat-value { color: var(--warning); }
        .stat-percent .stat-value { color: var(--accent); }
        .stat-days .stat-value { color: var(--info); }
        
        .progress-bar-custom {
            height: 8px;
            background: #e5e7eb;
            border-radius: 99px;
            overflow: hidden;
            margin-top: 0.75rem;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--warning));
            border-radius: 99px;
            transition: width 0.5s ease;
        }
        
        .filter-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.25rem 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        
        .week-nav {
            background: white;
            border-radius: var(--radius);
            padding: 0.75rem 1.25rem;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .week-nav-btn {
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .week-nav-btn:hover:not(.disabled) {
            background: var(--accent-dark);
            transform: scale(1.02);
            color: white;
        }
        
        .week-nav-btn.disabled {
            opacity: 0.4;
            pointer-events: none;
        }
        
        .week-label {
            flex: 1;
            text-align: center;
            font-weight: 700;
            color: #1f2937;
        }
        
        .week-badge {
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        .week-summary {
            background: white;
            border-radius: var(--radius);
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .summary-item {
            text-align: center;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 800;
        }
        
        .summary-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
        }
        
        .summary-present .summary-value { color: var(--success); }
        .summary-absent .summary-value { color: var(--danger); }
        .summary-late .summary-value { color: var(--warning); }
        .summary-norecord .summary-value { color: #6b7280; }
        
        .week-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 768px) {
            .week-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .week-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .day-card {
            background: white;
            border-radius: var(--radius);
            padding: 1rem;
            text-align: center;
            box-shadow: var(--shadow);
            border-top: 4px solid #e5e7eb;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .day-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.12);
        }
        
        .day-card.status-present { border-top-color: var(--success); }
        .day-card.status-absent { border-top-color: var(--danger); }
        .day-card.status-late { border-top-color: var(--warning); }
        .day-card.status-future,
        .day-card.status-no_record,
        .day-card.status-not_in_term { border-top-color: #d1d5db; background: #f9fafb; }
        
        .day-name {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7280;
        }
        
        .day-date {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1f2937;
            line-height: 1;
        }
        
        .day-month {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-bottom: 0.5rem;
        }
        
        .status-pill {
            display: inline-block;
            padding: 0.25rem 0.85rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        
        .pill-present { background: #dcfce7; color: var(--success); }
        .pill-absent { background: #fee2e2; color: var(--danger); }
        .pill-late { background: #fef3c7; color: var(--warning); }
        .pill-future, .pill-no_record { background: #f3f4f6; color: #9ca3af; }
        .pill-not_in_term { background: #f3f4f6; color: #9ca3af; font-style: italic; }
        
        .day-details {
            font-size: 0.7rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }
        
        .day-details i {
            margin-right: 0.25rem;
            font-size: 0.65rem;
        }
        
        .type-badge {
            display: inline-block;
            font-size: 0.6rem;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 4px;
            padding: 0.1rem 0.4rem;
            margin-top: 0.3rem;
        }
        
        .chart-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.25rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        
        .section-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #9ca3af;
            margin-bottom: 0.75rem;
        }
        
        .no-data {
            background: white;
            border-radius: var(--radius);
            padding: 3rem;
            text-align: center;
            box-shadow: var(--shadow);
        }
        
        .no-data img {
            width: 100px;
            margin-bottom: 1rem;
            opacity: 0.6;
        }
        
        @media (max-width: 640px) {
            .stat-value { font-size: 1.5rem; }
            .stat-card { padding: 0.75rem; }
            .day-date { font-size: 1.4rem; }
        }
        
        @media print {
            .week-nav, .filter-card, .no-print, .attendance-hero .mascot,
            .child-selector, .btn-print {
                display: none;
            }
            body { background: white; }
            .stat-card, .day-card, .week-nav, .filter-card, .chart-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <main class="container my-4" style="max-width: 1200px">
        
        <!-- Hero Section -->
        <div class="attendance-hero">
            <h1><i class="fas fa-user-check me-2"></i>Child Attendance Monitor</h1>
            <p class="mb-0 opacity-90">Welcome, <?= $parent_name ?> — track your child's attendance</p>
            <img src="/assets/images/parent_monitor.png" alt="Mascot" class="mascot" onerror="this.style.display='none'">
        </div>
        
        <!-- Child Selector -->
        <div class="child-selector no-print">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-child text-primary"></i>
                <span class="fw-semibold">Select Child:</span>
            </div>
            <div class="child-buttons">
                <?php foreach ($children as $child): 
                    $child_avatar = resolveProfilePicture($child['student_profile']);
                ?>
                    <a href="?child_id=<?= $child['student_id'] ?>&class_id=<?= $class_id ?>&term_id=<?= $selected_term_id ?>&week=<?= $selected_week ?>" 
                       class="child-btn <?= $selected_child_id == $child['student_id'] ? 'active' : '' ?>">
                        <img src="<?= $child_avatar ?>" alt="<?= htmlspecialchars($child['student_name']) ?>" class="child-avatar">
                        <span><?= htmlspecialchars($child['student_name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary btn-print">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>
        
        <!-- Student Info Bar -->
        <div class="alert alert-info d-flex align-items-center gap-3 mb-3" style="background: var(--accent-light); border: none;">
            <i class="fas fa-graduation-cap fa-2x text-primary"></i>
            <div>
                <strong><?= htmlspecialchars($selected_child['student_name']) ?></strong><br>
                <small><?= count($student_classes) ?> class(es) enrolled</small>
            </div>
        </div>
        
        <!-- Term Summary Stats -->
        <p class="section-label"><i class="fas fa-chart-simple me-1"></i> Term Overview — <?= $selected_term_name ?></p>
        <div class="stat-grid">
            <div class="stat-card stat-days">
                <div class="stat-icon">📅</div>
                <div class="stat-value"><?= $total_school_days ?></div>
                <div class="stat-label">School Days (Mon-Fri)</div>
            </div>
            <div class="stat-card stat-present">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?= $days_attended ?></div>
                <div class="stat-label">Days Attended</div>
            </div>
            <div class="stat-card stat-absent">
                <div class="stat-icon">❌</div>
                <div class="stat-value"><?= $days_absent ?></div>
                <div class="stat-label">Days Absent</div>
            </div>
            <div class="stat-card stat-late">
                <div class="stat-icon">⏰</div>
                <div class="stat-value"><?= $term_late ?></div>
                <div class="stat-label">Late Arrivals</div>
            </div>
            <div class="stat-card stat-percent">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?= $attendance_percentage ?>%</div>
                <div class="stat-label">Attendance Rate</div>
                <div class="progress-bar-custom">
                    <div class="progress-fill" style="width: <?= $attendance_percentage ?>%"></div>
                </div>
            </div>
        </div>
        
        <!-- Status Message -->
        <?php if ($attendance_percentage < 80 && $total_school_days > 0): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Attendance Alert:</strong> <?= htmlspecialchars($selected_child['student_name']) ?>'s attendance is below 80%. 
                Please encourage regular attendance for better academic performance.
            </div>
        </div>
        <?php elseif ($attendance_percentage >= 90 && $total_school_days > 0): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
            <i class="fas fa-star"></i>
            <div>
                <strong>Excellent!</strong> <?= htmlspecialchars($selected_child['student_name']) ?> has great attendance. Keep up the good work!
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Debug Info -->
        <?php if (empty($attendance_records) && !empty($class_ids)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle me-2"></i>
            <strong>No attendance records found.</strong> 
            <?= htmlspecialchars($selected_child['student_name']) ?> has <?= count($class_ids) ?> class(es) but no attendance records for <?= $selected_term_name ?>.
        </div>
        <?php elseif (empty($class_ids)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>No classes found.</strong> <?= htmlspecialchars($selected_child['student_name']) ?> is not enrolled in any classes.
        </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="filter-card no-print">
            <form method="GET" action="" class="row g-3 align-items-end">
                <input type="hidden" name="child_id" value="<?= $selected_child_id ?>">
                
                <?php if (!empty($student_classes)): ?>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="class_id">
                        <i class="fas fa-chalkboard me-1"></i> Class
                    </label>
                    <select id="class_id" name="class_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">All Classes</option>
                        <?php foreach ($student_classes as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= $class_id == $cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="term_id">
                        <i class="fas fa-calendar-alt me-1"></i> Term
                    </label>
                    <select id="term_id" name="term_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">All Terms</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $selected_term_id == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['term_name']) ?> 
                                (<?= date('M d', strtotime($t['start_date'])) ?> - <?= date('M d', strtotime($t['end_date'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100" style="background: var(--accent); border: none;">
                        <i class="fas fa-search me-1"></i> Apply Filters
                    </button>
                    <a href="?child_id=<?= $selected_child_id ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-undo-alt me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Weekly View Section -->
        <?php if (!empty($weeks) && !empty($student_classes)): ?>
        
        <p class="section-label mt-3">
            <i class="fas fa-calendar-week me-1"></i> Weekly Breakdown (Monday – Friday)
        </p>
        
        <!-- Week Navigation -->
        <div class="week-nav no-print">
            <a href="<?= $prev_url ?>" class="week-nav-btn <?= $selected_week <= 1 ? 'disabled' : '' ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            
            <div class="week-label">
                <span class="week-badge">Week <?= $selected_week ?> of <?= $total_weeks ?></span><br>
                <?= htmlspecialchars($weeks[$week_index]['label']) ?>
            </div>
            
            <div class="d-flex gap-2">
                <?php if ($selected_week > 1): ?>
                <a href="<?= $first_url ?>" class="week-nav-btn" style="background: #6b7280;">
                    <i class="fas fa-fast-backward"></i>
                </a>
                <?php endif; ?>
                <a href="<?= $next_url ?>" class="week-nav-btn <?= $selected_week >= $total_weeks ? 'disabled' : '' ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
        
        <!-- Week Summary -->
        <div class="week-summary">
            <div style="font-weight: 700; color: #1f2937; font-size: 0.9rem;">
                <i class="fas fa-chart-line me-1"></i> This week's summary:
            </div>
            <div class="summary-item summary-present">
                <div class="summary-value"><?= $week_stats['present'] ?></div>
                <div class="summary-label"><i class="fas fa-check-circle me-1"></i>Present</div>
            </div>
            <div class="summary-item summary-absent">
                <div class="summary-value"><?= $week_stats['absent'] ?></div>
                <div class="summary-label"><i class="fas fa-times-circle me-1"></i>Absent</div>
            </div>
            <div class="summary-item summary-late">
                <div class="summary-value"><?= $week_stats['late'] ?></div>
                <div class="summary-label"><i class="fas fa-clock me-1"></i>Late</div>
            </div>
            <div class="summary-item summary-norecord">
                <div class="summary-value"><?= $week_stats['no_record'] ?></div>
                <div class="summary-label"><i class="fas fa-minus-circle me-1"></i>No Record</div>
            </div>
        </div>
        
        <!-- Day Cards Grid -->
        <div class="week-grid">
            <?php foreach ($week_records as $wr): 
                $status = $wr['status'];
                $record = $wr['record'];
                $is_future = $wr['is_future'];
                $is_in_term = $wr['is_in_term'];
                
                $pill_map = [
                    'present'     => ['class' => 'pill-present', 'icon' => '✅', 'text' => 'Present'],
                    'absent'      => ['class' => 'pill-absent', 'icon' => '❌', 'text' => 'Absent'],
                    'late'        => ['class' => 'pill-late', 'icon' => '⏰', 'text' => 'Late'],
                    'future'      => ['class' => 'pill-future', 'icon' => '🔮', 'text' => 'Upcoming'],
                    'no_record'   => ['class' => 'pill-no_record', 'icon' => '📭', 'text' => 'No Record'],
                    'not_in_term' => ['class' => 'pill-not_in_term', 'icon' => '📅', 'text' => 'Outside Term'],
                ];
                $pill = $pill_map[$status] ?? $pill_map['no_record'];
            ?>
            <div class="day-card status-<?= $status ?>">
                <div class="day-name"><?= htmlspecialchars($wr['day']) ?></div>
                <div class="day-date"><?= $wr['day_num'] ?></div>
                <div class="day-month"><?= $wr['month'] ?> <?= $wr['year'] ?></div>
                
                <span class="status-pill <?= $pill['class'] ?>">
                    <?= $pill['icon'] ?> <?= $pill['text'] ?>
                </span>
                
                <?php if ($record): ?>
                <div class="day-details">
                    <i class="fas fa-chalkboard"></i> <?= htmlspecialchars($wr['class_name'] ?: $record['class_name']) ?>
                </div>
                <?php if (!empty($record['type']) && $status !== 'absent'): ?>
                <div class="type-badge">
                    <i class="fas fa-video me-1"></i> <?= ucfirst($record['type']) ?>
                </div>
                <?php endif; ?>
                <?php elseif ($status === 'future'): ?>
                <div class="day-details">
                    <i class="fas fa-hourglass-half"></i> Coming soon
                </div>
                <?php elseif ($status === 'no_record' && $is_in_term): ?>
                <div class="day-details">
                    <i class="fas fa-question-circle"></i> Not recorded yet
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Attendance by Class Chart -->
        <?php if (!empty($class_attendance)): ?>
        <div class="chart-card no-print">
            <h5 class="mb-3">
                <i class="fas fa-chart-pie text-primary me-2"></i>Attendance by Class
            </h5>
            <canvas id="attendanceChart" style="max-height: 300px; width: 100%;"></canvas>
        </div>
        <?php endif; ?>
        
        <?php elseif (empty($student_classes)): ?>
        <div class="no-data">
            <img src="/assets/images/no_data.svg" alt="No data" onerror="this.style.display='none'">
            <h5><i class="fas fa-calendar-times me-2"></i>No Enrollment Found</h5>
            <p class="text-muted"><?= htmlspecialchars($selected_child['student_name']) ?> is not enrolled in any classes.</p>
            <a href="?child_id=<?= $selected_child_id ?>" class="btn btn-primary mt-2" style="background: var(--accent); border: none;">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </a>
        </div>
        <?php else: ?>
        <div class="no-data">
            <img src="/assets/images/no_data.svg" alt="No data" onerror="this.style.display='none'">
            <h5><i class="fas fa-calendar-times me-2"></i>No attendance records found</h5>
            <p class="text-muted">Try adjusting your filters above or check back later.</p>
            <a href="?child_id=<?= $selected_child_id ?>" class="btn btn-primary mt-2" style="background: var(--accent); border: none;">
                <i class="fas fa-sync-alt me-1"></i> Reset Filters
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Parent Note -->
        <div class="alert alert-secondary mt-4 text-center small no-print">
            <i class="fas fa-heart text-danger me-2"></i>
            Stay involved in your child's education. Regular attendance leads to better academic outcomes!
        </div>
        
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize Attendance Chart
        <?php if (!empty($class_attendance)): ?>
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Attendance Percentage (%)',
                    data: <?= json_encode($chart_data) ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.7)',
                    borderColor: '#4f46e5',
                    borderWidth: 2,
                    borderRadius: 8,
                    barPercentage: 0.6,
                    categoryPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Attendance: ${context.raw}%`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Percentage (%)',
                            font: { size: 12 }
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Class',
                            font: { size: 12 }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>
<?php mysqli_close($conn); ob_end_flush(); ?>