<?php
// reports.php - Parent Portal: Dual View Reports (Summary + Official Report Card)
// Updated to align with parent_header.php sidebar and QPMCEDU.com styling
// Features: Two clear buttons, student selector, term selector, secure, mobile-friendly
// UPDATED: Added Official Report Card viewing functionality

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    header("Location: /parent/login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$parent_id = (int)$_SESSION['user_id'];

// Fetch current academic year and terms
$current_year_query = $conn->query("SELECT academic_year FROM student_classes GROUP BY academic_year ORDER BY academic_year DESC LIMIT 1");
$current_academic_year = $current_year_query->fetch_assoc()['academic_year'] ?? date('Y') . '/' . (date('Y') + 1);

$terms = [];
$stmt = $conn->prepare("SELECT id, term_name, year FROM academic_calendar ORDER BY year DESC, start_date DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $terms[] = $row;
}
$stmt->close();

// Determine current term (default to first term in list if none selected)
$selected_term_id = $terms[0]['id'] ?? 0;
if (isset($_GET['term_id']) && is_numeric($_GET['term_id'])) {
    $selected_term_id = (int)$_GET['term_id'];
} elseif (isset($_POST['term_id']) && is_numeric($_POST['term_id'])) {
    $selected_term_id = (int)$_POST['term_id'];
}

// Selected student (from GET or POST)
$selected_student_id = 0;
if (isset($_GET['student']) && is_numeric($_GET['student'])) {
    $selected_student_id = (int)$_GET['student'];
} elseif (isset($_POST['student']) && is_numeric($_POST['student'])) {
    $selected_student_id = (int)$_POST['student'];
}

// Fetch parent's children
$students = [];
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.name, u.profile_picture, u.gender, c.class_name, sc.academic_year
    FROM users u
    JOIN parent_student_mapping psm ON u.id = psm.student_id
    JOIN student_classes sc ON u.id = sc.user_id AND sc.academic_year = ?
    JOIN classes c ON sc.class_id = c.id
    WHERE psm.parent_id = ?
    ORDER BY u.name
");
$stmt->bind_param("si", $current_academic_year, $parent_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

// Validate selected student belongs to parent
$valid_student = false;
$student_name = "Your Child";
$student_class = "";
$student_gender = "";
$profile_pic = "/assets/images/default_profile.png";

foreach ($students as $s) {
    if ($s['id'] == $selected_student_id) {
        $valid_student = true;
        $student_name = $s['name'];
        $student_class = $s['class_name'];
        $student_gender = $s['gender'];
        $profile_pic = !empty($s['profile_picture']) ? $s['profile_picture'] : $profile_pic;
        break;
    }
}

if (!$valid_student && !empty($students)) {
    $selected_student_id = $students[0]['id'];
    $student_name = $students[0]['name'];
    $student_class = $students[0]['class_name'];
    $student_gender = $students[0]['gender'];
    $profile_pic = !empty($students[0]['profile_picture']) ? $students[0]['profile_picture'] : $profile_pic;
}

// Log access
logActivity($conn, $parent_id, "Accessed Reports Page", "Viewed reports for student: $selected_student_id, term: $selected_term_id");

// Fetch selected term name for display
$term_name = "";
foreach ($terms as $t) {
    if ($t['id'] == $selected_term_id) {
        $term_name = $t['term_name'] . ' ' . $t['year'];
        break;
    }
}

// ============================================
// HELPER FUNCTION: Normalize Profile Picture
// ============================================
function normalizeProfilePicture($profile_picture, $gender = null) {
    // Default profile picture paths based on gender
    $default_male = '/Uploads/profiles/default-male.jpg';
    $default_female = '/Uploads/profiles/default-female.jpg';
    $default_generic = '/Uploads/profiles/default_profile.png';
    
    // Determine which default to use based on gender
    $default_picture = $default_generic;
    if ($gender === 'Male' || $gender === 'male' || $gender === 'M') {
        $default_picture = $default_male;
    } elseif ($gender === 'Female' || $gender === 'female' || $gender === 'F') {
        $default_picture = $default_female;
    }
    
    // If profile picture is empty, return gender-appropriate default
    if (empty($profile_picture)) {
        return $default_picture;
    }
    
    // Clean the path - remove any variations of uploads/profiles
    $clean_path = str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $profile_picture);
    $clean_path = ltrim($clean_path, '/');
    
    // Construct the full server path
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean_path;
    
    // Check if file exists and is valid
    if (file_exists($full_path) && filesize($full_path) > 100) {
        // Return the clean web-accessible path
        return '/Uploads/profiles/' . $clean_path;
    }
    
    // If file doesn't exist or is invalid, return gender-appropriate default
    return $default_picture;
}

// ============================================
// OFFICIAL REPORT CARD DATA FETCHING
// ============================================
$report_card_data = null;
$report_details = null;
$grades = [];
$grand_total = 0;
$footer_messages = [
    "Prepared with diligence and care.",
    "End of report — beginning of action.",
    "Data-driven decisions start here.",
    "Compiled for clarity and insight.",
    "Report finalized: let the results speak.",
    "Every detail matters.",
    "From observation to transformation.",
    "Learning through reflection.",
    "Documenting progress, inspiring change.",
    "A snapshot of growth and goals.",
    "Education is the most powerful weapon which you can use to change the world. – Nelson Mandela",
    "The future belongs to those who prepare for it today. – Malcolm X",
    "Success is not final, failure is not fatal: It is the courage to continue that counts. – Winston Churchill",
    "Teaching is the one profession that creates all other professions. – Unknown",
    "What we learn becomes a part of who we are. – Unknown",
    "In learning you will teach, and in teaching you will learn. – Phil Collins",
    "A good teacher can inspire hope, ignite the imagination, and instill a love of learning. – Brad Henry",
    "The beautiful thing about learning is that no one can take it away from you. – B.B. King",
    "It always seems impossible until it's done. – Nelson Mandela",
    "Quality is not an accident; it is always the result of intelligent effort. – John Ruskin",
    "Progress is a process.",
    "Learning never stops.",
    "Every report is a step forward.",
    "Growth documented, goals defined.",
    "Reflect. Refine. Repeat.",
    "Small steps lead to big changes.",
    "From insight to impact.",
    "Teaching today, shaping tomorrow.",
    "Knowledge shared is power multiplied.",
    "The journey of improvement continues."
];

// Function to calculate grade and remark
function getGradeAndRemark($total) {
    if ($total >= 90) return ['grade' => 'A+', 'remark' => 'Distinction', 'class' => 'grade-ap'];
    if ($total >= 80) return ['grade' => 'A', 'remark' => 'Excellence', 'class' => 'grade-a'];
    if ($total >= 75) return ['grade' => 'B2', 'remark' => 'Very Good', 'class' => 'grade-b2'];
    if ($total >= 65) return ['grade' => 'B3', 'remark' => 'Good', 'class' => 'grade-b3'];
    if ($total >= 55) return ['grade' => 'C4', 'remark' => 'Credit', 'class' => 'grade-c'];
    if ($total >= 50) return ['grade' => 'C5', 'remark' => 'Credit', 'class' => 'grade-c'];
    if ($total >= 45) return ['grade' => 'C6', 'remark' => 'Credit', 'class' => 'grade-c'];
    if ($total >= 35) return ['grade' => 'D7', 'remark' => 'Pass', 'class' => 'grade-d'];
    if ($total >= 30) return ['grade' => 'E8', 'remark' => 'Weak Pass', 'class' => 'grade-e'];
    return ['grade' => 'Fa', 'remark' => 'Fail', 'class' => 'grade-f'];
}

// Fetch Report Card Data if view=report_card
$report_card_fetched = false;
if (
    isset($_GET['view']) && $_GET['view'] === 'report_card'
    && $selected_student_id > 0
    && $valid_student
    && $selected_term_id > 0
) {
    // Security: re-verify the student belongs to this parent
    $verify = $conn->prepare(
        "SELECT 1 FROM parent_student_mapping WHERE parent_id = ? AND student_id = ? LIMIT 1"
    );
    $verify->bind_param("ii", $parent_id, $selected_student_id);
    $verify->execute();
    $is_my_child = (bool) $verify->get_result()->fetch_row();
    $verify->close();

    if ($is_my_child) {
        // First, find the student_report id for this student and term
        $stmt = $conn->prepare("
            SELECT sr.id, sr.class_id, sr.promoted_to_class_id, sr.attendance, sr.reopening_date,
                   sr.conduct, sr.attitude, sr.interest, sr.teacher_remarks, sr.headmaster_remarks,
                   c.class_name, ac.term_name, ac.year
            FROM student_reports sr
            JOIN classes c ON sr.class_id = c.id
            JOIN academic_calendar ac ON sr.term_id = ac.id
            WHERE sr.user_id = ? AND sr.term_id = ?
            ORDER BY sr.id DESC LIMIT 1
        ");
        $stmt->bind_param("ii", $selected_student_id, $selected_term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_details = $result->fetch_assoc();
        $stmt->close();

        if ($report_details) {
            // Fetch grades for this student and term
            $stmt = $conn->prepare("
                SELECT g.id, g.mid_term_exam, g.end_term_exam, g.comment, g.class_id, s.subject_name
                FROM grades g
                JOIN subjects s ON g.subject_id = s.id
                WHERE g.user_id = ? AND g.term_id = ?
            ");
            $stmt->bind_param("ii", $selected_student_id, $selected_term_id);
            $stmt->execute();
            $grades_result = $stmt->get_result();
            $grades = $grades_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (!empty($grades)) {
                // Calculate grand total
                foreach ($grades as $grade) {
                    $mid_term = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
                    $end_term = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
                    $total = ($mid_term * 0.5) + ($end_term * 0.5);
                    $grand_total += $total;
                }
                $report_card_fetched = true;
            }
        }
    }
}

// Fetch promoted class name
$next_class_name = 'N/A';
if ($report_details && $report_details['promoted_to_class_id']) {
    $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $stmt->bind_param("i", $report_details['promoted_to_class_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $next_class_name = $row['class_name'];
    }
    $stmt->close();
}

// Format attendance
$attendance_display = 'N/A';
$attendance_percentage = 'N/A';
if ($report_details && $report_details['attendance']) {
    $attendance_display = $report_details['attendance'] . ' out of 66';
    $attendance_percentage = number_format(($report_details['attendance'] / 66) * 100, 1) . '%';
}

// Format reopening date
$reopening_display = $report_details && $report_details['reopening_date'] ? date('l, F j, Y', strtotime($report_details['reopening_date'])) : 'N/A';

// Normalize profile picture
$normalized_profile_pic = normalizeProfilePicture($profile_pic, $student_gender);

// Check if images exist
$signature_path = '/assets/images/signature_head.png';
$stamp_path = '/assets/images/stamp.png';
$seal_path = '/assets/images/seal.png';
$base_path = $_SERVER['DOCUMENT_ROOT'];
$signature_exists = file_exists($base_path . $signature_path);
$stamp_exists = file_exists($base_path . $stamp_path);
$seal_exists = file_exists($base_path . $seal_path);

// ============================================
// ACADEMIC PERFORMANCE SUMMARY DATA
// ============================================
$summary_data      = [];
$summary_periods   = [];
$summary_overall   = null;
$summary_fetched   = false;

if (
    isset($_GET['view']) && $_GET['view'] === 'summary'
    && $selected_student_id > 0
    && $valid_student
    && $selected_term_id > 0
) {
    // Security: re-verify the student belongs to this parent
    $verify = $conn->prepare(
        "SELECT 1 FROM parent_student_mapping WHERE parent_id = ? AND student_id = ? LIMIT 1"
    );
    $verify->bind_param("ii", $parent_id, $selected_student_id);
    $verify->execute();
    $is_my_child = (bool) $verify->get_result()->fetch_row();
    $verify->close();

    if ($is_my_child) {
        // Pull all bi-weekly entries for this student + term
        $stmt = $conn->prepare("
            SELECT
                ape.id,
                ape.subject_id,
                s.subject_name,
                DATE_FORMAT(ape.period_start_date, '%Y-%m-%d') AS period_start,
                DATE_FORMAT(ape.period_end_date,   '%Y-%m-%d') AS period_end,
                ape.class_practices_raw,  ape.class_practices_max,
                ape.assignments_raw,      ape.assignments_max,
                ape.cat_raw,              ape.cat_max,
                ape.group_project_raw,    ape.group_project_max,
                ape.total_scaled,
                ape.comments,
                ape.status
            FROM academic_progress_entries ape
            JOIN subjects s ON ape.subject_id = s.id
            WHERE ape.student_id   = ?
              AND ape.term_id      = ?
              AND ape.entry_period = 'bi-weekly'
              AND ape.status       IN ('submitted','reviewed')
            ORDER BY s.subject_name ASC, ape.period_start_date ASC
        ");
        $stmt->bind_param("ii", $selected_student_id, $selected_term_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Organise by subject
        $by_subject = [];
        foreach ($rows as $r) {
            $sid  = (int)$r['subject_id'];
            $pkey = $r['period_start'] . '|' . $r['period_end'];

            if (!isset($by_subject[$sid])) {
                $by_subject[$sid] = [
                    'subject_name' => $r['subject_name'],
                    'periods'      => [],
                ];
            }
            $by_subject[$sid]['periods'][$pkey] = $r;

            // Collect distinct periods
            if (!isset($summary_periods[$pkey])) {
                $summary_periods[$pkey] = [
                    'start' => $r['period_start'],
                    'end'   => $r['period_end'],
                ];
            }
        }

        // Helper: compute scaled score from a row
        $computeScaled = function(array $e): float {
            $ts = isset($e['total_scaled']) ? (float)$e['total_scaled'] : 0.0;
            if ($ts > 0) return $ts;
            $cp = ($e['class_practices_max'] > 0) ? ((float)$e['class_practices_raw'] / (float)$e['class_practices_max']) * 25 : 0.0;
            $as = ($e['assignments_max']     > 0) ? ((float)$e['assignments_raw']     / (float)$e['assignments_max'])     * 25 : 0.0;
            $ct = ($e['cat_max']             > 0) ? ((float)$e['cat_raw']             / (float)$e['cat_max'])             * 25 : 0.0;
            $gp = ($e['group_project_max']   > 0) ? ((float)$e['group_project_raw']   / (float)$e['group_project_max'])   * 25 : 0.0;
            return round($cp + $as + $ct + $gp, 2);
        };

        // Build summary_data per subject
        $all_averages = [];
        foreach ($by_subject as $sid => $subj) {
            $scores = [];
            foreach ($subj['periods'] as $pkey => $entry) {
                $scores[$pkey] = $computeScaled($entry);
            }
            $avg = count($scores) ? array_sum($scores) / count($scores) : null;
            if ($avg !== null) $all_averages[] = $avg;

            $summary_data[$sid] = [
                'subject_name' => $subj['subject_name'],
                'periods'      => $subj['periods'],
                'scores'       => $scores,
                'average'      => $avg !== null ? round($avg, 1) : null,
                'highest'      => count($scores) ? round(max($scores), 1) : null,
                'lowest'       => count($scores) ? round(min($scores), 1) : null,
                'trend'        => count($scores) > 1
                    ? (end($scores) >= reset($scores) ? 'up' : 'down')
                    : 'flat',
                'period_count' => count($scores),
                'latest_comment' => array_reverse(array_values($subj['periods']))[0]['comments'] ?? '',
            ];
        }

        // Sort by subject name
        usort($summary_data, fn($a,$b) => strcmp($a['subject_name'], $b['subject_name']));

        $summary_overall = count($all_averages)
            ? round(array_sum($all_averages) / count($all_averages), 1)
            : null;

        $summary_fetched = true;
    }
}

// Grade label helper
function gradeInfo(float $v): array {
    if ($v >= 80) return ['label' => 'Excellent', 'class' => 'success',  'icon' => 'fa-star'];
    if ($v >= 65) return ['label' => 'Good',      'class' => 'primary',  'icon' => 'fa-thumbs-up'];
    if ($v >= 50) return ['label' => 'Average',   'class' => 'warning',  'icon' => 'fa-minus'];
    return             ['label' => 'Needs Work', 'class' => 'danger',   'icon' => 'fa-exclamation'];
}

function scoreBarColor(float $v): string {
    if ($v >= 70) return '#059669';
    if ($v >= 50) return '#d97706';
    return '#dc2626';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Reports | QPMCEDU Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --qpms-orange: #ff6b00;
            --qpms-orange-dark: #e65c00;
            --qpms-gray: #212529;
        }
        body {
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
            color: var(--qpms-gray);
            overflow-x: hidden;
        }
        #qpmc-content {
            padding: 1.5rem 2rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        @media (max-width: 768px) {
            #qpmc-content { padding: 1rem; }
        }
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: var(--qpms-orange);
            color: white;
            font-weight: 600;
            padding: 1rem 1.5rem;
            border-bottom: none;
        }
        .card-header h3, .card-header h5 { margin: 0; }
        .btn-orange {
            background: var(--qpms-orange);
            border: none;
            color: white;
            border-radius: 0.75rem;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-orange:hover {
            background: var(--qpms-orange-dark);
            color: white;
            transform: translateY(-1px);
        }
        .btn-outline-orange {
            border: 1px solid var(--qpms-orange);
            color: var(--qpms-orange);
            background: transparent;
            border-radius: 0.75rem;
            padding: 0.5rem 1.25rem;
            transition: all 0.2s;
        }
        .btn-outline-orange:hover {
            background: var(--qpms-orange);
            color: white;
        }
        .report-card-btn {
            transition: all 0.3s ease;
            border-radius: 1.5rem;
            overflow: hidden;
        }
        .report-card-btn:hover { transform: translateY(-5px); }
        .profile-avatar {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid var(--qpms-orange);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .form-select:focus, .form-control:focus {
            border-color: var(--qpms-orange);
            box-shadow: 0 0 0 0.2rem rgba(255,107,0,0.25);
        }
        footer {
            text-align: center;
            padding: 1.5rem;
            color: #6c757d;
            font-size: 0.85rem;
            border-top: 1px solid #dee2e6;
            margin-top: 2rem;
        }

        /* Official Report Card Styles */
        .report-card-container {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            position: relative;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
        }
        .report-card-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('<?php echo $seal_exists ? $seal_path : ''; ?>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: 30% auto;
            opacity: 0.08;
            pointer-events: none;
            border-radius: 1rem;
        }
        .report-header-table {
            width: 100%;
            margin-bottom: 1.5rem;
        }
        .school-name {
            font-size: 1.5rem;
            color: #003087;
            font-weight: 700;
            margin: 0;
        }
        .school-motto {
            font-size: 0.8rem;
            color: #d81b60;
            font-style: italic;
        }
        .report-title {
            font-size: 1.1rem;
            color: #003087;
            text-decoration: underline;
            text-align: center;
            margin: 1rem 0;
            font-weight: 700;
        }
        .student-photo-report {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }
        .grades-table-report {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.75rem;
        }
        .grades-table-report th, .grades-table-report td {
            border: 1px solid #b0bec5;
            padding: 0.5rem;
            text-align: center;
        }
        .grades-table-report th {
            background-color: #eef2ff;
            font-weight: 700;
        }
        .grades-table-report .grade-ap td { background-color: rgba(200, 230, 255, 0.5); }
        .grades-table-report .grade-a td { background-color: rgba(200, 255, 200, 0.5); }
        .grades-table-report .grade-b2 td { background-color: rgba(255, 245, 200, 0.5); }
        .grades-table-report .grade-b3 td { background-color: rgba(255, 235, 180, 0.5); }
        .grades-table-report .grade-c td { background-color: rgba(255, 200, 200, 0.5); }
        .grades-table-report .grade-d td { background-color: rgba(245, 200, 255, 0.5); }
        .grades-table-report .grade-e td { background-color: rgba(245, 200, 255, 0.5); }
        .grades-table-report .grade-f td { background-color: rgba(235, 235, 235, 0.5); }
        .additional-details-report {
            margin-top: 1rem;
            font-size: 0.75rem;
        }
        .signature-img {
            max-height: 50px;
        }
        .stamp-img {
            max-width: 100px;
            max-height: 100px;
        }
        .footer-message-report {
            margin-top: 1rem;
            text-align: center;
            font-size: 0.7rem;
            color: #003087;
            font-style: italic;
            border-top: 1px solid #e0e0e0;
            padding-top: 0.75rem;
        }
        .print-hide {
            margin-top: 1rem;
            text-align: center;
        }
        @media print {
            .no-print, .print-hide, .btn, .card-header, footer, .report-card-btn, .student-selector-card, .filter-card {
                display: none !important;
            }
            .report-card-container {
                padding: 0.5rem;
                box-shadow: none;
            }
            .report-card-container::before {
                opacity: 0.15;
            }
            body {
                background: white;
            }
            .stamp-img {
                max-width: 80px;
                max-height: 80px;
            }
        }

        /* Summary section styles */
        .summary-stat-card {
            background: white;
            border-radius: 0.875rem;
            padding: 1.25rem 1rem;
            text-align: center;
            border: 1px solid #f0f0f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .summary-stat-num {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        .summary-stat-lbl {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6c757d;
            font-weight: 600;
        }
        .subject-row {
            background: white;
            border-radius: 0.875rem;
            border: 1px solid #f0f0f0;
            margin-bottom: 0.75rem;
            overflow: hidden;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .subject-row:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }
        .subject-row-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1.25rem;
            cursor: pointer;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .subject-name-cell {
            font-weight: 600;
            font-size: 0.9rem;
            min-width: 160px;
        }
        .score-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.7rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .mini-bar-wrap {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 120px;
            flex: 1;
            max-width: 180px;
        }
        .mini-bar-bg {
            flex: 1;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }
        .mini-bar-fg {
            height: 100%;
            border-radius: 3px;
        }
        .period-detail-table {
            width: 100%;
            font-size: 0.82rem;
            border-collapse: collapse;
        }
        .period-detail-table th {
            background: #f8f9fa;
            padding: 0.5rem 0.875rem;
            font-weight: 600;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
        }
        .period-detail-table td {
            padding: 0.6rem 0.875rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .period-detail-table tr:last-child td { border-bottom: none; }
        .period-detail-table tr:hover td { background: #fafafa; }
        .trend-up   { color: #059669; }
        .trend-down { color: #dc2626; }
        .trend-flat { color: #6c757d; }
        .overall-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.25rem;
            border-radius: 999px;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .raw-score { font-size: 0.72rem; color: #9ca3af; }
        .empty-summary {
            text-align: center;
            padding: 3rem 1.5rem;
            color: #6c757d;
        }
        .empty-summary i { font-size: 3rem; opacity: 0.2; display: block; margin-bottom: 1rem; }
        .collapse-chevron {
            transition: transform 0.2s;
        }
    </style>
</head>
<body>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php'; ?>

<div class="container-fluid px-0">
    
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1 fw-semibold" style="color: var(--qpms-gray);">
                <i class="fas fa-chart-line me-2" style="color: var(--qpms-orange);"></i>Academic Reports
            </h2>
            <p class="text-muted mb-0">
                <i class="fas fa-calendar-alt me-1"></i> Academic Year: <?= htmlspecialchars($current_academic_year) ?>
                <?php if (!empty($term_name)): ?> | Selected Term: <?= htmlspecialchars($term_name) ?><?php endif; ?>
            </p>
        </div>
        <div class="mt-2 mt-sm-0 no-print">
            <a href="/parent/dashboard.php" class="btn btn-orange">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Student & Term Selector Card -->
    <div class="card no-print student-selector-card">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end" id="filterForm">
                <?php if (isset($_GET['view'])): ?>
                    <input type="hidden" name="view" value="<?= htmlspecialchars($_GET['view']) ?>">
                <?php endif; ?>
                <div class="col-md-5 col-lg-4">
                    <label for="student" class="form-label fw-semibold">
                        <i class="fas fa-user-graduate me-1"></i> Select Child
                    </label>
                    <select name="student" id="student" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $selected_student_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?> - <?= htmlspecialchars($s['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5 col-lg-4">
                    <label for="term_id" class="form-label fw-semibold">
                        <i class="fas fa-calendar-alt me-1"></i> Academic Term
                    </label>
                    <select name="term_id" id="term_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= $term['id'] ?>" <?= $term['id'] == $selected_term_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name'] . ' - ' . $term['year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-lg-2">
                    <button type="submit" class="btn btn-orange w-100">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_student_id > 0 && !empty($students)): ?>
        
        <!-- Student Profile Card -->
        <div class="card text-center no-print">
            <div class="card-body py-4">
                <img src="<?= htmlspecialchars($normalized_profile_pic) ?>" alt="Student Profile" class="profile-avatar mb-3"
                     onerror="this.src='/assets/images/default_avatar.png'">
                <h3 class="fw-bold mb-1"><?= htmlspecialchars($student_name) ?></h3>
                <p class="text-muted mb-0">
                    <i class="fas fa-graduation-cap me-1"></i> <?= htmlspecialchars($student_class) ?>
                </p>
                <p class="text-muted small mt-1">
                    <i class="fas fa-id-card me-1"></i> Student ID: QPMS<?= htmlspecialchars($selected_student_id) ?>
                </p>
            </div>
        </div>

        <!-- TWO MAIN BUTTONS -->
        <div class="row g-4 mb-4 no-print">
            <!-- Button 1: Academic Performance Summary -->
            <div class="col-md-6">
                <a href="?student=<?= $selected_student_id ?>&term_id=<?= $selected_term_id ?>&view=summary" class="text-decoration-none">
                    <div class="card report-card-btn h-100" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
                        <div class="card-body text-center py-5 text-white">
                            <i class="fas fa-chart-bar fa-4x mb-4 opacity-75"></i>
                            <h4 class="fw-bold mb-2">Academic Performance Summary</h4>
                            <p class="mb-3 opacity-90">Subject-by-subject bi-weekly scores, averages, and performance trends</p>
                            <span class="btn btn-light text-primary rounded-pill px-4">
                                View Summary <i class="fas fa-arrow-right ms-2"></i>
                            </span>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Button 2: Official Report Card -->
            <div class="col-md-6">
                <a href="?student=<?= $selected_student_id ?>&term_id=<?= $selected_term_id ?>&view=report_card" class="text-decoration-none">
                    <div class="card report-card-btn h-100" style="background: linear-gradient(135deg, #e65c00 0%, #ff8c42 100%);">
                        <div class="card-body text-center py-5 text-white">
                            <i class="fas fa-file-alt fa-4x mb-4 opacity-75"></i>
                            <h4 class="fw-bold mb-2">Official Report Card</h4>
                            <p class="mb-3 opacity-90">Complete termly report card with grades, remarks, and teacher signatures</p>
                            <span class="btn btn-light text-orange rounded-pill px-4">
                                View Report Card <i class="fas fa-arrow-right ms-2"></i>
                            </span>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             ACADEMIC PERFORMANCE SUMMARY SECTION
             Shown when view=summary is in the URL
        ════════════════════════════════════════════════════════════════ -->
        <?php if (isset($_GET['view']) && $_GET['view'] === 'summary'): ?>
            <div class="card" id="summary-section">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-simple me-2"></i>
                        Academic Performance Summary
                        <?php if (!empty($term_name)): ?>
                            <small class="fw-normal opacity-75 ms-1">— <?= htmlspecialchars($term_name) ?></small>
                        <?php endif; ?>
                    </h5>
                    <div class="d-flex gap-2 flex-wrap">
                        <button onclick="window.print()" class="btn btn-sm btn-light">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                        <a href="?student=<?= $selected_student_id ?>&term_id=<?= $selected_term_id ?>" class="btn btn-sm btn-light">
                            <i class="fas fa-times me-1"></i> Close
                        </a>
                    </div>
                </div>

                <div class="card-body">

                    <?php if (!$summary_fetched || empty($summary_data)): ?>
                        <div class="empty-summary">
                            <i class="fas fa-inbox"></i>
                            <h5>No scores available yet</h5>
                            <p class="mb-3">No submitted bi-weekly scores were found for <strong><?= htmlspecialchars($student_name) ?></strong> in the selected term.</p>
                            <a href="?student=<?= $selected_student_id ?>&term_id=<?= $selected_term_id ?>&view=report_card"
                               class="btn btn-orange">
                                <i class="fas fa-file-alt me-2"></i> View Official Report Card Instead
                            </a>
                        </div>

                    <?php else: ?>
                        <?php
                            $entry_count   = array_sum(array_column($summary_data, 'period_count'));
                            $subject_count = count($summary_data);
                            $scored        = array_filter($summary_data, fn($s) => $s['average'] !== null);
                            $best_subj     = !empty($scored)
                                ? array_reduce($scored, fn($carry,$s) => ($carry === null || $s['average'] > $carry['average']) ? $s : $carry)
                                : null;
                            $period_count  = count($summary_periods);
                            $overall_info = $summary_overall !== null ? gradeInfo($summary_overall) : null;
                        ?>
                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-3">
                                <div class="summary-stat-card">
                                    <div class="summary-stat-num" style="color:#2a5298">
                                        <?= $summary_overall !== null ? $summary_overall . '%' : '—' ?>
                                    </div>
                                    <div class="summary-stat-lbl">Overall Average</div>
                                    <?php if ($overall_info): ?>
                                        <span class="badge bg-<?= $overall_info['class'] ?> mt-1">
                                            <?= $overall_info['label'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="summary-stat-card">
                                    <div class="summary-stat-num" style="color:#059669"><?= $subject_count ?></div>
                                    <div class="summary-stat-lbl">Subjects</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="summary-stat-card">
                                    <div class="summary-stat-num" style="color:#d97706"><?= $period_count ?></div>
                                    <div class="summary-stat-lbl">Periods Assessed</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="summary-stat-card">
                                    <div class="summary-stat-num" style="color:#7c3aed"><?= $entry_count ?></div>
                                    <div class="summary-stat-lbl">Total Entries</div>
                                </div>
                            </div>
                        </div>

                        <?php if ($best_subj): ?>
                            <div class="alert d-flex align-items-center gap-2 mb-4"
                                 style="background:#f0fdf4;border:1px solid #bbf7d0;color:#065f46;border-radius:0.875rem">
                                <i class="fas fa-trophy" style="color:#059669;font-size:1.1rem;flex-shrink:0"></i>
                                <span>
                                    <strong><?= htmlspecialchars($student_name) ?>'s</strong> strongest subject this term is
                                    <strong><?= htmlspecialchars($best_subj['subject_name']) ?></strong>
                                    with an average of <strong><?= $best_subj['average'] ?>%</strong>.
                                </span>
                            </div>
                        <?php endif; ?>

                        <div id="subject-list">
                            <?php foreach ($summary_data as $idx => $subj):
                                $avg     = $subj['average'];
                                $info    = $avg !== null ? gradeInfo($avg) : null;
                                $bar_col = $avg !== null ? scoreBarColor($avg) : '#e9ecef';
                                $collapseId = 'subj-' . $idx;
                            ?>
                            <div class="subject-row">
                                <div class="subject-row-header"
                                     data-bs-toggle="collapse"
                                     data-bs-target="#<?= $collapseId ?>"
                                     aria-expanded="false">
                                    <span class="subject-name-cell">
                                        <?= htmlspecialchars($subj['subject_name']) ?>
                                    </span>
                                    <div class="mini-bar-wrap">
                                        <div class="mini-bar-bg">
                                            <div class="mini-bar-fg"
                                                 style="width:<?= $avg ?? 0 ?>%;background:<?= $bar_col ?>">
                                            </div>
                                        </div>
                                        <span style="font-weight:700;font-size:.85rem;color:<?= $bar_col ?>;min-width:3.5ch">
                                            <?= $avg !== null ? $avg . '%' : '—' ?>
                                        </span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <?php if ($info): ?>
                                            <span class="score-pill bg-<?= $info['class'] ?> bg-opacity-10 text-<?= $info['class'] ?>">
                                                <i class="fas <?= $info['icon'] ?>"></i> <?= $info['label'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="score-pill bg-secondary bg-opacity-10 text-secondary">No data</span>
                                        <?php endif; ?>
                                        <?php if ($subj['period_count'] > 1): ?>
                                            <span class="trend-<?= $subj['trend'] ?>" title="Trend <?= $subj['trend'] ?>">
                                                <i class="fas fa-arrow-<?= $subj['trend'] === 'up' ? 'up' : ($subj['trend'] === 'down' ? 'down' : 'right') ?>"></i>
                                            </span>
                                        <?php endif; ?>
                                        <small class="text-muted"><?= $subj['period_count'] ?> period<?= $subj['period_count'] !== 1 ? 's' : '' ?></small>
                                        <i class="fas fa-chevron-down text-muted collapse-chevron" style="font-size:.75rem;"></i>
                                    </div>
                                </div>
                                <div class="collapse" id="<?= $collapseId ?>">
                                    <div style="border-top:1px solid #f0f0f0;overflow-x:auto">
                                        <table class="period-detail-table">
                                            <thead>
                                                <tr>
                                                    <th>Period</th>
                                                    <th style="text-align:right">Class Pract. <span class="raw-score">(/25)</span></th>
                                                    <th style="text-align:right">Assignments <span class="raw-score">(/25)</span></th>
                                                    <th style="text-align:right">CAT <span class="raw-score">(/25)</span></th>
                                                    <th style="text-align:right">Group Proj. <span class="raw-score">(/25)</span></th>
                                                    <th style="text-align:right">Total <span class="raw-score">(/100)</span></th>
                                                    <th>Teacher's Note</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $p_num = 1;
                                                foreach ($subj['periods'] as $pkey => $entry):
                                                    [$ps, $pe] = explode('|', $pkey);
                                                    $ts = $subj['scores'][$pkey];
                                                    $tc = scoreBarColor($ts);
                                                    $cp_s = $entry['class_practices_max'] > 0
                                                        ? round(($entry['class_practices_raw'] / $entry['class_practices_max']) * 25, 1) : null;
                                                    $as_s = $entry['assignments_max'] > 0
                                                        ? round(($entry['assignments_raw'] / $entry['assignments_max']) * 25, 1) : null;
                                                    $ct_s = $entry['cat_max'] > 0
                                                        ? round(($entry['cat_raw'] / $entry['cat_max']) * 25, 1) : null;
                                                    $gp_s = $entry['group_project_max'] > 0
                                                        ? round(($entry['group_project_raw'] / $entry['group_project_max']) * 25, 1) : null;
                                                    $fmt_period = date('M j', strtotime($ps)) . ' – ' . date('M j, Y', strtotime($pe));
                                                    $note       = trim($entry['comments'] ?? '');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-600" style="font-weight:600">Period <?= $p_num ?></span><br>
                                                        <small class="text-muted"><?= htmlspecialchars($fmt_period) ?></small>
                                                    </td>
                                                    <td style="text-align:right">
                                                        <?php if ($cp_s !== null): ?>
                                                            <span style="font-weight:600"><?= $cp_s ?></span>
                                                            <span class="raw-score">(<?= (float)$entry['class_practices_raw'] ?>/<?= (float)$entry['class_practices_max'] ?>)</span>
                                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                    </td>
                                                    <td style="text-align:right">
                                                        <?php if ($as_s !== null): ?>
                                                            <span style="font-weight:600"><?= $as_s ?></span>
                                                            <span class="raw-score">(<?= (float)$entry['assignments_raw'] ?>/<?= (float)$entry['assignments_max'] ?>)</span>
                                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                    </td>
                                                    <td style="text-align:right">
                                                        <?php if ($ct_s !== null): ?>
                                                            <span style="font-weight:600"><?= $ct_s ?></span>
                                                            <span class="raw-score">(<?= (float)$entry['cat_raw'] ?>/<?= (float)$entry['cat_max'] ?>)</span>
                                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                    </td>
                                                    <td style="text-align:right">
                                                        <?php if ($gp_s !== null): ?>
                                                            <span style="font-weight:600"><?= $gp_s ?></span>
                                                            <span class="raw-score">(<?= (float)$entry['group_project_raw'] ?>/<?= (float)$entry['group_project_max'] ?>)</span>
                                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                    </td>
                                                    <td style="text-align:right">
                                                        <span style="font-weight:700;color:<?= $tc ?>;font-size:.9rem">
                                                            <?= number_format($ts, 1) ?>
                                                        </span>
                                                    </td>
                                                    <td style="max-width:180px">
                                                        <?php if ($note): ?>
                                                            <span class="text-muted" style="font-size:.78rem;font-style:italic">
                                                                "<?= htmlspecialchars($note) ?>"
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted" style="font-size:.78rem">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php $p_num++; endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr style="background:#f8f9fa">
                                                    <td colspan="5" class="text-end fw-semibold" style="font-size:.8rem;color:#6c757d">
                                                        Subject Average
                                                    </td>
                                                    <td style="text-align:right">
                                                        <span style="font-weight:700;font-size:.95rem;color:<?= $bar_col ?>">
                                                            <?= $avg !== null ? $avg . '%' : '—' ?>
                                                        </span>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <span class="text-muted fw-semibold me-2" style="font-size:.85rem">
                                    OVERALL TERM AVERAGE
                                </span>
                                <?php if ($summary_overall !== null && $overall_info): ?>
                                    <span class="overall-badge bg-<?= $overall_info['class'] ?> bg-opacity-10 text-<?= $overall_info['class'] ?>">
                                        <i class="fas <?= $overall_info['icon'] ?>"></i>
                                        <?= $summary_overall ?>% — <?= $overall_info['label'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button onclick="expandAll()" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-expand-alt me-1"></i> Expand All
                                </button>
                                <a href="?student=<?= $selected_student_id ?>&term_id=<?= $selected_term_id ?>&view=report_card"
                                   class="btn btn-sm btn-orange">
                                    <i class="fas fa-file-alt me-1"></i> Official Report Card
                                </a>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════════
             OFFICIAL REPORT CARD SECTION
             Shown when view=report_card is in the URL
        ════════════════════════════════════════════════════════════════ -->
        <?php if (isset($_GET['view']) && $_GET['view'] === 'report_card'): ?>
            <div class="report-card-container" id="report-card-section">
                <?php if (!$report_card_fetched || empty($grades)): ?>
                    <div class="empty-summary">
                        <i class="fas fa-file-alt fa-3x mb-3 opacity-25"></i>
                        <h5>No Report Card Available</h5>
                        <p class="mb-3">No official report card data was found for <strong><?= htmlspecialchars($student_name) ?></strong> in the selected term.</p>
                        <p class="text-muted small">Please ensure that grades have been recorded for this student in the selected term.</p>
                        <a href="?student=<?= $selected_student_id ?>&term_id=<?= $selected_term_id ?>&view=summary"
                           class="btn btn-orange mt-2">
                            <i class="fas fa-chart-bar me-2"></i> View Performance Summary Instead
                        </a>
                    </div>
                <?php else: 
                    // Calculate aggregate score
                    $max_score = count($grades) * 100;
                    $aggregate_percentage = ($grand_total / $max_score) * 100;
                    $aggregate_grade = getGradeAndRemark($aggregate_percentage)['grade'];
                    
                    // Select random footer message
                    $random_footer = $footer_messages[array_rand($footer_messages)];
                    if (strpos($random_footer, '–') !== false) {
                        $random_footer = '"' . $random_footer . '"';
                    }
                ?>
                    <div class="print-hide mb-3">
                        <button onclick="window.print()" class="btn btn-orange">
                            <i class="fas fa-print me-2"></i> Print Report Card
                        </button>
                        <a href="?student=<?= $selected_student_id ?>&term_id=<?= $selected_term_id ?>&view=summary" 
                           class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-chart-bar me-2"></i> View Summary
                        </a>
                    </div>

                    <!-- Header Section -->
                    <table class="report-header-table">
                        <tr>
                            <td width="100" align="left" valign="top">
                                <img src="/assets/images/logo.png" alt="School Logo" width="70" height="75">
                            </td>
                            <td align="left" valign="top">
                                <h1 class="school-name">Queenest Paradise Montessori Complex</h1>
                                <p class="school-motto">"Christ, Our Foundation Integrity, Discipline and Excellence our hallmark"</p>
                            </td>
                            <td width="200" align="right" valign="top">
                                <strong style="color:#003087; font-size:10px;">CONTACT</strong><br>
                                <span style="font-size:9px; color:#d81b60;">P. O. Box AM 42 Amasaman</span><br>
                                <span style="font-size:9px; color:#d81b60;">0507390755</span><br>
                                <span style="font-size:9px; color:#d81b60;">info.queenspa49@gmail.com</span>
                             </td>
                        </tr>
                    </table>

                    <div class="report-title">
                        <?= htmlspecialchars($report_details['year'] . ' ' . $report_details['term_name'] . " - END OF TERM EXAMINATION Report") ?>
                    </div>

                    <!-- Student Details -->
                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 11px;">
                        <div>
                            <strong>Report Date:</strong> <?= date('F j, Y') ?><br>
                            <strong>Student Name:</strong> <?= htmlspecialchars($student_name) ?><br>
                            <strong>Student ID:</strong> QPMS<?= $selected_student_id ?>
                        </div>
                        <div>
                            <strong>Class/Grade:</strong> <?= htmlspecialchars($report_details['class_name']) ?><br>
                            <strong>Term:</strong> <?= htmlspecialchars($report_details['term_name']) ?><br>
                            <strong>Year:</strong> <?= htmlspecialchars($report_details['year']) ?>
                        </div>
                        <div>
                            <strong>Attendance (Days):</strong> <?= $attendance_display ?> (<?= $attendance_percentage ?>%)<br>
                            <strong>Reopening:</strong> <?= $reopening_display ?>
                        </div>
                        <div>
                            <img src="<?= htmlspecialchars($normalized_profile_pic) ?>" alt="Student Photo" class="student-photo-report"
                                 onerror="this.src='/assets/images/default_avatar.png'">
                        </div>
                    </div>

                    <!-- Grades Table -->
                    <table class="grades-table-report">
                        <thead>
                            <tr>
                                <th align="left">SUBJECT NAME</th>
                                <th align="center">SBA 50%</th>
                                <th align="center">EXAMS 50%</th>
                                <th align="center">TOTAL 100%</th>
                                <th align="center">GRADE</th>
                                <th align="center">REMARKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $report_grand_total = 0;
                            foreach ($grades as $grade):
                                $mid_term_val = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
                                $end_term_val = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
                                $sba_scaled = $mid_term_val * 0.5;
                                $exam_scaled = $end_term_val * 0.5;
                                $total_score = $sba_scaled + $exam_scaled;
                                $grade_info_report = getGradeAndRemark($total_score);
                                $report_grand_total += $total_score;
                            ?>
                            <tr class="<?= $grade_info_report['class'] ?>">
                                <td align="left"><?= htmlspecialchars($grade['subject_name']) ?></td>
                                <td align="center"><?= number_format($sba_scaled, 1) ?></td>
                                <td align="center"><?= number_format($exam_scaled, 1) ?></td>
                                <td align="center"><?= number_format($total_score, 1) ?></td>
                                <td align="center"><?= $grade_info_report['grade'] ?></td>
                                <td align="center"><?= htmlspecialchars($grade['comment'] ?? $grade_info_report['remark']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight: bold; background-color: #f5f5f5;">
                                <th align="left">TOTAL</th>
                                <th align="center"></th>
                                <th align="center"></th>
                                <th align="center"><?= number_format($report_grand_total, 1) ?></th>
                                <th align="center"></th>
                                <th align="center"></th>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Additional Details -->
                    <div class="additional-details-report">
                        <table width="100%" style="margin-top: 15px;">
                            <tr>
                                <td width="50%">
                                    <strong>Total Aggregate:</strong> 
                                    <?= number_format($aggregate_percentage, 1) ?>% (<?= $aggregate_grade ?>) 
                                    [<?= number_format($report_grand_total, 1) ?> / <?= $max_score ?>]
                                 </td>
                                <td align="right">
                                    <strong>Class Teacher's Signature</strong><br>
                                    <div style="height: 40px;"></div>
                                    <hr style="margin:0; width:80%; border-top:2px solid black;">
                                 </td>
                             </tr>
                            <tr>
                                <td>
                                    <strong>Report for:</strong> <span style="color:#2e7d32;"><?= htmlspecialchars($report_details['class_name']) ?></span>
                                 </td>
                                <td align="right">
                                    <strong>Promoted to:</strong> <span style="color:#d81b60;"><?= htmlspecialchars($next_class_name) ?></span>
                                 </td>
                             </tr>
                            <tr>
                                <td>
                                    <strong>Conduct:</strong> <span style="color:#d81b60;"><?= htmlspecialchars($report_details['conduct'] ?? 'N/A') ?></span>
                                 </td>
                                <td align="right">
                                    <strong>Attitude:</strong> <span style="color:#d81b60;"><?= htmlspecialchars($report_details['attitude'] ?? 'N/A') ?></span>
                                 </td>
                             </tr>
                            <tr>
                                <td>
                                    <strong>Interest:</strong> <span style="color:#d81b60;"><?= htmlspecialchars($report_details['interest'] ?? 'N/A') ?></span>
                                 </td>
                                <td align="right">
                                    <strong>HeadMistress / Master's Signature</strong><br>
                                    <?php if ($signature_exists): ?>
                                        <img src="<?= $signature_path ?>" class="signature-img" alt="Signature" style="max-height: 45px;"><br>
                                    <?php else: ?>
                                        <span style="color:#999; font-size:10px;">[Signature]</span><br>
                                    <?php endif; ?>
                                    <hr style="margin:5px 0 0 0; width:80%; border-top:1px solid #ccc;">
                                 </td>
                             </tr>
                            <tr>
                                <td colspan="2">
                                    <strong>Class Teacher's Remark:</strong> 
                                    <span style="color:#d81b60;"><?= htmlspecialchars($report_details['teacher_remarks'] ?? 'N/A') ?></span>
                                 </td>
                             </tr>
                            <tr>
                                <td colspan="2">
                                    <strong>Head Teacher's Remark:</strong> 
                                    <span style="color:#d81b60;"><?= htmlspecialchars($report_details['headmaster_remarks'] ?? 'N/A') ?></span>
                                 </td>
                             </tr>
                         </table>

                         <table width="100%" style="margin-top: 10px;">
                            <tr>
                                <td width="60%">
                                    Grace-Joan Kissi-Afare<br>
                                    (<strong>Executive Director,</strong> Queenest Paradise Montessori Complex)<br>
                                    <hr style="width:80%; margin-left:0; border-top:2px solid black;">
                                 </td>
                                <td align="right">
                                    <?php if ($stamp_exists): ?>
                                        <img src="<?= $stamp_path ?>" class="stamp-img" alt="Stamp">
                                    <?php else: ?>
                                        <span style="color:#999; font-size:10px;">[School Stamp]</span>
                                    <?php endif; ?>
                                 </td>
                             </tr>
                         </table>

                         <div class="footer-message-report">
                            <?= htmlspecialchars($random_footer) ?>
                         </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Quick Info Card (only show when not in report card or summary view) -->
        <?php if (!isset($_GET['view']) || ($_GET['view'] !== 'summary' && $_GET['view'] !== 'report_card')): ?>
            <div class="card mt-3 no-print">
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <i class="fas fa-book-open fa-2x mb-2 d-block" style="color:var(--qpms-orange)"></i>
                            <h6 class="fw-bold mb-0">Subjects Enrolled</h6>
                            <small class="text-muted">View detailed subject performance</small>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-chart-line fa-2x mb-2 d-block" style="color:var(--qpms-orange)"></i>
                            <h6 class="fw-bold mb-0">Performance Trends</h6>
                            <small class="text-muted">Track progress over periods</small>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-print fa-2x mb-2 d-block" style="color:var(--qpms-orange)"></i>
                            <h6 class="fw-bold mb-0">Printable Reports</h6>
                            <small class="text-muted">Official report cards are printer-friendly</small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- No Child Selected -->
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-child fa-5x text-muted mb-4 opacity-25"></i>
                <h4 class="text-muted">No Child Selected</h4>
                <p class="text-muted">Please select a child from the dropdown above to view academic reports.</p>
                <?php if (empty($students)): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No students are currently associated with your account. Please contact the school administration.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <footer class="no-print">
        <p class="mb-0">&copy; <?= date('Y') ?> Queenest Paradise Montessori Complex. All rights reserved. | Parent Portal v2.0</p>
        <p class="small text-muted mt-1">For official academic records, please contact the school administration.</p>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Chevron rotation on collapse toggle
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function (trigger) {
        const target = document.querySelector(trigger.dataset.bsTarget);
        if (!target) return;
        const chevron = trigger.querySelector('.collapse-chevron');

        target.addEventListener('show.bs.collapse', function () {
            if (chevron) chevron.style.transform = 'rotate(180deg)';
        });
        target.addEventListener('hide.bs.collapse', function () {
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        });
    });

    // Auto-scroll to summary section if view=summary
    const summarySection = document.getElementById('summary-section');
    if (summarySection) {
        setTimeout(() => summarySection.scrollIntoView({ behavior: 'smooth', block: 'start' }), 250);
    }

    // Auto-scroll to report card section if view=report_card
    const reportCardSection = document.getElementById('report-card-section');
    if (reportCardSection) {
        setTimeout(() => reportCardSection.scrollIntoView({ behavior: 'smooth', block: 'start' }), 250);
    }

    // Sidebar close on nav link click (mobile)
    const sidebar = document.getElementById('qpmc-sidebar');
    const overlay = document.getElementById('qpmc-overlay');
    if (sidebar && overlay) {
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 991 && typeof closeSidebar === 'function') {
                    closeSidebar();
                }
            });
        });
    }
});

// Expand all subject rows
function expandAll() {
    document.querySelectorAll('#subject-list .collapse').forEach(function (el) {
        bootstrap.Collapse.getOrCreateInstance(el).show();
    });
}
</script>

</body>
</html>