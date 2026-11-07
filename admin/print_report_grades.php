<?php
ob_start();
session_start();
require_once '../config/db.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: ../login.php');
    exit;
}

// ============================================
// HANDLE BULK PRINT REQUEST
// ============================================
$reports = [];
$bulk_print_mode = false;

if (isset($_POST['bulk_print']) && $_POST['bulk_print'] == '1') {
    $bulk_print_mode = true;
    if (isset($_POST['report_ids']) && !empty($_POST['report_ids'])) {
        $report_ids_string = $_POST['report_ids'];
        $reports = array_filter(explode(',', $report_ids_string), 'is_numeric');
    }
    if (empty($reports) && isset($_POST['report_ids']) && is_array($_POST['report_ids'])) {
        $reports = array_filter($_POST['report_ids'], 'is_numeric');
    }
} elseif (isset($_GET['report_id']) && is_numeric($_GET['report_id'])) {
    $reports = [(int)$_GET['report_id']];
}

if (empty($reports)) {
    error_log("No reports selected for printing.");
    $_SESSION['error_msg'] = "No reports selected for printing.";
    header('Location: report_card.php');
    exit;
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function normalizeProfilePicture($profile_picture, $gender = null) {
    $default_male = '/Uploads/profiles/default-male.jpg';
    $default_female = '/Uploads/profiles/default-female.jpg';
    $default_generic = '/Uploads/profiles/default_profile.png';
    
    $default_picture = $default_generic;
    if ($gender === 'Male' || $gender === 'male' || $gender === 'M') {
        $default_picture = $default_male;
    } elseif ($gender === 'Female' || $gender === 'female' || $gender === 'F') {
        $default_picture = $default_female;
    }
    
    if (empty($profile_picture)) {
        return $default_picture;
    }
    
    $clean_path = str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $profile_picture);
    $clean_path = ltrim($clean_path, '/');
    
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean_path;
    
    if (file_exists($full_path) && filesize($full_path) > 100) {
        return '/Uploads/profiles/' . $clean_path;
    }
    
    return $default_picture;
}

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
    return ['grade' => 'F9', 'remark' => 'Fail', 'class' => 'grade-f'];
}

function getRandomFooter($footer_messages) {
    $random_footer = $footer_messages[array_rand($footer_messages)];
    if (strpos($random_footer, '–') !== false) {
        $random_footer = '"' . $random_footer . '"';
    }
    return $random_footer;
}

// ============================================
// FETCH CLASS TEACHER NAME
// ============================================
function getClassTeacherName($conn, $class_id) {
    $stmt = $conn->prepare("
        SELECT u.name 
        FROM classes c
        LEFT JOIN users u ON c.teacher_id = u.id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['name'] ?? 'Class Teacher';
}

// ============================================
// FETCH ACADEMIC HISTORY – ALL TERMS FOR THE YEAR + GRADES + CLASS AVERAGE
// ============================================
function getStudentAcademicHistory($conn, $student_id, $class_id, $current_term_id) {
    $history = [];
    
    // Get academic_year_id for the current term
    $stmt = $conn->prepare("SELECT academic_year_id FROM academic_terms WHERE id = ?");
    $stmt->bind_param("i", $current_term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $year_row = $result->fetch_assoc();
    $stmt->close();
    if (!$year_row) {
        return [];
    }
    $academic_year_id = $year_row['academic_year_id'];
    
    // Get all terms for this academic year, ordered by term_number
    $stmt = $conn->prepare("
        SELECT id, term_name, term_number, start_date, end_date
        FROM academic_terms
        WHERE academic_year_id = ?
        ORDER BY term_number
    ");
    $stmt->bind_param("i", $academic_year_id);
    $stmt->execute();
    $terms_result = $stmt->get_result();
    $terms = $terms_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($terms)) {
        return [];
    }
    
    // For each term, fetch student grades and class average
    foreach ($terms as $term) {
        $term_id = $term['id'];
        // Student grades for this term and class
        $stmt = $conn->prepare("
            SELECT 
                mid_term_exam,
                end_term_exam,
                subject_id
            FROM grades
            WHERE user_id = ? AND class_id = ? AND term_id = ?
        ");
        $stmt->bind_param("iii", $student_id, $class_id, $term_id);
        $stmt->execute();
        $grades_result = $stmt->get_result();
        $grades = $grades_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $student_scores = [];
        $total_mid = 0;
        $total_end = 0;
        $subject_count = 0;
        foreach ($grades as $g) {
            $mid = is_numeric($g['mid_term_exam']) ? (float)$g['mid_term_exam'] : 0;
            $end = is_numeric($g['end_term_exam']) ? (float)$g['end_term_exam'] : 0;
            $score = ($mid * 0.5) + ($end * 0.5);
            $student_scores[] = [
                'subject_id' => $g['subject_id'],
                'score' => $score,
                'mid_term' => $mid,
                'end_term' => $end
            ];
            $total_mid += $mid;
            $total_end += $end;
            $subject_count++;
        }
        $avg_score = $subject_count > 0 ? round(array_sum(array_column($student_scores, 'score')) / $subject_count, 1) : 0;
        $avg_mid = $subject_count > 0 ? round($total_mid / $subject_count, 1) : 0;
        $avg_end = $subject_count > 0 ? round($total_end / $subject_count, 1) : 0;
        
        // Class average for this term and class
        $stmt = $conn->prepare("
            SELECT 
                AVG((mid_term_exam * 0.5) + (end_term_exam * 0.5)) as class_avg,
                AVG(mid_term_exam) as class_mid_avg,
                AVG(end_term_exam) as class_end_avg
            FROM grades
            WHERE class_id = ? AND term_id = ?
        ");
        $stmt->bind_param("ii", $class_id, $term_id);
        $stmt->execute();
        $class_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $history[] = [
            'term_id' => $term_id,
            'term_name' => $term['term_name'],
            'term_number' => $term['term_number'],
            'year' => date('Y', strtotime($term['start_date'])), // or from academic_years
            'start_date' => $term['start_date'],
            'end_date' => $term['end_date'],
            'grades' => $student_scores,
            'average_score' => $avg_score,
            'avg_mid_term' => $avg_mid,
            'avg_end_term' => $avg_end,
            'class_avg' => $class_row ? round((float)$class_row['class_avg'], 1) : 0,
            'class_mid_avg' => $class_row ? round((float)$class_row['class_mid_avg'], 1) : 0,
            'class_end_avg' => $class_row ? round((float)$class_row['class_end_avg'], 1) : 0,
        ];
    }
    
    return $history;
}

// ============================================
// GENERATE BAR CHART (SVG) – Student vs Class Average
// ============================================
function generatePerformanceTrend($history, $student_name) {
    if (empty($history)) {
        return '<p style="text-align:center;color:#666;">No historical data available for performance tracking.</p>';
    }
    
    $labels = [];
    $student_scores = [];
    $class_scores = [];
    $max_score = 0;
    $min_score = 100;
    
    foreach ($history as $term) {
        $label = substr($term['year'], -2) . ' ' . $term['term_name'];
        $labels[] = $label;
        $student_avg = $term['average_score'];
        $class_avg = $term['class_avg'];
        $student_scores[] = $student_avg;
        $class_scores[] = $class_avg;
        if ($student_avg > $max_score) $max_score = $student_avg;
        if ($class_avg > $max_score) $max_score = $class_avg;
        if ($student_avg < $min_score && $student_avg > 0) $min_score = $student_avg;
        if ($class_avg < $min_score && $class_avg > 0) $min_score = $class_avg;
    }
    
    // Set y-axis range
    $max_score = ceil(($max_score + 5) / 10) * 10;
    $min_score = floor(($min_score - 5) / 10) * 10;
    if ($min_score < 0) $min_score = 0;
    if ($max_score < 50) $max_score = 50;
    $range = $max_score - $min_score;
    if ($range < 10) $range = 10;
    
    // Chart dimensions
    $chart_width = 600;
    $chart_height = 220;
    $padding = 35;
    $plot_width = $chart_width - $padding * 2;
    $plot_height = $chart_height - $padding * 2;
    
    $num_terms = count($labels);
    $group_width = $num_terms > 1 ? $plot_width / $num_terms : $plot_width;
    $bar_width = $group_width * 0.35;
    $gap = $group_width * 0.15;
    $student_x_offset = -($bar_width + $gap/2);
    $class_x_offset = $gap/2;
    
    // Build SVG elements
    $bars_svg = '';
    $value_labels = '';
    
    for ($i = 0; $i < $num_terms; $i++) {
        $x_center = $padding + $i * $group_width + $group_width / 2;
        $student_y = $padding + $plot_height - (($student_scores[$i] - $min_score) / $range) * $plot_height;
        $class_y = $padding + $plot_height - (($class_scores[$i] - $min_score) / $range) * $plot_height;
        $bar_height_student = ($student_scores[$i] - $min_score) / $range * $plot_height;
        $bar_height_class = ($class_scores[$i] - $min_score) / $range * $plot_height;
        
        // Student bar
        $x1 = $x_center + $student_x_offset;
        $bars_svg .= '<rect x="' . $x1 . '" y="' . ($padding + $plot_height - $bar_height_student) . '" width="' . $bar_width . '" height="' . $bar_height_student . '" fill="#003087" rx="2" />';
        $value_labels .= '<text x="' . ($x1 + $bar_width/2) . '" y="' . ($padding + $plot_height - $bar_height_student - 5) . '" font-size="8" fill="#003087" text-anchor="middle" font-weight="bold">' . $student_scores[$i] . '%</text>';
        
        // Class average bar
        $x2 = $x_center + $class_x_offset;
        $bars_svg .= '<rect x="' . $x2 . '" y="' . ($padding + $plot_height - $bar_height_class) . '" width="' . $bar_width . '" height="' . $bar_height_class . '" fill="#d81b60" rx="2" />';
        $value_labels .= '<text x="' . ($x2 + $bar_width/2) . '" y="' . ($padding + $plot_height - $bar_height_class - 5) . '" font-size="8" fill="#d81b60" text-anchor="middle" font-weight="bold">' . $class_scores[$i] . '%</text>';
    }
    
    // Y-axis labels and grid lines
    $y_axis = '';
    $grid_lines = '';
    $steps = 5;
    for ($i = 0; $i <= $steps; $i++) {
        $val = $max_score - ($range * $i / $steps);
        $y = $padding + $plot_height - ($i / $steps) * $plot_height;
        $y_axis .= '<text x="8" y="' . ($y + 4) . '" font-size="9" fill="#666" text-anchor="start">' . round($val, 0) . '%</text>';
        $grid_lines .= '<line x1="' . $padding . '" y1="' . $y . '" x2="' . ($chart_width - $padding) . '" y2="' . $y . '" stroke="#e0e0e0" stroke-width="0.5" stroke-dasharray="4,4" />';
    }
    
    // X-axis labels
    $x_labels = '';
    foreach ($labels as $i => $label) {
        $x = $padding + $i * $group_width + $group_width / 2;
        $x_labels .= '<text x="' . $x . '" y="' . ($chart_height - 5) . '" font-size="8" fill="#666" text-anchor="middle">' . htmlspecialchars($label) . '</text>';
    }
    
    // Statistics
    $avg_all = count($student_scores) > 0 ? round(array_sum($student_scores) / count($student_scores), 1) : 0;
    $max_avg = count($student_scores) > 0 ? max($student_scores) : 0;
    $min_avg = count($student_scores) > 0 ? min($student_scores) : 0;
    $trend = count($student_scores) >= 2 ? ($student_scores[count($student_scores)-1] - $student_scores[0]) : 0;
    $trend_text = $trend > 3 ? '↑ Improving' : ($trend < -3 ? '↓ Declining' : '→ Stable');
    $trend_color = $trend > 3 ? '#2e7d32' : ($trend < -3 ? '#d32f2f' : '#f9a825');
    
    $html = '
    <div class="chart-container">
        <h4 style="text-align:center;color:#003087;margin:0 0 6px 0;">Performance Trend - ' . htmlspecialchars($student_name) . '</h4>
        
        <!-- Statistics Summary -->
        <div class="stats-summary">
            <div class="stat-item">
                <span class="stat-label">Current Avg</span>
                <span class="stat-value" style="color:#003087;">' . (end($student_scores) ? end($student_scores) : 0) . '%</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Overall Avg</span>
                <span class="stat-value" style="color:#2e7d32;">' . $avg_all . '%</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Highest</span>
                <span class="stat-value" style="color:#f9a825;">' . $max_avg . '%</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Lowest</span>
                <span class="stat-value" style="color:#d32f2f;">' . $min_avg . '%</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Trend</span>
                <span class="stat-value" style="color:' . $trend_color . ';">' . $trend_text . '</span>
            </div>
        </div>
        
        <div style="text-align:center;font-size:9px;color:#666;margin-bottom:3px;">
            <span style="display:inline-block;margin:0 10px;"><span style="display:inline-block;width:12px;height:12px;background:#003087;border-radius:2px;"></span> Student</span>
            <span style="display:inline-block;margin:0 10px;"><span style="display:inline-block;width:12px;height:12px;background:#d81b60;border-radius:2px;"></span> Class Average</span>
        </div>
        
        <svg width="100%" viewBox="0 0 ' . $chart_width . ' ' . $chart_height . '" preserveAspectRatio="xMidYMid meet" style="background:#fafafa;border-radius:6px;">
            ' . $grid_lines . '
            ' . $y_axis . '
            ' . $bars_svg . '
            ' . $value_labels . '
            ' . $x_labels . '
        </svg>
    </div>';
    
    // Subject-wise comparison will be generated separately with department subjects
    return $html;
}

// ============================================
// GENERATE SUBJECT BREAKDOWN FOR CURRENT TERM
// ============================================
function generateSubjectBreakdown($grades) {
    if (empty($grades)) {
        return '<p style="text-align:center;color:#666;">No subject data available.</p>';
    }
    
    $total_score = 0;
    $subject_count = count($grades);
    
    $html = '
    <div class="subject-breakdown">
        <h4 style="text-align:center;color:#003087;margin:0 0 6px 0;">Subject Performance Breakdown</h4>
        <table class="breakdown-table">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>SBA (50%)</th>
                    <th>Exam (50%)</th>
                    <th>Total</th>
                    <th>Grade</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($grades as $grade) {
        $mid = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
        $end = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
        $sba_scaled = $mid * 0.5;
        $exam_scaled = $end * 0.5;
        $total = $sba_scaled + $exam_scaled;
        $total_score += $total;
        $grade_info = getGradeAndRemark($total);
        $status_color = $total >= 65 ? '#2e7d32' : ($total >= 50 ? '#f9a825' : '#d32f2f');
        
        $html .= '
                <tr>
                    <td><strong>' . htmlspecialchars($grade['subject_name']) . '</strong></td>
                    <td>' . number_format($sba_scaled, 1) . '</td>
                    <td>' . number_format($exam_scaled, 1) . '</td>
                    <td><strong>' . number_format($total, 1) . '</strong></td>
                    <td><span class="grade-badge ' . $grade_info['class'] . '">' . $grade_info['grade'] . '</span></td>
                    <td style="color:' . $status_color . ';font-weight:bold;">' . $grade_info['remark'] . '</td>
                </tr>';
    }
    
    $avg_score = $subject_count > 0 ? round($total_score / $subject_count, 1) : 0;
    
    $html .= '
            </tbody>
        </table>
        <div class="breakdown-summary">
            <span><strong>Subjects:</strong> ' . $subject_count . '</span>
            <span><strong>Total Score:</strong> ' . number_format($total_score, 1) . '</span>
            <span><strong>Average:</strong> ' . $avg_score . '%</span>
        </div>
    </div>';
    
    return $html;
}

// ============================================
// FOOTER MESSAGES
// ============================================
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
    "In learning you will teach, and in teaching you will learn. – Phil Collins"
];

// ============================================
// PROCESS REPORTS
// ============================================
$reports_html = '';
$index = 0;
$total_reports = count($reports);
$successful_reports = 0;

foreach ($reports as $report_id) {
    // Fetch report basic info
    $stmt = $conn->prepare("SELECT user_id, term_id, class_id, promoted_to_class_id, attendance, reopening_date, conduct, attitude, interest, teacher_remarks, headmaster_remarks FROM student_reports WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report_basic = $result->fetch_assoc();
    $stmt->close();

    if (!$report_basic) {
        error_log("Report ID $report_id not found.");
        continue;
    }

    $user_id = $report_basic['user_id'];
    $term_id = $report_basic['term_id'];
    $class_id = $report_basic['class_id'];

    // Fetch user data including gender
    $stmt = $conn->prepare("SELECT name, profile_picture, gender, phone, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        error_log("User ID $user_id not found for report ID $report_id.");
        continue;
    }

    $profile_picture = normalizeProfilePicture($user['profile_picture'], $user['gender']);

    // Fetch report details
    $stmt = $conn->prepare("
        SELECT c.class_name, ac.term_name, ac.year, ac.start_date, ac.end_date 
        FROM classes c 
        JOIN academic_calendar ac ON ac.id = ? 
        WHERE c.id = ?
    ");
    $stmt->bind_param("ii", $term_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report_details = $result->fetch_assoc();
    $stmt->close();

    if (!$report_details) {
        error_log("Report details not found for report ID $report_id.");
        continue;
    }

    // Fetch promoted class name
    $next_class_name = 'N/A';
    if ($report_basic['promoted_to_class_id']) {
        $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $stmt->bind_param("i", $report_basic['promoted_to_class_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $next_class_name = $row['class_name'];
        }
        $stmt->close();
    }

    // Fetch Class Teacher name
    $class_teacher_name = getClassTeacherName($conn, $class_id);

    // Auto-calculate attendance
    $auto_days_attended = 0;
    $auto_days_marked = 0;

    if (!empty($report_details['start_date']) && !empty($report_details['end_date'])) {
        $att_stmt = $conn->prepare("
            SELECT 
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS days_present,
                COUNT(*) AS days_marked
            FROM attendance
            WHERE user_id = ? AND class_id = ? AND date BETWEEN ? AND ?
        ");
        $att_stmt->bind_param("iiss", $user_id, $class_id, $report_details['start_date'], $report_details['end_date']);
        $att_stmt->execute();
        $att_row = $att_stmt->get_result()->fetch_assoc();
        $att_stmt->close();

        if ($att_row) {
            $auto_days_attended = (int)($att_row['days_present'] ?? 0);
            $auto_days_marked   = (int)($att_row['days_marked'] ?? 0);
        }
    }

    $attendance_display = $auto_days_marked > 0 ? $auto_days_attended . ' out of ' . $auto_days_marked : 'N/A';
    $attendance_percentage = $auto_days_marked > 0 ? number_format(($auto_days_attended / $auto_days_marked) * 100, 1) . '%' : 'N/A';

    $reopening_display = $report_basic['reopening_date'] ? date('l, F j, Y', strtotime($report_basic['reopening_date'])) : 'N/A';

    // Fetch grades
    $stmt = $conn->prepare("
        SELECT g.mid_term_exam, g.end_term_exam, g.subject_id, s.subject_name 
        FROM grades g 
        JOIN subjects s ON g.subject_id = s.id 
        WHERE g.user_id = ? AND g.term_id = ? AND g.class_id = ?
    ");
    $stmt->bind_param("iii", $user_id, $term_id, $class_id);
    $stmt->execute();
    $grades_result = $stmt->get_result();
    $grades = $grades_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($grades)) {
        error_log("No grades found for user ID $user_id, term ID $term_id.");
        continue;
    }

    // Calculate totals and subject data
    $grades_html = '';
    $grand_total = 0;
    foreach ($grades as $grade) {
        $mid_term = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
        $end_term = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
        $sba_scaled = $mid_term * 0.5;
        $exam_scaled = $end_term * 0.5;
        $total = $sba_scaled + $exam_scaled;
        $grade_info = getGradeAndRemark($total);
        $grand_total += $total;

        $grades_html .= '<tr>
            <td align="left"><font color="black">' . htmlspecialchars($grade['subject_name'], ENT_QUOTES, 'UTF-8') . '</font></td>
            <td align="center"><font color="black">' . number_format($sba_scaled, 1) . '</font></td>
            <td align="center"><font color="black">' . number_format($exam_scaled, 1) . '</font></td>
            <td align="center"><font color="black"><strong>' . number_format($total, 1) . '</strong></font></td>
            <td align="center"><font color="black">' . $grade_info['grade'] . '</font></td>
            <td align="center"><font color="black">' . $grade_info['remark'] . '</font></td>
        </tr>';
    }

    // Total row
    $grades_html .= '<tr align="left" class="total-row">
        <th align="left"><font color="black"><h3>TOTAL</h3></font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black"><strong>' . number_format($grand_total, 1) . '</strong></font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black"></font></th>
    </tr>';

    // Aggregate
    $max_score = count($grades) * 100;
    $aggregate_percentage = ($grand_total / $max_score) * 100;
    $aggregate_grade = getGradeAndRemark($aggregate_percentage)['grade'];
    $aggregate_display = number_format($aggregate_percentage, 1) . '% (' . $aggregate_grade . ') [' . number_format($grand_total, 1) . ' / ' . $max_score . ']';

    // Get academic history for charts (with class averages) - pass current term_id
    $academic_history = getStudentAcademicHistory($conn, $user_id, $class_id, $term_id);

    // Check images
    $signature_path = '/assets/images/signature_head.png';
    $stamp_path = '/assets/images/stamp.png';
    $seal_path = '/assets/images/seal.png';
    $base_path = $_SERVER['DOCUMENT_ROOT'];
    $signature_exists = file_exists($base_path . $signature_path);
    $stamp_exists = file_exists($base_path . $stamp_path);
    $seal_exists = file_exists($base_path . $seal_path);

    $random_footer = getRandomFooter($footer_messages);

    // Generate performance trend (bar chart)
    $current_avg = round($aggregate_percentage, 1);
    $performance_chart = generatePerformanceTrend($academic_history, $user['name']);
    
    // Generate subject breakdown for current term
    $subject_breakdown = generateSubjectBreakdown($grades);
    
    // --- Generate subject comparison over time using DEPARTMENT subjects ---
    $subject_comparison_html = '';
    // Get department_id from the class
    $stmt_dept = $conn->prepare("SELECT department_id FROM classes WHERE id = ?");
    $stmt_dept->bind_param("i", $class_id);
    $stmt_dept->execute();
    $dept_res = $stmt_dept->get_result();
    $dept_row = $dept_res->fetch_assoc();
    $stmt_dept->close();
    $department_id = $dept_row['department_id'] ?? 0;

    if ($department_id > 0) {
        // Get all subjects for this department
        $stmt_subj = $conn->prepare("SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name");
        $stmt_subj->bind_param("i", $department_id);
        $stmt_subj->execute();
        $subj_res = $stmt_subj->get_result();
        $department_subjects = $subj_res->fetch_all(MYSQLI_ASSOC);
        $stmt_subj->close();
        
        if (!empty($department_subjects) && !empty($academic_history)) {
            $subject_comparison_html = '
            <div class="subject-comparison">
                <h4 style="text-align:center;color:#003087;margin:10px 0 6px 0;">Subject Performance Over Time</h4>
                <table class="subject-trend-table">
                    <thead>
                        <tr>
                            <th>Subject</th>';
            foreach ($academic_history as $term) {
                $subject_comparison_html .= '<th>' . htmlspecialchars($term['term_name']) . '<br><span style="font-size:7px;">' . htmlspecialchars($term['year']) . '</span></th>';
            }
            $subject_comparison_html .= '<th>Trend</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            foreach ($department_subjects as $subj) {
                $subject_id = $subj['id'];
                $subject_name = $subj['subject_name'];
                $scores = [];
                foreach ($academic_history as $term) {
                    $found = false;
                    foreach ($term['grades'] as $g) {
                        if ($g['subject_id'] == $subject_id) {
                            $scores[] = round($g['score'], 1);
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) $scores[] = null;
                }
                $subject_comparison_html .= '<tr><td><strong>' . htmlspecialchars($subject_name) . '</strong></td>';
                $trend_values = [];
                foreach ($scores as $score) {
                    if ($score !== null) {
                        $subject_comparison_html .= '<td>' . $score . '%</td>';
                        $trend_values[] = $score;
                    } else {
                        $subject_comparison_html .= '<td>—</td>';
                    }
                }
                if (count($trend_values) >= 2) {
                    $first = $trend_values[0];
                    $last = $trend_values[count($trend_values) - 1];
                    $diff = $last - $first;
                    if ($diff > 5) {
                        $trend = '↑ Improving';
                        $color = '#2e7d32';
                    } elseif ($diff < -5) {
                        $trend = '↓ Declining';
                        $color = '#d32f2f';
                    } else {
                        $trend = '→ Stable';
                        $color = '#f9a825';
                    }
                    $subject_comparison_html .= '<td style="color:' . $color . ';font-weight:bold;">' . $trend . '</td>';
                } else {
                    $subject_comparison_html .= '<td>—</td>';
                }
                $subject_comparison_html .= '</tr>';
            }
            
            $subject_comparison_html .= '
                    </tbody>
                </table>
            </div>';
        }
    }

    // Page break for bulk print
    $page_break_style = '';
    $page_break_class = '';
    if ($bulk_print_mode && $index < $total_reports - 1) {
        $page_break_style = 'page-break-after: always;';
        $page_break_class = 'page-break';
    }

    $reports_html .= '
    <div class="container ' . $page_break_class . '" id="report_' . $index . '" style="' . $page_break_style . '">
        ' . ($bulk_print_mode ? '
        <div class="bulk-print-indicator">
            <span>Report ' . ($index + 1) . ' of ' . $total_reports . ' - ' . htmlspecialchars($user['name']) . '</span>
        </div>
        ' : '') . '
        
        <!-- ===== PAGE 1 - Report Card ===== -->
        <div class="page-one">
            <!-- Header: School Name -->
            <div class="school-header-title">
                <h1>Queenest Paradise Montessori Complex</h1>
            </div>
            
            <!-- Header: Logo, Motto, Contact -->
            <table border="0" cellspacing="0" cellpadding="0" align="center" width="100%" class="header-table">
                <tr>
                    <td width="140" align="left" valign="top" class="logo-section">
                        <img src="/assets/images/logo.png" alt="School Logo" width="140" height="auto" style="max-height:130px;">
                    </td>
                    <td align="center" valign="middle" class="school-motto">
                        <p>"Christ, Our Foundation<br>Integrity, Discipline and Excellence our hallmark"</p>
                    </td>
                    <td width="280" align="right" valign="top" class="contact-info">
                        <strong>CONTACT</strong>
                        <p>P. O. Box AM 42 Amasaman</p>
                        <p>0507390755 / 0246573937</p>
                        <p>info.queenspa49@gmail.com</p>
                    </td>
                </tr>
            </table>
            
            <div class="report-title">
                ' . htmlspecialchars($report_details['year'] .' '. $report_details['term_name'] . " - END OF TERM EXAMINATION Report", ENT_QUOTES, 'UTF-8') . '
            </div>
            
            <!-- Student Details with Photo aligned -->
            <table border="0" cellspacing="0" cellpadding="0" width="100%" class="report-details-table">
                <tr>
                    <td width="72%" valign="top">
                        <table border="0" cellspacing="0" cellpadding="3" width="100%" class="details-table">
                            <tr>
                                <td width="30%"><strong>Report Date:</strong></td>
                                <td width="70%">' . date('l, F j, Y') . '</td>
                            </tr>
                            <tr>
                                <td><strong>Student Name:</strong></td>
                                <td>' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . '</td>
                            </tr>
                            <tr>
                                <td><strong>Student ID:</strong></td>
                                <td>QPMS' . str_pad($user_id, 4, '0', STR_PAD_LEFT) . '</td>
                            </tr>
                            <tr>
                                <td><strong>Class/Grade:</strong></td>
                                <td>' . htmlspecialchars($report_details['class_name'], ENT_QUOTES, 'UTF-8') . '</td>
                            </tr>
                            <tr>
                                <td><strong>Term:</strong></td>
                                <td>' . htmlspecialchars($report_details['term_name'], ENT_QUOTES, 'UTF-8') . '</td>
                            </tr>
                            <tr>
                                <td><strong>Year:</strong></td>
                                <td>' . htmlspecialchars($report_details['year'], ENT_QUOTES, 'UTF-8') . '</td>
                            </tr>
                            <tr>
                                <td><strong>Attendance:</strong></td>
                                <td>' . htmlspecialchars($attendance_display . ' (' . $attendance_percentage . ')', ENT_QUOTES, 'UTF-8') . '</td>
                            </tr>
                            <tr>
                                <td><strong>Reopening:</strong></td>
                                <td>' . htmlspecialchars($reopening_display, ENT_QUOTES, 'UTF-8') . '</td>
                            </tr>
                            <tr>
                                <td><strong>Promoted to:</strong></td>
                                <td><font color="#d81b60">' . htmlspecialchars($next_class_name, ENT_QUOTES, 'UTF-8') . '</font></td>
                            </tr>
                        </table>
                    </td>
                    <td width="28%" align="center" valign="top" class="student-photo">
                        <img src="' . htmlspecialchars($profile_picture, ENT_QUOTES, 'UTF-8') . '" alt="Student Photo" loading="lazy">
                    </td>
                </tr>
            </table>
            
            <!-- Grades Table (colorless) -->
            <table width="100%" align="center" border="1" cellspacing="0" bordercolor="black" class="grades-table">
                <tr align="left">
                    <th align="left" width="30%"><font color="black">SUBJECT NAME</font></th>
                    <th align="center" width="15%"><font color="black">SBA 50%</font></th>
                    <th align="center" width="15%"><font color="black">EXAMS 50%</font></th>
                    <th align="center" width="15%"><font color="black">TOTAL 100%</font></th>
                    <th align="center" width="10%"><font color="black">GRADE</font></th>
                    <th align="center" width="15%"><font color="black">REMARKS</font></th>
                </tr>
                ' . $grades_html . '
            </table>
            
            <!-- Total Aggregate -->
            <table width="100%" border="0" align="center" cellpadding="3" cellspacing="0" style="margin-top:2px;">
                <tr>
                    <td width="100%" valign="top">
                        <strong>Total Aggregate:</strong> ' . $aggregate_display . '
                    </td>
                </tr>
            </table>
            
            <!-- Conduct, Attitude, Interest (colorless) -->
            <table width="100%" border="0" align="center" cellpadding="3" cellspacing="0" class="behavior-table">
                <tr>
                    <td width="33%" valign="top">
                        <strong>Conduct:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['conduct'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td width="33%" valign="top">
                        <strong>Attitude:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['attitude'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td width="34%" valign="top">
                        <strong>Interest:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['interest'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
            </table>
            
            <!-- Teacher Remarks -->
            <div class="remarks-section">
                <table width="100%" border="0" align="center" cellpadding="2" cellspacing="0">
                    <tr>
                        <td colspan="2" valign="top">
                            <strong>Class Teacher\'s Remark:</strong> 
                            <span style="color:#d81b60;">' . htmlspecialchars($report_basic['teacher_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</span>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" valign="top">
                            <strong>Head Teacher\'s Remark:</strong> 
                            <span style="color:#d81b60;">' . htmlspecialchars($report_basic['headmaster_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Footer: Administrator (Left) and Teacher (Right) -->
            <table width="100%" border="0" align="center" cellpadding="5" cellspacing="0" class="signature-table">
                <tr>
                    <td width="50%" valign="top" class="admin-section">
                        <div style="text-align:center;">
                            <strong style="font-size:12px;color:#003087;">ADMINISTRATOR</strong>
                            <br><br>
                            <span style="font-size:13px;font-weight:700;">Grace-Joan Kissi-Afare</span><br>
                            <span style="font-size:10px;color:#666;">(Executive Director, Queenest Paradise Montessori Complex)</span>
                            <br><br>
                            ' . ($signature_exists ? '<img src="' . $signature_path . '" width="180" height="55" alt="Director Signature" style="display:block;margin:0 auto;">' : '') . '
                            <hr align="center" size="2" color="black" width="60%">
                            <span style="font-size:9px;color:#666;">Signature</span>
                            <br>
                            ' . ($stamp_exists ? '<img src="' . $stamp_path . '" width="140" height="140" alt="Stamp" style="display:block;margin:4px auto;object-fit:contain;">' : '') . '
                            <span style="font-size:9px;color:#666;">Stamp</span>
                        </div>
                    </td>
                    <td width="50%" valign="top" class="teacher-section">
                        <div style="text-align:center;">
                            <strong style="font-size:12px;color:#003087;">CLASS TEACHER</strong>
                            <br><br>
                            <span style="font-size:14px;font-weight:700;color:#d81b60;">' . htmlspecialchars($class_teacher_name, ENT_QUOTES, 'UTF-8') . '</span><br>
                            <span style="font-size:10px;color:#666;">' . htmlspecialchars($report_details['class_name'], ENT_QUOTES, 'UTF-8') . ' Class Teacher</span>
                            <br><br>
                            <div style="height:40px;"></div>
                            <hr align="center" size="2" color="black" width="60%">
                            <span style="font-size:9px;color:#666;">Signature</span>
                        </div>
                    </td>
                </tr>
            </table>
            
            <!-- Footer Message -->
            <div class="footer-message">
                ' . htmlspecialchars($random_footer, ENT_QUOTES, 'UTF-8') . '
            </div>
        </div>
        
        <!-- ===== PAGE 2 - Data Driven Analysis ===== -->
        <div class="page-two" style="page-break-before:always;">
            <div class="page-two-header">
                <h2 style="text-align:center;color:#003087;margin:0 0 4px 0;">DATA DRIVEN ANALYSIS</h2>
                <p style="text-align:center;color:#666;font-size:11px;margin:0 0 10px 0;">
                    ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . ' | ' . htmlspecialchars($report_details['class_name'], ENT_QUOTES, 'UTF-8') . ' | ' . htmlspecialchars($report_details['year'], ENT_QUOTES, 'UTF-8') . '
                </p>
            </div>
            
            <!-- Performance Trend Chart (Bar Chart) -->
            ' . $performance_chart . '
            
            <!-- Subject Breakdown for current term -->
            ' . $subject_breakdown . '
            
            <!-- Subject Comparison Over Time -->
            ' . $subject_comparison_html . '
            
            <!-- Page 2 Footer -->
            <div class="page-two-footer">
                Generated on ' . date('F j, Y, g:i A') . ' | Queenest Paradise Montessori Complex
            </div>
        </div>
    </div>';

    $index++;
    $successful_reports++;
}

if (empty($reports_html)) {
    error_log("No valid reports generated for printing.");
    $_SESSION['error_msg'] = "No valid reports found for printing.";
    header('Location: report_card.php');
    exit;
}

$page_title = ($bulk_print_mode ? "Bulk Print - " . $successful_reports . " Report Cards" : "Print Report Card") . " - Queenest Paradise Montessori Complex";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Queenest Paradise Montessori Complex">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style type="text/css">
        @page {
            size: A4;
            margin: 8mm 10mm;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            display: none;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
            font-family: 'Inter', 'Roboto', 'Arial', sans-serif;
            color: #1a1a1a;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        @media print {
            body {
                display: block;
                background-color: white;
            }
            .container {
                display: block !important;
                page-break-after: always;
            }
            .container:last-child {
                page-break-after: avoid;
            }
            .bulk-print-indicator {
                display: none !important;
            }
            .print-controls {
                display: none !important;
            }
            .page-two {
                page-break-before: always !important;
            }
            /* Watermark background on each page */
            .page-one::before, .page-two::before {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            .page-one, .page-two {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }
        
        /* Print Controls Bar */
        .print-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #003087, #d81b60);
            color: white;
            padding: 12px 25px;
            z-index: 9999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .print-controls h3 {
            margin: 0;
            font-size: 16px;
        }
        
        .print-controls .btn-group {
            display: flex;
            gap: 8px;
        }
        
        .print-controls .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .print-controls .btn-print {
            background: white;
            color: #003087;
        }
        
        .print-controls .btn-print:hover {
            background: #f0f0f0;
            transform: translateY(-2px);
        }
        
        .print-controls .btn-close {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        
        .print-controls .btn-close:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .bulk-print-indicator {
            background: #e8f4fd;
            border-left: 4px solid #003087;
            padding: 6px 12px;
            margin-bottom: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #003087;
            text-align: center;
            border-radius: 4px;
        }
        
        .container {
            display: none;
            width: 100%;
            min-height: 100vh;
            padding: 0;
            box-sizing: border-box;
            position: relative;
            background: white;
            page-break-after: always;
        }
        
        .page-one, .page-two {
            padding: 6mm 8mm;
            position: relative;
            z-index: 1;
            min-height: 100vh;
        }
        
        .page-two {
            page-break-before: always;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        /* ===== WATERMARK – centered on each page ===== */
        .page-one::before,
        .page-two::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 55%;
            height: 55%;
            background-image: url('<?php echo $seal_exists ? $seal_path : ''; ?>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.08;
            z-index: 0;
            pointer-events: none;
        }
        
        .page-one > *, .page-two > * {
            position: relative;
            z-index: 1;
        }
        
        /* ===== SCHOOL HEADER ===== */
        .school-header-title {
            text-align: center;
            margin-bottom: 2px;
        }
        
        .school-header-title h1 {
            font-size: 22px;
            font-weight: 800;
            color: #003087;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            padding: 0;
            line-height: 1.2;
        }
        
        .header-table {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            border-collapse: collapse;
        }
        
        .logo-section img {
            width: 140px;
            height: auto;
            max-height: 130px;
        }
        
        .school-motto {
            text-align: center;
            vertical-align: middle;
            padding: 0 8px;
        }
        
        .school-motto p {
            font-size: 11px;
            color: #d81b60;
            font-style: italic;
            margin: 0;
            line-height: 1.3;
        }
        
        .contact-info {
            text-align: right;
            vertical-align: top;
        }
        
        .contact-info strong {
            font-size: 10px;
            color: #003087;
            text-decoration: underline;
            font-weight: 700;
            display: block;
            margin-bottom: 2px;
        }
        
        .contact-info p {
            font-size: 9px;
            color: #d81b60;
            margin: 1px 0;
            line-height: 1.2;
        }
        
        .report-title {
            font-size: 13px;
            color: #003087;
            text-decoration: underline;
            margin: 3mm 0 2mm 0;
            text-align: center;
            font-weight: 700;
            line-height: 1.2;
        }
        
        /* ===== STUDENT DETAILS ===== */
        .report-details-table {
            margin-bottom: 4px;
        }
        
        .details-table {
            border-collapse: collapse;
            font-size: 9px;
            color: #1a1a1a;
        }
        
        .details-table td {
            padding: 1px 3px;
            vertical-align: top;
        }
        
        .details-table strong {
            color: #003087;
            font-weight: 700;
        }
        
        .student-photo {
            text-align: center;
            vertical-align: top;
            padding-left: 8px;
        }
        
        .student-photo img {
            width: 100px;
            height: 105px;
            object-fit: cover;
            border: 3px solid #003087;
            border-radius: 10px;
            background-color: #f5f5f5;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        /* ===== GRADES TABLE - COLORLESS ===== */
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3px;
            border-radius: 6px;
            overflow: hidden;
            font-size: 9px;
        }
        
        .grades-table th, .grades-table td {
            border: 1px solid #000000;
            padding: 2px 4px;
            text-align: center;
            color: #1a1a1a;
            background-color: transparent !important;
        }
        
        .grades-table th {
            font-weight: 700;
            background-color: transparent !important;
        }
        
        .grades-table .total-row th {
            background-color: transparent !important;
        }
        
        /* ===== BEHAVIOR TABLE ===== */
        .behavior-table {
            margin-top: 3px;
        }
        
        .behavior-table td {
            padding: 3px 6px;
            font-size: 9px;
            background: transparent !important;
            border-radius: 4px;
        }
        
        /* ===== REMARKS SECTION ===== */
        .remarks-section {
            margin-top: 3px;
            padding: 4px 6px;
            background: transparent !important;
            border-left: 3px solid #003087;
            border-radius: 4px;
        }
        
        .remarks-section td {
            padding: 1px 4px;
            font-size: 9px;
        }
        
        /* ===== SIGNATURE TABLE ===== */
        .signature-table {
            margin-top: 4px;
        }
        
        .signature-table td {
            padding: 3px 6px;
            vertical-align: top;
        }
        
        .admin-section, .teacher-section {
            border-top: 2px solid #e0e0e0;
            padding-top: 6px !important;
        }
        
        .teacher-section {
            border-left: 2px solid #e0e0e0;
        }
        
        .admin-section hr, .teacher-section hr {
            border: 0;
            border-top: 2px solid #003087;
            margin: 2px auto;
            width: 60%;
        }
        
        .footer-message {
            font-size: 9px;
            padding: 4px 0 2px 0;
            text-align: center;
            color: #003087;
            font-style: italic;
            border-top: 1px solid #e0e0e0;
            margin-top: 4px;
        }
        
        /* ===== PAGE 2 - DATA DRIVEN ANALYSIS ===== */
        .page-two {
            padding-top: 4px;
        }
        
        .page-two-header {
            margin-bottom: 6px;
        }
        
        .page-two-header h2 {
            font-size: 16px;
            border-bottom: 2px solid #003087;
            display: inline-block;
            padding-bottom: 2px;
        }
        
        /* ===== STATS SUMMARY ===== */
        .stats-summary {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 6px;
            padding: 6px;
            background: #f5f7fa;
            border-radius: 6px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-label {
            display: block;
            font-size: 7px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            display: block;
            font-size: 12px;
            font-weight: 700;
        }
        
        /* ===== CHART CONTAINER ===== */
        .chart-container {
            margin: 4px 0 8px 0;
            padding: 8px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background: #fafafa;
        }
        
        .chart-container h4 {
            font-size: 11px;
        }
        
        .chart-legend {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 4px;
            flex-wrap: wrap;
            font-size: 8px;
        }
        
        .chart-legend span {
            display: flex;
            align-items: center;
            gap: 3px;
        }
        
        .legend-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        /* ===== SUBJECT BREAKDOWN ===== */
        .subject-breakdown {
            margin: 4px 0 8px 0;
            padding: 8px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background: #fafafa;
        }
        
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }
        
        .breakdown-table th, .breakdown-table td {
            padding: 2px 4px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }
        
        .breakdown-table th {
            background: #e8f0fe;
            font-weight: 700;
            color: #003087;
        }
        
        .breakdown-table td:first-child {
            text-align: left;
        }
        
        .breakdown-summary {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 4px;
            font-size: 9px;
            color: #555;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 8px;
            border: 1px solid #ccc;
            background: transparent !important;
        }
        
        .grade-badge.grade-ap { color: #003087; border-color: #003087; }
        .grade-badge.grade-a { color: #1b5e20; border-color: #1b5e20; }
        .grade-badge.grade-b2 { color: #f57f17; border-color: #f57f17; }
        .grade-badge.grade-b3 { color: #e65100; border-color: #e65100; }
        .grade-badge.grade-c { color: #b71c1c; border-color: #b71c1c; }
        .grade-badge.grade-d { color: #4a148c; border-color: #4a148c; }
        .grade-badge.grade-e { color: #4a148c; border-color: #4a148c; }
        .grade-badge.grade-f { color: #424242; border-color: #424242; }
        
        /* ===== SUBJECT COMPARISON ===== */
        .subject-comparison {
            margin: 4px 0 8px 0;
            padding: 8px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background: #fafafa;
        }
        
        .subject-trend-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }
        
        .subject-trend-table th, .subject-trend-table td {
            padding: 2px 4px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }
        
        .subject-trend-table th {
            background: #e8f0fe;
            font-weight: 700;
            color: #003087;
        }
        
        .subject-trend-table td:first-child {
            text-align: left;
        }
        
        .page-two-footer {
            font-size: 8px;
            text-align: center;
            color: #999;
            border-top: 1px solid #e0e0e0;
            padding-top: 6px;
            margin-top: 6px;
        }
    </style>
</head>
<body>
    <?php if ($bulk_print_mode): ?>
    <div class="print-controls">
        <h3>
            <i class="fas fa-print" style="margin-right: 10px;"></i>
            Bulk Print - <?php echo $successful_reports; ?> Report Card<?php echo $successful_reports > 1 ? 's' : ''; ?> Ready
        </h3>
        <div class="btn-group">
            <button class="btn btn-print" onclick="manualPrint()">
                <i class="fas fa-print"></i> Print All Reports
            </button>
            <button class="btn btn-close" onclick="closeWindow()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <?php echo $reports_html; ?>
    
    <script type="text/javascript">
        document.body.style.display = 'block';
        document.querySelectorAll('.container').forEach(function(container) {
            container.style.display = 'block';
        });
        
        setTimeout(function() {
            window.print();
        }, 400);
        
        function manualPrint() {
            window.print();
        }
        
        function closeWindow() {
            window.close();
            if (!window.closed) {
                window.location.href = 'report_card.php';
            }
        }
    </script>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>