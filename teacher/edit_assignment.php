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
$user_check_sql = "SELECT id, department_id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $teacher_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid teacher_id in session: $teacher_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $teacher_department_id = $user['department_id'];
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
logActivity($conn, $teacher_id, "Accessed Edit Assignment Page", "Teacher accessed edit assignment page");

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle AJAX request for groups
if (isset($_GET['action']) && $_GET['action'] === 'get_groups') {
    header('Content-Type: application/json');
    $class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
    if (!$class_id) {
        echo json_encode(['error' => 'Invalid class ID']);
        exit;
    }
    $groups_sql = "SELECT id, name FROM groups WHERE class_id = ? AND status = 'active' ORDER BY name";
    $groups_stmt = $conn->prepare($groups_sql);
    if ($groups_stmt) {
        $groups_stmt->bind_param("i", $class_id);
        $groups_stmt->execute();
        $result = $groups_stmt->get_result();
        $groups = $result->fetch_all(MYSQLI_ASSOC);
        $groups_stmt->close();
        echo json_encode(['groups' => $groups]);
    } else {
        echo json_encode(['error' => 'Failed to fetch groups']);
    }
    exit;
}

// Fetch assignment details
$assignment_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    // Show friendly error instead of redirecting immediately (to give user feedback)
    $error_msg = "Invalid assignment ID. No ID provided or ID is not numeric.";
    // Still we'll display the form with error, but no assignment data.
    $assignment = null;
} else {
    $select_sql = "SELECT a.*, 
                          c.class_name, c.department_id,
                          s.subject_name,
                          d.name AS department_name,
                          at.term_name,
                          ay.year_label AS academic_year_label
                   FROM assignments a 
                   JOIN classes c ON a.class_id = c.id 
                   JOIN subjects s ON a.subject_id = s.id 
                   JOIN departments d ON c.department_id = d.id
                   LEFT JOIN academic_terms at ON a.term_id = at.id
                   LEFT JOIN academic_years ay ON at.academic_year_id = ay.id
                   WHERE a.id = ? AND a.teacher_id = ?";
    $select_stmt = $conn->prepare($select_sql);
    if ($select_stmt) {
        $select_stmt->bind_param("ii", $assignment_id, $teacher_id);
        $select_stmt->execute();
        $assignment_result = $select_stmt->get_result();
        if ($assignment_result->num_rows === 0) {
            error_log("Assignment not found or unauthorized: ID $assignment_id, teacher_id $teacher_id");
            $error_msg = "Assignment not found or you do not have permission to edit it.";
            $assignment = null;
        } else {
            $assignment = $assignment_result->fetch_assoc();
        }
        $select_stmt->close();
    } else {
        error_log("Failed to prepare assignment query: " . $conn->error);
        $error_msg = "System error: Unable to fetch assignment.";
        $assignment = null;
    }
}

// Process form submission
$form_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assignment !== null) {
    $conn->begin_transaction();
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid CSRF token.");
        }

        // Sanitize inputs
        $title = trim(filter_input(INPUT_POST, 'title', FILTER_UNSAFE_RAW));
        $description = trim(filter_input(INPUT_POST, 'description', FILTER_UNSAFE_RAW));
        $type = trim(filter_input(INPUT_POST, 'type', FILTER_UNSAFE_RAW));
        $max_points = filter_input(INPUT_POST, 'max_points', FILTER_VALIDATE_FLOAT);
        $due_date = trim(filter_input(INPUT_POST, 'due_date', FILTER_UNSAFE_RAW));
        $is_group_assignment = isset($_POST['is_group_assignment']) ? 1 : 0;
        $group_id = $is_group_assignment ? filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT) : null;
        $status = trim(filter_input(INPUT_POST, 'status', FILTER_UNSAFE_RAW));
        $tags = trim(filter_input(INPUT_POST, 'tags', FILTER_UNSAFE_RAW));
        $link_url = trim(filter_input(INPUT_POST, 'link_url', FILTER_UNSAFE_RAW));
        $questions_json = trim(filter_input(INPUT_POST, 'questions_json', FILTER_UNSAFE_RAW));
        $remove_file = isset($_POST['remove_file']) ? 1 : 0;

        // Validate required
        if (empty($title)) throw new Exception("Assignment title is required.");
        if (empty($type)) throw new Exception("Assignment type is required.");
        if ($max_points === false || $max_points < 0) throw new Exception("Maximum points must be a positive number.");
        $valid_statuses = ['draft', 'published', 'closed'];
        if (!in_array($status, $valid_statuses)) throw new Exception("Invalid assignment status.");

        // Validate due date
        if (!empty($due_date)) {
            $due_date_obj = DateTime::createFromFormat('Y-m-d', $due_date);
            if (!$due_date_obj || $due_date_obj->format('Y-m-d') !== $due_date) {
                throw new Exception("Invalid due date format. Use YYYY-MM-DD.");
            }
            if ($due_date < date('Y-m-d')) {
                throw new Exception("Due date cannot be in the past.");
            }
        }

        // Validate link URL
        if (!empty($link_url) && !filter_var($link_url, FILTER_VALIDATE_URL)) {
            throw new Exception("Invalid link URL.");
        }

        // Validate group assignment
        if ($is_group_assignment) {
            if (!$group_id) {
                throw new Exception("Please select a group for this assignment.");
            }
            // Verify group belongs to class
            $group_check = $conn->prepare("SELECT id FROM groups WHERE id = ? AND class_id = ? AND status = 'active'");
            $group_check->bind_param("ii", $group_id, $assignment['class_id']);
            $group_check->execute();
            $group_result = $group_check->get_result();
            if ($group_result->num_rows === 0) {
                throw new Exception("Selected group is invalid or inactive.");
            }
            $group_check->close();
        }

        // Validate questions JSON
        $questions_array = [];
        if (!empty($questions_json)) {
            $questions_array = json_decode($questions_json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($questions_array)) {
                throw new Exception("Invalid questions format.");
            }
            foreach ($questions_array as $q) {
                if (!isset($q['question_text']) || trim($q['question_text']) === '') {
                    throw new Exception("Each question must have text.");
                }
            }
        }
        // Store questions as JSON string (or NULL if empty)
        $questions_db = empty($questions_array) ? null : json_encode($questions_array);

        // File upload handling
        $file_path = $assignment['file_path']; // keep old by default
        if ($remove_file) {
            // Remove existing file from server if it exists
            if (!empty($assignment['file_path']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $assignment['file_path'])) {
                unlink($_SERVER['DOCUMENT_ROOT'] . $assignment['file_path']);
            }
            $file_path = null;
        } elseif (!empty($_FILES['file']['name'])) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/Assignments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            // Validate file size (max 10MB)
            if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
                throw new Exception("File size must be less than 10MB.");
            }
            // Validate file extension
            $allowed_extensions = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'png', 'zip'];
            $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("File type not allowed. Allowed: " . implode(', ', $allowed_extensions));
            }
            $file_name = time() . '_' . basename($_FILES['file']['name']);
            $upload_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $upload_path)) {
                // Remove old file if exists
                if (!empty($assignment['file_path']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $assignment['file_path'])) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $assignment['file_path']);
                }
                $file_path = '/Uploads/Assignments/' . $file_name;
            } else {
                throw new Exception("Failed to upload file.");
            }
        }

        // Update assignment
        $update_sql = "UPDATE assignments 
                       SET title = ?, description = ?, type = ?, max_points = ?, file_path = ?, 
                           due_date = ?, is_group_assignment = ?, group_id = ?, status = ?, 
                           tags = ?, link_url = ?, questions = ?, updated_at = NOW()
                       WHERE id = ? AND teacher_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $update_stmt->bind_param("sssdsdsissssi",
            $title, $description, $type, $max_points, $file_path,
            $due_date, $is_group_assignment, $group_id, $status,
            $tags, $link_url, $questions_db, $assignment_id, $teacher_id
        );
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update assignment: " . $update_stmt->error);
        }
        $update_stmt->close();

        // Log activity
        logActivity($conn, $teacher_id, "Updated Assignment", "Assignment ID $assignment_id updated");

        $conn->commit();
        $_SESSION['success_msg'] = "Assignment updated successfully.";
        header("Location: /teacher/manage_assignments.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Assignment update error: " . $e->getMessage());
        $form_error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="<?= isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit assignment - QueensPa School">
    <title>Edit Assignment - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        success: {
                            500: '#10b981',
                            600: '#059669',
                        },
                        warning: {
                            500: '#f59e0b',
                            600: '#d97706',
                        },
                        danger: {
                            500: '#ef4444',
                            600: '#dc2626',
                        },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'slide-up': 'slideUp 0.4s ease-out',
                        'scale-in': 'scaleIn 0.3s ease-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        scaleIn: {
                            '0%': { transform: 'scale(0.95)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                    },
                },
            },
        };
    </script>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .status-badge {
            @apply inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium;
        }
        .status-published { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200; }
        .status-draft { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200; }
        .status-closed { @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300; }
        .btn-primary {
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            @apply shadow-lg;
        }
        .question-item {
            transition: all 0.2s;
        }
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .loading-overlay.hidden {
            display: none;
        }
        .invalid-feedback {
            display: none;
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .is-invalid {
            border-color: #ef4444 !important;
        }
        .is-invalid ~ .invalid-feedback {
            display: block;
        }
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            @apply bg-gray-100 dark:bg-gray-800 rounded;
        }
        ::-webkit-scrollbar-thumb {
            @apply bg-gray-400 dark:bg-gray-600 rounded;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800">
    <div id="loading-overlay" class="loading-overlay hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 flex flex-col items-center space-y-3">
            <i class="fas fa-spinner fa-spin text-primary-500 text-4xl"></i>
            <p class="text-gray-700 dark:text-gray-300">Processing...</p>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-center mb-8 animate-fade-in">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-800 dark:text-white flex items-center">
                <i class="fas fa-edit text-primary-500 mr-3"></i> Edit Assignment
            </h1>
            <div class="flex space-x-3 mt-4 md:mt-0">
                <button id="dark-mode-toggle" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center shadow-sm">
                    <i class="fas fa-moon mr-2"></i> <span id="dark-mode-text">Dark Mode</span>
                </button>
                <a href="/teacher/manage_assignments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($error_msg)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-slide-up dark:bg-red-900 dark:border-red-700 dark:text-red-200">
                <div><i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($error_msg) ?></div>
                <button class="close-alert text-red-500 hover:text-red-700 dark:text-red-300">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (isset($form_error)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-slide-up dark:bg-red-900 dark:border-red-700 dark:text-red-200">
                <div><i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($form_error) ?></div>
                <button class="close-alert text-red-500 hover:text-red-700">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-slide-up dark:bg-green-900 dark:border-green-700 dark:text-green-200">
                <div><i class="fas fa-check-circle mr-2"></i> <?= htmlspecialchars($_SESSION['success_msg']) ?></div>
                <button class="close-alert text-green-500 hover:text-green-700">&times;</button>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <?php if ($assignment !== null): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden animate-slide-up">
            <div class="bg-gradient-to-r from-primary-500 to-primary-700 px-6 py-4">
                <h2 class="text-xl font-semibold text-white">Assignment Details</h2>
                <p class="text-primary-100 text-sm">Edit the fields you want to change</p>
            </div>
            <form id="edit-form" method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                <input type="hidden" id="questions_json" name="questions_json">

                <!-- Non-editable info row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Department</label>
                        <div class="mt-1 text-gray-900 dark:text-white"><?= htmlspecialchars($assignment['department_name']) ?></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Class</label>
                        <div class="mt-1 text-gray-900 dark:text-white"><?= htmlspecialchars($assignment['class_name']) ?></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Subject</label>
                        <div class="mt-1 text-gray-900 dark:text-white"><?= htmlspecialchars($assignment['subject_name']) ?></div>
                    </div>
                </div>

                <!-- Editable fields -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Title *</label>
                        <input type="text" name="title" id="title" required
                               value="<?= htmlspecialchars($assignment['title']) ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <div class="invalid-feedback">Title is required.</div>
                    </div>
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Type *</label>
                        <select name="type" id="type" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <option value="quiz" <?= $assignment['type'] === 'quiz' ? 'selected' : '' ?>>Quiz</option>
                            <option value="test" <?= $assignment['type'] === 'test' ? 'selected' : '' ?>>Test</option>
                            <option value="homework" <?= $assignment['type'] === 'homework' ? 'selected' : '' ?>>Homework</option>
                            <option value="classwork" <?= $assignment['type'] === 'classwork' ? 'selected' : '' ?>>Classwork</option>
                            <option value="group_work" <?= $assignment['type'] === 'group_work' ? 'selected' : '' ?>>Group Work</option>
                            <option value="project" <?= $assignment['type'] === 'project' ? 'selected' : '' ?>>Project</option>
                            <option value="exam" <?= $assignment['type'] === 'exam' ? 'selected' : '' ?>>Exam</option>
                        </select>
                        <div class="invalid-feedback">Type is required.</div>
                    </div>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                    <textarea name="description" id="description" rows="4"
                              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"><?= htmlspecialchars($assignment['description']) ?></textarea>
                </div>

                <!-- Questions -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Questions (Optional)</label>
                    <div id="questions-container" class="space-y-2">
                        <?php
                        $questions = json_decode($assignment['questions'] ?? '', true);
                        if (is_array($questions) && !empty($questions)) {
                            foreach ($questions as $q) {
                                $text = htmlspecialchars($q['question_text'] ?? '');
                                echo '<div class="question-item flex items-center space-x-2">';
                                echo '<input type="text" class="question-input flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Enter question text" value="' . $text . '">';
                                echo '<button type="button" class="remove-question bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg"><i class="fas fa-trash"></i></button>';
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                    <button type="button" id="add-question" class="mt-3 bg-success-500 hover:bg-success-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center">
                        <i class="fas fa-plus mr-2"></i> Add Question
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="max_points" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Max Points *</label>
                        <input type="number" name="max_points" id="max_points" step="0.01" min="0" required
                               value="<?= htmlspecialchars($assignment['max_points']) ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <div class="invalid-feedback">Max points must be a positive number.</div>
                    </div>
                    <div>
                        <label for="due_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Due Date</label>
                        <input type="date" name="due_date" id="due_date"
                               value="<?= htmlspecialchars($assignment['due_date']) ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <div class="invalid-feedback">Invalid date.</div>
                    </div>
                </div>

                <div>
                    <label for="file" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Attachment (Optional)</label>
                    <input type="file" name="file" id="file" accept=".pdf,.doc,.docx,.txt,.jpg,.png,.zip"
                           class="mt-1 block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-gray-700 dark:file:text-gray-200">
                    <?php if ($assignment['file_path']): ?>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Current: <a href="<?= htmlspecialchars($assignment['file_path']) ?>" target="_blank" class="text-primary-500 hover:underline"><?= htmlspecialchars(basename($assignment['file_path'])) ?></a></p>
                        <div class="mt-2 flex items-center">
                            <input type="checkbox" name="remove_file" id="remove_file" value="1" class="h-4 w-4 text-danger-500 focus:ring-danger-500 border-gray-300 rounded">
                            <label for="remove_file" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">Remove current file</label>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="link_url" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Link URL (Optional)</label>
                        <input type="url" name="link_url" id="link_url"
                               value="<?= htmlspecialchars($assignment['link_url']) ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                               placeholder="https://...">
                        <div class="invalid-feedback">Please enter a valid URL.</div>
                    </div>
                    <div>
                        <label for="tags" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tags (comma separated)</label>
                        <input type="text" name="tags" id="tags"
                               value="<?= htmlspecialchars($assignment['tags']) ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                               placeholder="math, algebra, quiz">
                    </div>
                </div>

                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <input type="checkbox" name="is_group_assignment" id="is_group_assignment" <?= $assignment['is_group_assignment'] ? 'checked' : '' ?> class="h-4 w-4 text-primary-500 focus:ring-primary-500 border-gray-300 rounded">
                        <label for="is_group_assignment" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">This is a group assignment</label>
                    </div>
                    <div class="flex-1 max-w-xs">
                        <label for="group_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Group</label>
                        <select name="group_id" id="group_id" disabled
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <option value="">Select group</option>
                        </select>
                        <div class="invalid-feedback">Please select a group.</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
                        <select name="status" id="status" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <option value="draft" <?= $assignment['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= $assignment['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="closed" <?= $assignment['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Term (Read-only)</label>
                        <div class="mt-1 text-gray-900 dark:text-white"><?= htmlspecialchars($assignment['term_name'] ?? 'Not set') ?> (<?= htmlspecialchars($assignment['academic_year_label'] ?? '') ?>)</div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 pt-4">
                    <a href="/teacher/manage_assignments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all">Cancel</a>
                    <button type="submit" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center">
                        <i class="fas fa-save mr-2"></i> Update Assignment
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
            <!-- Show error message if assignment not found or invalid ID -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-8 text-center">
                <i class="fas fa-exclamation-triangle text-5xl text-yellow-500 mb-4"></i>
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">Assignment Not Found</h2>
                <p class="text-gray-600 dark:text-gray-300 mb-6"><?= htmlspecialchars($error_msg ?? 'The assignment you are trying to edit does not exist or you do not have permission.') ?></p>
                <a href="/teacher/manage_assignments.php" class="bg-primary-500 hover:bg-primary-600 text-white px-6 py-2 rounded-lg inline-flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Manage Assignments
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ?>
    <?php ob_end_flush(); ?>

    <script>
        $(document).ready(function() {
            // Dark mode toggle
            const darkModeToggle = $('#dark-mode-toggle');
            const html = $('html');
            function updateDarkModeUI() {
                const isDark = html.hasClass('dark');
                darkModeToggle.html(isDark ? '<i class="fas fa-sun mr-2"></i> Light Mode' : '<i class="fas fa-moon mr-2"></i> Dark Mode');
            }
            if (localStorage.getItem('darkMode') === 'enabled') {
                html.addClass('dark');
            }
            updateDarkModeUI();
            darkModeToggle.on('click', function() {
                html.toggleClass('dark');
                const isDark = html.hasClass('dark');
                localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
                updateDarkModeUI();
            });

            // Auto-dismiss alerts
            $('.close-alert').on('click', function() { $(this).closest('[class*="bg-"]').fadeOut(300); });
            setTimeout(() => $('[class*="bg-"]:has(.close-alert)').fadeOut(500), 5000);

            // Only run if assignment exists
            <?php if ($assignment !== null): ?>
            // Questions management
            let questionCount = <?= count(json_decode($assignment['questions'] ?? '', true) ?: []) ?>;
            const questionsContainer = $('#questions-container');
            function addQuestion(text = '') {
                questionCount++;
                const qDiv = $(`<div class="question-item flex items-center space-x-2">
                    <input type="text" class="question-input flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Enter question text" value="${text.replace(/"/g, '&quot;')}">
                    <button type="button" class="remove-question bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg"><i class="fas fa-trash"></i></button>
                </div>`);
                questionsContainer.append(qDiv);
                qDiv.find('.remove-question').on('click', () => qDiv.remove());
            }
            $('#add-question').on('click', () => addQuestion());
            $('.remove-question').on('click', function() { $(this).closest('.question-item').remove(); });

            // Group assignment logic
            const isGroupCheck = $('#is_group_assignment');
            const groupSelect = $('#group_id');
            const classId = <?= (int)$assignment['class_id'] ?>;
            function loadGroups() {
                if (!classId) return;
                groupSelect.prop('disabled', true);
                groupSelect.html('<option value="">Loading...</option>');
                $.ajax({
                    url: window.location.href,
                    type: 'GET',
                    data: { action: 'get_groups', class_id: classId },
                    dataType: 'json',
                    success: function(res) {
                        if (res.error) {
                            groupSelect.html('<option value="">Error loading groups</option>');
                            return;
                        }
                        let options = '<option value="">Select group</option>';
                        $.each(res.groups, function(i, group) {
                            const selected = group.id == <?= (int)$assignment['group_id'] ?> ? 'selected' : '';
                            options += `<option value="${group.id}" ${selected}>${escapeHtml(group.name)}</option>`;
                        });
                        groupSelect.html(options);
                        groupSelect.prop('disabled', !isGroupCheck.is(':checked'));
                    },
                    error: function() {
                        groupSelect.html('<option value="">Failed to load groups</option>');
                    }
                });
            }
            loadGroups();
            isGroupCheck.on('change', function() {
                groupSelect.prop('disabled', !this.checked);
                if (!this.checked) groupSelect.val('');
            });

            // Form validation
            const form = $('#edit-form');
            function validateForm() {
                let valid = true;
                const title = $('#title').val().trim();
                if (!title) { $('#title').addClass('is-invalid'); valid = false; } else { $('#title').removeClass('is-invalid'); }
                const type = $('#type').val();
                if (!type) { $('#type').addClass('is-invalid'); valid = false; } else { $('#type').removeClass('is-invalid'); }
                const maxPoints = parseFloat($('#max_points').val());
                if (isNaN(maxPoints) || maxPoints < 0) { $('#max_points').addClass('is-invalid'); valid = false; } else { $('#max_points').removeClass('is-invalid'); }
                const dueDate = $('#due_date').val();
                if (dueDate && new Date(dueDate) < new Date(new Date().toISOString().split('T')[0])) {
                    $('#due_date').addClass('is-invalid');
                    valid = false;
                } else {
                    $('#due_date').removeClass('is-invalid');
                }
                const linkUrl = $('#link_url').val();
                if (linkUrl && !isValidUrl(linkUrl)) {
                    $('#link_url').addClass('is-invalid');
                    valid = false;
                } else {
                    $('#link_url').removeClass('is-invalid');
                }
                if (isGroupCheck.is(':checked') && !groupSelect.val()) {
                    groupSelect.addClass('is-invalid');
                    valid = false;
                } else {
                    groupSelect.removeClass('is-invalid');
                }
                return valid;
            }

            function isValidUrl(string) {
                try {
                    new URL(string);
                    return true;
                } catch (_) {
                    return false;
                }
            }

            // Collect questions JSON before submit
            form.on('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return;
                }
                const questions = [];
                $('.question-input').each(function() {
                    const text = $(this).val().trim();
                    if (text) questions.push({ question_text: text });
                });
                $('#questions_json').val(questions.length ? JSON.stringify(questions) : '');
                $('#loading-overlay').removeClass('hidden');
            });

            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            <?php endif; ?>

            // Show loading overlay on any AJAX
            $(document).ajaxStart(() => $('#loading-overlay').removeClass('hidden'));
            $(document).ajaxStop(() => $('#loading-overlay').addClass('hidden'));
        });
    </script>
</body>
</html>