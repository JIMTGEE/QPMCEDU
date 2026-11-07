<?php
ob_start();
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher to access this page.";
    header('Location: /login.php');
    exit;
}

// Validate query parameters
$required_params = ['student_id', 'class_id', 'term_id'];
foreach ($required_params as $param) {
    if (!isset($_GET[$param]) || !is_numeric($_GET[$param]) || $_GET[$param] <= 0) {
        $_SESSION['error_msg'] = "Invalid or missing report parameters.";
        header('Location: /teacher/submit_grades.php');
        exit;
    }
}

$student_id = (int)$_GET['student_id'];
$class_id = (int)$_GET['class_id'];
$term_id = (int)$_GET['term_id'];

// Define the array of 50 footer messages
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
    "It always seems impossible until it’s done. – Nelson Mandela",
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
    "The journey of improvement continues.",
    "End of document. Start of brilliance.",
    "If you’ve read this far, you deserve a coffee.",
    "This report is 100% typo-free (hopefully).",
    "Signed, sealed, and ready to inspire.",
    "No trees were harmed in the making of this digital report.",
    "Proof that hard work looks good on paper.",
    "This page intentionally left awesome.",
    "Report complete. Mic drop.",
    "The end... or is it just the beginning?",
    "Compiled with caffeine and commitment.",
    "Guided by purpose, driven by results.",
    "Empowering learners, one insight at a time.",
    "Mission-focused, impact-driven.",
    "Teaching with intention, reporting with integrity.",
    "Vision without action is merely a dream.",
    "Committed to excellence in education.",
    "Where data meets dedication.",
    "Every number tells a story.",
    "Purposeful reporting for purposeful teaching.",
    "Turning observations into opportunities."
];

// Select a random footer and add quotation marks if it's a quote
$random_footer = $footer_messages[array_rand($footer_messages)];
if (strpos($random_footer, '–') !== false) {
    $random_footer = '"' . $random_footer . '"';
}

// Fetch student data
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['error_msg'] = "Student not found.";
    header('Location: /teacher/submit_grades.php');
    exit;
}

// Fetch report details (class, term, year, and additional fields)
$stmt = $conn->prepare("
    SELECT sr.id AS report_id, sr.promoted_to_class_id, sr.attendance, sr.reopening_date,
           sr.conduct, sr.attitude, sr.interest, sr.teacher_remarks, sr.headmaster_remarks
    FROM student_reports sr
    WHERE sr.user_id = ? AND sr.class_id = ? AND sr.term_id = ?
");
$stmt->bind_param("iii", $student_id, $class_id, $term_id);
$stmt->execute();
$result = $stmt->get_result();
$report_details = $result->fetch_assoc();
$stmt->close();

if (!$report_details) {
    $_SESSION['error_msg'] = "Report details not found.";
    header('Location: /teacher/submit_grades.php');
    exit;
}

// Fetch class details
$stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$class_details = $result->fetch_assoc();
$stmt->close();

if (!$class_details) {
    $_SESSION['error_msg'] = "Class details not found.";
    header('Location: /teacher/submit_grades.php');
    exit;
}

// Fetch term details
$stmt = $conn->prepare("SELECT term_name, year FROM academic_calendar WHERE id = ?");
$stmt->bind_param("i", $term_id);
$stmt->execute();
$result = $stmt->get_result();
$term_details = $result->fetch_assoc();
$stmt->close();

if (!$term_details) {
    $_SESSION['error_msg'] = "Term details not found.";
    header('Location: /teacher/submit_grades.php');
    exit;
}

// Fetch promoted class name
$next_class_name = 'N/A';
if ($report_details['promoted_to_class_id']) {
    $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $stmt->bind_param("i", $report_details['promoted_to_class_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $next_class_name = $row['class_name'];
    }
    $stmt->close();
}

// Define total days for the term
$total_days = 76;

// Format attendance
$attendance_display = $report_details['attendance'] ? $report_details['attendance'] . ' out of ' . $total_days : 'N/A';
$attendance_percentage = $report_details['attendance'] && is_numeric($report_details['attendance']) && $report_details['attendance'] > 0 ? number_format(($report_details['attendance'] / $total_days) * 100, 1) . '%' : 'N/A';

// Format reopening date
$reopening_display = $report_details['reopening_date'] ? date('l, F j, Y', strtotime($report_details['reopening_date'])) : 'N/A';

// Fetch grades data for subject pie chart
$stmt = $conn->prepare("
    SELECT g.mid_term_exam, g.end_term_exam, g.comment, g.class_id, s.subject_name
    FROM grades g
    JOIN subjects s ON g.subject_id = s.id
    WHERE g.user_id = ? AND g.term_id = ? AND g.class_id = ?
");
$stmt->bind_param("iii", $student_id, $term_id, $class_id);
$stmt->execute();
$grades_result = $stmt->get_result();
$grades = $grades_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($grades)) {
    $_SESSION['error_msg'] = "No grades found for this student and term.";
    header('Location: /teacher/submit_grades.php');
    exit;
}

// Validate class_id consistency
$class_ids = array_unique(array_column($grades, 'class_id'));
if (count($class_ids) > 1 || $class_ids[0] != $class_id) {
    $_SESSION['error_msg'] = "Inconsistent class IDs found for this student and term.";
    header('Location: /teacher/submit_grades.php');
    exit;
}

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

// Calculate student grand total and subject data for pie chart
$student_grand_total = 0;
$subject_data = [];
foreach ($grades as $grade) {
    $mid_term = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
    $end_term = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
    $total = ($mid_term * 0.5) + ($end_term * 0.5);
    $student_grand_total += $total;
    $subject_data[] = ['label' => $grade['subject_name'], 'value' => $total];
}

// Fetch class performance for column chart
$class_query = "SELECT 
                    AVG(student_total) as class_average,
                    MAX(student_total) as class_highest,
                    MIN(student_total) as class_lowest
                FROM (
                    SELECT g.user_id, SUM((g.mid_term_exam * 0.5 + g.end_term_exam * 0.5)) as student_total
                    FROM grades g 
                    JOIN student_classes sc ON g.user_id = sc.user_id 
                    WHERE g.term_id = ? 
                    AND sc.academic_year = ?
                    AND sc.class_id = ?
                    GROUP BY g.user_id
                ) as sub";
$class_stmt = $conn->prepare($class_query);
$class_stmt->bind_param("iii", $term_id, $term_details['year'], $class_id);
$class_stmt->execute();
$class_results = $class_stmt->get_result()->fetch_assoc();
$class_average = (float)($class_results['class_average'] ?? 0);
$class_highest = (float)($class_results['class_highest'] ?? 0);
$class_lowest = (float)($class_results['class_lowest'] ?? 0);
$class_stmt->close();

// Check if images exist
$signature_path = '/assets/images/signature_head.png';
$stamp_path = '/assets/images/stamp.png';
$seal_path = '/assets/images/seal.png';
$base_path = $_SERVER['DOCUMENT_ROOT'];
$signature_exists = file_exists($base_path . $signature_path);
$stamp_exists = file_exists($base_path . $stamp_path);
$seal_exists = file_exists($base_path . $seal_path);

$page_title = "Report Card - Queen's Paradise Montessori School";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #ffffff;
            font-family: 'Inter', 'Roboto', 'Arial', sans-serif;
            color: #1a1a1a;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .container {
            width: 210mm;
            height: 297mm;
            margin: 5mm auto;
            padding: 5mm;
            box-sizing: border-box;
            position: relative;
            page-break-after: always;
        }
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('<?php echo $seal_exists ? $seal_path : ''; ?>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: 50% auto;
            opacity: 0.5;
            z-index: 1;
        }
        .watermark-error {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #d32f2f;
            font-size: 12px;
            z-index: -1;
            pointer-events: none;
        }
        .header-section {
            margin-bottom: 5px;
            position: relative;
            z-index: 2;
        }
        .header-table {
            width: 100%;
            max-width: 908px;
            margin: 0 auto;
            border-collapse: collapse;
        }
        .logo-section {
            text-align: left;
            vertical-align: top;
            padding-right: 5px;
        }
        .school-info {
            text-align: left;
            vertical-align: top;
            padding-right: 5px;
        }
        .school-info h1 {
            font-size: 20px;
            color: #003087;
            margin: 0;
            font-weight: 700;
            line-height: 1.2;
        }
        .school-info p {
            font-size: 11px;
            color: #d81b60;
            font-style: italic;
            margin: 3px 0 0 0;
            line-height: 1.3;
        }
        .contact-info {
            text-align: left;
            vertical-align: top;
        }
        .contact-info strong {
            font-size: 11px;
            color: #003087;
            text-decoration: underline;
            font-weight: 700;
            display: block;
            margin-bottom: 3px;
        }
        .contact-info p {
            font-size: 10px;
            color: #d81b60;
            margin: 1px 0;
            line-height: 1.3;
        }
        .report-title {
            font-size: 16px;
            color: #003087;
            text-decoration: underline;
            margin: 5px 0;
            text-align: center;
            position: relative;
            z-index: 2;
            font-weight: 700;
            line-height: 1.2;
        }
        .report-details {
            text-align: left;
            font-size: 12px;
            color: #1a1a1a;
            display: flex;
            align-items: flex-start;
            position: relative;
            z-index: 2;
        }
        .report-details table {
            width: 70%;
            border-collapse: collapse;
        }
        .report-details td {
            padding: 5px 7px;
            vertical-align: top;
        }
        .report-details strong {
            color: #003087;
        }
        .report-details .subtitle {
            color: #555;
        }
        .student-photo {
            width: 30%;
            text-align: right;
            vertical-align: top;
        }
        .student-photo img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }
        .grades-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 10px;
            position: relative;
            z-index: 2;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .grades-table th, .grades-table td {
            border: 1px solid #b0bec5;
            padding: 6px;
            text-align: center;
            font-size: 10px;
            color: #1a1a1a;
        }
        .grades-table th {
            font-weight: 700;
            background-color: rgba(238, 242, 255, 0.8);
        }
        .grades-table th:nth-child(1) { background-color: rgba(200, 230, 255, 0.8); }
        .grades-table th:nth-child(2) { background-color: rgba(200, 255, 200, 0.8); }
        .grades-table th:nth-child(3) { background-color: rgba(255, 245, 200, 0.8); }
        .grades-table th:nth-child(4) { background-color: rgba(255, 200, 200, 0.8); }
        .grades-table th:nth-child(5) { background-color: rgba(245, 200, 255, 0.8); }
        .grades-table th:nth-child(6) { background-color: rgba(200, 245, 255, 0.8); }
        .grades-table tr.grade-ap td { background-color: rgba(200, 230, 255, 0.8); }
        .grades-table tr.grade-a td { background-color: rgba(200, 255, 200, 0.8); }
        .grades-table tr.grade-b2 td { background-color: rgba(255, 245, 200, 0.8); }
        .grades-table tr.grade-b3 td { background-color: rgba(255, 235, 180, 0.8); }
        .grades-table tr.grade-c td { background-color: rgba(255, 200, 200, 0.8); }
        .grades-table tr.grade-d td { background-color: rgba(245, 200, 255, 0.8); }
        .grades-table tr.grade-e td { background-color: rgba(245, 200, 255, 0.8); }
        .grades-table tr.grade-f td { background-color: rgba(235, 235, 235, 0.8); }
        .grades-table tr:hover td {
            background-color: rgba(220, 220, 220, 0.9);
        }
        .grades-table .total-row th:nth-child(1) {
            text-align: left;
        }
        .grades-table .total-row h3 {
            margin: 0;
            font-size: 12px;
        }
        .grades-table .total-row th[align="center"] {
            font-size: 12px;
            font-weight: 700;
        }
        .charts-container {
            margin-top: 8px;
            position: relative;
            z-index: 2;
        }
        .chart-card {
            width: 100%;
            max-width: 740px;
            height: 200px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 8px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }
        .chart-section {
            width: 48%;
            height: 100%;
        }
        .additional-details {
            margin-top: 8px;
            text-align: left;
            font-size: 12px;
            color: #1a1a1a;
            position: relative;
            z-index: 2;
        }
        .additional-details table {
            width: 100%;
            border-collapse: collapse;
            display: table;
        }
        .additional-details td {
            padding: 5px 7px;
            vertical-align: top;
        }
        .additional-details hr {
            border: 0;
            border-top: 3px solid #003087;
            margin: 5px 0;
        }
        .style1 {
            color: #d81b60;
        }
        .footer-section {
            margin-top: 10px;
            text-align: center;
            font-size: 11px;
            color: #003087;
            font-style: italic;
            position: relative;
            z-index: 2;
            padding: 5px 0;
        }
        @media print {
            body {
                margin: 0;
                padding: 0;
                background-color: #ffffff;
            }
            .container {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 5mm;
                box-sizing: border-box;
                page-break-after: always;
            }
            .container::before {
                background-image: url('<?php echo $seal_exists ? $seal_path : ''; ?>');
                background-size: 50% auto;
                opacity: 0.5;
                z-index: 1;
            }
            .header-section {
                margin-bottom: 3mm;
            }
            .header-table {
                max-width: 100%;
            }
            .logo-section img {
                width: 80px;
                height: 85px;
            }
            .school-info h1 {
                font-size: 18px;
                line-height: 1.1;
            }
            .school-info p {
                font-size: 10px;
                margin: 2px 0;
                line-height: 1.2;
            }
            .contact-info strong {
                font-size: 10px;
                margin-bottom: 2px;
            }
            .contact-info p {
                font-size: 9px;
                margin: 1px 0;
                line-height: 1.2;
            }
            .report-title {
                font-size: 14px;
                margin: 3mm 0;
                line-height: 1.1;
            }
            .report-details {
                font-size: 10px;
                margin-bottom: 3mm;
            }
            .report-details td {
                padding: 3px 5px;
            }
            .student-photo img {
                width: 70px;
                height: 75px;
            }
            .grades-table {
                box-shadow: none;
                margin-top: 5px;
            }
            .grades-table th, .grades-table td {
                font-size: 9px;
                padding: 4px;
            }
            .charts-container {
                margin-top: 5px;
            }
            .chart-card {
                box-shadow: none;
                width: 100%;
                max-width: 740px;
                height: 180px;
                padding: 5px;
                display: flex;
                gap: 8px;
            }
            .chart-section {
                width: 48%;
                height: 100%;
            }
            .additional-details {
                display: block;
                margin-top: 5px;
                font-size: 10px;
            }
            .additional-details table {
                display: table;
            }
            .additional-details td {
                padding: 3px 5px;
            }
            .additional-details hr {
                margin: 3px 0;
            }
            .additional-details img {
                width: 80px !important;
                height: 40px !important;
            }
            .footer-section {
                font-size: 9px;
                padding: 3mm 0;
                position: relative;
                bottom: 0;
            }
        }
        @media screen and (max-width: 768px) {
            .container {
                width: 100%;
                height: auto;
                margin: 5mm auto;
                padding: 5px;
            }
            .container::before {
                background-size: 60% auto;
                opacity: 0.5;
            }
            .school-info h1 {
                font-size: 18px;
            }
            .school-info p {
                font-size: 10px;
            }
            .contact-info strong {
                font-size: 10px;
            }
            .contact-info p {
                font-size: 9px;
            }
            .logo-section img {
                width: 70px;
                height: 75px;
            }
            .report-title {
                font-size: 14px;
                margin: 8px 0;
            }
            .report-details {
                font-size: 11px;
                flex-direction: column;
            }
            .report-details table {
                width: 100%;
            }
            .report-details td {
                padding: 4px 6px;
            }
            .student-photo {
                width: 100%;
                text-align: center;
                margin-top: 8px;
            }
            .student-photo img {
                width: 70px;
                height: 75px;
            }
            .grades-table th, .grades-table td {
                font-size: 9px;
                padding: 5px;
            }
            .grades-table th:nth-child(1) { background-color: rgba(200, 230, 255, 0.8); }
            .grades-table th:nth-child(2) { background-color: rgba(200, 255, 200, 0.8); }
            .grades-table th:nth-child(3) { background-color: rgba(255, 245, 200, 0.8); }
            .grades-table th:nth-child(4) { background-color: rgba(255, 200, 200, 0.8); }
            .grades-table th:nth-child(5) { background-color: rgba(245, 200, 255, 0.8); }
            .grades-table th:nth-child(6) { background-color: rgba(200, 245, 255, 0.8); }
            .grades-table tr.grade-ap td { background-color: rgba(200, 230, 255, 0.8); }
            .grades-table tr.grade-a td { background-color: rgba(200, 255, 200, 0.8); }
            .grades-table tr.grade-b2 td { background-color: rgba(255, 245, 200, 0.8); }
            .grades-table tr.grade-b3 td { background-color: rgba(255, 235, 180, 0.8); }
            .grades-table tr.grade-c td { background-color: rgba(255, 200, 200, 0.8); }
            .grades-table tr.grade-d td { background-color: rgba(245, 200, 255, 0.8); }
            .grades-table tr.grade-e td { background-color: rgba(245, 200, 255, 0.8); }
            .grades-table tr.grade-f td { background-color: rgba(235, 235, 235, 0.8); }
            .grades-table tr:hover td {
                background-color: rgba(220, 220, 220, 0.9);
            }
            .grades-table .total-row th[align="center"] {
                font-size: 11px;
            }
            .charts-container {
                margin-top: 8px;
            }
            .chart-card {
                width: 100%;
                max-width: 300px;
                height: 320px;
                padding: 6px;
                flex-direction: column;
                gap: 8px;
            }
            .chart-section {
                width: 100%;
                height: 150px;
            }
            .additional-details {
                font-size: 11px;
            }
            .additional-details table {
                display: table;
            }
            .additional-details td {
                padding: 4px 6px;
            }
            .additional-details hr {
                width: 80%;
            }
            .additional-details img {
                width: 70px !important;
                height: 75px !important;
            }
            .footer-section {
                font-size: 10px;
                padding: 4px 0;
            }
        }
        @media screen and (max-width: 480px) {
            .container {
                margin: 3mm auto;
                padding: 3px;
            }
            .container::before {
                background-size: 70% auto;
                opacity: 0.5;
            }
            .school-info h1 {
                font-size: 16px;
            }
            .school-info p {
                font-size: 9px;
            }
            .contact-info strong {
                font-size: 9px;
            }
            .contact-info p {
                font-size: 8px;
            }
            .logo-section img {
                width: 60px;
                height: 65px;
            }
            .report-title {
                font-size: 12px;
                margin: 6px 0;
            }
            .report-details {
                font-size: 10px;
                flex-direction: column;
            }
            .report-details table {
                width: 100%;
            }
            .header-table td {
                display: block;
                width: 100%;
                text-align: center;
                padding: 4px 0;
            }
            .logo-section, .school-info, .contact-info {
                text-align: center;
                padding-right: 0;
            }
            .contact-info p {
                display: block;
            }
            .student-photo {
                width: 100%;
                text-align: center;
                margin-top: 6px;
            }
            .student-photo img {
                width: 60px;
                height: 65px;
            }
            .grades-table th, .grades-table td {
                font-size: 8px;
                padding: 4px;
            }
            .grades-table th:nth-child(1) { background-color: rgba(200, 230, 255, 0.8); }
            .grades-table th:nth-child(2) { background-color: rgba(200, 255, 200, 0.8); }
            .grades-table th:nth-child(3) { background-color: rgba(255, 245, 200, 0.8); }
            .grades-table th:nth-child(4) { background-color: rgba(255, 200, 200, 0.8); }
            .grades-table th:nth-child(5) { background-color: rgba(245, 200, 255, 0.8); }
            .grades-table th:nth-child(6) { background-color: rgba(200, 245, 255, 0.8); }
            .grades-table tr.grade-ap td { background-color: rgba(200, 230, 255, 0.8); }
            .grades-table tr.grade-a td { background-color: rgba(200, 255, 200, 0.8); }
            .grades-table tr.grade-b2 td { background-color: rgba(255, 245, 200, 0.8); }
            .grades-table tr.grade-b3 td { background-color: rgba(255, 235, 180, 0.8); }
            .grades-table tr.grade-c td { background-color: rgba(255, 200, 200, 0.8); }
            .grades-table tr.grade-d td { background-color: rgba(245, 200, 255, 0.8); }
            .grades-table tr.grade-e td { background-color: rgba(245, 200, 255, 0.8); }
            .grades-table tr.grade-f td { background-color: rgba(235, 235, 235, 0.8); }
            .grades-table .total-row th[align="center"] {
                font-size: 10px;
            }
            .charts-container {
                margin-top: 6px;
            }
            .chart-card {
                width: 100%;
                max-width: 250px;
                height: 300px;
                padding: 4px;
                flex-direction: column;
                gap: 6px;
            }
            .chart-section {
                width: 100%;
                height: 140px;
            }
            .additional-details {
                font-size: 10px;
            }
            .additional-details table {
                display: table;
            }
            .additional-details td {
                padding: 3px 5px;
            }
            .additional-details hr {
                width: 80%;
            }
            .additional-details img {
                width: 60px !important;
                height: 65px !important;
            }
            .footer-section {
                font-size: 9px;
                padding: 3px 0;
            }
        }
    </style>
    <script>
        window.onload = function() {
            var container = document.querySelector('.container');
            if (!<?php echo $seal_exists ? 'true' : 'false'; ?>) {
                console.error('Seal image not found at <?php echo $seal_path; ?>');
            }
        };
    </script>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <table border="0" cellspacing="0" cellpadding="0" align="center" width="100%" class="header-table">
                <tr>
                    <td width="100%" valign="top">
                        <table width="100%" align="center" cellspacing="0">
                            <tr>
                                <td width="120" align="left" valign="top" class="logo-section">
                                    <a href="whatsapp://send?text=https%3A%2F%2Fqpmsedu.com%2Fteacher%2Fview_report_card.php%3Fstudent_id=<?php echo $student_id; ?>&class_id=<?php echo $class_id; ?>&term_id=<?php echo $term_id; ?>">
                                        <img src="/assets/images/logo.png" alt="School Logo" width="80" height="85">
                                    </a>
                                </td>
                                <td width="400" align="left" valign="top" class="school-info">
                                    <h1>Queen's Paradise Montessori School</h1>
                                    <p>"Christ, Our Foundation Integrity, Discipline and Excellence our hallmark"</p>
                                </td>
                                <td width="358" align="left" valign="top" class="contact-info">
                                    <strong>CONTACT</strong>
                                    <p>P. O. Box AM 42 Amasaman</p>
                                    <p>0507390755</p>
                                    <p>info.queenspa49@gmail.com</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
        <div class="report-title">
            <?php echo htmlspecialchars($term_details['year'] . " Term " . $term_details['term_name'] . " - END OF TERM EXAMINATION Report", ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="report-details">
            <table width="100%" border="0" align="center" cellpadding="" cellspacing="">
                <tr>
                    <td width="326" align="left" valign="top"><strong>Report Date:</strong> <?php echo date('F j, Y'); ?></td>
                    <td width="432" align="left" valign="top"><strong>Student Name:</strong> <?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td width="542" align="left" valign="top"><strong>Student ID:</strong> QPMS<?php echo $student_id; ?></td>
                </tr>
                <tr>
                    <td align="left" valign="top"><strong>Class/Grade:</strong> <?php echo htmlspecialchars($class_details['class_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td align="left" valign="top"><strong>Term:</strong> <?php echo htmlspecialchars($term_details['term_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td align="left" valign="top"><strong>Year:</strong> <?php echo htmlspecialchars($term_details['year'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td width="432" align="left" valign="top"><strong><span class="subtitle">Attendance (Days):</span></strong> <?php echo htmlspecialchars($attendance_display . ' (' . $attendance_percentage . ')', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td width="542" align="left" valign="top"><strong><span class="subtitle">Reopening:</span></strong> <?php echo htmlspecialchars($reopening_display, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            </table>
            <div class="student-photo">
                <a href="whatsapp://send?text=https%3A%2F%2Fqpmsedu.com%2Fteacher%2Fview_report_card.php%3Fstudent_id=<?php echo $student_id; ?>&class_id=<?php echo $class_id; ?>&term_id=<?php echo $term_id; ?>">
                    <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? '/assets/images/default_profile.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Student Photo">
                </a>
            </div>
        </div>
        <table width="100%" align="center" border="1" cellspacing="0" bordercolor="black" class="grades-table">
            <tr align="left">
                <th align="left"><font color="black">SUBJECT NAME</font></th>
                <th align="center"><font color="black">SBA 50%</font></th>
                <th align="center"><font color="black">EXAMS 50%</font></th>
                <th align="center"><font color="black">TOTAL 100%</font></th>
                <th align="center"><font color="black">GRADE</font></th>
                <th align="center"><font color="black">REMARKS</font></th>
            </tr>
            <?php
            $grand_total = 0;
            foreach ($grades as $grade) {
                $mid_term = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
                $end_term = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
                if (!is_numeric($grade['mid_term_exam']) || !is_numeric($grade['end_term_exam'])) {
                    error_log("Non-numeric grade detected for student_id=$student_id, term_id=$term_id, subject={$grade['subject_name']}: mid_term_exam={$grade['mid_term_exam']}, end_term_exam={$grade['end_term_exam']}");
                }
                $sba_scaled = $mid_term * 0.5;
                $exam_scaled = $end_term * 0.5;
                $total = $sba_scaled + $exam_scaled;
                $grade_info = getGradeAndRemark($total);
                $grand_total += $total;
            ?>
            <tr class="<?php echo $grade_info['class']; ?>">
                <td align="left"><font color="black"><?php echo htmlspecialchars($grade['subject_name'], ENT_QUOTES, 'UTF-8'); ?></font></td>
                <td align="center"><font color="black"><?php echo number_format($sba_scaled, 1); ?></font></td>
                <td align="center"><font color="black"><?php echo number_format($exam_scaled, 1); ?></font></td>
                <td align="center"><font color="black"><?php echo number_format($total, 1); ?></font></td>
                <td align="center"><font color="black"><?php echo $grade_info['grade']; ?></font></td>
                <td align="center"><font color="black"><?php echo $grade_info['remark']; ?></font></td>
            </tr>
            <?php } ?>
            <tr align="left" class="total-row">
                <th align="left"><font color="black"><h3>TOTAL</h3></font></th>
                <th align="center"><font color="black"></font></th>
                <th align="center"><font color="black"></font></th>
                <th align="center"><font color="black"><?php echo number_format($grand_total, 1); ?></font></th>
                <th align="center"><font color="black"></font></th>
                <th align="center"><font color="black"></font></th>
            </tr>
        </table>
        <div class="additional-details">
            <table width="100%" border="0" align="center" cellpadding="" cellspacing="">
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Total Aggregate:</strong> 
                        <?php
                        $max_score = count($grades) * 100;
                        $aggregate_percentage = ($grand_total / $max_score) * 100;
                        $aggregate_grade = getGradeAndRemark($aggregate_percentage)['grade'];
                        echo number_format($aggregate_percentage, 1) . '% (' . $aggregate_grade . ') [' . number_format($grand_total, 1) . ' / ' . $max_score . ']';
                        ?>
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>Class Teacher's Signature</strong><br>
                        <div style="height: 50px;"></div>
                        <hr align="left" size="6" color="black" width="60%">
                    </td>
                </tr>
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Report for:</strong> <font color="#2e7d32"><?php echo htmlspecialchars($class_details['class_name'], ENT_QUOTES, 'UTF-8'); ?></font>
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>Promoted to:</strong> <font color="#d81b60"><?php echo htmlspecialchars($next_class_name, ENT_QUOTES, 'UTF-8'); ?></font>
                    </td>
                </tr>
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Conduct:</strong> <font color="#d81b60"><?php echo htmlspecialchars($report_details['conduct'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></font>
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>Attitude:</strong> <font color="#d81b60"><?php echo htmlspecialchars($report_details['attitude'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></font>
                    </td>
                </tr>
                <tr>
                    <td width="549" align="left" valign="top">
                        <strong>Interest:</strong> <font color="#d81b60"><?php echo htmlspecialchars($report_details['interest'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></font>
                    </td>
                    <td width="745" align="left" valign="top">
                        <strong>HeadMistress / Master's Signature</strong><br>
                        <?php if ($signature_exists) { ?>
                            <img src="<?php echo $signature_path; ?>" width="200" height="50"><br>
                        <?php } else { ?>
                            <span style="color: #d32f2f;">Signature image not found</span><br>
                        <?php } ?>
                        <hr align="left" size="6" color="black" width="60%">
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="left" valign="top">
                        <strong>Class Teacher's Remark:</strong> <font color="#d81b60"><?php echo htmlspecialchars($report_details['teacher_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></font>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="left" valign="top">
                        <strong><span class="subtitle">Head Teacher's Remark:</span></strong> <font color="#d81b60"><?php echo htmlspecialchars($report_details['headmaster_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></font>
                    </td>
                </tr>
            </table>
            <table width="100%" border="0" align="left" cellpadding="" cellspacing="">
                <tr>
                    <td width="300" align="left" valign="top">
                        Grace-Joan Kissi-Afare<br>
                        (<strong>Executive Director,</strong> Queen's Paradise Montessori School)<br>
                        <hr align="left" size="6" color="black" width="80%">
                    </td>
                    <td width="200" align="left" valign="top">
                        <?php if ($stamp_exists) { ?>
                            <img src="<?php echo $stamp_path; ?>" width="100" height="100">
                        <?php } else { ?>
                            <span style="color: #d32f2f;">Stamp image not found</span>
                        <?php } ?>
                    </td>
                </tr>
            </table>
        </div>
        <div class="charts-container">
            <div class="chart-card">
                <div class="chart-section" id="subject_pie_chart"></div>
                <div class="chart-section" id="barcharter"></div>
            </div>
        </div>
        <div class="footer-section">
            <?php echo htmlspecialchars($random_footer, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php if (!$seal_exists) { ?>
            <div class="watermark-error">Seal image not found at <?php echo $seal_path; ?></div>
        <?php } ?>
    </div>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
        google.charts.load("current", {"packages":["corechart", "bar"]});
        google.charts.setOnLoadCallback(drawCharts);
        function drawCharts() {
            // Subject Pie Chart
            var subjectData = new google.visualization.DataTable({
                cols: [{label: "Subject", type: "string"}, {label: "Score", type: "number"}],
                rows: [
                    <?php
                    $subject_rows = '';
                    foreach ($subject_data as $s) {
                        $subject_rows .= '{"c":[{"v":"' . addslashes($s['label']) . '"},{"v":' . $s['value'] . '}]},';
                    }
                    echo rtrim($subject_rows, ',');
                    ?>
                ]
            });
            var subjectPieOptions = {
                title: "Subject Performance",
                is3D: true,
                width: 360,
                height: 180,
                titleTextStyle: {fontSize: 12, color: '#003087'},
                legend: {position: 'right', textStyle: {fontSize: 10}},
                pieSliceText: 'label',
                pieSliceTextStyle: {fontSize: 10, color: '#1a1a1a'},
                chartArea: {width: '80%', height: '80%'},
                colors: ['#003087', '#d81b60', '#2e7d32', '#f4b400', '#6a1b9a', '#0288d1', '#c2185b', '#388e3c']
            };
            var subjectPieChart = new google.visualization.PieChart(document.getElementById("subject_pie_chart"));
            subjectPieChart.draw(subjectData, subjectPieOptions);

            // Class Performance Column Chart
            var subtitle = 'Student: <?php echo number_format($student_grand_total, 1); ?>, Highest: <?php echo number_format($class_highest, 1); ?>, Average: <?php echo number_format($class_average, 1); ?>, Lowest: <?php echo number_format($class_lowest, 1); ?>';
            var barData = google.visualization.arrayToDataTable([
                ["Metric", "Student Score", "Class Highest", "Class Average", "Class Lowest"],
                ["<?php echo addslashes($term_details['year'] . '/' . $term_details['term_name']); ?>", 
                 <?php echo $student_grand_total; ?>, 
                 <?php echo $class_highest; ?>, 
                 <?php echo $class_average; ?>, 
                 <?php echo $class_lowest; ?>]
            ]);
            var barOptions = {
                title: "Class Performance Comparison",
                titleTextStyle: {fontSize: 12, color: '#003087'},
                subtitle: subtitle,
                width: 360,
                height: 180,
                series: {
                    0: {color: '#003087'},
                    1: {color: '#d81b60'},
                    2: {color: '#f4b400'},
                    3: {color: '#2e7d32'}
                },
                chartArea: {width: '70%', height: '70%'},
                legend: {position: 'top', textStyle: {fontSize: 10}},
                hAxis: {textStyle: {fontSize: 10}},
                vAxis: {textStyle: {fontSize: 10}, format: '#.#', title: 'Score'},
                bar: {groupWidth: '20%'}
            };
            var barChart = new google.visualization.ColumnChart(document.getElementById("barcharter"));
            barChart.draw(barData, barOptions);
        }
    </script>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>