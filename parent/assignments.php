<?php
/**
 * Parent Assignments Page — QPMCEDU.com
 * UPDATED: Uses academic_calendar table for current year and term detection
 * 
 * Database schema (based on academic_calendar table):
 *   - academic_calendar   : id, year, term_name, start_date, end_date, created_at
 *   - assignments          : id, title, type, description, due_date, max_points, status, term_id, ...
 *   - assignment_submissions: id, assignment_id, user_id, status, score, feedback (JSON), ...
 *   - student_classes      : user_id, class_id, academic_year (varchar)
 *   - parent_student_mapping: parent_id, student_id
 */

session_start();

// ── Security & Session ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    header("Location: /parent/login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!isset($conn) || !$conn) {
    error_log("Database connection failed");
    http_response_code(500);
    die("System error: Database connection failed.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$parent_id  = (int)$_SESSION['user_id'];

logActivity($conn, $parent_id, "Accessed Assignments Page", "Parent viewed assignments");

// =============================================================================
// HELPER: Get Current Academic Year and Term based on server date
// Uses academic_calendar table (year, term_name, start_date, end_date)
// =============================================================================

/**
 * Finds the current academic term based on current date using academic_calendar table
 */
function getCurrentTermFromCalendar($conn) {
    $today = date('Y-m-d');
    $sql = "SELECT id, year, term_name, start_date, end_date 
            FROM academic_calendar 
            WHERE start_date <= ? AND end_date >= ?
            ORDER BY start_date DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $today, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row;
        }
        $stmt->close();
    }
    
    // Fallback: get the next upcoming term
    $sql = "SELECT id, year, term_name, start_date, end_date 
            FROM academic_calendar 
            WHERE start_date > ?
            ORDER BY start_date ASC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row;
        }
        $stmt->close();
    }
    
    // Last resort: get the latest term
    $sql = "SELECT id, year, term_name, start_date, end_date 
            FROM academic_calendar 
            ORDER BY start_date DESC 
            LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        return $row;
    }
    
    return null;
}

/**
 * Get all unique academic years from academic_calendar
 */
function getAllAcademicYearsFromCalendar($conn) {
    $years = [];
    $sql = "SELECT DISTINCT year, MIN(start_date) as start_date, MAX(end_date) as end_date 
            FROM academic_calendar 
            GROUP BY year 
            ORDER BY year DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $years[] = [
                'year_label' => $row['year'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date']
            ];
        }
    }
    return $years;
}

/**
 * Get terms for a specific academic year from academic_calendar
 */
function getTermsForYear($conn, $year) {
    $terms = [];
    $sql = "SELECT id, year, term_name, start_date, end_date 
            FROM academic_calendar 
            WHERE year = ? 
            ORDER BY start_date ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $terms[] = $row;
        }
        $stmt->close();
    }
    return $terms;
}

// =============================================================================
// FETCH CURRENT ACADEMIC YEAR AND TERM (AUTOMATIC from academic_calendar)
// =============================================================================

$current_term_data = getCurrentTermFromCalendar($conn);
$current_academic_year = $current_term_data ? $current_term_data['year'] : null;
$current_term_id = $current_term_data ? $current_term_data['id'] : null;

// Fetch all academic years for dropdown
$academic_years = getAllAcademicYearsFromCalendar($conn);

// Allow manual override via GET parameters (for viewing other terms)
$selected_year = filter_input(INPUT_GET, 'year', FILTER_UNSAFE_RAW);
$selected_term_id = filter_input(INPUT_GET, 'term_id', FILTER_VALIDATE_INT);

// If no manual selection, use current
if (!$selected_year && $current_academic_year) {
    $selected_year = $current_academic_year;
}
if (!$selected_term_id && $current_term_id) {
    $selected_term_id = $current_term_id;
}

// Get selected academic year details
$selected_ay = null;
foreach ($academic_years as $ay) {
    if ($ay['year_label'] == $selected_year) {
        $selected_ay = $ay;
        break;
    }
}
if (!$selected_ay && !empty($academic_years)) {
    $selected_ay = $academic_years[0];
    $selected_year = $selected_ay['year_label'];
}

// Fetch terms for selected academic year
$terms = [];
if ($selected_year) {
    $terms = getTermsForYear($conn, $selected_year);
}

// Get selected term details
$selected_term = null;
foreach ($terms as $t) {
    if ($t['id'] == $selected_term_id) {
        $selected_term = $t;
        break;
    }
}
if (!$selected_term && !empty($terms)) {
    $selected_term = $terms[0];
    $selected_term_id = $selected_term['id'];
}

// =============================================================================
// FETCH STUDENTS MAPPED TO PARENT (for selected academic year)
// Note: student_classes.academic_year is VARCHAR, matches academic_calendar.year
// =============================================================================

$students = [];
if ($selected_year) {
    $s_stmt = $conn->prepare("
        SELECT DISTINCT 
            u.id, 
            u.name, 
            u.email,
            c.id as class_id, 
            c.class_name
        FROM users u
        JOIN parent_student_mapping psm ON u.id = psm.student_id
        JOIN student_classes sc ON u.id = sc.user_id AND sc.academic_year = ?
        JOIN classes c ON sc.class_id = c.id
        WHERE psm.parent_id = ?
        ORDER BY u.name
    ");
    $s_stmt->bind_param("si", $selected_year, $parent_id);
    $s_stmt->execute();
    $s_res = $s_stmt->get_result();
    while ($row = $s_res->fetch_assoc()) {
        $students[] = $row;
    }
    $s_stmt->close();
}

$selected_student_id = filter_input(INPUT_GET, 'student', FILTER_VALIDATE_INT) ?: 0;
$valid_ids = array_column($students, 'id');
if (!in_array($selected_student_id, $valid_ids)) {
    $selected_student_id = !empty($students) ? $students[0]['id'] : 0;
}
$selected_student = null;
foreach ($students as $s) {
    if ($s['id'] == $selected_student_id) {
        $selected_student = $s;
        break;
    }
}

// =============================================================================
// FILTERS
// =============================================================================

$subject_filter = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: null;
$status_filter  = trim(filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW) ?? '');
$type_filter    = trim(filter_input(INPUT_GET, 'type', FILTER_UNSAFE_RAW) ?? '');
$search         = trim(filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW) ?? '');

$valid_statuses = ['pending', 'submitted', 'graded', 'late', 'returned', 'overdue'];
$valid_types    = ['homework', 'project', 'quiz', 'test', 'classwork', 'essay', 'practical', 'group_work'];
if (!in_array($status_filter, $valid_statuses)) $status_filter = '';
if (!in_array($type_filter, $valid_types)) $type_filter = '';

// =============================================================================
// FETCH ASSIGNMENTS + SUBMISSION DATA
// =============================================================================

$assignments = [];
$subjects_for_filter = [];
$metrics = [
    'total' => 0, 'graded' => 0, 'submitted' => 0, 'pending' => 0,
    'late' => 0, 'overdue' => 0, 'returned' => 0, 'avg_score' => 0, 
    'completion_rate' => 0, 'total_points_earned' => 0, 'total_points_possible' => 0
];

if ($selected_student_id > 0 && $selected_term_id) {
    
    // Subjects for filter dropdown (only published assignments in current term)
    $sub_stmt = $conn->prepare("
        SELECT DISTINCT s.id, s.subject_name, s.subject_code
        FROM subjects s
        JOIN assignments a ON a.subject_id = s.id
        JOIN student_classes sc ON a.class_id = sc.class_id
        WHERE sc.user_id = ? AND sc.academic_year = ? AND a.term_id = ? AND a.status = 'published'
        ORDER BY s.subject_name
    ");
    $sub_stmt->bind_param("isi", $selected_student_id, $selected_year, $selected_term_id);
    $sub_stmt->execute();
    $sub_res = $sub_stmt->get_result();
    while ($row = $sub_res->fetch_assoc()) {
        $subjects_for_filter[] = $row;
    }
    $sub_stmt->close();

    // Build assignment query
    $conditions = ["a.status = 'published'", "sc.user_id = ?", "sc.academic_year = ?", "a.term_id = ?"];
    $params = [$selected_student_id, $selected_year, $selected_term_id];
    $param_types = "isi";

    if ($subject_filter) {
        $conditions[] = "a.subject_id = ?";
        $params[] = $subject_filter;
        $param_types .= "i";
    }
    if ($type_filter) {
        $conditions[] = "a.type = ?";
        $params[] = $type_filter;
        $param_types .= "s";
    }
    if ($search) {
        $conditions[] = "(a.title LIKE ? OR s.subject_name LIKE ? OR a.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $param_types .= "sss";
    }

    $where = implode(' AND ', $conditions);
    $today = new DateTime();

    // Main assignments query
    $a_stmt = $conn->prepare("
        SELECT
            a.id, a.title, a.type, a.description, a.due_date, a.max_points,
            a.is_group_assignment, a.file_path as assignment_file, a.link_url, a.tags,
            a.created_at as assigned_at, a.term_id,
            s.id as subject_id, s.subject_name, s.subject_code,
            t_user.name as teacher_name,
            COALESCE(sub.status, 'pending') as sub_status,
            sub.id as submission_id,
            sub.score,
            sub.submission_date,
            sub.feedback,
            sub.answers,
            sub.submission_text,
            sub.file_paths as submitted_files,
            sub.graded_at,
            sub.late_submission_reason,
            sub.is_graded
        FROM assignments a
        JOIN student_classes sc ON a.class_id = sc.class_id
        JOIN subjects s ON a.subject_id = s.id
        JOIN users t_user ON a.teacher_id = t_user.id
        LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id AND sub.user_id = ?
        WHERE $where
        ORDER BY a.due_date ASC
    ");
    
    // Prepend student_id for the sub LEFT JOIN param
    array_unshift($params, $selected_student_id);
    $param_types = "i" . $param_types;
    
    if ($a_stmt) {
        $a_stmt->bind_param($param_types, ...$params);
        $a_stmt->execute();
        $a_res = $a_stmt->get_result();
        
        while ($row = $a_res->fetch_assoc()) {
            // Parse feedback JSON (same structure as teacher portal)
            $row['feedback_text'] = '';
            $row['feedback_data'] = [];
            $row['revision_notes'] = '';
            if (!empty($row['feedback'])) {
                $dec = json_decode($row['feedback'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                    $row['feedback_text'] = $dec['overall'] ?? $row['feedback'];
                    $row['feedback_data'] = $dec;
                    $row['revision_notes'] = $dec['revision_notes'] ?? '';
                } else {
                    $row['feedback_text'] = $row['feedback'];
                }
            }
            
            // Parse submitted files (JSON array)
            $row['parsed_files'] = [];
            if (!empty($row['submitted_files'])) {
                $dec = json_decode($row['submitted_files'], true);
                $row['parsed_files'] = (json_last_error() === JSON_ERROR_NONE && is_array($dec))
                    ? $dec
                    : array_filter(explode(',', $row['submitted_files']));
            }
            
            // Parse answers if needed for detailed view
            $row['parsed_answers'] = [];
            if (!empty($row['answers'])) {
                $dec = json_decode($row['answers'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                    $row['parsed_answers'] = $dec;
                } elseif (!empty($row['submission_text'])) {
                    $row['parsed_answers'] = [['answer' => $row['submission_text']]];
                }
            }
            
            // Score percentage
            $row['score_pct'] = ($row['score'] > 0 && $row['max_points'] > 0)
                ? round(($row['score'] / $row['max_points']) * 100, 1)
                : null;
            
            // Determine if overdue (based on due date)
            $row['is_overdue'] = false;
            if (!empty($row['due_date']) && in_array($row['sub_status'], ['pending', 'submitted', ''])) {
                try {
                    $due = new DateTime($row['due_date']);
                    $row['is_overdue'] = $today > $due;
                } catch (Exception $e) {}
            }
            
            // Determine if late (submitted after due date)
            $row['is_late_submission'] = false;
            if (!empty($row['submission_date']) && !empty($row['due_date'])) {
                try {
                    $sub_date = new DateTime($row['submission_date']);
                    $due = new DateTime($row['due_date']);
                    $row['is_late_submission'] = $sub_date > $due;
                } catch (Exception $e) {}
            }
            
            // Determine display status
            if ($row['sub_status'] === 'graded') {
                $row['display_status'] = 'graded';
            } elseif ($row['sub_status'] === 'returned') {
                $row['display_status'] = 'returned';
            } elseif ($row['sub_status'] === 'submitted') {
                $row['display_status'] = 'submitted';
            } elseif ($row['sub_status'] === 'late' || $row['is_late_submission']) {
                $row['display_status'] = 'late';
            } elseif ($row['is_overdue']) {
                $row['display_status'] = 'overdue';
            } else {
                $row['display_status'] = 'pending';
            }
            
            $assignments[] = $row;
        }
        $a_stmt->close();
    }
    
    // =========================================================================
    // CALCULATE METRICS
    // =========================================================================
    
    $metrics['total'] = count($assignments);
    $metrics['graded'] = count(array_filter($assignments, fn($a) => $a['display_status'] === 'graded'));
    $metrics['submitted'] = count(array_filter($assignments, fn($a) => $a['display_status'] === 'submitted'));
    $metrics['pending'] = count(array_filter($assignments, fn($a) => $a['display_status'] === 'pending'));
    $metrics['overdue'] = count(array_filter($assignments, fn($a) => $a['display_status'] === 'overdue'));
    $metrics['late'] = count(array_filter($assignments, fn($a) => $a['display_status'] === 'late'));
    $metrics['returned'] = count(array_filter($assignments, fn($a) => $a['display_status'] === 'returned'));
    
    // Calculate average score (only graded assignments)
    $scores = array_filter(array_column($assignments, 'score'), fn($s) => $s !== null && $s > 0);
    $metrics['avg_score'] = !empty($scores) ? round(array_sum($scores) / count($scores), 1) : 0;
    
    // Calculate total points earned vs possible
    foreach ($assignments as $a) {
        if ($a['score'] !== null && $a['max_points'] > 0 && $a['display_status'] === 'graded') {
            $metrics['total_points_earned'] += (float)$a['score'];
            $metrics['total_points_possible'] += (float)$a['max_points'];
        }
    }
    
    // Completion rate
    $metrics['completion_rate'] = $metrics['total'] > 0
        ? round((($metrics['graded'] + $metrics['submitted'] + $metrics['late']) / $metrics['total']) * 100, 1)
        : 0;
    
    // Overall percentage
    $metrics['overall_percentage'] = $metrics['total_points_possible'] > 0
        ? round(($metrics['total_points_earned'] / $metrics['total_points_possible']) * 100, 1)
        : 0;
}

// Chart data (only graded assignments with scores)
$chart_labels = $chart_scores = $chart_max = [];
foreach ($assignments as $a) {
    if ($a['display_status'] === 'graded' && $a['score'] !== null && $a['score'] > 0) {
        $label = strlen($a['title']) > 25 ? substr($a['title'], 0, 22) . '…' : $a['title'];
        $chart_labels[] = $label;
        $chart_scores[] = (float)$a['score'];
        $chart_max[] = (float)$a['max_points'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Assignments | QPMCEDU Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js"></script>
    <style>
        :root {
            --qpms-orange: #ff6b00;
            --qpms-orange-dark: #e65c00;
            --qpms-orange-light: #fff3e0;
            --qpms-gray: #212529;
        }
        body { background:#f8f9fa; font-family:'Inter',sans-serif; color:var(--qpms-gray); overflow-x:hidden; }
        
        /* Cards */
        .card { border:none; border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,.05); overflow:hidden; margin-bottom:1.5rem; }
        .card-header { background:var(--qpms-orange); color:white; font-weight:600; padding:1rem 1.5rem; border-bottom:none; }
        .card-header h5, .card-header h3 { margin:0; }
        
        /* Buttons */
        .btn-orange { background:var(--qpms-orange); border:none; color:white; border-radius:.75rem; padding:.5rem 1.25rem; font-weight:500; transition:all .2s; }
        .btn-orange:hover { background:var(--qpms-orange-dark); color:white; transform:translateY(-1px); }
        .btn-outline-orange { border:1px solid var(--qpms-orange); color:var(--qpms-orange); background:transparent; border-radius:.75rem; padding:.5rem 1.25rem; transition:all .2s; }
        .btn-outline-orange:hover { background:var(--qpms-orange); color:white; }
        
        /* Stat cards */
        .stat-card { background:white; border-radius:1rem; padding:1.25rem; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,.04); transition:transform .2s; height:100%; }
        .stat-card:hover { transform:translateY(-3px); }
        .stat-card i { font-size:1.75rem; margin-bottom:.5rem; display:block; }
        .stat-card h6 { font-size:1.75rem; font-weight:700; margin:.3rem 0; color:var(--qpms-gray); }
        .stat-card small { color:#6c757d; font-size:.8rem; }
        .stat-card.orange i { color:var(--qpms-orange); }
        .stat-card.danger i { color:#ef4444; }
        .stat-card.warning i { color:#f59e0b; }
        .stat-card.success i { color:#10b981; }
        .stat-card.info i { color:#3b82f6; }
        .stat-card.purple i { color:#8b5cf6; }
        
        /* Table */
        .table { font-size:.875rem; }
        .table thead th { background:#fff3e6; color:var(--qpms-orange); font-weight:600; border-bottom:2px solid var(--qpms-orange); white-space:nowrap; }
        .form-select:focus, .form-control:focus { border-color:var(--qpms-orange); box-shadow:0 0 0 .2rem rgba(255,107,0,.25); }
        
        /* Status badges */
        .status-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.3rem .75rem; border-radius:9999px; font-size:.72rem; font-weight:500; white-space:nowrap; }
        .status-graded    { background:#d1fae5; color:#065f46; }
        .status-submitted { background:#dbeafe; color:#1e40af; }
        .status-pending   { background:#fef3c7; color:#92400e; }
        .status-late      { background:#fee2e2; color:#991b1b; }
        .status-overdue   { background:#fce7f3; color:#9d174d; }
        .status-returned  { background:#f3e8ff; color:#6b21a5; }
        
        /* Score styling */
        .score-high { color:#10b981; font-weight:600; }
        .score-mid  { color:#f59e0b; font-weight:600; }
        .score-low  { color:#ef4444; font-weight:600; }
        
        /* Progress bar */
        .score-bar-wrap { background:#e5e7eb; border-radius:9999px; height:6px; width:80px; display:inline-block; vertical-align:middle; margin-left:.4rem; overflow:hidden; }
        .score-bar { height:100%; border-radius:9999px; transition:width .4s; }
        .bar-high { background:#10b981; }
        .bar-mid  { background:#f59e0b; }
        .bar-low  { background:#ef4444; }
        
        /* Modal */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:1050; display:none; justify-content:center; align-items:flex-start; padding:2rem 1rem; overflow-y:auto; }
        .modal-overlay.show { display:flex; }
        .modal-box { background:white; border-radius:1.25rem; max-width:820px; width:100%; margin:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); animation:modalIn .25s ease; }
        @keyframes modalIn { from{opacity:0;transform:translateY(-20px)} to{opacity:1;transform:translateY(0)} }
        .modal-box-header { padding:1.25rem 1.5rem; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; }
        .modal-box-body { padding:1.5rem; max-height:70vh; overflow-y:auto; }
        .modal-box-footer { padding:1rem 1.5rem; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:.75rem; }
        
        /* Detail sections */
        .detail-row { display:flex; gap:.5rem; margin-bottom:.6rem; font-size:.875rem; }
        .detail-label { font-weight:600; color:#374151; min-width:130px; }
        .detail-val { color:#6b7280; }
        .answer-card { border-left:4px solid #ff6b00; background:#fff7f0; border-radius:.5rem; padding:1rem; margin-bottom:.75rem; }
        .feedback-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:.75rem; padding:1rem; }
        .revision-box { background:#fdf4ff; border:1px solid #e9d5ff; border-radius:.75rem; padding:1rem; }
        
        /* Filter bar */
        .filter-bar { background:white; border-radius:1rem; padding:1rem 1.25rem; box-shadow:0 2px 8px rgba(0,0,0,.04); margin-bottom:1.5rem; }
        
        /* Student header */
        .student-header { background:linear-gradient(135deg,var(--qpms-orange) 0%,var(--qpms-orange-dark) 100%); color:white; }
        .student-header .form-select, .student-header .form-control { background:rgba(255,255,255,.15); color:white; border-color:rgba(255,255,255,.3); }
        .student-header .form-select option { color:var(--qpms-gray); background:white; }
        .student-header .form-label { color:rgba(255,255,255,.8); }
        
        /* Empty state */
        .empty-state { text-align:center; padding:3rem; }
        .empty-state i { font-size:3rem; color:#cbd5e1; margin-bottom:1rem; display:block; }
        
        /* Row hover */
        .assignments-table tbody tr { cursor:pointer; transition:background .15s; }
        .assignments-table tbody tr:hover { background:#fff7f0 !important; }
        
        /* Alert */
        .alert-overdue { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:.75rem; padding:.75rem 1rem; font-size:.85rem; margin-bottom:1rem; }
        
        /* Current term indicator */
        .current-badge { background:rgba(255,255,255,.2); border-radius:9999px; padding:.25rem .75rem; font-size:.75rem; display:inline-flex; align-items:center; gap:.3rem; }
        
        footer { text-align:center; padding:1.5rem; color:#6c757d; font-size:.85rem; border-top:1px solid #dee2e6; margin-top:2rem; }
        
        /* Tags */
        .tag-pill { display:inline-block; background:#f3f4f6; color:#374151; border-radius:9999px; padding:.2rem .6rem; font-size:.72rem; margin:.15rem; }
        
        /* Progress circle */
        .progress-circle { width:100px; height:100px; margin:0 auto; }
        
        @media print {
            .no-print, form, .filter-bar, .student-header form { display:none!important; }
            body { background:white; }
            .card { box-shadow:none; border:1px solid #ddd; break-inside:avoid; }
        }
        
        @media(max-width:768px){
            .stat-card h6 { font-size:1.2rem; }
            .stat-card i { font-size:1.2rem; }
            .table { font-size:.75rem; }
            .status-badge { font-size:.65rem; padding:.2rem .5rem; }
        }
    </style>
</head>
<body>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php'; ?>

<div class="container-fluid px-0">

    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 px-3">
        <div>
            <h2 class="mb-1 fw-semibold" style="color:var(--qpms-gray);">
                <i class="fas fa-tasks me-2" style="color:var(--qpms-orange);"></i>Assignments
            </h2>
            <p class="text-muted mb-0">
                <i class="fas fa-info-circle me-1"></i>
                Track your child's homework, projects, quizzes & grades
            </p>
        </div>
        <div class="mt-2 mt-sm-0 d-flex gap-2 no-print">
            <button onclick="window.print()" class="btn btn-outline-orange">
                <i class="fas fa-print me-1"></i>Print
            </button>
            <a href="/parent/dashboard.php" class="btn btn-orange">
                <i class="fas fa-tachometer-alt me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <?php if (empty($students)): ?>
    <!-- No Students Message -->
    <div class="card mx-3">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h5>No Students Found</h5>
                <p class="text-muted">No students are linked to your account for <?= htmlspecialchars($selected_year ?? 'this academic year') ?>. Please contact school administration.</p>
            </div>
        </div>
    </div>

    <?php elseif (!$selected_term): ?>
    <!-- No Term Found Message -->
    <div class="card mx-3">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h5>No Academic Terms Found</h5>
                <p class="text-muted">No terms have been defined for <?= htmlspecialchars($selected_year ?? 'this academic year') ?> in the academic calendar.</p>
            </div>
        </div>
    </div>

    <?php else: ?>

    <!-- Student / Year / Term Selector -->
    <div class="card student-header mx-3 mb-4">
        <div class="card-body">
            <form method="GET" id="selectorForm" class="row g-3 align-items-end">
                <!-- Academic Year -->
                <div class="col-md-3">
                    <label class="form-label small mb-1">Academic Year</label>
                    <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($academic_years as $ay): ?>
                            <option value="<?= htmlspecialchars($ay['year_label']) ?>" <?= $ay['year_label'] == $selected_year ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ay['year_label']) ?>
                                <?= ($current_academic_year && $ay['year_label'] == $current_academic_year) ? '✓ Current' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Term -->
                <div class="col-md-3">
                    <label class="form-label small mb-1">Term</label>
                    <select name="term_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($terms as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $t['id'] == $selected_term_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['term_name']) ?>
                                <?= ($current_term_id && $t['id'] == $current_term_id) ? '✓ Current' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Student -->
                <div class="col-md-3">
                    <label class="form-label small mb-1">Student</label>
                    <select name="student" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($students as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= $st['id'] == $selected_student_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['name']) ?> — <?= htmlspecialchars($st['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="current-badge">
                        <i class="fas fa-calendar-alt me-1"></i>
                        Current: <?= htmlspecialchars(($current_term_data ? $current_term_data['term_name'] . ' ' . $current_term_data['year'] : 'Not set')) ?>
                    </div>
                </div>
                <!-- Preserve filters when changing selector -->
                <?php if ($subject_filter): ?><input type="hidden" name="subject_id" value="<?= $subject_filter ?>"><?php endif; ?>
                <?php if ($status_filter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>"><?php endif; ?>
                <?php if ($type_filter): ?><input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>"><?php endif; ?>
                <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
            </form>
            <?php if ($selected_student): ?>
            <div class="mt-2 opacity-75 small">
                <i class="fas fa-graduation-cap me-1"></i><?= htmlspecialchars($selected_student['class_name']) ?>
                &nbsp;|&nbsp;
                <i class="fas fa-calendar me-1"></i><?= htmlspecialchars($selected_year ?? '') ?>
                &nbsp;|&nbsp;
                <i class="fas fa-bookmark me-1"></i><?= htmlspecialchars($selected_term['term_name'] ?? '') ?>
                <?php if ($current_term_data && $selected_term_id == $current_term_data['id']): ?>
                    <span class="ms-2 badge bg-light text-dark">Current Term</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selected_student_id && $selected_student): ?>

    <!-- Overdue Alert -->
    <?php if ($metrics['overdue'] > 0): ?>
    <div class="alert-overdue mx-3 no-print">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong><?= $metrics['overdue'] ?> assignment<?= $metrics['overdue'] > 1 ? 's are' : ' is' ?> overdue</strong> — please check with <?= htmlspecialchars($selected_student['name']) ?> immediately.
    </div>
    <?php endif; ?>

    <!-- Returned Alert -->
    <?php if ($metrics['returned'] > 0): ?>
    <div class="alert alert-warning mx-3 no-print" style="background:#fef3c7; border-color:#fde68a; color:#92400e;">
        <i class="fas fa-undo-alt me-2"></i>
        <strong><?= $metrics['returned'] ?> assignment<?= $metrics['returned'] > 1 ? 's have' : ' has' ?> been returned</strong> for revision. Please review the teacher's feedback.
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row g-3 mx-2 mb-4">
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card orange">
                <i class="fas fa-tasks"></i>
                <h6><?= $metrics['total'] ?></h6>
                <small>Total</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card success">
                <i class="fas fa-check-circle"></i>
                <h6><?= $metrics['graded'] ?></h6>
                <small>Graded</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card info">
                <i class="fas fa-paper-plane"></i>
                <h6><?= $metrics['submitted'] + $metrics['late'] ?></h6>
                <small>Submitted</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card warning">
                <i class="fas fa-hourglass-half"></i>
                <h6><?= $metrics['pending'] ?></h6>
                <small>Pending</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card danger">
                <i class="fas fa-clock"></i>
                <h6><?= $metrics['overdue'] ?></h6>
                <small>Overdue</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card purple">
                <i class="fas fa-undo"></i>
                <h6><?= $metrics['returned'] ?></h6>
                <small>Returned</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card orange">
                <i class="fas fa-chart-line"></i>
                <h6><?= $metrics['completion_rate'] ?>%</h6>
                <small>Completion</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card success">
                <i class="fas fa-star"></i>
                <h6><?= number_format($metrics['avg_score'], 1) ?></h6>
                <small>Avg Score</small>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card info">
                <i class="fas fa-percent"></i>
                <h6><?= $metrics['overall_percentage'] ?>%</h6>
                <small>Overall</small>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar mx-3 no-print">
        <form method="GET" id="filterForm" class="row g-2 align-items-end">
            <input type="hidden" name="year" value="<?= htmlspecialchars($selected_year ?? '') ?>">
            <input type="hidden" name="term_id" value="<?= $selected_term_id ?>">
            <input type="hidden" name="student" value="<?= $selected_student_id ?>">

            <div class="col-sm-4 col-lg-3">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Title, subject, description…" value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Subject</label>
                <select name="subject_id" class="form-select form-select-sm">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects_for_filter as $subj): ?>
                    <option value="<?= $subj['id'] ?>" <?= $subject_filter == $subj['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($subj['subject_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="pending"   <?= $status_filter === 'pending'   ? 'selected' : '' ?>>Pending</option>
                    <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="graded"    <?= $status_filter === 'graded'    ? 'selected' : '' ?>>Graded</option>
                    <option value="late"      <?= $status_filter === 'late'      ? 'selected' : '' ?>>Late</option>
                    <option value="returned"  <?= $status_filter === 'returned'  ? 'selected' : '' ?>>Returned</option>
                    <option value="overdue"   <?= $status_filter === 'overdue'   ? 'selected' : '' ?>>Overdue</option>
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach (['homework','project','quiz','test','classwork','essay','practical','group_work'] as $tp): ?>
                    <option value="<?= $tp ?>" <?= $type_filter === $tp ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $tp)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-orange btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="?year=<?= urlencode($selected_year ?? '') ?>&term_id=<?= $selected_term_id ?>&student=<?= $selected_student_id ?>" class="btn btn-outline-secondary btn-sm ms-1">
                    <i class="fas fa-times me-1"></i>Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Assignments Table -->
    <div class="card mx-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Assignment Details</h5>
            <span class="badge bg-light text-dark"><?= $metrics['total'] ?> assignments</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($assignments)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h6>No Assignments Found</h6>
                <p class="text-muted">No published assignments match the current filters for <?= htmlspecialchars($selected_student['name']) ?> in <?= htmlspecialchars($selected_term['term_name']) ?>.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 assignments-table">
                    <thead>
                        <tr>
                            <th style="width:30%">Assignment</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Due Date</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a):
                            $score_class = '';
                            if ($a['score_pct'] !== null) {
                                if ($a['score_pct'] >= 80) $score_class = 'score-high';
                                elseif ($a['score_pct'] >= 50) $score_class = 'score-mid';
                                else $score_class = 'score-low';
                            }
                            $bar_class = $score_class === 'score-high' ? 'bar-high' : ($score_class === 'score-mid' ? 'bar-mid' : 'bar-low');
                            $row_class = $a['display_status'] === 'overdue' ? 'table-danger' : ($a['display_status'] === 'returned' ? 'table-warning' : '');
                        ?>
                        <tr onclick="openDetail(<?= $a['id'] ?>)" class="<?= $row_class ?>" id="row-<?= $a['id'] ?>">
                            <td>
                                <strong class="d-block"><?= htmlspecialchars($a['title']) ?></strong>
                                <small class="text-muted">
                                    <i class="fas fa-chalkboard-teacher me-1"></i><?= htmlspecialchars($a['teacher_name']) ?>
                                </small>
                                <?php if (!empty($a['is_group_assignment']) && $a['is_group_assignment']): ?>
                                <span class="tag-pill ms-1"><i class="fas fa-users me-1"></i>Group</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-medium"><?= htmlspecialchars($a['subject_name']) ?></span>
                                <?php if (!empty($a['subject_code'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($a['subject_code']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $a['type']))) ?></span></td>
                            <td>
                                <?php if (!empty($a['due_date'])): ?>
                                    <?php
                                    $due = new DateTime($a['due_date']);
                                    $now = new DateTime();
                                    $diff = $now->diff($due);
                                    $days_left = $now < $due ? (int)$diff->days : -(int)$diff->days;
                                    ?>
                                    <i class="far fa-calendar-alt me-1 text-muted"></i>
                                    <?= $due->format('M d, Y') ?>
                                    <?php if (!in_array($a['display_status'], ['graded', 'submitted', 'late'])): ?>
                                        <br>
                                        <?php if ($days_left < 0): ?>
                                            <small class="text-danger"><i class="fas fa-exclamation-circle me-1"></i><?= abs($days_left) ?>d overdue</small>
                                        <?php elseif ($days_left <= 3): ?>
                                            <small class="text-warning"><i class="fas fa-clock me-1"></i><?= $days_left ?>d left</small>
                                        <?php else: ?>
                                            <small class="text-muted"><?= $days_left ?>d left</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($a['display_status'] === 'graded' && $a['score'] !== null): ?>
                                    <span class="<?= $score_class ?>">
                                        <?= number_format($a['score'], 1) ?> / <?= number_format($a['max_points'], 1) ?>
                                    </span>
                                    <?php if ($a['score_pct'] !== null): ?>
                                    <br>
                                    <small class="<?= $score_class ?>"><?= $a['score_pct'] ?>%</small>
                                    <span class="score-bar-wrap">
                                        <span class="score-bar <?= $bar_class ?>" style="width:<?= min(100, $a['score_pct']) ?>%"></span>
                                    </span>
                                    <?php endif; ?>
                                <?php elseif ($a['display_status'] === 'submitted' || $a['display_status'] === 'late'): ?>
                                    <span class="text-muted">Pending grade</span>
                                <?php else: ?>
                                    <span class="text-muted">— / <?= number_format($a['max_points'], 1) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge_map = [
                                    'graded'    => ['graded',    'check-circle',       'Graded'],
                                    'submitted' => ['submitted', 'paper-plane',        'Submitted'],
                                    'late'      => ['late',      'exclamation-circle', 'Late'],
                                    'returned'  => ['returned',  'undo',               'Returned'],
                                    'overdue'   => ['overdue',   'clock',              'Overdue'],
                                    'pending'   => ['pending',   'hourglass-half',     'Pending'],
                                ];
                                [$bClass, $bIcon, $bLabel] = $badge_map[$a['display_status']] ?? $badge_map['pending'];
                                ?>
                                <span class="status-badge status-<?= $bClass ?>">
                                    <i class="fas fa-<?= $bIcon ?>"></i><?= $bLabel ?>
                                </span>
                                <?php if ($a['display_status'] === 'returned' && $a['revision_notes']): ?>
                                <br><small class="text-muted d-block mt-1"><i class="fas fa-info-circle me-1"></i>Revision needed</small>
                                <?php endif; ?>
                            </td>
                            <td class="no-print">
                                <button class="btn btn-sm btn-outline-orange" onclick="event.stopPropagation();openDetail(<?= $a['id'] ?>)">
                                    <i class="fas fa-eye me-1"></i>Details
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Score Chart (only if there are graded assignments) -->
    <?php if (!empty($chart_labels)): ?>
    <div class="card mx-3 mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Score Overview — <?= htmlspecialchars($selected_student['name']) ?></h5>
        </div>
        <div class="card-body">
            <div id="scoreChart" style="height:350px;"></div>
            <p class="text-muted small text-center mt-2 mb-0">
                <i class="fas fa-info-circle me-1"></i>Showing graded assignments. Bars show score vs maximum points.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal-box">
            <div class="modal-box-header">
                <div>
                    <h5 class="mb-0 fw-bold" id="modalTitle">Assignment Details</h5>
                    <small class="text-muted" id="modalSubject"></small>
                </div>
                <button onclick="closeDetail()" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-box-body" id="modalBody">
                <!-- Populated by JS -->
            </div>
            <div class="modal-box-footer">
                <button onclick="closeDetail()" class="btn btn-secondary btn-sm">Close</button>
            </div>
        </div>
    </div>

    <?php endif; // selected_student ?>
    <?php endif; // students ?>

    <!-- Footer -->
    <footer class="no-print">
        <p class="mb-0">&copy; <?= date('Y') ?> QueensPa School — Parent Portal</p>
        <p class="small text-muted mt-1">For questions about assignments, please contact your child's subject teacher.</p>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Assignment data passed from PHP
const ASSIGNMENTS = <?= json_encode(array_values($assignments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Helper functions
function esc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function statusBadge(status) {
    const map = {
        'graded':    ['status-graded',    'check-circle',      'Graded'],
        'submitted': ['status-submitted', 'paper-plane',       'Submitted'],
        'late':      ['status-late',      'exclamation-circle','Late'],
        'returned':  ['status-returned',  'undo',              'Returned for Revision'],
        'overdue':   ['status-overdue',   'clock',             'Overdue'],
        'pending':   ['status-pending',   'hourglass-half',    'Pending']
    };
    const [cls, icon, label] = map[status] || map.pending;
    return `<span class="status-badge ${cls}"><i class="fas fa-${icon} me-1"></i>${label}</span>`;
}

function scoreColor(pct) {
    if (pct === null || pct === undefined) return '';
    return pct >= 80 ? 'score-high' : (pct >= 50 ? 'score-mid' : 'score-low');
}

function barColor(pct) {
    return pct >= 80 ? 'bar-high' : (pct >= 50 ? 'bar-mid' : 'bar-low');
}

function formatDate(d) {
    if (!d) return 'Not set';
    try {
        const dt = new Date(d.replace(' ', 'T'));
        if (isNaN(dt)) return d;
        return dt.toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'});
    } catch(e) { return d; }
}

function formatDateTime(d) {
    if (!d) return '—';
    try {
        const dt = new Date(d.replace(' ', 'T'));
        if (isNaN(dt)) return d;
        return dt.toLocaleString('en-GB', {day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
    } catch(e) { return d; }
}

// Open assignment detail modal
function openDetail(id) {
    const a = ASSIGNMENTS.find(x => x.id == id);
    if (!a) return;
    
    document.getElementById('modalTitle').textContent = a.title;
    document.getElementById('modalSubject').textContent = a.subject_name + (a.subject_code ? ` (${a.subject_code})` : '');
    
    let html = '';
    
    // Assignment info section
    html += `<div class="row g-3 mb-3">
        <div class="col-sm-6">
            <div class="detail-row"><span class="detail-label">Type:</span><span class="detail-val"><span class="badge bg-secondary">${esc(a.type ? a.type.charAt(0).toUpperCase() + a.type.slice(1).replace('_', ' ') : 'Assignment')}</span></span></div>
            <div class="detail-row"><span class="detail-label">Teacher:</span><span class="detail-val"><i class="fas fa-chalkboard-teacher me-1 text-muted"></i>${esc(a.teacher_name)}</span></div>
            <div class="detail-row"><span class="detail-label">Max Points:</span><span class="detail-val fw-bold">${parseFloat(a.max_points).toFixed(1)}</span></div>
            <div class="detail-row"><span class="detail-label">Assigned:</span><span class="detail-val">${formatDate(a.assigned_at)}</span></div>
            <div class="detail-row"><span class="detail-label">Due Date:</span><span class="detail-val text-danger fw-medium">${formatDate(a.due_date)}</span></div>
        </div>
        <div class="col-sm-6">
            <div class="detail-row"><span class="detail-label">Status:</span><span class="detail-val">${statusBadge(a.display_status)}</span></div>
            ${a.submission_id ? `<div class="detail-row"><span class="detail-label">Submitted:</span><span class="detail-val">${formatDateTime(a.submission_date)}</span></div>` : ''}
            ${a.display_status === 'graded' && a.score ? `
            <div class="detail-row">
                <span class="detail-label">Score:</span>
                <span class="detail-val">
                    <span class="${scoreColor(a.score_pct)} fs-5">${parseFloat(a.score).toFixed(1)}</span>
                    / ${parseFloat(a.max_points).toFixed(1)}
                    ${a.score_pct ? `<span class="${scoreColor(a.score_pct)} ms-1">(${a.score_pct}%)</span>` : ''}
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">&nbsp;</span>
                <span class="detail-val">
                    <div class="score-bar-wrap" style="width:120px;">
                        <div class="score-bar ${barColor(a.score_pct)}" style="width:${Math.min(100, a.score_pct)}%"></div>
                    </div>
                </span>
            </div>` : ''}
            ${a.graded_at ? `<div class="detail-row"><span class="detail-label">Graded At:</span><span class="detail-val">${formatDateTime(a.graded_at)}</span></div>` : ''}
        </div>
    </div>`;
    
    // Description
    if (a.description) {
        html += `<div class="mb-3">
            <p class="fw-semibold small text-muted text-uppercase mb-1"><i class="fas fa-align-left me-1"></i>Description</p>
            <p class="text-gray-700 mb-0">${esc(a.description)}</p>
        </div>`;
    }
    
    // Resources (link / file)
    let resources = '';
    if (a.link_url) {
        resources += `<a href="${esc(a.link_url)}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-2"><i class="fas fa-external-link-alt me-1"></i>Resource Link</a>`;
    }
    if (a.assignment_file) {
        resources += `<a href="${esc(a.assignment_file)}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-download me-1"></i>Download Assignment</a>`;
    }
    if (resources) html += `<div class="mb-3">${resources}</div>`;
    
    // Tags
    if (a.tags) {
        try {
            const tags = JSON.parse(a.tags);
            if (Array.isArray(tags) && tags.length) {
                html += `<div class="mb-3">${tags.map(t => `<span class="tag-pill">${esc(t)}</span>`).join('')}</div>`;
            }
        } catch(e) {}
    }
    
    // Submission section
    if (!a.submission_id) {
        html += `<div class="alert alert-warning mb-3 py-2">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>${a.display_status === 'overdue' ? 'This assignment is overdue and has not been submitted.' : 'This assignment has not been submitted yet.'}</strong>
        </div>`;
    } else {
        // Submitted files
        if (a.parsed_files && a.parsed_files.length > 0) {
            html += `<div class="mb-3">
                <p class="fw-semibold small text-muted text-uppercase mb-1"><i class="fas fa-paperclip me-1"></i>Submitted Files</p>
                ${a.parsed_files.map((f, i) => `
                    <a href="${esc(f)}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-1 mb-1">
                        <i class="fas fa-file me-1"></i>File ${i+1}
                    </a>`).join('')}
            </div>`;
        }
        
        // Submission text
        if (a.submission_text) {
            html += `<div class="mb-3">
                <p class="fw-semibold small text-muted text-uppercase mb-2"><i class="fas fa-pen me-1"></i>Written Response</p>
                <div class="answer-card">${esc(a.submission_text)}</div>
            </div>`;
        }
        
        // Answers (if structured)
        if (a.parsed_answers && Array.isArray(a.parsed_answers) && a.parsed_answers.length > 0 && !a.submission_text) {
            html += `<div class="mb-3">
                <p class="fw-semibold small text-muted text-uppercase mb-2"><i class="fas fa-question-circle me-1"></i>Answers</p>`;
            a.parsed_answers.forEach((ans, idx) => {
                const answerText = typeof ans === 'string' ? ans : (ans.answer || ans.text || JSON.stringify(ans));
                html += `<div class="answer-card"><strong>Q${idx+1}:</strong> ${esc(answerText)}</div>`;
            });
            html += `</div>`;
        }
        
        // Late submission reason
        if (a.late_submission_reason) {
            html += `<div class="mb-3">
                <p class="fw-semibold small text-muted text-uppercase mb-1"><i class="fas fa-comment-alt me-1"></i>Late Submission Reason</p>
                <p class="text-muted fst-italic">${esc(a.late_submission_reason)}</p>
            </div>`;
        }
    }
    
    // Teacher Feedback (if graded)
    if (a.display_status === 'graded' && a.feedback_text) {
        html += `<div class="feedback-box mb-3">
            <p class="fw-semibold small text-success mb-1"><i class="fas fa-comment-dots me-1"></i>Teacher Feedback</p>
            <p class="mb-0">${esc(a.feedback_text)}</p>
        </div>`;
    }
    
    // Revision notes (if returned)
    if (a.display_status === 'returned' && a.revision_notes) {
        html += `<div class="revision-box mb-3">
            <p class="fw-semibold small mb-1" style="color:#7c3aed"><i class="fas fa-undo me-1"></i>Revision Instructions from Teacher</p>
            <p class="mb-0">${esc(a.revision_notes)}</p>
        </div>`;
    }
    
    // Encouragement tip
    if (a.display_status === 'graded' && a.score_pct !== null) {
        let tip = '';
        if (a.score_pct >= 80) tip = '🌟 Excellent work! Keep up the great effort.';
        else if (a.score_pct >= 60) tip = '👍 Good effort! There is room for improvement.';
        else tip = '📚 Please review this subject with your child and speak to the teacher if needed.';
        html += `<p class="text-muted small mt-2"><i class="fas fa-lightbulb me-1 text-warning"></i>${tip}</p>`;
    }
    
    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('detailModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeDetail() {
    document.getElementById('detailModal').classList.remove('show');
    document.body.style.overflow = '';
}

// Close modal on backdrop click
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetail();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDetail();
});

// ── ApexCharts (if data exists) ──
<?php if (!empty($chart_labels)): ?>
(function() {
    const labels = <?= json_encode($chart_labels) ?>;
    const scores = <?= json_encode($chart_scores) ?>;
    const maxPts = <?= json_encode($chart_max) ?>;
    const pcts = scores.map((s, i) => maxPts[i] > 0 ? parseFloat((s / maxPts[i] * 100).toFixed(1)) : 0);
    
    new ApexCharts(document.querySelector('#scoreChart'), {
        chart: { type: 'bar', height: 350, toolbar: { show: false }, background: 'transparent' },
        series: [
            { name: 'Score', data: scores, color: '#ff6b00' },
            { name: 'Max Points', data: maxPts, color: '#e5e7eb' }
        ],
        xaxis: {
            categories: labels,
            labels: { rotate: -40, rotateAlways: true, style: { fontSize: '11px' } }
        },
        yaxis: { title: { text: 'Points' }, min: 0 },
        plotOptions: {
            bar: { borderRadius: 5, columnWidth: '55%', dataLabels: { position: 'top' }, grouped: true }
        },
        dataLabels: {
            enabled: true, offsetY: -18,
            style: { fontSize: '11px', fontWeight: 'bold' },
            formatter: (val, opts) => opts.seriesIndex === 0 ? val.toFixed(1) : ''
        },
        tooltip: {
            shared: true,
            y: {
                formatter: (val, { seriesIndex, dataPointIndex }) => {
                    if (seriesIndex === 0) return `${val.toFixed(1)} pts (${pcts[dataPointIndex]}%)`;
                    return `${val.toFixed(1)} pts (max)`;
                }
            }
        },
        legend: { show: true, position: 'top' },
        grid: { borderColor: '#e9ecef' }
    }).render();
})();
<?php endif; ?>

// Auto-refresh filters on select change (for non-form elements)
document.querySelectorAll('#filterForm select, #filterForm input').forEach(el => {
    if (el.name !== 'search') {
        el.addEventListener('change', () => document.getElementById('filterForm').submit());
    }
});

// Debounced search
let searchTimeout;
const searchInput = document.querySelector('input[name="search"]');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 500);
    });
}
</script>

</body>
</html>