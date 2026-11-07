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

// Check for bulk print POST request
if (isset($_POST['bulk_print']) && $_POST['bulk_print'] == '1') {
    $bulk_print_mode = true;
    
    // Get report IDs from the comma-separated string
    if (isset($_POST['report_ids']) && !empty($_POST['report_ids'])) {
        $report_ids_string = $_POST['report_ids'];
        $reports = array_filter(explode(',', $report_ids_string), 'is_numeric');
    }
    
    // Alternative: Get from array (if submitted that way)
    if (empty($reports) && isset($_POST['report_ids']) && is_array($_POST['report_ids'])) {
        $reports = array_filter($_POST['report_ids'], 'is_numeric');
    }
} 
// Check for single report from GET
elseif (isset($_GET['report_id']) && is_numeric($_GET['report_id'])) {
    $reports = [(int)$_GET['report_id']];
}

if (empty($reports)) {
    error_log("No reports selected for printing.");
    $_SESSION['error_msg'] = "No reports selected for printing.";
    header('Location: report_card.php');
    exit;
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
    
    // Check if file exists and is valid (minimum size check to avoid empty/corrupt files)
    if (file_exists($full_path) && filesize($full_path) > 100) {
        // Return the clean web-accessible path
        return '/Uploads/profiles/' . $clean_path;
    }
    
    // If file doesn't exist or is invalid, return gender-appropriate default
    return $default_picture;
}

// Define footer messages
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

// Function to get random footer
function getRandomFooter($footer_messages) {
    $random_footer = $footer_messages[array_rand($footer_messages)];
    if (strpos($random_footer, '–') !== false) {
        $random_footer = '"' . $random_footer . '"';
    }
    return $random_footer;
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

// Prepare data for each report
$reports_data = [];
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

    // Fetch user data including gender
    $stmt = $conn->prepare("SELECT name, profile_picture, gender FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        error_log("User ID $user_id not found for report ID $report_id.");
        continue;
    }

    // Normalize profile picture with gender awareness
    $profile_picture = normalizeProfilePicture($user['profile_picture'], $user['gender']);

    // Fetch report details
    $stmt = $conn->prepare("SELECT c.class_name, ac.term_name, ac.year FROM classes c JOIN academic_calendar ac ON ? = ac.id WHERE c.id = ?");
    $stmt->bind_param("ii", $term_id, $report_basic['class_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $report_details = $result->fetch_assoc();
    $stmt->close();

    if (!$report_details) {
        error_log("Report details not found for report ID $report_id, term ID $term_id, class ID {$report_basic['class_id']}.");
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

    // Format attendance
    $attendance_display = $report_basic['attendance'] ? $report_basic['attendance'] . ' out of 66' : 'N/A';
    $attendance_percentage = $report_basic['attendance'] && is_numeric($report_basic['attendance']) && $report_basic['attendance'] > 0 ? number_format(($report_basic['attendance'] / 66) * 100, 1) . '%' : 'N/A';

    // Format reopening date
    $reopening_display = $report_basic['reopening_date'] ? date('l, F j, Y', strtotime($report_basic['reopening_date'])) : 'N/A';

    // Fetch grades
    $stmt = $conn->prepare("SELECT g.mid_term_exam, g.end_term_exam, s.subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.user_id = ? AND g.term_id = ?");
    $stmt->bind_param("ii", $user_id, $term_id);
    $stmt->execute();
    $grades_result = $stmt->get_result();
    $grades = $grades_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($grades)) {
        error_log("No grades found for user ID $user_id, term ID $term_id.");
        continue;
    }

    // Calculate totals and subject data
    $student_grand_total = 0;
    $subject_data = [];
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
        $student_grand_total += $total;
        $subject_data[] = ['label' => $grade['subject_name'], 'value' => $total];

        $grades_html .= '<tr class="' . $grade_info['class'] . '">
            <td align="left"><font color="black">' . htmlspecialchars($grade['subject_name'], ENT_QUOTES, 'UTF-8') . '</font></td>
            <td align="center"><font color="black">' . number_format($sba_scaled, 1) . '</font></td>
            <td align="center"><font color="black">' . number_format($exam_scaled, 1) . '</font></td>
            <td align="center"><font color="black">' . number_format($total, 1) . '</font></td>
            <td align="center"><font color="black">' . $grade_info['grade'] . '</font></td>
            <td align="center"><font color="black">' . $grade_info['remark'] . '</font></td>
        </tr>';
    }

    // Total row
    $grades_html .= '<tr align="left" class="total-row">
        <th align="left"><font color="black"><h3>TOTAL</h3></font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black">' . number_format($grand_total, 1) . '</font></th>
        <th align="center"><font color="black"></font></th>
        <th align="center"><font color="black"></font></th>
    </tr>';

    // Aggregate
    $max_score = count($grades) * 100;
    $aggregate_percentage = ($grand_total / $max_score) * 100;
    $aggregate_grade = getGradeAndRemark($aggregate_percentage)['grade'];
    $aggregate_display = number_format($aggregate_percentage, 1) . '% (' . $aggregate_grade . ') [' . number_format($grand_total, 1) . ' / ' . $max_score . ']';

    // Check images
    $signature_path = '/assets/images/signature_head.png';
    $stamp_path = '/assets/images/stamp.png';
    $seal_path = '/assets/images/seal.png';
    $base_path = $_SERVER['DOCUMENT_ROOT'];
    $signature_exists = file_exists($base_path . $signature_path);
    $stamp_exists = file_exists($base_path . $stamp_path);
    $seal_exists = file_exists($base_path . $seal_path);

    // Random footer
    $random_footer = getRandomFooter($footer_messages);

    // Collect data for reference (no charts)
    $reports_data[] = [
        'year' => $report_details['year'],
        'term_name' => $report_details['term_name'],
        'student_name' => $user['name']
    ];

    // Add page break for bulk print except for last report
    $page_break_style = '';
    $page_break_class = '';
    if ($bulk_print_mode && $index < $total_reports - 1) {
        $page_break_style = 'page-break-after: always;';
        $page_break_class = 'page-break';
    }

    // Generate HTML for this report (without charts)
    $reports_html .= '
    <div class="container ' . $page_break_class . '" id="report_' . $index . '" style="' . $page_break_style . '">
        <!-- Bulk Print Header (only shown in bulk mode) -->
        ' . ($bulk_print_mode ? '
        <div class="bulk-print-indicator">
            <span>Report ' . ($index + 1) . ' of ' . $total_reports . ' - ' . htmlspecialchars($user['name']) . '</span>
        </div>
        ' : '') . '
        
        <div class="header-section">
            <!-- Main Header Title - Single Line, Large -->
            <div class="school-header-title">
                <h1>Queenest Paradise Montessori Complex</h1>
            </div>
            
            <!-- Sub-header with Logo, Motto, and Contact -->
            <table border="0" cellspacing="0" cellpadding="0" align="center" width="100%" class="header-table">
                <tr>
                    <td width="120" align="left" valign="top" class="logo-section">
                        <img src="/assets/images/logo.png" alt="School Logo" width="100" height="105">
                    </td>
                    <td align="left" valign="middle" class="school-motto">
                        <p>"Christ, Our Foundation<br>Integrity, Discipline and Excellence our hallmark"</p>
                    </td>
                    <td width="250" align="right" valign="top" class="contact-info">
                        <strong>CONTACT</strong>
                        <p>P. O. Box AM 42 Amasaman</p>
                        <p>0507390755</p>
                        <p>info.queenspa49@gmail.com</p>
                    </td>
                </tr>
            </table>
        </div>
        <div class="report-title">
            ' . htmlspecialchars($report_details['year'] .' '. $report_details['term_name'] . " - END OF TERM EXAMINATION Report", ENT_QUOTES, 'UTF-8') . '
        </div>
        <div class="report-details">
            <table width="100%" border="0" align="center" cellpadding="0" cellspacing="0" class="details-table">
                <tr>
                    <td width="33%" valign="top"><strong>Report Date:</strong> ' . date('l, F j, Y') . '</td>
                    <td width="34%" valign="top"><strong>Student Name:</strong> ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . '</td>
                    <td width="33%" valign="top"><strong>Student ID:</strong> QPMS' . $report_id . '</td>
                </tr>
                <tr>
                    <td valign="top"><strong>Class/Grade:</strong> ' . htmlspecialchars($report_details['class_name'], ENT_QUOTES, 'UTF-8') . '</td>
                    <td valign="top"><strong>Term:</strong> ' . htmlspecialchars($report_details['term_name'], ENT_QUOTES, 'UTF-8') . '</td>
                    <td valign="top"><strong>Year:</strong> ' . htmlspecialchars($report_details['year'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
                <tr>
                    <td valign="top"><strong>Attendance (Days):</strong> ' . htmlspecialchars($attendance_display . ' (' . $attendance_percentage . ')', ENT_QUOTES, 'UTF-8') . '</td>
                    <td valign="top" colspan="2"><strong>Reopening:</strong> ' . htmlspecialchars($reopening_display, ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
            </table>
            <div class="student-photo">
                <img src="' . htmlspecialchars($profile_picture, ENT_QUOTES, 'UTF-8') . '" alt="Student Photo" loading="lazy">
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
            ' . $grades_html . '
        </table>
        <div class="additional-details">
            <table width="100%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td width="50%" valign="top">
                        <strong>Total Aggregate:</strong> ' . $aggregate_display . '
                    </td>
                    <td width="50%" valign="top">
                        <strong>Class Teacher\'s Signature</strong><br>
                        <div style="height: 55px;"></div>
                        <hr align="left" size="2" color="black" width="60%">
                    </td>
                </tr>
                <tr>
                    <td valign="top">
                        <strong>Report for:</strong> <font color="#2e7d32">' . htmlspecialchars($report_details['class_name'], ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td valign="top">
                        <strong>Promoted to:</strong> <font color="#d81b60">' . htmlspecialchars($next_class_name, ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
                <tr>
                    <td valign="top">
                        <strong>Conduct:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['conduct'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td valign="top">
                        <strong>Attitude:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['attitude'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
                <tr>
                    <td valign="top">
                        <strong>Interest:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['interest'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                    <td valign="top">
                        <strong>HeadMistress / Master\'s Signature</strong><br>
                        ' . ($signature_exists ? '<img src="' . $signature_path . '" width="210" height="55" alt="Signature"><br>' : '<span style="color: #d32f2f;">Signature image not found</span><br>') . '
                        <hr align="left" size="2" color="black" width="60%">
                    </td>
                </tr>
                <tr>
                    <td colspan="2" valign="top">
                        <strong>Class Teacher\'s Remark:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['teacher_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" valign="top">
                        <strong>Head Teacher\'s Remark:</strong> <font color="#d81b60">' . htmlspecialchars($report_basic['headmaster_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</font>
                    </td>
                </tr>
            </table>
            <table width="100%" border="0" align="left" cellpadding="0" cellspacing="0" style="margin-top: 10px;">
                <tr>
                    <td width="300" valign="top">
                        Grace-Joan Kissi-Afare<br>
                        (<strong>Executive Director,</strong> Queenest Paradise Montessori Complex)<br>
                        <hr align="left" size="2" color="black" width="80%">
                    </td>
                    <td width="200" valign="top">
                        ' . ($stamp_exists ? '<img src="' . $stamp_path . '" width="160" height="160" alt="Stamp" style="object-fit: contain;">' : '<span style="color: #d32f2f;">Stamp image not found</span>') . '
                    </td>
                </tr>
            </table>
            <!-- Footer Message Under Stamp Section -->
            <div class="footer-message">
                ' . htmlspecialchars($random_footer, ENT_QUOTES, 'UTF-8') . '
            </div>
        </div>
        ' . (!$seal_exists ? '<div class="watermark-error">Seal image not found at ' . $seal_path . '</div>' : '') . '
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style type="text/css">
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
        }
        
        /* Print Controls Bar */
        .print-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #003087, #d81b60);
            color: white;
            padding: 15px 30px;
            z-index: 9999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .print-controls h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .print-controls .btn-group {
            display: flex;
            gap: 10px;
        }
        
        .print-controls .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
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
        
        /* Bulk Print Indicator */
        .bulk-print-indicator {
            background: #e8f4fd;
            border-left: 4px solid #003087;
            padding: 8px 15px;
            margin-bottom: 10px;
            font-size: 12px;
            font-weight: 600;
            color: #003087;
            text-align: center;
            border-radius: 4px;
        }
        
        .container {
            display: none;
            width: 210mm;
            min-height: 297mm;
            margin: <?php echo $bulk_print_mode ? '60px' : '0'; ?> auto 0;
            padding: 6mm;
            box-sizing: border-box;
            position: relative;
            background: white;
        }
        
        <?php if ($bulk_print_mode): ?>
        .page-break {
            page-break-after: always;
        }
        <?php endif; ?>
        
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
            background-size: 55% auto;
            opacity: 0.15;
            z-index: 1;
            pointer-events: none;
        }
        
        .watermark-error {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #d32f2f;
            font-size: 13px;
            z-index: -1;
            pointer-events: none;
        }
        
        .header-section {
            margin-bottom: 3mm;
            position: relative;
            z-index: 2;
        }
        
        /* Main School Header - Single Line, Large */
        .school-header-title {
            text-align: center;
            margin-bottom: 4px;
        }
        
        .school-header-title h1 {
            font-size: 26px;
            font-weight: 800;
            color: #003087;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            padding: 0;
            line-height: 1.2;
            white-space: nowrap;
        }
        
        .header-table {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            border-collapse: collapse;
        }
        
        .logo-section {
            text-align: left;
            vertical-align: top;
        }
        
        .logo-section img {
            width: 110px;
            height: 115px;
        }
        
        .school-motto {
            text-align: left;
            vertical-align: middle;
            padding-left: 10px;
        }
        
        .school-motto p {
            font-size: 13px;
            color: #d81b60;
            font-style: italic;
            margin: 0;
            line-height: 1.4;
        }
        
        .contact-info {
            text-align: right;
            vertical-align: top;
        }
        
        .contact-info strong {
            font-size: 12px;
            color: #003087;
            text-decoration: underline;
            font-weight: 700;
            display: block;
            margin-bottom: 3px;
        }
        
        .contact-info p {
            font-size: 11px;
            color: #d81b60;
            margin: 1px 0;
            line-height: 1.3;
        }
        
        .report-title {
            font-size: 16px;
            color: #003087;
            text-decoration: underline;
            margin: 5mm 0 4mm 0;
            text-align: center;
            position: relative;
            z-index: 2;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .report-details {
            text-align: left;
            font-size: 11px;
            color: #1a1a1a;
            display: flex;
            align-items: flex-start;
            position: relative;
            z-index: 2;
            margin-bottom: 8px;
        }
        
        .details-table {
            width: 75%;
            border-collapse: collapse;
        }
        
        .details-table td {
            padding: 3px 5px;
            vertical-align: top;
            white-space: nowrap;
        }
        
        .report-details strong {
            color: #003087;
            font-weight: 700;
        }
        
        .student-photo {
            width: 25%;
            text-align: right;
            vertical-align: top;
        }
        
        .student-photo img {
            width: 80px;
            height: 85px;
            object-fit: cover;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background-color: #f5f5f5;
        }
        
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            position: relative;
            z-index: 2;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .grades-table th, .grades-table td {
            border: 1px solid #b0bec5;
            padding: 5px;
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
        
        .grades-table .total-row th:nth-child(1) {
            text-align: left;
        }
        
        .grades-table .total-row h3 {
            margin: 0;
            font-size: 11px;
        }
        
        .grades-table .total-row th[align="center"] {
            font-size: 11px;
            font-weight: 700;
        }
        
        .additional-details {
            margin-top: 8px;
            text-align: left;
            font-size: 11px;
            color: #1a1a1a;
            position: relative;
            z-index: 2;
        }
        
        .additional-details table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .additional-details td {
            padding: 3px 5px;
            vertical-align: top;
        }
        
        .additional-details hr {
            border: 0;
            border-top: 2px solid #003087;
            margin: 3px 0;
        }
        
        .additional-details img {
            width: 85px !important;
            height: 45px !important;
        }
        
        /* Increased stamp size */
        .additional-details img[alt="Stamp"] {
            width: 160px !important;
            height: 160px !important;
        }
        
        /* Footer Message Style - Under Stamp */
        .footer-message {
            font-size: 11px;
            padding: 8px 0 4px 0;
            text-align: center;
            color: #003087;
            font-style: italic;
            position: relative;
            z-index: 2;
            border-top: 1px solid #e0e0e0;
            margin-top: 10px;
            clear: both;
        }
        
        .chart-fallback {
            font-size: 11px;
            color: #d32f2f;
            text-align: center;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <?php if ($bulk_print_mode): ?>
    <!-- Print Controls Bar -->
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
        // Show all containers immediately (no chart loading delay)
        document.body.style.display = 'block';
        document.querySelectorAll('.container').forEach(function(container) {
            container.style.display = 'block';
        });
        
        // Auto-trigger print dialog after a short delay to ensure rendering
        setTimeout(function() {
            window.print();
        }, 300);
        
        // Manual print function
        function manualPrint() {
            window.print();
        }
        
        // Close window function
        function closeWindow() {
            window.close();
            // Fallback if window.close() doesn't work
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