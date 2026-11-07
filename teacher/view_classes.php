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
logActivity($conn, $teacher_id, "Accessed View Classes", "Teacher accessed view classes page from IP: $ip_address");

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
} else {
    error_log("Prepare failed for academic years: " . $conn->error);
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
    // Find current term in the list
    foreach ($terms as $term) {
        if (strpos($current_term_name, $term['term_name']) !== false) {
            $selected_term_id = $term['id'];
            break;
        }
    }
    // Fallback to first term
    if (!$selected_term_id) {
        $selected_term_id = $terms[0]['id'];
    }
}

// Validate selected term exists in current year
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

// CRITICAL FIX: Fetch classes using academic_year (matching dashboard approach)
// The class_schedules table uses academic_year, not term_id
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
        $dept_id = $row['department_id'];
        if (!isset($classes[$dept_id])) {
            $classes[$dept_id] = [];
        }
        $classes[$dept_id][] = $row;
    }
    $classes_stmt->close();
}

// Get student counts for these classes
$class_ids = [];
foreach ($classes as $dept_classes) {
    foreach ($dept_classes as $class) {
        $class_ids[] = $class['id'];
    }
}

$student_counts = [];
$group_counts = [];

if (!empty($class_ids)) {
    $class_ids_list = implode(',', array_map('intval', $class_ids));
    
    // Student counts from student_classes
    $student_count_sql = "
        SELECT sc.class_id, COUNT(DISTINCT sc.user_id) as student_count
        FROM student_classes sc
        WHERE sc.class_id IN ($class_ids_list)
        AND sc.academic_year = ?
        GROUP BY sc.class_id";
    $student_count_stmt = $conn->prepare($student_count_sql);
    if ($student_count_stmt) {
        $student_count_stmt->bind_param("s", $selected_academic_year);
        $student_count_stmt->execute();
        $result = $student_count_stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $student_counts[$row['class_id']] = (int)$row['student_count'];
        }
        $student_count_stmt->close();
    }
    
    // Group counts
    $group_count_sql = "
        SELECT class_id, COUNT(*) as group_count
        FROM groups
        WHERE class_id IN ($class_ids_list)
        AND status = 'active'
        GROUP BY class_id";
    $group_count_stmt = $conn->prepare($group_count_sql);
    if ($group_count_stmt) {
        $group_count_stmt->execute();
        $result = $group_count_stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $group_counts[$row['class_id']] = (int)$row['group_count'];
        }
        $group_count_stmt->close();
    }
}

// Calculate metrics
$total_classes = count($class_ids);
$total_students = array_sum($student_counts);
$total_groups = array_sum($group_counts);

// Student distribution for chart
$student_distribution_labels = [];
$student_distribution_data = [];
foreach ($classes as $dept_classes) {
    foreach ($dept_classes as $class) {
        $student_distribution_labels[] = $class['class_name'];
        $student_distribution_data[] = $student_counts[$class['id']] ?? 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage assigned classes for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, teacher, class management, groups">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Classes - QueensPa School">
    <meta property="og:description" content="Teacher interface to view and manage assigned classes, including groups and assignments.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmseduc.com/teacher/view_classes.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Classes - QueensPa School">
    <meta name="twitter:description" content="Teacher interface to view and manage assigned classes, including groups and assignments.">
    <title>View Classes - QueensPa School</title>

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
                    <i class="fas fa-chalkboard mr-2"></i> View Classes
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
                        Currently showing classes for: <strong><?= htmlspecialchars($selected_academic_year) ?></strong> - <strong><?= htmlspecialchars($selected_term_name) ?></strong>
                        (<?= htmlspecialchars($selected_term_start) ?> to <?= htmlspecialchars($selected_term_end) ?>)
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
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
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1">
                            <i class="fas fa-user-friends text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Groups</h5>
                            <p class="text-3xl font-bold text-orange-400 mb-1"><?= $total_groups ?></p>
                            <small class="text-gray-300">Active</small>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Assigned Classes -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Assigned Classes</h3>
                </div>
                <div class="p-6 text-white">
                    <div class="max-w-md mx-auto mb-6">
                        <input type="text" class="w-full px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white placeholder-gray-400" id="class-search" placeholder="Search classes...">
                    </div>
                    <div class="flex flex-col md:flex-row gap-4 mb-6">
                        <select class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 glassmorphism bg-opacity-50 text-white" id="department-filter">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (empty($classes)): ?>
                        <div class="bg-yellow-500 bg-opacity-20 border border-yellow-400 text-yellow-200 px-4 py-3 rounded-lg animate-fade-in">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            No classes assigned for <strong><?= htmlspecialchars($selected_academic_year) ?></strong>.
                            <?php if ($selected_academic_year === '2025/2026'): ?>
                                <br><small>Please ensure class schedules are set up for this academic year.</small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($classes as $dept_id => $dept_classes): ?>
                            <div class="mb-6" data-department="<?= $dept_id ?>">
                                <div class="bg-gradient-to-r from-gray-700 to-gray-600 px-4 py-3 rounded-t-lg cursor-pointer" onclick="toggleCollapse('dept-<?= $dept_id ?>')">
                                    <div class="flex justify-between items-center">
                                        <h5 class="text-lg font-semibold text-white">
                                            <?= htmlspecialchars($departments[$dept_id] ?? 'Unknown Department') ?> 
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
                                                        <th class="px-4 py-3 text-left">Students</th>
                                                        <th class="px-4 py-3 text-left">Groups</th>
                                                        <th class="px-4 py-3 text-left">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-500">
                                                    <?php foreach ($dept_classes as $class): ?>
                                                        <tr class="class-row hover:bg-gray-700 transition-colors duration-200" 
                                                            data-class-name="<?= strtolower(htmlspecialchars($class['class_name'])) ?>">
                                                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($class['class_name']) ?></td>
                                                            <td class="px-4 py-3 text-gray-300"><?= $student_counts[$class['id']] ?? 0 ?></td>
                                                            <td class="px-4 py-3 text-gray-300"><?= $group_counts[$class['id']] ?? 0 ?></td>
                                                            <td class="px-4 py-3">
                                                                <div class="flex flex-wrap gap-2">
                                                                    <a href="class_schedule.php?class_id=<?= $class['id'] ?>" 
                                                                       class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300">
                                                                        <i class="fas fa-calendar-alt"></i> Schedule
                                                                    </a>
                                                                    <a href="submit_grades.php?class_id=<?= $class['id'] ?>" 
                                                                       class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300">
                                                                        <i class="fas fa-chart-line"></i> Grades
                                                                    </a>
                                                                    <a href="create_group.php?class_id=<?= $class['id'] ?>" 
                                                                       class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300">
                                                                        <i class="fas fa-users"></i> Groups
                                                                    </a>
                                                                </div>
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

            <!-- Student Distribution Chart -->
            <?php if (!empty($student_distribution_labels)): ?>
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Student Distribution</h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="studentDistributionChart"></canvas>
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
                <a href="class_schedule.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-calendar-alt block text-xl mb-2"></i>
                    <span class="text-sm">Schedules</span>
                </a>
                <a href="student_list.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                    <i class="fas fa-users block text-xl mb-2"></i>
                    <span class="text-sm">Students</span>
                </a>
            </section>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ?>
    <?php ob_end_flush(); ?>

    <script>
        const studentDistributionLabels = <?= json_encode($student_distribution_labels) ?>;
        const studentDistributionData = <?= json_encode($student_distribution_data) ?>;

        let studentDistributionChart;

        function initializeChart() {
            const ctx = document.getElementById('studentDistributionChart')?.getContext('2d');
            if (!ctx) return;
            
            studentDistributionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: studentDistributionLabels,
                    datasets: [{
                        label: 'Students',
                        data: studentDistributionData,
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
                            ticks: { color: '#fff', stepSize: 1 },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        },
                        x: {
                            ticks: { color: '#fff' },
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
            const searchInput = document.getElementById('class-search');
            const deptFilter = document.getElementById('department-filter');
            const classRows = document.querySelectorAll('.class-row');
            const deptCards = document.querySelectorAll('[data-department]');

            function filterClasses() {
                const searchTerm = searchInput?.value.toLowerCase() || '';
                const deptValue = deptFilter?.value || '';

                deptCards.forEach(card => {
                    const deptId = card.getAttribute('data-department');
                    card.style.display = deptValue && deptId !== deptValue ? 'none' : 'block';
                });

                classRows.forEach(row => {
                    const className = row.getAttribute('data-class-name') || '';
                    row.style.display = className.includes(searchTerm) ? '' : 'none';
                });
            }

            searchInput?.addEventListener('input', filterClasses);
            deptFilter?.addEventListener('change', filterClasses);

            // Academic year filter
            const yearFilter = document.getElementById('academic-year-filter');
            const termFilter = document.getElementById('term-filter');
            
            yearFilter?.addEventListener('change', () => {
                window.location.href = `?academic_year=${encodeURIComponent(yearFilter.value)}`;
            });

            // Initialize departments as expanded
            document.querySelectorAll('[id^="dept-"]').forEach(el => {
                el.style.display = 'block';
            });

            // Initialize chart
            initializeChart();
        });
    </script>
</body>
</html>