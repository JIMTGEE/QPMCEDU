<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering and session
ob_start();
session_start();

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Access denied. You must be a teacher to access this page.";
    header("Location: /login.php");
    exit();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once $db_path;

// Validate database connection
if (!isset($conn) || !($conn instanceof mysqli) || !$conn->ping()) {
    error_log("Database connection failed or not established: " . ($conn ? $conn->connect_error : "No connection object"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Set UTF-8 encoding for database
$conn->set_charset("utf8mb4");

// Check for student_id parameter
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

// Fetch all classes
$classes_query = "
    SELECT DISTINCT c.id, c.class_name
    FROM classes c
    INNER JOIN student_classes sc ON c.id = sc.class_id
    INNER JOIN users u ON sc.user_id = u.id
    WHERE u.role = 'student'
    ORDER BY c.class_name ASC
";
$classes_result = $conn->query($classes_query);
if (!$classes_result) {
    error_log("Classes query failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch classes.";
    header("Location: /login.php");
    exit();
}
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

// Fetch all students for autocomplete
$students_query = "
    SELECT DISTINCT u.id, u.name, u.email 
    FROM users u
    INNER JOIN student_classes sc ON u.id = sc.user_id
    WHERE u.role = 'student'
    ORDER BY u.name ASC
";
$students_result = $conn->query($students_query);
if (!$students_result) {
    error_log("Students query failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch students.";
    header("Location: /login.php");
    exit();
}
$students = $students_result->fetch_all(MYSQLI_ASSOC);
$student_list = [];
foreach ($students as $student) {
    $student_list[] = [
        'id' => $student['id'],
        'label' => $student['name'] ?: $student['email'],
        'value' => $student['id']
    ];
}

// Fetch students by class if no student_id is provided
$students_by_class = [];
if (!$student_id) {
    foreach ($classes as $class) {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.id, u.name, u.email
            FROM users u
            INNER JOIN student_classes sc ON u.id = sc.user_id
            WHERE sc.class_id = ? AND u.role = 'student'
            ORDER BY u.name ASC
        ");
        if (!$stmt) {
            error_log("Students by class query preparation failed: " . $conn->error);
            continue;
        }
        $stmt->bind_param("i", $class['id']);
        if (!$stmt->execute()) {
            error_log("Students by class query execution failed: " . $stmt->error);
            $stmt->close();
            continue;
        }
        $students_by_class[$class['id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Fetch student details if student_id is provided
$student_data = null;
$student_name = '';
if ($student_id > 0) {
    $stmt = $conn->prepare("SELECT name, email, profile_picture FROM users WHERE id = ? AND role = 'student'");
    if (!$stmt) {
        error_log("Student query preparation failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch student data.";
        header("Location: /login.php");
        exit();
    }
    $stmt->bind_param("i", $student_id);
    if (!$stmt->execute()) {
        error_log("Student query execution failed: " . $stmt->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch student data.";
        header("Location: /login.php");
        exit();
    }
    $student_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student_data) {
        $_SESSION['error_msg'] = "Student not found.";
        header("Location: /teacher/dashboard.php");
        exit();
    }
    $student_name = $student_data['name'] ?: ($student_data['email'] ?: 'Unknown Student');
}

// Fetch all terms and associated classes where reports exist for the student
$terms = [];
if ($student_id > 0) {
    $terms_query = "
        SELECT DISTINCT ac.id, ac.term_name, ac.year, ac.start_date, ac.end_date, sr.class_id, c.class_name
        FROM academic_calendar ac
        INNER JOIN student_reports sr ON ac.id = sr.term_id AND sr.user_id = ?
        INNER JOIN classes c ON sr.class_id = c.id
        ORDER BY ac.year DESC, ac.start_date DESC
    ";
    $stmt = $conn->prepare($terms_query);
    if (!$stmt) {
        error_log("Terms query preparation failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch terms.";
        header("Location: /teacher/dashboard.php");
        exit();
    }
    $stmt->bind_param("i", $student_id);
    if (!$stmt->execute()) {
        error_log("Terms query execution failed: " . $stmt->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch terms.";
        header("Location: /teacher/dashboard.php");
        exit();
    }
    $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
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
    "In learning you will teach, and in teaching you will learn. – Phil Collins",
    "A good teacher can inspire hope, ignite the imagination, and instill a love of learning. – Brad Henry",
    "The beautiful thing about learning is that no one can take it away from you. – B.B. King",
    "It always seems impossible until it’s done. – Nelson Mandela",
    "Quality is not an act; it is a habit. – Aristotle"
];

// Function to calculate grade and remark
function getGradeAndRemark($total) {
    if (!is_numeric($total)) {
        error_log("Invalid total score for grading: " . var_export($total, true));
        return ['grade' => 'N/A', 'remark' => 'Invalid Score', 'class' => 'grade-f'];
    }
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

// Function to count weekdays (school days) between two dates
function countWeekdays($start_date, $end_date) {
    $start = strtotime($start_date);
    $end = strtotime($end_date);
    $count = 0;
    while ($start <= $end) {
        $day = date('N', $start);
        if ($day < 6) { // Monday to Friday
            $count++;
        }
        $start = strtotime('+1 day', $start);
    }
    return $count;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <title>View Student Reports - QPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <style>
        :root {
            --primary-orange: #ff6200;
            --secondary-orange: #ff8c00;
            --primary-green: #2e7d32;
            --background-light: #f8fafc;
            --text-dark: #1f2937;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--background-light);
            color: var(--text-dark);
        }

        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .report-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        .report-table th, .report-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .report-table th {
            background: linear-gradient(90deg, var(--primary-orange), var(--secondary-orange));
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .report-table tr:hover {
            background-color: #f9fafb;
        }

        .grade-ap { color: var(--primary-green); font-weight: 600; }
        .grade-a { color: #388e3c; font-weight: 600; }
        .grade-b2 { color: #4caf50; font-weight: 600; }
        .grade-b3 { color: #66bb6a; font-weight: 600; }
        .grade-c { color: #f4b400; font-weight: 600; }
        .grade-d { color: #ef6c00; font-weight: 600; }
        .grade-e { color: #d84315; font-weight: 600; }
        .grade-f { color: #d32f2f; font-weight: 600; }

        .watermark-error {
            color: #d32f2f;
            font-size: 0.75rem;
            text-align: center;
            margin-top: 0.5rem;
        }

        .accordion-header {
            cursor: pointer;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .accordion-header:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
        }

        .accordion-content {
            max-height: 0;
            overflow: hidden;
            padding: 0 1rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .accordion-content.active {
            max-height: 2000px;
            padding: 1.5rem;
        }

        .search-bar {
            max-width: 600px;
            margin: 0 auto 2rem;
            position: relative;
        }

        .search-bar input {
            padding-left: 2.5rem;
            border-radius: 9999px;
            border: 1px solid #d1d5db;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }

        .search-bar input:focus {
            border-color: var(--primary-orange);
            box-shadow: 0 0 0 3px rgba(255, 98, 0, 0.2);
        }

        .search-bar i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }

        .ui-autocomplete {
            max-height: 250px;
            overflow-y: auto;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 0.5rem 0;
        }

        .ui-autocomplete .ui-menu-item {
            padding: 0.5rem 1rem;
            cursor: pointer;
        }

        .ui-autocomplete .ui-menu-item:hover {
            background: #f1f5f9;
        }

        @media print {
            .no-print { display: none; }
            .report-container { padding: 0; }
            .accordion-header { 
                background: none; 
                border-bottom: 1px solid #e5e7eb; 
                box-shadow: none;
            }
            .accordion-content { 
                max-height: none; 
                padding: 1rem; 
                box-shadow: none; 
            }
            .report-table th {
                background: var(--primary-orange);
            }
            .search-bar { display: none; }
            .sticky-header { position: static; }
        }

        @media (max-width: 768px) {
            .report-container { padding: 1rem; }
            .report-table th, .report-table td { font-size: 0.875rem; padding: 0.75rem; }
            .search-bar { max-width: 100%; }
            .accordion-header { font-size: 1rem; }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Header -->
    <header class="sticky-header text-white p-4 shadow-lg" style="background: linear-gradient(90deg, var(--primary-orange), var(--secondary-orange));">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-xl md:text-2xl font-bold"><?php echo $student_id ? 'Termly Reports for ' . htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') : 'View Student Reports'; ?></h1>
            <nav class="no-print flex items-center space-x-4">
                <a href="<?php echo $student_id ? 'view_student_reports.php' : '/teacher/dashboard.php'; ?>" class="text-white hover:text-gray-200 transition-colors">Back to <?php echo $student_id ? 'Student List' : 'Dashboard'; ?></a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto p-6 flex-grow">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error_msg'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error_msg']); ?>
                </div>
            <?php endif; ?>

            <?php if (!$student_id): ?>
                <!-- Search Bar -->
                <div class="search-bar no-print mb-8">
                    <i class="fas fa-search"></i>
                    <input type="text" id="student-search" class="w-full p-3 pl-10 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-orange-500" placeholder="Search for a student by name or email..." aria-label="Search students">
                </div>

                <!-- Student List by Class -->
                <h2 class="text-2xl font-semibold mb-6 text-gray-800">Students by Class</h2>
                <?php if (!empty($classes)): ?>
                    <?php foreach ($classes as $class): ?>
                        <?php if (!empty($students_by_class[$class['id']])): ?>
                            <div class="report-container mb-8">
                                <div class="accordion-header">
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo count($students_by_class[$class['id']]); ?> Students)</h3>
                                    <i class="fas fa-chevron-down text-gray-600"></i>
                                </div>
                                <div class="accordion-content">
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>Student Name</th>
                                                <th>Email</th>
                                                <th class="no-print">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($students_by_class[$class['id']] as $student): ?>
                                                <tr class="student-row" data-student-id="<?php echo $student['id']; ?>">
                                                    <td><?php echo htmlspecialchars($student['name'] ?: $student['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="no-print">
                                                        <a href="view_student_reports.php?student_id=<?php echo $student['id']; ?>" class="text-orange-500 hover:text-orange-700 font-medium">View Termly Reports</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center text-gray-600 text-lg">No classes found.</p>
                <?php endif; ?>
            <?php else: ?>
                <!-- Student Reports -->
                <?php if (empty($terms)): ?>
                    <p class="text-center text-gray-600 text-lg">No reports found for this student.</p>
                <?php else: ?>
                    <?php foreach ($terms as $term): ?>
                        <?php
                        $class_id_term = $term['class_id'];
                        $class_name_term = $term['class_name'] ?: 'Unknown Class';

                        // Fetch report details
                        $report_query = "
                            SELECT promoted_to_class_id, attendance, reopening_date, conduct, attitude, interest, teacher_remarks, headmaster_remarks
                            FROM student_reports
                            WHERE user_id = ? AND term_id = ? AND class_id = ?
                        ";
                        $stmt = $conn->prepare($report_query);
                        if (!$stmt) {
                            error_log("Report query preparation failed: " . $conn->error);
                            continue;
                        }
                        $stmt->bind_param("iii", $student_id, $term['id'], $class_id_term);
                        if (!$stmt->execute()) {
                            error_log("Report query execution failed: " . $stmt->error);
                            $stmt->close();
                            continue;
                        }
                        $report = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$report) {
                            error_log("No report found for student_id=$student_id, term_id={$term['id']}, class_id=$class_id_term");
                            continue;
                        }

                        // Fetch grades
                        $grades_query = "
                            SELECT s.subject_name, g.mid_term_exam, g.end_term_exam, g.comment
                            FROM grades g
                            JOIN subjects s ON g.subject_id = s.id
                            WHERE g.user_id = ? AND g.term_id = ? AND g.class_id = ?
                        ";
                        $stmt = $conn->prepare($grades_query);
                        if (!$stmt) {
                            error_log("Grades query preparation failed: " . $conn->error);
                            continue;
                        }
                        $stmt->bind_param("iii", $student_id, $term['id'], $class_id_term);
                        if (!$stmt->execute()) {
                            error_log("Grades query execution failed: " . $stmt->error);
                            $stmt->close();
                            continue;
                        }
                        $grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        if (empty($grades)) {
                            error_log("No grades found for student_id=$student_id, term_id={$term['id']}, class_id=$class_id_term");
                            continue;
                        }

                        // Fetch promoted class
                        $next_class = 'N/A';
                        if ($report['promoted_to_class_id']) {
                            $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
                            if ($stmt) {
                                $stmt->bind_param("i", $report['promoted_to_class_id']);
                                if ($stmt->execute()) {
                                    $pc_result = $stmt->get_result();
                                    if ($pc_row = $pc_result->fetch_assoc()) {
                                        $next_class = $pc_row['class_name'];
                                    }
                                } else {
                                    error_log("Promoted class query execution failed: " . $stmt->error);
                                }
                                $stmt->close();
                            } else {
                                error_log("Promoted class query preparation failed: " . $conn->error);
                            }
                        }

                        // Calculate total school days dynamically based on term dates
                        $total_days = countWeekdays($term['start_date'], $term['end_date']);

                        // Format attendance
                        $attendance_display = $report['attendance'] && is_numeric($report['attendance']) ? $report['attendance'] . ' / ' . $total_days : 'N/A';
                        $attendance_percentage = $report['attendance'] && is_numeric($report['attendance']) && $report['attendance'] > 0 ? number_format(($report['attendance'] / $total_days) * 100, 1) . '%' : 'N/A';
                        $reopening_display = $report['reopening_date'] ? date('l, F j, Y', strtotime($report['reopening_date'])) : 'N/A';

                        // Calculate aggregate
                        $grand_total = 0;
                        $subject_count = count($grades);
                        $max_score = $subject_count * 100;
                        foreach ($grades as $grade) {
                            $mid = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
                            $end = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
                            $total = ($mid * 0.5) + ($end * 0.5);
                            $grand_total += $total;
                        }
                        $aggregate_percentage = $max_score > 0 ? ($grand_total / $max_score) * 100 : 0;
                        $aggregate_grade = getGradeAndRemark($aggregate_percentage)['grade'];

                        // Check for signature and stamp
                        $signature_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/signature_head.png';
                        $stamp_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/stamp.png';
                        $seal_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/seal.png';
                        $signature_exists = file_exists($signature_path);
                        $stamp_exists = file_exists($stamp_path);
                        $seal_exists = file_exists($seal_path);

                        // Select random footer
                        $random_footer = $footer_messages[array_rand($footer_messages)];
                        if (strpos($random_footer, '–') !== false) {
                            $random_footer = '"' . $random_footer . '"';
                        }
                        ?>
                        <div class="report-container mb-8">
                            <div class="accordion-header">
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($term['term_name'] . ' ' . $term['year'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($class_name_term, ENT_QUOTES, 'UTF-8'); ?></h3>
                                <i class="fas fa-chevron-down text-gray-600"></i>
                            </div>
                            <div class="accordion-content">
                                <div class="header-section text-center mb-8">
                                    <img src="/assets/images/logo.png" alt="QPMS Logo" class="mx-auto mb-4" width="120" height="120">
                                    <h2 class="text-2xl font-bold text-orange-600">Queen's Paradise Montessori School</h2>
                                    <h3 class="text-xl font-semibold text-gray-800">End of <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['year'], ENT_QUOTES, 'UTF-8'); ?> Report</h3>
                                    <p class="text-sm text-gray-500">P.O. Box AM 42, Amasaman, Ghana | info.queenspa49@gmail.com</p>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <div>
                                        <p><strong>Name:</strong> <span class="text-orange-600"><?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <p><strong>Class:</strong> <span class="text-green-600"><?php echo htmlspecialchars($class_name_term, ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <p><strong>Term:</strong> <?php echo htmlspecialchars($term['term_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p><strong>Year:</strong> <?php echo htmlspecialchars($term['year'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div class="text-right">
                                        <img src="<?php echo htmlspecialchars($student_data['profile_picture'] ?? '/assets/images/default_profile.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Student Photo" class="border-2 border-orange-600 rounded-lg inline-block" width="100" height="100">
                                    </div>
                                </div>
                                <table class="report-table mb-6">
                                    <thead>
                                        <tr>
                                            <th>Subject</th>
                                            <th>SBA (50%)</th>
                                            <th>Exams (50%)</th>
                                            <th>Total (100%)</th>
                                            <th>Grade</th>
                                            <th>Remarks</th>
                                            <th>Comments</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($grades as $grade): ?>
                                            <?php
                                            $mid = is_numeric($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
                                            $end = is_numeric($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
                                            $total = ($mid * 0.5) + ($end * 0.5);
                                            $grade_info = getGradeAndRemark($total);
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($grade['subject_name'] ?: 'Unknown Subject', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo number_format($mid * 0.5, 1); ?></td>
                                                <td><?php echo number_format($end * 0.5, 1); ?></td>
                                                <td><?php echo number_format($total, 1); ?></td>
                                                <td class="<?php echo $grade_info['class']; ?>"><?php echo $grade_info['grade']; ?></td>
                                                <td><?php echo $grade_info['remark']; ?></td>
                                                <td><?php echo htmlspecialchars($grade['comment'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="font-semibold bg-gray-50">
                                            <td colspan="3">Aggregate (%)</td>
                                            <td colspan="4"><?php echo number_format($aggregate_percentage, 1) . '% (' . $aggregate_grade . ') [' . number_format($grand_total, 1) . ' / ' . $max_score . ']'; ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <div>
                                        <p><strong>Attendance:</strong> <?php echo htmlspecialchars($attendance_display . ' (' . $attendance_percentage . ')', ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p><strong>School Reopens:</strong> <?php echo htmlspecialchars($reopening_display, ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p><strong>Report for:</strong> <span class="text-green-600"><?php echo htmlspecialchars($class_name_term, ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <p><strong>Promoted to:</strong> <span class="text-orange-600"><?php echo htmlspecialchars($next_class, ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <p><strong>Conduct:</strong> <span class="text-orange-600"><?php echo htmlspecialchars($report['conduct'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <p><strong>Attitude:</strong> <span class="text-orange-600"><?php echo htmlspecialchars($report['attitude'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <p><strong>Interest:</strong> <span class="text-orange-600"><?php echo htmlspecialchars($report['interest'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span></p>
                                    </div>
                                    <div>
                                        <p><strong>Class Teacher's Signature</strong></p>
                                        <hr class="border-t-2 border-gray-800 w-3/5 my-4">
                                        <p><strong>HeadMistress/Master's Signature</strong></p>
                                        <?php if ($signature_exists): ?>
                                            <img src="/assets/images/signature_head.png" alt="Headmaster Signature" width="150" height="40" class="my-2">
                                        <?php else: ?>
                                            <span class="text-red-600">Signature image not found</span>
                                        <?php endif; ?>
                                        <hr class="border-t-2 border-gray-800 w-3/5 my-4">
                                    </div>
                                </div>
                                <div class="mb-6">
                                    <p><strong>Class Teacher's Remark:</strong> <span class="text-orange-600"><?php echo htmlspecialchars($report['teacher_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span></p>
                                    <p><strong>Head Teacher's Remark:</strong> <span class="text-orange-600"><?php echo htmlspecialchars($report['headmaster_remarks'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span></p>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <div>
                                        <p>Grace-Joan Kissi-Afare<br><strong>Executive Director, Queen's Paradise Montessori School</strong></p>
                                        <hr class="border-t-2 border-gray-800 w-4/5 my-4">
                                    </div>
                                    <div class="text-right">
                                        <?php if ($stamp_exists): ?>
                                            <img src="/assets/images/stamp.png" alt="School Stamp" width="80" height="80">
                                        <?php else: ?>
                                            <span class="text-red-600">Stamp image not found</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-center text-sm italic text-orange-600">
                                    <?php echo htmlspecialchars($random_footer, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php if (!$seal_exists): ?>
                                    <div class="watermark-error">Seal image not found at <?php echo htmlspecialchars($seal_path, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-900 text-white p-6 mt-auto">
        <div class="container mx-auto text-center">
            <p>© <?php echo date('Y'); ?> Queen's Paradise Montessori School. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Accordion functionality
        document.querySelectorAll('.accordion-header').forEach(header => {
            header.addEventListener('click', () => {
                const content = header.nextElementSibling;
                const icon = header.querySelector('i');
                content.classList.toggle('active');
                icon.classList.toggle('fa-chevron-down');
                icon.classList.toggle('fa-chevron-up');
            });
        });

        // Autocomplete search
        $(document).ready(function() {
            const students = <?php echo json_encode($student_list); ?>;
            $("#student-search").autocomplete({
                source: students,
                minLength: 2,
                select: function(event, ui) {
                    window.location.href = 'view_student_reports.php?student_id=' + ui.item.id;
                }
            });

            // Filter students by search input (client-side fallback)
            $("#student-search").on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                $('.student-row').each(function() {
                    const name = $(this).find('td:first').text().toLowerCase();
                    const email = $(this).find('td:eq(1)').text().toLowerCase();
                    if (name.includes(searchTerm) || email.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
                $('.report-container').each(function() {
                    const visibleStudents = $(this).find('.student-row:visible').length;
                    if (visibleStudents > 0) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>