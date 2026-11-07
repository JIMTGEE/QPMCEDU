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

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Check database connection
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
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed Teacher Dashboard", "Teacher accessed dashboard from IP: $ip_address");

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

// Determine current academic year and term from academic_calendar table
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
    // Current date falls within an active term
    $academic_info = $academic_info_result->fetch_assoc();
    $academic_year = $academic_info['year'];
    $current_term_name = $academic_info['term_name'];
    $term_start_date = $academic_info['start_date'];
    $term_end_date = $academic_info['end_date'];
    
    // Extract term number from term_name (e.g., "Term 1" -> 1)
    preg_match('/\d+/', $current_term_name, $matches);
    $current_term_id = !empty($matches) ? (int)$matches[0] : null;
} else {
    // No active term found - check for the most recent or upcoming term
    $fallback_query = "SELECT `year`, `term_name`, `start_date`, `end_date` 
                       FROM academic_calendar 
                       WHERE start_date <= DATE_ADD(?, INTERVAL 30 DAY) 
                       ORDER BY ABS(DATEDIFF(start_date, ?)) ASC 
                       LIMIT 1";
    $fallback_stmt = $conn->prepare($fallback_query);
    $fallback_stmt->bind_param("ss", $current_date, $current_date);
    $fallback_stmt->execute();
    $fallback_result = $fallback_stmt->get_result();
    
    if ($fallback_result->num_rows > 0) {
        $academic_info = $fallback_result->fetch_assoc();
        $academic_year = $academic_info['year'];
        $current_term_name = $academic_info['term_name'] . ' (Upcoming)';
        $term_start_date = $academic_info['start_date'];
        $term_end_date = $academic_info['end_date'];
        
        preg_match('/\d+/', $academic_info['term_name'], $matches);
        $current_term_id = !empty($matches) ? (int)$matches[0] : 2; // Default to 2 if pattern fails
    } else {
        // Ultimate fallback
        $current_year = date('Y');
        $current_month = date('n');
        
        // Determine approximate academic year and term based on current date
        if ($current_month >= 9) {
            $academic_year = $current_year . '/' . ($current_year + 1);
            $current_term_id = 1;
            $current_term_name = 'Term 1 (Estimated)';
        } elseif ($current_month >= 1 && $current_month <= 4) {
            $academic_year = ($current_year - 1) . '/' . $current_year;
            $current_term_id = 2;
            $current_term_name = 'Term 2 (Estimated)';
        } elseif ($current_month >= 5 && $current_month <= 7) {
            $academic_year = ($current_year - 1) . '/' . $current_year;
            $current_term_id = 3;
            $current_term_name = 'Term 3 (Estimated)';
        } else {
            $academic_year = $current_year . '/' . ($current_year + 1);
            $current_term_id = 1;
            $current_term_name = 'Term 1 (Estimated)';
        }
        
        $term_start_date = null;
        $term_end_date = null;
    }
    $fallback_stmt->close();
}
$academic_info_stmt->close();

// Debug logging
error_log("Academic Year: $academic_year, Term: $current_term_name (ID: $current_term_id)");

// Fetch teacher's department
$teacher_sql = "SELECT department_id FROM users WHERE id = ?";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();
$teacher_department_id = $teacher ? $teacher['department_id'] : null;
$teacher_stmt->close();

// Days of the week
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Fetch all departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Get teacher's classes (from class_schedules)
$teacher_classes_sql = "
    SELECT DISTINCT c.id, c.class_name, c.department_id
    FROM class_schedules cs
    JOIN classes c ON cs.class_id = c.id
    WHERE cs.teacher_id = ? AND cs.academic_year = ?";
$teacher_classes_stmt = $conn->prepare($teacher_classes_sql);
$teacher_classes_stmt->bind_param("is", $teacher_id, $academic_year);
$teacher_classes_stmt->execute();
$teacher_classes_result = $teacher_classes_stmt->get_result();
$teacher_classes = [];
while ($row = $teacher_classes_result->fetch_assoc()) {
    $teacher_classes[$row['id']] = $row;
}
$teacher_classes_stmt->close();

$teacher_classes_ids = !empty($teacher_classes) ? array_keys($teacher_classes) : [0];
$teacher_classes_list = implode(',', $teacher_classes_ids);

// Fetch metrics based on class_schedules data
// Total schedules count
$total_schedules_sql = "
    SELECT COUNT(*) as total_schedules
    FROM class_schedules cs
    WHERE cs.teacher_id = ? AND cs.academic_year = ?";
$total_schedules_stmt = $conn->prepare($total_schedules_sql);
$total_schedules_stmt->bind_param("is", $teacher_id, $academic_year);
$total_schedules_stmt->execute();
$total_schedules = $total_schedules_stmt->get_result()->fetch_assoc()['total_schedules'] ?? 0;
$total_schedules_stmt->close();

// Class count (unique classes)
$class_count = count($teacher_classes);

// Student count from student_classes
$student_count_sql = "
    SELECT COUNT(DISTINCT sc.user_id) as student_count
    FROM student_classes sc
    WHERE sc.class_id IN ($teacher_classes_list)
    AND sc.academic_year = ?";
$student_count_stmt = $conn->prepare($student_count_sql);
$student_count = 0;
if ($student_count_stmt) {
    $student_count_stmt->bind_param("s", $academic_year);
    $student_count_stmt->execute();
    $student_count = $student_count_stmt->get_result()->fetch_assoc()['student_count'] ?? 0;
    $student_count_stmt->close();
}

// Subject count (unique subjects from schedules)
$subject_count_sql = "
    SELECT COUNT(DISTINCT cs.subject_id) as subject_count
    FROM class_schedules cs
    WHERE cs.teacher_id = ? AND cs.academic_year = ?";
$subject_count_stmt = $conn->prepare($subject_count_sql);
$subject_count_stmt->bind_param("is", $teacher_id, $academic_year);
$subject_count_stmt->execute();
$subject_count = $subject_count_stmt->get_result()->fetch_assoc()['subject_count'] ?? 0;
$subject_count_stmt->close();

// Teaching hours per week
$teaching_hours_sql = "
    SELECT SUM(TIMESTAMPDIFF(MINUTE, cs.start_time, cs.end_time) / 60.0) as teaching_hours
    FROM class_schedules cs
    WHERE cs.teacher_id = ? AND cs.academic_year = ?";
$teaching_hours_stmt = $conn->prepare($teaching_hours_sql);
$teaching_hours_stmt->bind_param("is", $teacher_id, $academic_year);
$teaching_hours_stmt->execute();
$teaching_hours = round($teaching_hours_stmt->get_result()->fetch_assoc()['teaching_hours'] ?? 0, 1);
$teaching_hours_stmt->close();

// Schedules by day of week
$schedules_by_day_sql = "
    SELECT cs.day_of_week, COUNT(*) as schedule_count
    FROM class_schedules cs
    WHERE cs.teacher_id = ? AND cs.academic_year = ?
    GROUP BY cs.day_of_week";
$schedules_by_day_stmt = $conn->prepare($schedules_by_day_sql);
$schedules_by_day_stmt->bind_param("is", $teacher_id, $academic_year);
$schedules_by_day_stmt->execute();
$schedules_by_day_result = $schedules_by_day_stmt->get_result();
$schedules_by_day = array_fill_keys($days_of_week, 0);
while ($row = $schedules_by_day_result->fetch_assoc()) {
    $schedules_by_day[$row['day_of_week']] = $row['schedule_count'];
}
$schedules_by_day_stmt->close();

// Teaching hours by day
$teaching_hours_by_day_sql = "
    SELECT cs.day_of_week, SUM(TIMESTAMPDIFF(MINUTE, cs.start_time, cs.end_time) / 60.0) as hours
    FROM class_schedules cs
    WHERE cs.teacher_id = ? AND cs.academic_year = ?
    GROUP BY cs.day_of_week";
$teaching_hours_by_day_stmt = $conn->prepare($teaching_hours_by_day_sql);
$teaching_hours_by_day_stmt->bind_param("is", $teacher_id, $academic_year);
$teaching_hours_by_day_stmt->execute();
$teaching_hours_by_day_result = $teaching_hours_by_day_stmt->get_result();
$teaching_hours_by_day = array_fill_keys($days_of_week, 0);
while ($row = $teaching_hours_by_day_result->fetch_assoc()) {
    $teaching_hours_by_day[$row['day_of_week']] = round($row['hours'], 1);
}
$teaching_hours_by_day_stmt->close();

// Most recent schedules (upcoming/current)
$recent_schedules_sql = "
    SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time,
           c.class_name, s.subject_name, d.name as department_name
    FROM class_schedules cs
    JOIN classes c ON cs.class_id = c.id
    JOIN subjects s ON cs.subject_id = s.id
    LEFT JOIN departments d ON cs.department_id = d.id
    WHERE cs.teacher_id = ? AND cs.academic_year = ?
    ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), cs.start_time
    LIMIT 10";
$recent_schedules_stmt = $conn->prepare($recent_schedules_sql);
$recent_schedules_stmt->bind_param("is", $teacher_id, $academic_year);
$recent_schedules_stmt->execute();
$recent_schedules_result = $recent_schedules_stmt->get_result();
$recent_schedules = $recent_schedules_result->fetch_all(MYSQLI_ASSOC);
$recent_schedules_stmt->close();

// Today's schedule
$today = date('l'); // Full day name (Monday, Tuesday, etc.)
$today_schedules_sql = "
    SELECT cs.id, cs.start_time, cs.end_time, c.class_name, s.subject_name
    FROM class_schedules cs
    JOIN classes c ON cs.class_id = c.id
    JOIN subjects s ON cs.subject_id = s.id
    WHERE cs.teacher_id = ? AND cs.academic_year = ? AND cs.day_of_week = ?
    ORDER BY cs.start_time";
$today_schedules_stmt = $conn->prepare($today_schedules_sql);
$today_schedules_stmt->bind_param("iss", $teacher_id, $academic_year, $today);
$today_schedules_stmt->execute();
$today_schedules_result = $today_schedules_stmt->get_result();
$today_schedules = $today_schedules_result->fetch_all(MYSQLI_ASSOC);
$today_schedules_stmt->close();

// Notification count (basic - can be expanded)
$notification_count_sql = "
    SELECT COUNT(*) as notification_count
    FROM notifications
    WHERE (target_role = 'teacher' OR user_id = ?) AND is_read = 0";
$notification_count_stmt = $conn->prepare($notification_count_sql);
$notification_count_stmt->bind_param("i", $teacher_id);
$notification_count_stmt->execute();
$notification_count = $notification_count_stmt->get_result()->fetch_assoc()['notification_count'] ?? 0;
$notification_count_stmt->close();

// Recent activity logs
$recent_logs_sql = "
    SELECT action, description, created_at
    FROM activity_logs
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5";
$recent_logs_stmt = $conn->prepare($recent_logs_sql);
$recent_logs_stmt->bind_param("i", $teacher_id);
$recent_logs_stmt->execute();
$recent_logs_result = $recent_logs_stmt->get_result();
$recent_logs = $recent_logs_result->fetch_all(MYSQLI_ASSOC);
$recent_logs_stmt->close();

// Fetch classes with their schedules for detailed view
$classes_with_schedules_sql = "
    SELECT c.id, c.class_name, c.department_id,
           GROUP_CONCAT(DISTINCT s.subject_name ORDER BY s.subject_name SEPARATOR ', ') as subjects,
           GROUP_CONCAT(DISTINCT cs.day_of_week ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday') SEPARATOR ', ') as days,
           MIN(cs.start_time) as first_start_time,
           MAX(cs.end_time) as last_end_time,
           COUNT(cs.id) as schedule_count
    FROM classes c
    LEFT JOIN class_schedules cs ON c.id = cs.class_id AND cs.teacher_id = ? AND cs.academic_year = ?
    LEFT JOIN subjects s ON cs.subject_id = s.id
    WHERE c.id IN ($teacher_classes_list)
    GROUP BY c.id, c.class_name, c.department_id
    ORDER BY c.department_id, c.class_name";
$classes_with_schedules_stmt = $conn->prepare($classes_with_schedules_sql);
$classes_with_schedules_stmt->bind_param("is", $teacher_id, $academic_year);
$classes_with_schedules_stmt->execute();
$classes_with_schedules_result = $classes_with_schedules_stmt->get_result();
$classes_by_dept = [];
while ($row = $classes_with_schedules_result->fetch_assoc()) {
    $classes_by_dept[$row['department_id']][] = $row;
}
$classes_with_schedules_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher dashboard for QueensPa School - Manage schedules, classes, and students">
    <meta name="keywords" content="QueensPa School, teacher dashboard, class schedules, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Teacher Dashboard - QueensPa School</title>

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
        .schedule-card {
            transition: all 0.3s ease;
        }
        .schedule-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(249, 115, 22, 0.3);
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
                    <i class="fas fa-chalkboard-teacher mr-2"></i> Teacher Dashboard
                </h1>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism" id="theme-toggle">
                    <i class="fas fa-moon mr-2"></i> Toggle Theme
                </button>
            </div>

            <!-- Academic Year Info -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Current Academic Information</h3>
                </div>
                <div class="p-6 text-white">
                    <div class="grid md:grid-cols-2 gap-6">
                        <div class="space-y-3">
                            <p><strong class="font-semibold">Academic Year:</strong> <?= htmlspecialchars($academic_year) ?></p>
                            <p><strong class="font-semibold">Current Term:</strong> <?= htmlspecialchars($current_term_name) ?></p>
                            <p><strong class="font-semibold">Department:</strong> 
                                <?php 
                                $dept_name = 'Not Assigned';
                                foreach ($departments as $dept) {
                                    if ($dept['id'] == $teacher_department_id) {
                                        $dept_name = $dept['name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($dept_name);
                                ?>
                            </p>
                        </div>
                        <div class="space-y-3">
                            <p><strong class="font-semibold">Term Period:</strong> 
                                <?= $term_start_date && $term_end_date ? htmlspecialchars($term_start_date . ' to ' . $term_end_date) : 'Not set' ?>
                            </p>
                            <p><strong class="font-semibold">Total Schedules:</strong> <?= $total_schedules ?></p>
                            <p><strong class="font-semibold">Teaching Days:</strong> 
                                <?= count(array_filter($schedules_by_day)) ?> / 5 days
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Key Metrics -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Key Metrics (<?= htmlspecialchars($academic_year) ?>)</h3>
                </div>
                <div class="p-6 text-white">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-calendar-alt text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Total Schedules</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $total_schedules ?></p>
                            <small class="text-gray-300">Class sessions</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-chalkboard text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Classes</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $class_count ?></p>
                            <small class="text-gray-300">Assigned classes</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-users text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Students</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $student_count ?></p>
                            <small class="text-gray-300">Total enrolled</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-book text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Subjects</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $subject_count ?></p>
                            <small class="text-gray-300">Subjects taught</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-clock text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Teaching Hours</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $teaching_hours ?> hrs</p>
                            <small class="text-gray-300">Per week</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-bell text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Notifications</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1 notification-count"><?= $notification_count ?></p>
                            <small class="text-gray-300">Unread</small>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Today's Schedule -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0"><i class="fas fa-calendar-day mr-2"></i> Today's Schedule (<?= $today ?>)</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($today_schedules)): ?>
                        <div class="bg-blue-500 bg-opacity-20 border border-blue-400 text-blue-200 px-4 py-3 rounded-lg text-center">
                            <i class="fas fa-info-circle mr-2"></i>
                            No classes scheduled for today.
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4">
                            <?php foreach ($today_schedules as $schedule): ?>
                                <div class="glassmorphism rounded-xl p-4 schedule-card">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <h5 class="text-lg font-semibold text-white"><?= htmlspecialchars($schedule['class_name']) ?></h5>
                                            <p class="text-orange-400"><?= htmlspecialchars($schedule['subject_name']) ?></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-white font-bold"><?= htmlspecialchars($schedule['start_time']) ?> - <?= htmlspecialchars($schedule['end_time']) ?></p>
                                            <span class="text-sm text-gray-300">Duration: <?= 
                                                round((strtotime($schedule['end_time']) - strtotime($schedule['start_time'])) / 60) 
                                            ?> mins</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Schedule Distribution by Day -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Weekly Schedule Distribution</h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="scheduleDistributionChart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Teaching Hours by Day -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Teaching Hours by Day</h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="teachingHoursChart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Classes and Schedules Details -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Your Classes and Schedules</h3>
                </div>
                <div class="p-6 text-white">
                    <!-- Search and Filters -->
                    <div class="flex flex-col md:flex-row gap-4 mb-6">
                        <input type="text" class="flex-1 px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white placeholder-gray-400" id="class-search" placeholder="Search classes...">
                        <select class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white" id="dept-filter">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (empty($classes_by_dept)): ?>
                        <div class="bg-yellow-500 bg-opacity-20 border border-yellow-400 text-yellow-200 px-4 py-3 rounded-lg">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            No classes assigned for this academic year.
                        </div>
                    <?php else: ?>
                        <?php foreach ($classes_by_dept as $dept_id => $dept_classes): ?>
                            <?php 
                            $dept_name = 'Unknown';
                            foreach ($departments as $dept) {
                                if ($dept['id'] == $dept_id) {
                                    $dept_name = $dept['name'];
                                    break;
                                }
                            }
                            ?>
                            <div class="mb-6" data-department="<?= $dept_id ?>">
                                <div class="bg-gradient-to-r from-gray-700 to-gray-600 px-4 py-3 rounded-t-lg cursor-pointer" onclick="toggleDepartment('dept-<?= $dept_id ?>', this)">
                                    <div class="flex justify-between items-center">
                                        <h5 class="text-lg font-semibold text-white">
                                            <?= htmlspecialchars($dept_name) ?>
                                            <span class="bg-orange-500 text-white px-2 py-1 rounded-full text-sm ml-2"><?= count($dept_classes) ?> Classes</span>
                                        </h5>
                                        <i class="fas fa-chevron-down transition-transform duration-300 text-orange-400" id="chevron-dept-<?= $dept_id ?>"></i>
                                    </div>
                                </div>
                                <div id="dept-<?= $dept_id ?>" class="bg-transparent border border-gray-500 rounded-b-lg">
                                    <div class="p-4">
                                        <div class="overflow-x-auto">
                                            <table class="w-full table-auto">
                                                <thead>
                                                    <tr class="bg-gradient-to-r from-orange-500 to-red-500 text-white">
                                                        <th class="px-4 py-3 text-left">Class Name</th>
                                                        <th class="px-4 py-3 text-left">Subjects</th>
                                                        <th class="px-4 py-3 text-left">Days</th>
                                                        <th class="px-4 py-3 text-left">Time Range</th>
                                                        <th class="px-4 py-3 text-left">Sessions</th>
                                                        <th class="px-4 py-3 text-left">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-500">
                                                    <?php foreach ($dept_classes as $class): ?>
                                                        <tr class="class-row hover:bg-gray-700 transition-colors duration-200"
                                                            data-class-name="<?= strtolower(htmlspecialchars($class['class_name'] ?? '')) ?>">
                                                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($class['class_name'] ?? 'N/A') ?></td>
                                                            <td class="px-4 py-3 text-gray-300"><?= htmlspecialchars($class['subjects'] ?: 'No subjects scheduled') ?></td>
                                                            <td class="px-4 py-3 text-gray-300"><?= htmlspecialchars($class['days'] ?: 'No days scheduled') ?></td>
                                                            <td class="px-4 py-3 text-gray-300">
                                                                <?= htmlspecialchars($class['first_start_time'] && $class['last_end_time'] 
                                                                    ? substr($class['first_start_time'], 0, 5) . ' - ' . substr($class['last_end_time'], 0, 5) 
                                                                    : 'N/A') ?>
                                                            </td>
                                                            <td class="px-4 py-3 text-center">
                                                                <span class="bg-orange-500 text-white px-2 py-1 rounded-full text-sm">
                                                                    <?= $class['schedule_count'] ?>
                                                                </span>
                                                            </td>
                                                            <td class="px-4 py-3">
                                                                <a href="class_schedule.php?class_id=<?= $class['id'] ?>" 
                                                                   class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 inline-block"
                                                                   title="View class schedule">
                                                                    <i class="fas fa-calendar-alt"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Recent Schedules -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Recent Schedules</h3>
                </div>
                <div class="p-6 text-white">
                    <div class="overflow-x-auto">
                        <div class="max-h-64 overflow-y-auto">
                            <table class="w-full table-auto">
                                <thead class="sticky top-0">
                                    <tr class="bg-gradient-to-r from-orange-500 to-red-500 text-white">
                                        <th class="px-4 py-3 text-left">Day</th>
                                        <th class="px-4 py-3 text-left">Time</th>
                                        <th class="px-4 py-3 text-left">Class</th>
                                        <th class="px-4 py-3 text-left">Subject</th>
                                        <th class="px-4 py-3 text-left">Department</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-500">
                                    <?php if (empty($recent_schedules)): ?>
                                        <tr>
                                            <td colspan="5" class="px-4 py-8 text-center text-gray-300">
                                                No schedules found for this academic year.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_schedules as $schedule): ?>
                                            <tr class="hover:bg-gray-700 transition-colors duration-200">
                                                <td class="px-4 py-3"><?= htmlspecialchars($schedule['day_of_week']) ?></td>
                                                <td class="px-4 py-3"><?= htmlspecialchars($schedule['start_time'] . ' - ' . $schedule['end_time']) ?></td>
                                                <td class="px-4 py-3 font-medium"><?= htmlspecialchars($schedule['class_name']) ?></td>
                                                <td class="px-4 py-3"><?= htmlspecialchars($schedule['subject_name']) ?></td>
                                                <td class="px-4 py-3"><?= htmlspecialchars($schedule['department_name'] ?? 'N/A') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Recent Activity -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Recent Activity</h3>
                </div>
                <div class="p-6 text-white">
                    <div class="overflow-x-auto">
                        <div class="max-h-64 overflow-y-auto">
                            <table class="w-full table-auto">
                                <thead class="sticky top-0">
                                    <tr class="bg-gradient-to-r from-orange-500 to-red-500 text-white">
                                        <th class="px-4 py-3 text-left">Action</th>
                                        <th class="px-4 py-3 text-left">Description</th>
                                        <th class="px-4 py-3 text-left">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-500">
                                    <?php if (empty($recent_logs)): ?>
                                        <tr>
                                            <td colspan="3" class="px-4 py-8 text-center text-gray-300">No recent activity found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_logs as $log): ?>
                                            <tr class="hover:bg-gray-700 transition-colors duration-200">
                                                <td class="px-4 py-3 font-medium"><?= htmlspecialchars($log['action'] ?? '') ?></td>
                                                <td class="px-4 py-3 text-gray-300"><?= htmlspecialchars($log['description'] ?? '') ?></td>
                                                <td class="px-4 py-3 text-gray-300"><?= htmlspecialchars($log['created_at'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-3 gap-4 mb-8 animate-fade-in-up">
                <a href="class_schedule.php" class="bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-calendar-alt block text-xl mb-2"></i>
                    <span class="text-sm">Manage Schedules</span>
                </a>
                <a href="student_list.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-users block text-xl mb-2"></i>
                    <span class="text-sm">View Students</span>
                </a>
                <a href="class_list.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-chalkboard block text-xl mb-2"></i>
                    <span class="text-sm">View Classes</span>
                </a>
            </section>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ?>
    <?php ob_end_flush(); ?>

    <script>
        // Chart data
        const scheduleByDayData = <?= json_encode(array_values($schedules_by_day)) ?>;
        const teachingHoursByDayData = <?= json_encode(array_values($teaching_hours_by_day)) ?>;
        const daysOfWeek = <?= json_encode($days_of_week) ?>;

        // Initialize charts
        let scheduleChart, hoursChart;

        function initializeCharts() {
            // Schedule Distribution Chart
            const ctxSchedule = document.getElementById('scheduleDistributionChart').getContext('2d');
            scheduleChart = new Chart(ctxSchedule, {
                type: 'bar',
                data: {
                    labels: daysOfWeek,
                    datasets: [{
                        label: 'Number of Class Sessions',
                        data: scheduleByDayData,
                        backgroundColor: createGradient(ctxSchedule, ['#f97316', '#ea580c']),
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
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: { family: 'Orbitron', size: 14 },
                            bodyFont: { family: 'Inter', size: 12 },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Number of Sessions', color: '#fff', font: { family: 'Inter', size: 12 } },
                            ticks: { color: '#fff', font: { family: 'Inter' } },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        },
                        x: {
                            ticks: { color: '#fff', font: { family: 'Inter' } },
                            grid: { display: false },
                        }
                    }
                }
            });

            // Teaching Hours Chart
            const ctxHours = document.getElementById('teachingHoursChart').getContext('2d');
            hoursChart = new Chart(ctxHours, {
                type: 'line',
                data: {
                    labels: daysOfWeek,
                    datasets: [{
                        label: 'Teaching Hours',
                        data: teachingHoursByDayData,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: '#f97316',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        tension: 0.3,
                        fill: true,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: { family: 'Orbitron', size: 14 },
                            bodyFont: { family: 'Inter', size: 12 },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Hours', color: '#fff', font: { family: 'Inter', size: 12 } },
                            ticks: { color: '#fff', font: { family: 'Inter' } },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        },
                        x: {
                            ticks: { color: '#fff', font: { family: 'Inter' } },
                            grid: { display: false },
                        }
                    }
                }
            });
        }

        function createGradient(ctx, colors) {
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            colors.forEach((color, index) => {
                gradient.addColorStop(index / (colors.length - 1), color);
            });
            return gradient;
        }

        function updateChartsTheme() {
            const isDark = document.body.classList.contains('dark');
            const textColor = isDark ? '#fff' : '#000';
            const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';

            scheduleChart.options.scales.y.grid.color = gridColor;
            scheduleChart.options.scales.y.ticks.color = textColor;
            scheduleChart.options.scales.x.ticks.color = textColor;
            scheduleChart.options.scales.y.title.color = textColor;
            scheduleChart.update();

            hoursChart.options.scales.y.grid.color = gridColor;
            hoursChart.options.scales.y.ticks.color = textColor;
            hoursChart.options.scales.x.ticks.color = textColor;
            hoursChart.options.scales.y.title.color = textColor;
            hoursChart.update();
        }

        function toggleDepartment(id, header) {
            const element = document.getElementById(id);
            const chevron = document.getElementById('chevron-' + id);
            if (element.style.display === 'none') {
                element.style.display = 'block';
                chevron.style.transform = 'rotate(0deg)';
                header.classList.remove('rounded-b-lg');
            } else {
                element.style.display = 'none';
                chevron.style.transform = 'rotate(180deg)';
                header.classList.add('rounded-b-lg');
            }
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
                updateChartsTheme();
            });

            // Search and filter
            const searchInput = document.getElementById('class-search');
            const deptFilter = document.getElementById('dept-filter');
            const classRows = document.querySelectorAll('.class-row');
            const deptSections = document.querySelectorAll('[data-department]');

            function filterClasses() {
                const searchTerm = searchInput.value.toLowerCase();
                const deptValue = deptFilter.value;

                deptSections.forEach(section => {
                    const deptId = section.getAttribute('data-department');
                    if (deptValue && deptId !== deptValue) {
                        section.style.display = 'none';
                    } else {
                        section.style.display = 'block';
                    }
                });

                classRows.forEach(row => {
                    const className = row.getAttribute('data-class-name') || '';
                    row.style.display = className.includes(searchTerm) ? '' : 'none';
                });
            }

            searchInput.addEventListener('input', filterClasses);
            deptFilter.addEventListener('change', filterClasses);

            // Initialize departments (all expanded by default)
            document.querySelectorAll('[id^="dept-"]').forEach(el => {
                el.style.display = 'block';
            });

            // Initialize charts
            initializeCharts();
        });
    </script>
</body>
</html>