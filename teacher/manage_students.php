<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection and logging
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate user_id
$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $teacher_id);
    $user_check_stmt->execute();
    if ($user_check_stmt->get_result()->num_rows === 0) {
        error_log("Invalid teacher_id: $teacher_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
logActivity($conn, $teacher_id, "Accessed Manage Students", "Teacher accessed manage students page from IP: $ip_address");

// Generate CSRF token
$conn->begin_transaction();
try {
    $csrf_token = bin2hex(random_bytes(32));
    $csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
    $csrf_stmt = $conn->prepare($csrf_sql);
    if (!$csrf_stmt) {
        throw new Exception("Prepare failed for CSRF token: " . $conn->error);
    }
    $csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
    $csrf_stmt->execute();
    $csrf_stmt->close();
    $conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log("CSRF token error: " . $e->getMessage());
}

// Determine current academic year and term (matching dashboard logic)
$current_date = date('Y-m-d');
$academic_info_query = "SELECT `year`, `term_name`, `start_date`, `end_date` 
                        FROM academic_calendar 
                        WHERE ? BETWEEN start_date AND end_date 
                        ORDER BY start_date ASC 
                        LIMIT 1";
$academic_info_stmt = $conn->prepare($academic_info_query);
$academic_info_stmt->bind_param("s", $current_date);
$academic_info_stmt->execute();
$academic_info_result = $academic_info_stmt->get_result();

if ($academic_info_result->num_rows > 0) {
    $academic_info = $academic_info_result->fetch_assoc();
    $current_academic_year = $academic_info['year'];
    $current_term_name = $academic_info['term_name'];
    $term_start_date = $academic_info['start_date'];
    $term_end_date = $academic_info['end_date'];
} else {
    // Fallback to estimate
    $current_year = date('Y');
    $current_month = date('n');
    if ($current_month >= 9) {
        $current_academic_year = $current_year . '/' . ($current_year + 1);
    } elseif ($current_month >= 1 && $current_month <= 7) {
        $current_academic_year = ($current_year - 1) . '/' . $current_year;
    } else {
        $current_academic_year = $current_year . '/' . ($current_year + 1);
    }
    $current_term_name = 'Term 1 (Estimated)';
    $term_start_date = null;
    $term_end_date = null;
}
$academic_info_stmt->close();

// Fetch all academic years
$academic_years_sql = "SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC";
$academic_years_stmt = $conn->prepare($academic_years_sql);
$academic_years = [];
if ($academic_years_stmt) {
    $academic_years_stmt->execute();
    $academic_years = $academic_years_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $academic_years_stmt->close();
}

// Get selected academic year (default to current)
$selected_academic_year = $_GET['academic_year'] ?? $current_academic_year;
$valid_years = array_column($academic_years, 'year');
if (!in_array($selected_academic_year, $valid_years)) {
    $selected_academic_year = $current_academic_year;
}

// Fetch terms for selected year
$terms_sql = "SELECT id, term_name, start_date, end_date FROM academic_calendar WHERE year = ? ORDER BY start_date";
$terms_stmt = $conn->prepare($terms_sql);
$terms = [];
if ($terms_stmt) {
    $terms_stmt->bind_param("s", $selected_academic_year);
    $terms_stmt->execute();
    $terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $terms_stmt->close();
}

// Determine selected term
$selected_term_id = $_GET['term_id'] ?? null;
if (!$selected_term_id && !empty($terms)) {
    foreach ($terms as $term) {
        if (strpos($current_term_name, $term['term_name']) !== false) {
            $selected_term_id = $term['id'];
            break;
        }
    }
    if (!$selected_term_id) {
        $selected_term_id = $terms[0]['id'];
    }
}

// Get selected term details
$selected_term_name = '';
$selected_term_start = '';
$selected_term_end = '';
foreach ($terms as $term) {
    if ($term['id'] == $selected_term_id) {
        $selected_term_name = $term['term_name'];
        $selected_term_start = $term['start_date'];
        $selected_term_end = $term['end_date'];
        break;
    }
}

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments = [];
if ($departments_stmt) {
    $departments_stmt->execute();
    $departments_result = $departments_stmt->get_result();
    while ($row = $departments_result->fetch_assoc()) {
        $departments[$row['id']] = $row['name'];
    }
    $departments_stmt->close();
}

// CRITICAL FIX: Fetch classes using academic_year instead of term_id
$classes_sql = "
    SELECT DISTINCT c.id, c.class_name, c.department_id
    FROM class_schedules cs
    JOIN classes c ON cs.class_id = c.id
    WHERE cs.teacher_id = ? AND cs.academic_year = ?
    ORDER BY c.department_id, c.class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes = [];
if ($classes_stmt) {
    $classes_stmt->bind_param("is", $teacher_id, $selected_academic_year);
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    while ($row = $classes_result->fetch_assoc()) {
        $classes[$row['id']] = $row;
    }
    $classes_stmt->close();
}

// Get class IDs for queries
$class_ids = array_keys($classes);
$class_ids_list = !empty($class_ids) ? implode(',', array_map('intval', $class_ids)) : '0';

// Fetch student counts
$student_counts = [];
if (!empty($class_ids)) {
    $counts_sql = "
        SELECT sc.class_id, COUNT(DISTINCT sc.user_id) as student_count
        FROM student_classes sc
        WHERE sc.class_id IN ($class_ids_list) AND sc.academic_year = ?
        GROUP BY sc.class_id";
    $counts_stmt = $conn->prepare($counts_sql);
    if ($counts_stmt) {
        $counts_stmt->bind_param("s", $selected_academic_year);
        $counts_stmt->execute();
        $counts_result = $counts_stmt->get_result();
        while ($row = $counts_result->fetch_assoc()) {
            $student_counts[$row['class_id']] = (int)$row['student_count'];
        }
        $counts_stmt->close();
    }
}

// Fetch students grouped by class
$grouped_students = [];
$attendance_data = [];

foreach ($classes as $class_id => $class) {
    $grouped_students[$class['class_name']] = [];
    
    $students_sql = "
        SELECT sc.id, sc.user_id, sc.academic_year, u.name AS student_name, u.email, u.phone, u.gender, 
               c.class_name, u.profile_picture, d.id AS department_id
        FROM student_classes sc 
        JOIN users u ON sc.user_id = u.id 
        JOIN classes c ON sc.class_id = c.id 
        JOIN departments d ON c.department_id = d.id
        WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
        ORDER BY u.name";
    $students_stmt = $conn->prepare($students_sql);
    if (!$students_stmt) {
        error_log("Students query preparation failed for class $class_id: " . $conn->error);
        continue;
    }
    $students_stmt->bind_param("is", $class_id, $selected_academic_year);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    
    while ($student_row = $students_result->fetch_assoc()) {
        $student_id = $student_row['user_id'];
        
        // Fetch attendance using term dates
        $attendance_sql = "
            SELECT SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
                   COUNT(*) AS total_sessions
            FROM attendance 
            WHERE user_id = ? AND class_id = ?";
        
        // Add date range if term dates available
        $params = [$student_id, $class_id];
        $types = "ii";
        
        if ($selected_term_start && $selected_term_end) {
            $attendance_sql .= " AND date BETWEEN ? AND ?";
            $params[] = $selected_term_start;
            $params[] = $selected_term_end;
            $types .= "ss";
        }
        
        $attendance_stmt = $conn->prepare($attendance_sql);
        $present_count = 0;
        $total_sessions = 0;
        
        if ($attendance_stmt) {
            $attendance_stmt->bind_param($types, ...$params);
            $attendance_stmt->execute();
            $attendance = $attendance_stmt->get_result()->fetch_assoc();
            $present_count = $attendance['present_count'] ?? 0;
            $total_sessions = $attendance['total_sessions'] ?? 0;
            $attendance_stmt->close();
        }
        
        $student_row['present_count'] = $present_count;
        $student_row['total_sessions'] = $total_sessions;
        
        $attendance_data[] = [
            'name' => $student_row['student_name'],
            'class' => $class['class_name'],
            'attendance_rate' => $total_sessions > 0 
                ? round(($present_count / $total_sessions) * 100, 1) 
                : 0
        ];
        
        // Fetch recent assignments
        $assignments_sql = "
            SELECT a.id, a.title, a.due_date, s.subject_name, 
                   COALESCE(ass.status, 'pending') AS submission_status
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            LEFT JOIN assignment_submissions ass ON a.id = ass.assignment_id AND ass.user_id = ?
            WHERE a.class_id = ?
            ORDER BY a.due_date ASC
            LIMIT 3";
        $assignments_stmt = $conn->prepare($assignments_sql);
        if ($assignments_stmt) {
            $assignments_stmt->bind_param("ii", $student_id, $class_id);
            $assignments_stmt->execute();
            $student_row['assignments'] = $assignments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $assignments_stmt->close();
        } else {
            $student_row['assignments'] = [];
        }
        
        // Fetch unread notification count
        $notifications_sql = "
            SELECT COUNT(*) as unread_count
            FROM notifications 
            WHERE user_id = ? AND is_read = 0";
        $notifications_stmt = $conn->prepare($notifications_sql);
        if ($notifications_stmt) {
            $notifications_stmt->bind_param("i", $student_id);
            $notifications_stmt->execute();
            $notifications = $notifications_stmt->get_result()->fetch_assoc();
            $student_row['unread_notifications'] = $notifications['unread_count'] ?? 0;
            $notifications_stmt->close();
        } else {
            $student_row['unread_notifications'] = 0;
        }
        
        $grouped_students[$class['class_name']][] = $student_row;
    }
    $students_stmt->close();
}

// Calculate metrics
$total_students = array_sum($student_counts);
$total_classes = count($classes);

// Prepare print data
$print_students = $grouped_students;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Monitor students in classes assigned to the teacher in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, teacher students, class students, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Manage Students - QueensPa School">
    <meta property="og:description" content="Teacher interface for monitoring students in assigned classes in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmseduc.com/teacher/manage_students.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Manage Students - QueensPa School">
    <meta name="twitter:description" content="Teacher interface for monitoring students in assigned classes in QueensPa School.">
    <title>Manage Students - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                        orbitron: ['Orbitron', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#fff7ed',
                            100: '#ffedd5',
                            200: '#fed7aa',
                            300: '#fdba74',
                            400: '#fb923c',
                            500: '#f97316',
                            600: '#ea580c',
                            700: '#c2410c',
                            800: '#9a3412',
                            900: '#7c2d12',
                        },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out',
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'zoom-in': 'zoomIn 0.6s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'glow': 'glow 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(30px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        zoomIn: { '0%': { opacity: '0', transform: 'scale(0.95)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                        glow: {
                            '0%, 100%': { boxShadow: '0 0 5px rgba(249, 115, 22, 0.5)' },
                            '50%': { boxShadow: '0 0 20px rgba(249, 115, 22, 0.8)' },
                        },
                    },
                },
            },
            darkMode: 'class',
        }
    </script>

    <style>
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Orbitron', sans-serif; }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin: 0 auto;
        }
        @media print {
            body * { display: none !important; }
            .print-section, .print-section * { display: block !important; visibility: visible !important; }
            .print-section { position: static; font-size: 12pt; color: #000; }
            .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #f97316; padding-bottom: 10px; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .print-table th, .print-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .print-table th { background: #f97316; color: white; }
            .print-group-header { font-size: 14pt; font-weight: bold; margin: 20px 0 10px; color: #f97316; page-break-before: always; }
            .print-footer { text-align: center; margin-top: 20px; font-size: 10pt; color: #555; }
            @page { size: A4; margin: 20mm; }
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-orange-900 dark:bg-gradient-to-br dark:from-gray-900 dark:via-gray-800 dark:to-orange-900">
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-80 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-orange-500 text-4xl animate-pulse-slow"></i>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="content" id="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-center mb-8 animate-fade-in">
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white text-center flex-grow mb-4 md:mb-0 animate-glow">
                    <i class="fas fa-user-graduate mr-2"></i> Manage Students
                </h1>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism" id="theme-toggle">
                    <i class="fas fa-moon mr-2"></i> Toggle Theme
                </button>
            </div>

            <!-- Academic Year and Term Selection -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Academic Year and Term</h3>
                </div>
                <div class="p-6 text-white">
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="academic-year-filter" class="block text-sm font-medium mb-2">Academic Year</label>
                            <select id="academic-year-filter" class="w-full px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white">
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= htmlspecialchars($year['year']) ?>" <?= $selected_academic_year === $year['year'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year['year']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="term-filter" class="block text-sm font-medium mb-2">Term (informational - filtering by academic year)</label>
                            <select id="term-filter" class="w-full px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= $term['id'] ?>" <?= $selected_term_id == $term['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($term['term_name']) ?> (<?= htmlspecialchars($term['start_date']) ?> - <?= htmlspecialchars($term['end_date']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if ($selected_term_name): ?>
                    <div class="mt-4 bg-blue-500 bg-opacity-20 border border-blue-400 text-blue-200 px-4 py-3 rounded-lg">
                        <i class="fas fa-info-circle mr-2"></i>
                        Showing students for: <strong><?= htmlspecialchars($selected_academic_year) ?></strong> - <strong><?= htmlspecialchars($selected_term_name) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Key Metrics -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Key Metrics (<?= htmlspecialchars($selected_academic_year) ?>)</h3>
                </div>
                <div class="p-6 text-white">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-chalkboard text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Classes</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $total_classes ?></p>
                            <small class="text-gray-300">Assigned</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-users text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Students</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $total_students ?></p>
                            <small class="text-gray-300">Enrolled</small>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Student List -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl flex justify-between items-center">
                    <h3 class="text-xl font-semibold mb-0">Student List</h3>
                    <button class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-3 py-1 rounded-lg text-sm font-medium transition-all duration-300" onclick="printContent()">
                        <i class="fas fa-print mr-2"></i> Print List
                    </button>
                </div>
                <div class="p-6 text-white">
                    <!-- Search and Filters -->
                    <div class="flex flex-col md:flex-row gap-4 mb-6">
                        <input type="text" class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white placeholder-gray-400 w-full md:w-1/3" id="student-search" placeholder="Search students...">
                        <select class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white" id="class-filter">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= htmlspecialchars($class['class_name']) ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white" id="department-filter">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if (empty($grouped_students)): ?>
                        <div class="bg-yellow-500 bg-opacity-20 border border-yellow-400 text-yellow-200 px-4 py-3 rounded-lg animate-fade-in">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            No students assigned for <strong><?= htmlspecialchars($selected_academic_year) ?></strong>.
                            <?php if ($selected_academic_year === '2025/2026'): ?>
                                <br><small>Please ensure students are enrolled in classes for this academic year.</small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($grouped_students as $class_name => $students): ?>
                            <?php 
                            $class_id_for_section = '';
                            $dept_id_for_section = '';
                            foreach ($classes as $cid => $cdata) {
                                if ($cdata['class_name'] === $class_name) {
                                    $class_id_for_section = $cid;
                                    $dept_id_for_section = $cdata['department_id'];
                                    break;
                                }
                            }
                            $section_id = 'class-' . preg_replace('/[^a-zA-Z0-9]+/', '-', $class_name);
                            ?>
                            <div class="mb-6" data-class="<?= htmlspecialchars($class_name) ?>" data-department="<?= $dept_id_for_section ?>">
                                <div class="bg-gradient-to-r from-gray-700 to-gray-600 px-4 py-3 rounded-t-lg cursor-pointer" onclick="toggleCollapse('<?= $section_id ?>')">
                                    <div class="flex justify-between items-center">
                                        <h5 class="text-lg font-semibold text-white">
                                            <?= htmlspecialchars($class_name) ?>
                                            <span class="bg-orange-500 text-white px-2 py-1 rounded-full text-sm ml-2"><?= count($students) ?> Students</span>
                                        </h5>
                                        <i class="fas fa-chevron-down transition-transform duration-300 text-orange-400" id="chevron-<?= $section_id ?>"></i>
                                    </div>
                                </div>
                                <div id="<?= $section_id ?>" class="bg-transparent border border-gray-500 rounded-b-lg">
                                    <div class="p-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                            <?php foreach ($students as $student): ?>
                                                <div class="student-card bg-white bg-opacity-10 rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-1 transition duration-300 glassmorphism" 
                                                     data-student-name="<?= strtolower(htmlspecialchars($student['student_name'])) ?>" 
                                                     data-class="<?= htmlspecialchars($class_name) ?>" 
                                                     data-department="<?= $student['department_id'] ?>">
                                                    <div class="p-4">
                                                        <div class="flex items-center gap-4 mb-4">
                                                            <img src="<?= !empty($student['profile_picture']) ? htmlspecialchars($student['profile_picture']) : '/assets/images/default-profile.jpg' ?>" 
                                                                 class="w-16 h-16 rounded-full object-cover border-2 border-orange-500" 
                                                                 alt="<?= htmlspecialchars($student['student_name']) ?>">
                                                            <div>
                                                                <h6 class="text-lg font-semibold text-white"><?= htmlspecialchars($student['student_name']) ?></h6>
                                                                <p class="text-sm text-gray-300"><?= htmlspecialchars($student['academic_year']) ?></p>
                                                                <span class="bg-gradient-to-r from-blue-500 to-blue-600 text-white text-sm px-3 py-1 rounded-full">
                                                                    <?= $student['present_count'] ?> / <?= $student['total_sessions'] ?> Present
                                                                </span>
                                                                <?php if ($student['unread_notifications'] > 0): ?>
                                                                    <span class="bg-red-500 text-white text-sm px-3 py-1 rounded-full ml-2">
                                                                        <?= $student['unread_notifications'] ?> Unread
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="mb-4 text-gray-300">
                                                            <p class="text-sm"><strong>Email:</strong> <?= htmlspecialchars($student['email']) ?></p>
                                                            <p class="text-sm"><strong>Phone:</strong> <?= htmlspecialchars($student['phone']) ?></p>
                                                            <p class="text-sm"><strong>Gender:</strong> <?= htmlspecialchars($student['gender']) ?></p>
                                                        </div>
                                                        <div class="mb-4">
                                                            <h6 class="text-sm font-semibold text-white mb-2">Recent Assignments</h6>
                                                            <?php if (empty($student['assignments'])): ?>
                                                                <p class="text-sm text-gray-400">No recent assignments</p>
                                                            <?php else: ?>
                                                                <ul class="text-sm text-gray-300 space-y-1">
                                                                    <?php foreach ($student['assignments'] as $assignment): ?>
                                                                        <li>
                                                                            <span class="font-medium"><?= htmlspecialchars($assignment['title']) ?></span>
                                                                            (<?= htmlspecialchars($assignment['subject_name']) ?>) - 
                                                                            <span class="<?= $assignment['submission_status'] === 'submitted' ? 'text-green-400' : 'text-red-400' ?>">
                                                                                <?= ucfirst($assignment['submission_status']) ?>
                                                                            </span>
                                                                            <br>
                                                                            <small>Due: <?= htmlspecialchars($assignment['due_date']) ?></small>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Attendance Visualization -->
            <?php if (!empty($attendance_data)): ?>
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Attendance Overview</h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- Quick Links -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mb-8 animate-fade-in-up">
                <a href="dashboard.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-tachometer-alt block text-xl mb-2"></i>
                    <span class="text-sm">Dashboard</span>
                </a>
                <a href="view_classes.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-chalkboard block text-xl mb-2"></i>
                    <span class="text-sm">Classes</span>
                </a>
                <a href="submit_grades.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-chart-line block text-xl mb-2"></i>
                    <span class="text-sm">Grades</span>
                </a>
                <a href="manage_attendance.php" class="bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-check-circle block text-xl mb-2"></i>
                    <span class="text-sm">Attendance</span>
                </a>
            </section>

            <!-- Print Section -->
            <div class="print-section hidden">
                <div class="print-header">
                    <h2>QueensPa School</h2>
                    <p>Teacher Student List - <?= date('F j, Y') ?> | Academic Year: <?= htmlspecialchars($selected_academic_year) ?></p>
                </div>
                <?php foreach ($grouped_students as $class_name => $class_students): ?>
                    <h3 class="print-group-header"><?= htmlspecialchars($class_name) ?> (<?= count($class_students) ?> Students)</h3>
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Academic Year</th>
                                <th>Attendance</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Gender</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($class_students as $student): ?>
                                <tr>
                                    <td><?= htmlspecialchars($student['student_name']) ?></td>
                                    <td><?= htmlspecialchars($student['academic_year']) ?></td>
                                    <td><?= $student['present_count'] ?> / <?= $student['total_sessions'] ?></td>
                                    <td><?= htmlspecialchars($student['email']) ?></td>
                                    <td><?= htmlspecialchars($student['phone']) ?></td>
                                    <td><?= htmlspecialchars($student['gender']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
                <div class="print-footer">
                    <p>Generated by QueensPa School Management System</p>
                </div>
            </div>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ?>
    <?php ob_end_flush(); ?>

    <script>
        const attendanceData = <?= json_encode($attendance_data) ?>;
        let attendanceChart;

        function initializeChart() {
            const ctx = document.getElementById('attendanceChart')?.getContext('2d');
            if (!ctx || attendanceData.length === 0) return;
            
            const labels = attendanceData.map(item => `${item.name} (${item.class})`);
            const values = attendanceData.map(item => item.attendance_rate);
            
            attendanceChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Attendance Rate (%)',
                        data: values,
                        backgroundColor: function(context) {
                            const chart = context.chart;
                            const {ctx, chartArea} = chart;
                            if (!chartArea) return '#f97316';
                            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            gradient.addColorStop(0, '#f97316');
                            gradient.addColorStop(1, '#ea580c');
                            return gradient;
                        },
                        borderColor: '#f97316',
                        borderWidth: 1,
                        borderRadius: 8,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: { color: '#fff' },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        },
                        x: {
                            ticks: { color: '#fff', maxRotation: 45, minRotation: 45 },
                            grid: { display: false },
                        }
                    }
                }
            });
        }

        function toggleCollapse(id) {
            const element = document.getElementById(id);
            const chevron = document.getElementById('chevron-' + id);
            if (element.style.display === 'none') {
                element.style.display = 'block';
                chevron.style.transform = 'rotate(0deg)';
            } else {
                element.style.display = 'none';
                chevron.style.transform = 'rotate(180deg)';
            }
        }

        function printContent() {
            window.print();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const loadingOverlay = document.getElementById('loading-overlay');
            loadingOverlay.classList.add('hidden');

            // Dark mode toggle
            const themeToggle = document.getElementById('theme-toggle');
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark');
                themeToggle.innerHTML = '<i class="fas fa-sun mr-2"></i> Toggle Theme';
            }
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                const isDark = document.body.classList.contains('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                themeToggle.innerHTML = isDark 
                    ? '<i class="fas fa-sun mr-2"></i> Toggle Theme' 
                    : '<i class="fas fa-moon mr-2"></i> Toggle Theme';
            });

            // Search and filter
            const searchInput = document.getElementById('student-search');
            const classFilter = document.getElementById('class-filter');
            const deptFilter = document.getElementById('department-filter');
            const studentCards = document.querySelectorAll('.student-card');
            const classSections = document.querySelectorAll('[data-class]');

            function filterStudents() {
                const searchTerm = searchInput?.value.toLowerCase() || '';
                const classValue = classFilter?.value || '';
                const deptValue = deptFilter?.value || '';

                classSections.forEach(section => {
                    const className = section.getAttribute('data-class');
                    const deptId = section.getAttribute('data-department');
                    const matchesClass = !classValue || className === classValue;
                    const matchesDept = !deptValue || deptId === deptValue;
                    section.style.display = (matchesClass && matchesDept) ? 'block' : 'none';
                });

                studentCards.forEach(card => {
                    const studentName = card.getAttribute('data-student-name') || '';
                    const cardClass = card.getAttribute('data-class');
                    const cardDept = card.getAttribute('data-department');
                    const matchesSearch = studentName.includes(searchTerm);
                    const matchesClass = !classValue || cardClass === classValue;
                    const matchesDept = !deptValue || cardDept === deptValue;
                    card.style.display = (matchesSearch && matchesClass && matchesDept) ? 'block' : 'none';
                });
            }

            searchInput?.addEventListener('input', filterStudents);
            classFilter?.addEventListener('change', filterStudents);
            deptFilter?.addEventListener('change', filterStudents);

            // Academic year filter
            const yearFilter = document.getElementById('academic-year-filter');
            const termFilter = document.getElementById('term-filter');
            
            yearFilter?.addEventListener('change', () => {
                window.location.href = `?academic_year=${encodeURIComponent(yearFilter.value)}`;
            });

            // Initialize all sections as expanded
            document.querySelectorAll('[id^="class-"]').forEach(el => {
                el.style.display = 'block';
            });

            // Initialize chart
            initializeChart();
        });
    </script>
</body>
</html>