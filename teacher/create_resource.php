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

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch current term
$current_term_sql = "SELECT id FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
$current_term_id = 1; // Fallback term ID
if ($current_term_stmt) {
    $current_term_stmt->execute();
    $current_term = $current_term_stmt->get_result()->fetch_assoc();
    $current_term_id = $current_term['id'] ?? 1;
    $current_term_stmt->close();
} else {
    error_log("Prepare failed for current term query: " . $conn->error);
}

// Fetch current academic year - FIXED: changed is_current to is_active
$academic_year_sql = "SELECT id, year_label FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
$academic_year_stmt = $conn->prepare($academic_year_sql);
$current_academic_year_id = null;
$current_academic_year_label = date('Y') . '-' . (date('Y') + 1);
if ($academic_year_stmt) {
    $academic_year_stmt->execute();
    $ay_result = $academic_year_stmt->get_result()->fetch_assoc();
    if ($ay_result) {
        $current_academic_year_id    = $ay_result['id'];
        $current_academic_year_label = $ay_result['year_label'];
    }
    $academic_year_stmt->close();
} else {
    error_log("Prepare failed for academic year query: " . $conn->error);
}

// ─── Allowed resource types (mirrors the DB ENUM) ────────────────────────────
$valid_resource_types = [
    'document', 'video', 'audio', 'link', 'interactive',
    'image', 'pdf', 'worksheet', 'presentation', 'quiz'
];

// ─── Allowed file MIME types and extensions ───────────────────────────────────
$allowed_file_types = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
    'application/msword'                                                    => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel'                                              => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'    => 'xlsx',
    'application/vnd.ms-powerpoint'                                         => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'audio/mpeg'      => 'mp3',
    'audio/wav'       => 'wav',
    'audio/ogg'       => 'ogg',
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/ogg'       => 'ogv',
    'text/plain'      => 'txt',
];
$max_file_size = 20 * 1024 * 1024; // 20 MB

// ─── Handle POST (create or edit) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // CSRF check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            error_log("CSRF token validation failed");
            throw new Exception("Invalid CSRF token. Please refresh the page and try again.");
        }

        $post_action = trim(filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'create');

        // ── Shared field validation ──────────────────────────────────────────
        $title         = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $description   = trim($_POST['description'] ?? '');
        $resource_type = trim(filter_input(INPUT_POST, 'resource_type', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $url           = trim(filter_input(INPUT_POST, 'url', FILTER_SANITIZE_URL) ?? '');
        $tags          = trim(filter_input(INPUT_POST, 'tags', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $access_level  = trim(filter_input(INPUT_POST, 'access_level', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'class');
        $ghanaian_content = isset($_POST['ghanaian_content']) ? 1 : 0;
        $subject_id    = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT) ?: null;
        $class_id      = filter_input(INPUT_POST, 'class_id',   FILTER_VALIDATE_INT) ?: null;
        $term_id       = filter_input(INPUT_POST, 'term_id',    FILTER_VALIDATE_INT) ?: $current_term_id;
        $academic_year = trim(filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_SPECIAL_CHARS) ?? $current_academic_year_label);
        $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT) ?: $current_academic_year_id;

        // Required fields
        if (empty($title)) {
            throw new Exception("Resource title is required.");
        }
        if (strlen($title) > 255) {
            throw new Exception("Resource title must not exceed 255 characters.");
        }
        if (!in_array($resource_type, $valid_resource_types, true)) {
            throw new Exception("Invalid resource type selected.");
        }
        if (!in_array($access_level, ['public', 'class', 'private'], true)) {
            throw new Exception("Invalid access level.");
        }

        // URL validation when provided
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception("The URL provided is not valid.");
        }

        // Tags length guard
        if (strlen($tags) > 255) {
            throw new Exception("Tags must not exceed 255 characters.");
        }

        // ── File upload handling ─────────────────────────────────────────────
        $file_path = '';

        if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['file'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $upload_errors = [
                    UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the server's maximum allowed size.",
                    UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the form's maximum allowed size.",
                    UPLOAD_ERR_PARTIAL    => "The file was only partially uploaded. Please try again.",
                    UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder on the server.",
                    UPLOAD_ERR_CANT_WRITE => "Failed to write the file to disk.",
                    UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the upload.",
                ];
                throw new Exception($upload_errors[$file['error']] ?? "An unknown upload error occurred.");
            }

            if ($file['size'] > $max_file_size) {
                throw new Exception("File size exceeds the maximum allowed limit of 20 MB.");
            }

            // Verify MIME type using finfo (not relying solely on $_FILES['type'])
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mime     = $finfo->file($file['tmp_name']);
            if (!array_key_exists($mime, $allowed_file_types)) {
                throw new Exception("File type '$mime' is not allowed. Please upload a document, image, audio, or video file.");
            }

            // Build a safe filename
            $ext           = $allowed_file_types[$mime];
            $safe_filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $upload_dir    = $_SERVER['DOCUMENT_ROOT'] . '/uploads/resources/';

            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Could not create upload directory. Please contact the administrator.");
                }
            }

            $destination = $upload_dir . $safe_filename;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception("Failed to save the uploaded file. Please try again.");
            }
            $file_path = '/uploads/resources/' . $safe_filename;
        }

        // At least one of URL or file must be supplied (except for quiz/interactive types
        // where the content might be purely described)
        if (empty($url) && empty($file_path) && !in_array($resource_type, ['quiz', 'interactive'], true)) {
            throw new Exception("Please provide either a URL or upload a file for this resource.");
        }

        // ── CREATE action ────────────────────────────────────────────────────
        if ($post_action === 'create') {
            $insert_sql = "INSERT INTO learning_resources
                            (title, description, resource_type, file_path, url,
                             subject_id, class_id, teacher_id, term_id, academic_year,
                             access_level, tags, is_approved, created_by, ghanaian_content, academic_year_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            if (!$insert_stmt) {
                throw new Exception("System error: Unable to prepare resource insert. " . $conn->error);
            }
            // 15 variables for 15 placeholders (the 0 is hardcoded, not a placeholder)
            $insert_stmt->bind_param(
                "sssssiiisissiii",
                $title, $description, $resource_type, $file_path, $url,
                $subject_id, $class_id, $teacher_id, $term_id, $academic_year,
                $access_level, $tags, $teacher_id, $ghanaian_content, $academic_year_id
            );
            if (!$insert_stmt->execute()) {
                throw new Exception("System error: Could not save resource. " . $insert_stmt->error);
            }
            $new_resource_id = $insert_stmt->insert_id;
            $insert_stmt->close();

            logActivity($conn, $teacher_id, "Created Resource", "Resource '$title' (ID $new_resource_id) created");
            $_SESSION['success_msg'] = "Resource \"" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "\" created successfully. It will be visible after approval.";
            
        // ── EDIT action ──────────────────────────────────────────────────────
        } elseif ($post_action === 'edit') {
            $resource_id = filter_input(INPUT_POST, 'resource_id', FILTER_VALIDATE_INT);
            if (!$resource_id) {
                throw new Exception("Invalid resource ID.");
            }

            // Verify ownership
            $verify_sql  = "SELECT id, file_path FROM learning_resources WHERE id = ? AND teacher_id = ?";
            $verify_stmt = $conn->prepare($verify_sql);
            $verify_stmt->bind_param("ii", $resource_id, $teacher_id);
            $verify_stmt->execute();
            $existing = $verify_stmt->get_result()->fetch_assoc();
            $verify_stmt->close();

            if (!$existing) {
                throw new Exception("You do not have permission to edit this resource.");
            }

            // Keep old file_path if no new file was uploaded
            if (empty($file_path)) {
                $file_path = $existing['file_path'] ?? '';
            } else {
                // Delete the old file if a new one was uploaded successfully
                $old_file_abs = $_SERVER['DOCUMENT_ROOT'] . $existing['file_path'];
                if (!empty($existing['file_path']) && file_exists($old_file_abs)) {
                    @unlink($old_file_abs);
                }
            }

            $update_sql = "UPDATE learning_resources
                           SET title = ?, description = ?, resource_type = ?,
                               file_path = ?, url = ?, subject_id = ?, class_id = ?,
                               term_id = ?, academic_year = ?, access_level = ?,
                               tags = ?, ghanaian_content = ?, academic_year_id = ?
                           WHERE id = ? AND teacher_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) {
                throw new Exception("System error: Unable to prepare resource update. " . $conn->error);
            }
            $update_stmt->bind_param(
                "sssssiiississiii",
                $title, $description, $resource_type,
                $file_path, $url, $subject_id, $class_id,
                $term_id, $academic_year, $access_level,
                $tags, $ghanaian_content, $academic_year_id,
                $resource_id, $teacher_id
            );
            if (!$update_stmt->execute()) {
                throw new Exception("System error: Could not update resource. " . $update_stmt->error);
            }
            $update_stmt->close();

            logActivity($conn, $teacher_id, "Edited Resource", "Resource '$title' (ID $resource_id) updated");
            $_SESSION['success_msg'] = "Resource \"" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "\" updated successfully.";

        } else {
            throw new Exception("Invalid action specified.");
        }

        // Rotate CSRF token after successful action
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $conn->commit();
        header("Location: /teacher/manage_resources.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("create_resource error: " . $e->getMessage());
        $_SESSION['error_msg'] = $e->getMessage();
        // If a file was uploaded before the DB failed, clean it up
        if (!empty($file_path)) {
            $abs = $_SERVER['DOCUMENT_ROOT'] . $file_path;
            if (file_exists($abs)) @unlink($abs);
        }
        // Fall through to re-render the form with the error
    }
}

// ─── Fetch subjects for this teacher's department ─────────────────────────────
$subjects_sql = "SELECT id, subject_name, subject_code FROM subjects
                 WHERE department_id = ? AND status = 'Active'
                 ORDER BY subject_name ASC";
$subjects_stmt = $conn->prepare($subjects_sql);
$subjects = [];
if ($subjects_stmt) {
    $subjects_stmt->bind_param("i", $teacher_department_id);
    $subjects_stmt->execute();
    $subjects = $subjects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $subjects_stmt->close();
} else {
    error_log("Prepare failed for subjects query: " . $conn->error);
}

// ─── Fetch classes for this teacher ───────────────────────────────────────────
$classes_sql = "SELECT DISTINCT c.id, c.class_name
                FROM classes c
                LEFT JOIN class_schedules cs ON c.id = cs.class_id
                WHERE c.department_id = ?
                  AND (c.teacher_id = ? OR cs.teacher_id = ?)
                ORDER BY c.class_name ASC";
$classes_stmt = $conn->prepare($classes_sql);
$classes = [];
if ($classes_stmt) {
    $classes_stmt->bind_param("iii", $teacher_department_id, $teacher_id, $teacher_id);
    $classes_stmt->execute();
    $classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $classes_stmt->close();
} else {
    error_log("Prepare failed for classes query: " . $conn->error);
}

// ─── Fetch all available terms ────────────────────────────────────────────────
$terms_sql = "SELECT id, term_name FROM academic_calendar ORDER BY start_date DESC";
$terms_stmt = $conn->prepare($terms_sql);
$terms = [];
if ($terms_stmt) {
    $terms_stmt->execute();
    $terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $terms_stmt->close();
} else {
    error_log("Prepare failed for terms query: " . $conn->error);
}

// ─── Fetch all academic years ─────────────────────────────────────────────────
$ay_sql = "SELECT id, year_label, is_active FROM academic_years ORDER BY year_label DESC";
$ay_stmt = $conn->prepare($ay_sql);
$academic_years = [];
if ($ay_stmt) {
    $ay_stmt->execute();
    $academic_years = $ay_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ay_stmt->close();
} else {
    error_log("Prepare failed for academic years query: " . $conn->error);
}

// Log page access
logActivity($conn, $teacher_id, "Accessed Create Resource Page", "Teacher accessed the create resource page");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create a new learning resource for QueensPa School.">
    <meta name="keywords" content="QueensPa School, create resource, teacher dashboard, learning materials">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Create Resource - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer">

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
                            500: '#0288d1',
                            600: '#0270a8',
                        },
                        accent: {
                            500: '#4caf50',
                            600: '#43a047',
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
        };
    </script>

    <style>
        body { font-family: 'Poppins', sans-serif; line-height: 1.6; }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.1);
            border-color: #0288d1;
            outline: none;
        }
        .btn-primary, .btn-accent {
            transition: transform 0.2s, background-color 0.3s;
        }
        .btn-primary:hover, .btn-accent:hover { transform: translateY(-1px); }
        .card-section {
            transition: box-shadow 0.2s;
        }
        .card-section:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        /* File drop zone */
        #drop-zone {
            transition: background-color 0.2s, border-color 0.2s;
        }
        #drop-zone.drag-over {
            background-color: #e0f2fe;
            border-color: #0288d1;
        }
        /* Hide URL / file sections depending on resource type */
        .url-field, .file-field { display: none; }
        /* Character counter */
        .char-counter { font-size: 0.75rem; color: #6b7280; text-align: right; }
        .char-counter.warn { color: #ef4444; }
    </style>
</head>
<body class="min-h-screen bg-gray-50">

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-white text-5xl"></i>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="container mx-auto px-4 py-12 max-w-4xl animate-slide-up">

        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row justify-between items-center mb-10">
            <h1 class="text-3xl font-bold text-gray-800 flex items-center">
                <i class="fas fa-plus-circle text-primary-500 mr-3"></i> Create New Resource
            </h1>
            <a href="/teacher/manage_resources.php"
               class="mt-4 sm:mt-0 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium btn-primary">
                <i class="fas fa-arrow-left mr-2"></i> Back to Resources
            </a>
        </div>

        <!-- Flash messages -->
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-start">
                <i class="fas fa-exclamation-circle mr-2 mt-0.5 flex-shrink-0"></i>
                <span><?= htmlspecialchars($_SESSION['error_msg'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['success_msg'], ENT_QUOTES, 'UTF-8') ?>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form id="create-form" action="/teacher/create_resource.php" method="POST"
              enctype="multipart/form-data" novalidate>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">

            <!-- ── Section 1: Basic Info ─────────────────────────────────── -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6 card-section">
                <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i> Basic Information
                </h2>

                <!-- Title -->
                <div class="mb-4">
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">
                        Resource Title <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="title" name="title"
                           class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg"
                           maxlength="255"
                           value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="e.g. Introduction to Fractions"
                           required>
                    <p class="char-counter" id="title-counter">0 / 255</p>
                </div>

                <!-- Description -->
                <div class="mb-4">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                        Description <span class="text-gray-400">(optional)</span>
                    </label>
                    <textarea id="description" name="description" rows="4"
                              class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg"
                              placeholder="Briefly describe what this resource covers…"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <!-- Resource Type -->
                <div class="mb-4">
                    <label for="resource_type" class="block text-sm font-medium text-gray-700 mb-1">
                        Resource Type <span class="text-red-500">*</span>
                    </label>
                    <select id="resource_type" name="resource_type"
                            class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                        <option value="">— Select a type —</option>
                        <option value="document"     <?= ($_POST['resource_type'] ?? '') === 'document'     ? 'selected' : '' ?>>Document</option>
                        <option value="video"        <?= ($_POST['resource_type'] ?? '') === 'video'        ? 'selected' : '' ?>>Video</option>
                        <option value="audio"        <?= ($_POST['resource_type'] ?? '') === 'audio'        ? 'selected' : '' ?>>Audio</option>
                        <option value="link"         <?= ($_POST['resource_type'] ?? '') === 'link'         ? 'selected' : '' ?>>Link (URL only)</option>
                        <option value="interactive"  <?= ($_POST['resource_type'] ?? '') === 'interactive'  ? 'selected' : '' ?>>Interactive</option>
                        <option value="image"        <?= ($_POST['resource_type'] ?? '') === 'image'        ? 'selected' : '' ?>>Image</option>
                        <option value="pdf"          <?= ($_POST['resource_type'] ?? '') === 'pdf'          ? 'selected' : '' ?>>PDF</option>
                        <option value="worksheet"    <?= ($_POST['resource_type'] ?? '') === 'worksheet'    ? 'selected' : '' ?>>Worksheet</option>
                        <option value="presentation" <?= ($_POST['resource_type'] ?? '') === 'presentation' ? 'selected' : '' ?>>Presentation</option>
                        <option value="quiz"         <?= ($_POST['resource_type'] ?? '') === 'quiz'         ? 'selected' : '' ?>>Quiz</option>
                    </select>
                    <p id="type-hint" class="text-xs text-gray-500 mt-1 hidden"></p>
                </div>

                <!-- URL field (shown when type = link / video) -->
                <div id="url-section" class="mb-4 url-field">
                    <label for="url" class="block text-sm font-medium text-gray-700 mb-1">
                        URL <span id="url-required-badge" class="text-red-500 hidden">*</span>
                        <span class="text-gray-400">(optional for non-link types)</span>
                    </label>
                    <input type="url" id="url" name="url"
                           class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg"
                           value="<?= htmlspecialchars($_POST['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="https://example.com/resource">
                    <p class="text-xs text-gray-500 mt-1">Supports YouTube, Google Drive, FreeCodeCamp, or any public URL.</p>
                </div>

                <!-- File upload (shown for file-based types) -->
                <div id="file-section" class="mb-4 file-field">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Upload File <span id="file-required-badge" class="text-red-500 hidden">*</span>
                        <span class="text-gray-400">(optional if URL provided)</span>
                    </label>
                    <!-- Drop zone -->
                    <div id="drop-zone"
                         class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer">
                        <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                        <p class="text-sm text-gray-500">Drag &amp; drop your file here, or <span class="text-primary-500 font-medium">browse</span></p>
                        <p class="text-xs text-gray-400 mt-1">Max 20 MB · PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, PNG, GIF, WEBP, MP3, WAV, MP4, WEBM</p>
                        <input type="file" id="file" name="file" class="hidden"
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.mp3,.wav,.ogg,.mp4,.webm,.ogv,.txt">
                    </div>
                    <div id="file-preview" class="mt-3 hidden">
                        <div class="flex items-center bg-blue-50 border border-blue-200 rounded-lg px-4 py-2">
                            <i class="fas fa-file text-primary-500 mr-2"></i>
                            <span id="file-name" class="text-sm text-gray-700 flex-1 truncate"></span>
                            <span id="file-size" class="text-xs text-gray-500 ml-2"></span>
                            <button type="button" id="remove-file" class="ml-3 text-red-400 hover:text-red-600 text-sm">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section 2: Classification ────────────────────────────── -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6 card-section">
                <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-tags text-primary-500 mr-2"></i> Classification
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <!-- Subject -->
                    <div>
                        <label for="subject_id" class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                        <select id="subject_id" name="subject_id"
                                class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="">— No subject —</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int)$subject['id'] ?>"
                                    <?= ((int)($_POST['subject_id'] ?? 0)) === (int)$subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['subject_name'] . ' (' . $subject['subject_code'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Class -->
                    <div>
                        <label for="class_id" class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                        <select id="class_id" name="class_id"
                                class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="">— No specific class —</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int)$class['id'] ?>"
                                    <?= ((int)($_POST['class_id'] ?? 0)) === (int)$class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Term -->
                    <div>
                        <label for="term_id" class="block text-sm font-medium text-gray-700 mb-1">Term</label>
                        <select id="term_id" name="term_id"
                                class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <?php if (empty($terms)): ?>
                                <option value="<?= $current_term_id ?>">Current Term</option>
                            <?php else: ?>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= (int)$term['id'] ?>"
                                        <?= ((int)($_POST['term_id'] ?? $current_term_id)) === (int)$term['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($term['term_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Academic Year -->
                    <div>
                        <label for="academic_year_id" class="block text-sm font-medium text-gray-700 mb-1">Academic Year</label>
                        <?php if (!empty($academic_years)): ?>
                            <select id="academic_year_id" name="academic_year_id"
                                    class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <?php foreach ($academic_years as $ay): ?>
                                    <option value="<?= (int)$ay['id'] ?>"
                                            data-label="<?= htmlspecialchars($ay['year_label'], ENT_QUOTES, 'UTF-8') ?>"
                                        <?= ((int)($_POST['academic_year_id'] ?? $current_academic_year_id)) === (int)$ay['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ay['year_label'], ENT_QUOTES, 'UTF-8') ?>
                                        <?= $ay['is_active'] ? '(Current)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" id="academic_year" name="academic_year"
                                   class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg"
                                   value="<?= htmlspecialchars($_POST['academic_year'] ?? $current_academic_year_label, ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="e.g. 2025-2026">
                        <?php endif; ?>
                        <!-- Hidden fallback for academic_year string when using the dropdown -->
                        <input type="hidden" id="academic_year" name="academic_year"
                               value="<?= htmlspecialchars($_POST['academic_year'] ?? $current_academic_year_label, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <!-- Tags -->
                <div class="mt-4">
                    <label for="tags" class="block text-sm font-medium text-gray-700 mb-1">
                        Tags <span class="text-gray-400">(optional, comma-separated)</span>
                    </label>
                    <input type="text" id="tags" name="tags"
                           class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg"
                           maxlength="255"
                           value="<?= htmlspecialchars($_POST['tags'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="e.g. fractions, grade 4, numeracy">
                    <p class="char-counter" id="tags-counter">0 / 255</p>
                </div>
            </div>

            <!-- ── Section 3: Access & Settings ─────────────────────────── -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6 card-section">
                <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-lock text-primary-500 mr-2"></i> Access &amp; Settings
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <!-- Access Level -->
                    <div>
                        <label for="access_level" class="block text-sm font-medium text-gray-700 mb-1">
                            Access Level <span class="text-red-500">*</span>
                        </label>
                        <select id="access_level" name="access_level"
                                class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                            <option value="class"   <?= ($_POST['access_level'] ?? 'class') === 'class'   ? 'selected' : '' ?>>Class Only</option>
                            <option value="public"  <?= ($_POST['access_level'] ?? '') === 'public'  ? 'selected' : '' ?>>Public (All Students)</option>
                            <option value="private" <?= ($_POST['access_level'] ?? '') === 'private' ? 'selected' : '' ?>>Private (Only Me)</option>
                        </select>
                        <p id="access-hint" class="text-xs text-gray-500 mt-1">
                            Class Only: only students in the selected class can see this resource.
                        </p>
                    </div>

                    <!-- Ghanaian content flag -->
                    <div class="flex items-center pt-7">
                        <input type="checkbox" id="ghanaian_content" name="ghanaian_content"
                               class="h-4 w-4 text-primary-500 border-gray-300 rounded"
                               <?= !empty($_POST['ghanaian_content']) ? 'checked' : '' ?>>
                        <label for="ghanaian_content" class="ml-2 text-sm text-gray-700">
                            🇬🇭 Contains Ghanaian Content
                        </label>
                    </div>
                </div>
            </div>

            <!-- ── Submit ────────────────────────────────────────────────── -->
            <div class="flex flex-col sm:flex-row justify-end gap-3">
                <a href="/teacher/manage_resources.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium text-center btn-primary">
                    Cancel
                </a>
                <button type="submit" id="submit-btn"
                        class="bg-accent-500 hover:bg-accent-600 text-white px-6 py-2 rounded-lg text-sm font-medium btn-accent flex items-center justify-center">
                    <i class="fas fa-save mr-2"></i> Save Resource
                </button>
            </div>

        </form>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script>
    $(function () {

        // ── Resource-type hints ──────────────────────────────────────────────
        const typeHints = {
            document:     'Upload a Word document, text file, or similar.',
            video:        'Provide a YouTube/Vimeo URL or upload an MP4/WebM file.',
            audio:        'Upload an MP3, WAV, or OGG file.',
            link:         'Provide the full URL to an external website or resource.',
            interactive:  'Provide a URL to an interactive learning tool.',
            image:        'Upload a JPG, PNG, GIF, or WebP image.',
            pdf:          'Upload a PDF file.',
            worksheet:    'Upload a printable worksheet (PDF, DOCX, etc.).',
            presentation: 'Upload a PowerPoint (PPTX) or PDF presentation.',
            quiz:         'Provide a URL to an online quiz or upload a quiz document.',
        };

        // Types that REQUIRE a URL, types that REQUIRE a file, types that accept both
        const urlOnlyTypes  = ['link', 'video', 'interactive'];
        const fileOnlyTypes = ['audio', 'image'];
        const bothTypes     = ['document', 'pdf', 'worksheet', 'presentation', 'quiz'];

        function updateTypeUI(type) {
            const $urlSection  = $('#url-section');
            const $fileSection = $('#file-section');
            const $hint        = $('#type-hint');
            const $urlReq      = $('#url-required-badge');
            const $fileReq     = $('#file-required-badge');
            const $urlLabel    = $urlSection.find('label span.text-gray-400');
            const $fileLabel   = $fileSection.find('label span.text-gray-400');

            // Reset
            $urlSection.hide();
            $fileSection.hide();
            $urlReq.addClass('hidden');
            $fileReq.addClass('hidden');
            $('#url').prop('required', false);

            if (!type) { $hint.addClass('hidden'); return; }

            $hint.removeClass('hidden').text(typeHints[type] || '');

            if (urlOnlyTypes.includes(type)) {
                $urlSection.show();
                $urlReq.removeClass('hidden');
                $urlLabel.addClass('hidden');
                $('#url').prop('required', true);
            } else if (fileOnlyTypes.includes(type)) {
                $fileSection.show();
                $fileReq.removeClass('hidden');
                $fileLabel.addClass('hidden');
            } else if (bothTypes.includes(type)) {
                $urlSection.show();
                $fileSection.show();
            }
        }

        $('#resource_type').on('change', function () {
            updateTypeUI($(this).val());
        });
        // Trigger on page load to restore state after form re-render
        updateTypeUI($('#resource_type').val());

        // ── Access level hint ────────────────────────────────────────────────
        const accessHints = {
            'class':   'Class Only: only students in the selected class can see this resource.',
            'public':  'Public: all students across the school can see this resource.',
            'private': 'Private: only you can see this resource (useful as a draft).',
        };
        $('#access_level').on('change', function () {
            $('#access-hint').text(accessHints[$(this).val()] || '');
        });

        // ── Sync academic_year hidden field from dropdown ────────────────────
        $('#academic_year_id').on('change', function () {
            const label = $(this).find(':selected').data('label') || '';
            $('#academic_year').val(label);
        }).trigger('change');

        // ── Character counters ───────────────────────────────────────────────
        function charCounter(inputId, counterId, max) {
            const $input   = $('#' + inputId);
            const $counter = $('#' + counterId);
            $input.on('input', function () {
                const len = $(this).val().length;
                $counter.text(len + ' / ' + max);
                $counter.toggleClass('warn', len > max * 0.9);
            }).trigger('input');
        }
        charCounter('title', 'title-counter', 255);
        charCounter('tags',  'tags-counter',  255);

        // ── Drag & drop file upload ──────────────────────────────────────────
        const $dropZone = $('#drop-zone');
        const $fileInput = $('#file');

        $dropZone.on('click', function () { $fileInput.trigger('click'); });

        $dropZone.on('dragover dragenter', function (e) {
            e.preventDefault();
            $(this).addClass('drag-over');
        }).on('dragleave dragend drop', function (e) {
            e.preventDefault();
            $(this).removeClass('drag-over');
        }).on('drop', function (e) {
            const files = e.originalEvent.dataTransfer.files;
            if (files.length) {
                $fileInput[0].files = files;
                previewFile(files[0]);
            }
        });

        $fileInput.on('change', function () {
            if (this.files.length) previewFile(this.files[0]);
        });

        function previewFile(file) {
            $('#file-name').text(file.name);
            $('#file-size').text(formatBytes(file.size));
            $('#file-preview').removeClass('hidden');
            $dropZone.find('p:first').text('File selected ✓').addClass('text-accent-500');
        }

        $('#remove-file').on('click', function () {
            $fileInput.val('');
            $('#file-preview').addClass('hidden');
            $dropZone.find('p:first').text('Drag & drop your file here, or ').removeClass('text-accent-500');
        });

        function formatBytes(bytes) {
            if (bytes < 1024)       return bytes + ' B';
            if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // ── Client-side validation before submit ─────────────────────────────
        $('#create-form').on('submit', function (e) {
            const type   = $('#resource_type').val();
            const url    = $('#url').val().trim();
            const file   = $fileInput[0].files.length;
            const title  = $('#title').val().trim();
            let errors   = [];

            if (!title)  errors.push('Resource title is required.');
            if (!type)   errors.push('Please select a resource type.');

            if (urlOnlyTypes.includes(type) && !url) {
                errors.push('A URL is required for "' + type + '" resources.');
            }
            if (fileOnlyTypes.includes(type) && !file) {
                errors.push('A file upload is required for "' + type + '" resources.');
            }
            if (bothTypes.includes(type) && !url && !file) {
                errors.push('Please provide a URL or upload a file.');
            }

            if (errors.length) {
                e.preventDefault();
                const html = '<ul class="list-disc list-inside">' +
                    errors.map(m => '<li>' + m + '</li>').join('') + '</ul>';
                // Insert or update inline error block
                let $err = $('#inline-errors');
                if (!$err.length) {
                    $err = $('<div id="inline-errors" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-start"><i class="fas fa-exclamation-circle mr-2 mt-1 flex-shrink-0"></i><div></div></div>');
                    $('#create-form').prepend($err);
                }
                $err.find('div').html(html);
                $('html, body').animate({ scrollTop: 0 }, 300);
                return;
            }

            $('#loading-overlay').removeClass('hidden');
            $('#submit-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Saving…');
        });

        // Hide loading overlay on page load (handles back-button case)
        $('#loading-overlay').addClass('hidden');
    });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>