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
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, email, department_id FROM users WHERE id = ? AND role = 'teacher'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
if (!$teacher) {
    error_log("Invalid teacher_id in session: $user_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$stmt->close();

// Fetch current academic year and term
$current_term_sql = "SELECT id, term_name FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
$current_term_id = 1; // Fallback term ID
$current_term_name = 'Unknown Term';
if ($current_term_stmt) {
    $current_term_stmt->execute();
    $current_term = $current_term_stmt->get_result()->fetch_assoc();
    $current_term_id = $current_term['id'] ?? 1;
    $current_term_name = $current_term['term_name'] ?? 'Unknown Term';
    $current_term_stmt->close();
} else {
    error_log("Prepare failed for current term query: " . $conn->error);
}

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $user_id, "Accessed Exam Schedules", "Teacher accessed exam schedule management page");

// Fetch terms for filter dropdown
$terms_sql = "SELECT id, term_name FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE()";
$terms_stmt = $conn->prepare($terms_sql);
$terms = [];
if ($terms_stmt) {
    $terms_stmt->execute();
    $terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $terms_stmt->close();
} else {
    error_log("Prepare failed for terms query: " . $conn->error);
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_exam'])) {
    $conn->begin_transaction();
    try {
        $csrf_token_post = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
        $exam_id = filter_input(INPUT_POST, 'exam_id', FILTER_VALIDATE_INT);

        if ($csrf_token_post !== $_SESSION['csrf_token']) {
            error_log("CSRF token validation failed: Expected " . ($_SESSION['csrf_token'] ?? 'none') . ", Received " . ($csrf_token_post ?? 'none'));
            throw new Exception("Invalid CSRF token.");
        }

        if (!$exam_id) {
            throw new Exception("Invalid exam ID.");
        }

        // Verify exam exists and belongs to teacher
        $stmt = $conn->prepare("
            SELECT es.id
            FROM exam_schedules es
            JOIN classes c ON es.class_id = c.id
            WHERE es.id = ? AND c.teacher_id = ?
        ");
        $stmt->bind_param("ii", $exam_id, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception("Invalid exam schedule or insufficient permissions.");
        }
        $stmt->close();

        // Delete exam schedule
        $stmt = $conn->prepare("DELETE FROM exam_schedules WHERE id = ?");
        $stmt->bind_param("i", $exam_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to delete exam schedule.");
        }
        $stmt->close();

        logActivity($conn, $user_id, "Deleted Exam Schedule", "Exam schedule deleted for id: $exam_id");
        $_SESSION['success_msg'] = "Exam schedule deleted successfully.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate CSRF token
        $conn->commit();
        header("Location: /teacher/manage_exam_schedule.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Delete error: " . $e->getMessage());
        $_SESSION['error_msg'] = $e->getMessage();
    }
}

// Fetch exam schedules with pagination and filters
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = trim(filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$term_filter = trim(filter_input(INPUT_GET, 'term', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');

$where_conditions = ["c.teacher_id = ?"];
$params = [$user_id];
$types = "i";

if ($search !== '') {
    $where_conditions[] = "(c.class_name LIKE ? OR s.subject_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($term_filter && in_array($term_filter, array_column($terms, 'term_name'))) {
    $where_conditions[] = "ac.term_name = ?";
    $params[] = $term_filter;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

$count_sql = "SELECT COUNT(*) as count
              FROM exam_schedules es
              JOIN classes c ON es.class_id = c.id
              JOIN subjects s ON es.subject_id = s.id
              JOIN academic_calendar ac ON es.term_id = ac.id
              WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['count'];
$count_stmt->close();
$pages = max(1, ceil($total / $per_page));

$exams_sql = "SELECT es.id, c.class_name, s.subject_name, ac.term_name, es.exam_date, es.start_time, es.end_time, es.room
              FROM exam_schedules es
              JOIN classes c ON es.class_id = c.id
              JOIN subjects s ON es.subject_id = s.id
              JOIN academic_calendar ac ON es.term_id = ac.id
              WHERE $where_clause
              ORDER BY es.exam_date DESC
              LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$exams_stmt = $conn->prepare($exams_sql);
if (!$exams_stmt) {
    error_log("Prepare failed for exams query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch exam schedules.";
    header("Location: /teacher/dashboard.php");
    exit();
}
$exams_stmt->bind_param($types, ...$params);
$exams_stmt->execute();
$exams = $exams_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exams_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage exam schedules for QueensPa School.">
    <meta name="keywords" content="QueensPa School, manage exam schedules, teacher dashboard, exam management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Exam Schedules - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Poppins', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        primary: {
                            500: '#f97316',
                            600: '#ea580c',
                        },
                        accent: {
                            500: '#34d399',
                            600: '#2ca37a',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.5s ease-out',
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'scale-in': 'scaleIn 0.3s ease-out',
                    },
                    keyframes: {
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        scaleIn: {
                            '0%': { transform: 'scale(0.95)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                    },
                },
            },
            darkMode: 'class',
        };
    </script>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
            border-color: #f97316;
        }
        .btn-primary, .btn-accent {
            transition: transform 0.2s, background-color 0.3s;
        }
        .btn-primary:hover, .btn-accent:hover {
            transform: translateY(-1px);
        }
        .table-row:hover {
            background-color: #f1f5f9;
            transition: background-color 0.2s;
        }
        .modal-content {
            max-height: 80vh;
            overflow-y: auto;
        }
        [data-tooltip] {
            position: relative;
        }
        [data-tooltip]:hover:after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1f2937;
            color: white;
            padding: 0.5rem;
            border-radius: 0.25rem;
            white-space: nowrap;
            z-index: 50;
        }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <!-- Delete Exam Modal -->
    <div id="deleteExamModal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden" role="dialog" aria-labelledby="deleteExamModalLabel" aria-modal="true">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full modal-content animate-scale-in">
            <div class="bg-primary-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="deleteExamModalLabel">Delete Exam Schedule</h3>
                <button id="close-delete" class="text-white hover:text-gray-200" aria-label="Close delete modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="delete-form" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="exam_id" id="delete_exam_id">
                <input type="hidden" name="delete_exam" value="1">
                <p class="text-gray-700 dark:text-gray-200">Are you sure you want to delete this exam schedule?</p>
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancel-delete" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium btn-primary">Cancel</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium btn-primary">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    try {
        $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
        if (!file_exists($header_path)) {
            throw new Exception("Teacher header file missing: $header_path");
        }
        include $header_path;
    } catch (Exception $e) {
        error_log("Failed to include teacher_header.php: " . $e->getMessage());
        logActivity($conn, $user_id, "Error", "Failed to load teacher header template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>

    <div class="container mx-auto px-4 py-12 max-w-7xl">
        <!-- Header -->
        <div class="bg-gradient-to-r from-primary-500 to-primary-600 text-white p-6 rounded-xl shadow-lg mb-8 animate-slide-up">
            <h1 class="text-3xl font-bold flex items-center">
                <i class="fas fa-calendar-alt text-white mr-3"></i> Manage Exam Schedules, <?= htmlspecialchars($teacher['name'], ENT_QUOTES, 'UTF-8') ?>!
            </h1>
            <p class="text-lg">Organize exams for your classes - <?= htmlspecialchars($current_term_name, ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-fade-in">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['error_msg'], ENT_QUOTES, 'UTF-8') ?>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-fade-in">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['success_msg'], ENT_QUOTES, 'UTF-8') ?>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Filters and Search -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-8">
            <form id="filter-form" method="GET" class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1">
                    <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Search</label>
                    <input type="text" id="search" name="search" class="form-control w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by subject or class..." aria-label="Search exam schedules">
                </div>
                <div>
                    <label for="term_filter" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Term</label>
                    <select id="term_filter" name="term" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" aria-label="Filter by term">
                        <option value="">All Terms</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= htmlspecialchars($term['term_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $term_filter === $term['term_name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <a href="/teacher/create_exam.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium btn-primary no-print" aria-label="Create new exam schedule">
                        <i class="fas fa-plus mr-2"></i>Create Exam
                    </a>
                </div>
            </form>
        </div>

        <!-- Exam Schedules Table -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider">Class</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider">Subject</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider">Term</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider">Exam Date</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider">Time</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider">Room</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 dark:text-gray-200 uppercase tracking-wider no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($exams)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No exam schedules found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($exams as $exam): ?>
                                <tr class="table-row">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                        <?= htmlspecialchars($exam['class_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars($exam['subject_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars($exam['term_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars($exam['exam_date'] ?? 'TBD', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= $exam['start_time'] && $exam['end_time'] ? htmlspecialchars($exam['start_time'] . ' - ' . $exam['end_time'], ENT_QUOTES, 'UTF-8') : 'TBD' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars($exam['room'] ?? 'TBD', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2 no-print">
                                        <a href="/teacher/edit_exam.php?id=<?= htmlspecialchars($exam['id'], ENT_QUOTES, 'UTF-8') ?>" 
                                           class="text-primary-500 hover:text-primary-600" data-tooltip="Edit exam schedule">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="text-red-500 hover:text-red-600 delete-exam" 
                                                data-exam-id="<?= htmlspecialchars($exam['id'], ENT_QUOTES, 'UTF-8') ?>" 
                                                data-tooltip="Delete exam schedule">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($pages > 1): ?>
                <div class="mt-6 flex justify-between items-center no-print">
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total) ?> of <?= $total ?> exam schedules
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&term=<?= urlencode($term_filter) ?>" 
                               class="bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded-lg text-sm font-medium btn-primary">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&term=<?= urlencode($term_filter) ?>" 
                               class="px-3 py-1 rounded-lg text-sm font-medium <?= $i == $page ? 'bg-primary-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600' ?>">
                               <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $pages): ?>
                            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&term=<?= urlencode($term_filter) ?>" 
                               class="bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded-lg text-sm font-medium btn-primary">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    try {
        $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
        if (!file_exists($footer_path)) {
            ?>
            <footer class="bg-gradient-to-r from-primary-500 to-primary-600 text-white p-6">
                <div class="container mx-auto text-center">
                    <p class="mb-2">© 2025 QueensPa School. All rights reserved.</p>
                    <div class="flex justify-center space-x-4 mb-2">
                        <a href="https://facebook.com/page" class="text-white hover:underline"><i class="fab fa-facebook-square mr-1"></i>Facebook Page</a>
                        <a href="https://twitter.com" class="text-white hover:underline"><i class="fab fa-twitter mr-1"></i>Twitter</a>
                        <a href="https://linkedin.com" class="text-white hover:underline"><i class="fab fa-linkedin mr-1"></i>LinkedIn</a>
                        <a href="https://youtube.com" class="text-white hover:underline"><i class="fab fa-youtube mr-1"></i>YouTube</a>
                    </div>
                    <p>Developed by Kadevelops | Contact: <a href="mailto:contact@kadevelops.com" class="hover:underline">Email</a> | <a href="https://wa.me/1234567890" class="hover:underline">WhatsApp</a></p>
                </div>
            </footer>
            <?php
        } else {
            include $footer_path;
        }
    } catch (Exception $e) {
        error_log("Failed to include teacher_footer.php: " . $e->getMessage());
        logActivity($conn, $user_id, "Error", "Failed to load teacher footer template: " . $e->getMessage());
    }
    ?>

    <script>
        $(document).ready(function() {
            const loadingOverlay = $('#loading-overlay');
            const deleteModal = $('#deleteExamModal');
            const deleteForm = $('#delete-form');
            const closeDelete = $('#close-delete');
            const cancelDelete = $('#cancel-delete');
            const filterForm = $('#filter-form');

            // Filter and search with debouncing
            let filterTimeout;
            $('#search, #term_filter').on('input change', function() {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(() => {
                    filterForm.submit();
                }, 300);
            });

            // Delete exam
            $('.delete-exam').on('click', function() {
                $('#delete_exam_id').val($(this).data('exam-id'));
                deleteModal.removeClass('hidden');
            });

            // Close delete modal
            closeDelete.on('click', () => deleteModal.addClass('hidden'));
            cancelDelete.on('click', () => deleteModal.addClass('hidden'));

            // Form submission
            deleteForm.on('submit', () => loadingOverlay.removeClass('hidden'));

            // Dark mode toggle
            const darkModeToggle = $('<button>', {
                html: '<i class="fas fa-moon"></i>',
                class: 'fixed bottom-4 right-4 bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium btn-primary no-print',
                'aria-label': 'Toggle dark mode'
            }).appendTo('body');
            darkModeToggle.on('click', () => {
                $('body').toggleClass('dark');
                localStorage.setItem('darkMode', $('body').hasClass('dark'));
            });
            if (localStorage.getItem('darkMode') === 'true') {
                $('body').addClass('dark');
            }

            // Hide loading overlay
            loadingOverlay.addClass('hidden');
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>