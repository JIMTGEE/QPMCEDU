<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// === SECURITY & AUTH ===
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Access denied.";
    header("Location: /login.php");
    exit();
}
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!$conn || mysqli_connect_error()) {
    $_SESSION['error_msg'] = "Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Verify teacher exists
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher'");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    $stmt->close();
    header("Location: /login.php");
    exit();
}
$stmt->close();

logActivity($conn, $teacher_id, "Page Access", "Create Assignment");

// CSRF Token (session-based)
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// =============================================================================
// AUTOMATIC TERM DETECTION - Using academic_calendar table
// =============================================================================
$active_term_id = null;
$active_term_name = '';
$active_academic_year = '';

// Find the current term where today's date falls between start_date and end_date
$current_date = date('Y-m-d');
$term_stmt = $conn->prepare("
    SELECT id, term_name, year
    FROM academic_calendar 
    WHERE start_date <= ? AND end_date >= ?
    ORDER BY start_date DESC
    LIMIT 1
");
$term_stmt->bind_param("ss", $current_date, $current_date);
$term_stmt->execute();
$term_res = $term_stmt->get_result();
if ($term_row = $term_res->fetch_assoc()) {
    $active_term_id = $term_row['id'];
    $active_term_name = $term_row['term_name'];
    $active_academic_year = $term_row['year'];
}
$term_stmt->close();

// If no current term found (e.g., during holidays), find the next upcoming term
if (!$active_term_id) {
    $next_term_stmt = $conn->prepare("
        SELECT id, term_name, year
        FROM academic_calendar 
        WHERE start_date > ?
        ORDER BY start_date ASC
        LIMIT 1
    ");
    $next_term_stmt->bind_param("s", $current_date);
    $next_term_stmt->execute();
    $next_res = $next_term_stmt->get_result();
    if ($next_row = $next_res->fetch_assoc()) {
        $active_term_id = $next_row['id'];
        $active_term_name = $next_row['term_name'];
        $active_academic_year = $next_row['year'];
    }
    $next_term_stmt->close();
}

// If still no term found, get the most recent term
if (!$active_term_id) {
    $fallback_stmt = $conn->prepare("
        SELECT id, term_name, year
        FROM academic_calendar 
        ORDER BY end_date DESC 
        LIMIT 1
    ");
    $fallback_stmt->execute();
    $fallback_res = $fallback_stmt->get_result();
    if ($fallback_row = $fallback_res->fetch_assoc()) {
        $active_term_id = $fallback_row['id'];
        $active_term_name = $fallback_row['term_name'];
        $active_academic_year = $fallback_row['year'];
    }
    $fallback_stmt->close();
}

// =============================================================================
// AJAX HANDLERS
// =============================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['error' => null];

    try {
        // Fetch teacher's classes & subjects
        if ($_GET['action'] === 'get_teacher_options') {
            // Classes from class_schedules
            $classes = [];
            $class_sql = "SELECT DISTINCT c.id, c.class_name
                          FROM class_schedules cs
                          JOIN classes c ON cs.class_id = c.id
                          WHERE cs.teacher_id = ?
                          ORDER BY c.class_name";
            $class_stmt = $conn->prepare($class_sql);
            $class_stmt->bind_param("i", $teacher_id);
            $class_stmt->execute();
            $class_res = $class_stmt->get_result();
            while ($row = $class_res->fetch_assoc()) {
                $classes[] = $row;
            }
            $class_stmt->close();

            // Fallback: classes where teacher is class teacher
            if (empty($classes)) {
                $class_sql2 = "SELECT id, class_name FROM classes WHERE class_teacher_id = ? ORDER BY class_name";
                $class_stmt2 = $conn->prepare($class_sql2);
                $class_stmt2->bind_param("i", $teacher_id);
                $class_stmt2->execute();
                $class_res2 = $class_stmt2->get_result();
                while ($row = $class_res2->fetch_assoc()) {
                    $classes[] = $row;
                }
                $class_stmt2->close();
            }

            // Subjects from class_schedules with department name
            $subjects = [];
            $subj_sql = "SELECT DISTINCT s.id, s.subject_name, d.name AS department_name
                         FROM class_schedules cs
                         JOIN subjects s ON cs.subject_id = s.id
                         LEFT JOIN departments d ON s.department_id = d.id
                         WHERE cs.teacher_id = ?
                         ORDER BY s.subject_name";
            $subj_stmt = $conn->prepare($subj_sql);
            $subj_stmt->bind_param("i", $teacher_id);
            $subj_stmt->execute();
            $subj_res = $subj_stmt->get_result();
            while ($row = $subj_res->fetch_assoc()) {
                $subjects[] = $row;
            }
            $subj_stmt->close();

            // Fallback: subjects where teacher is assigned directly
            if (empty($subjects)) {
                $subj_sql2 = "SELECT s.id, s.subject_name, d.name AS department_name
                              FROM subjects s
                              LEFT JOIN departments d ON s.department_id = d.id
                              WHERE s.teacher_id = ?
                              ORDER BY s.subject_name";
                $subj_stmt2 = $conn->prepare($subj_sql2);
                $subj_stmt2->bind_param("i", $teacher_id);
                $subj_stmt2->execute();
                $subj_res2 = $subj_stmt2->get_result();
                while ($row = $subj_res2->fetch_assoc()) {
                    $subjects[] = $row;
                }
                $subj_stmt2->close();
            }

            $response['classes'] = $classes;
            $response['subjects'] = $subjects;
        }

        // Fetch groups for a class
        if ($_GET['action'] === 'get_groups') {
            $class_id = (int)($_GET['class_id'] ?? 0);
            $groups = [];
            if ($class_id) {
                $group_sql = "SELECT id, name FROM `groups` WHERE class_id = ? AND status = 'active' ORDER BY name";
                $group_stmt = $conn->prepare($group_sql);
                $group_stmt->bind_param("i", $class_id);
                $group_stmt->execute();
                $group_res = $group_stmt->get_result();
                while ($row = $group_res->fetch_assoc()) {
                    $groups[] = $row;
                }
                $group_stmt->close();
            }
            $response['groups'] = $groups;
        }

        // Gemini AI: generate questions
        if ($_GET['action'] === 'generate_questions') {
            // CSRF validation
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception("Invalid security token.");
            }

            $subject_name = trim($_POST['subject_name'] ?? '');
            $topic = trim($_POST['topic'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $num_questions = (int)($_POST['num_questions'] ?? 5);
            if ($num_questions < 1) $num_questions = 1;
            if ($num_questions > 20) $num_questions = 20;

            // Build prompt
            $prompt = "Generate $num_questions questions for $subject_name.\n";
            if (!empty($topic)) $prompt .= "Topic: $topic.\n";
            if (!empty($description)) $prompt .= "Additional context: $description.\n";
            $prompt .= "Return only the questions as plain text, each on a new line. Do not number them.";

            // Gemini API call
            $api_key = $apiKey = getenv('GEMINI_API_KEY');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent";

            $payload = [
                "contents" => [
                    ["parts" => [["text" => $prompt]]]
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-goog-api-key: ' . $api_key
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("AI service unavailable. HTTP $httpCode");
            }

            $data = json_decode($result, true);
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $questions = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (strlen($line) > 5) {
                    $questions[] = $line;
                }
            }
            // Limit to requested number
            $questions = array_slice($questions, 0, $num_questions);
            $response['questions'] = $questions;
        }
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
        error_log("AJAX error: " . $e->getMessage());
    }
    echo json_encode($response);
    exit();
}

// =============================================================================
// FORM SUBMISSION
// =============================================================================
$error_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        if ($_POST['csrf_token'] !== $csrf_token) {
            throw new Exception("Invalid CSRF token.");
        }

        $class_id   = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $term_id    = (int)($_POST['term_id'] ?? 0); // Hidden field
        $title      = trim(htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES));
        $type       = $_POST['type'] ?? '';
        $max_points = max(0.01, (float)($_POST['max_points'] ?? 100));
        $due_date   = $_POST['due_date'] ?? null;
        $is_group   = isset($_POST['is_group_assignment']) ? 1 : 0;
        $group_id   = $is_group ? (int)($_POST['group_id'] ?? 0) : null;
        $status     = in_array($_POST['status'] ?? '', ['draft', 'published', 'closed']) ? $_POST['status'] : 'draft';
        $tags       = trim(htmlspecialchars($_POST['tags'] ?? '', ENT_QUOTES));
        $desc       = trim($_POST['description'] ?? '');
        $questions_json = $_POST['questions_json'] ?? '[]';

        // Validate required fields
        if (!$class_id || !$subject_id || !$term_id || !$title || !$type) {
            throw new Exception("Please fill all required fields.");
        }

        $valid_types = ['quiz','test','homework','classwork','group_work','project'];
        if (!in_array($type, $valid_types)) {
            throw new Exception("Invalid assignment type.");
        }

        if ($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            throw new Exception("Invalid due date format.");
        }

        if ($is_group && !$group_id) {
            throw new Exception("Please select a group for group assignment.");
        }

        $questions = json_decode($questions_json, true) ?: [];
        if ($questions_json !== '[]' && (!is_array($questions) || json_last_error() !== JSON_ERROR_NONE)) {
            throw new Exception("Invalid question data.");
        }

        // File upload
        $file_path = null;
        if (!empty($_FILES['file']['name'])) {
            $dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/Assignments/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowed));
            if ($_FILES['file']['size'] > 5*1024*1024) throw new Exception("File must be under 5MB.");

            $file_path = '/Uploads/Assignments/' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $_SERVER['DOCUMENT_ROOT'] . $file_path)) {
                throw new Exception("Failed to upload file.");
            }
        }

        // Link URL
        $link_url = trim($_POST['link_url'] ?? '');
        if ($link_url) {
            if (!filter_var($link_url, FILTER_VALIDATE_URL)) throw new Exception("Invalid URL.");
            if (strlen($link_url) > 500) throw new Exception("Link URL too long.");
        }

        // Insert assignment
        $sql = "INSERT INTO assignments 
                (class_id, subject_id, teacher_id, term_id, title, description, type, questions, max_points, file_path, link_url, is_group_assignment, group_id, status, tags, due_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iiiissssdsiiisss",
            $class_id, $subject_id, $teacher_id, $term_id,
            $title, $desc, $type, $questions_json, $max_points,
            $file_path, $link_url, $is_group, $group_id, $status, $tags, $due_date
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create assignment: " . $stmt->error);
        }

        $assignment_id = $conn->insert_id;
        $stmt->close();

        logActivity($conn, $teacher_id, "Assignment Created", "ID: $assignment_id");
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $conn->commit();

        $_SESSION['success_msg'] = "Assignment created successfully!";
        header("Location: /teacher/manage_assignments.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Assignment - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #eef2f6 100%);
        }

        /* Custom card styles */
        .card-modern {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(2px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.08);
            transition: all 0.2s ease;
        }

        .card-modern:hover {
            box-shadow: 0 24px 40px -16px rgba(0, 0, 0, 0.12);
        }

        /* Input focus effects */
        .input-fancy {
            transition: all 0.2s ease;
        }
        .input-fancy:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        /* Question item style */
        .question-item-modern {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            transition: all 0.2s;
        }
        .question-item-modern:hover {
            background: #fef9f4;
            border-color: #fed7aa;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #eef2f6;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #f97316;
        }

        /* Preview panel */
        .preview-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            transition: all 0.2s;
        }

        /* Drop zone */
        .dropzone-modern {
            transition: all 0.2s ease;
        }
        .dropzone-modern.drag-over {
            background-color: #fff7ed;
            border-color: #f97316;
        }

        /* Button hover animations */
        .btn-hover {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-hover:active {
            transform: scale(0.96);
        }

        /* Glow for AI section */
        .ai-glow {
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            border: 1px solid #fed7aa;
        }
    </style>
</head>
<body class="antialiased">

    <div id="loading-overlay" class="fixed inset-0 bg-white/90 backdrop-blur-sm z-50 flex justify-center items-center hidden transition-all duration-300">
        <div class="bg-orange-50 rounded-2xl p-6 shadow-xl flex items-center gap-3">
            <i class="fas fa-spinner fa-spin text-orange-500 text-2xl"></i>
            <span class="text-orange-700 font-medium">Processing...</span>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="content" id="content">
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            
            <!-- Page Header -->
            <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <div class="h-10 w-10 rounded-xl bg-orange-100 flex items-center justify-center">
                            <i class="fas fa-tasks text-orange-500 text-xl"></i>
                        </div>
                        <h1 class="text-3xl font-bold text-slate-800 tracking-tight">Create Assignment</h1>
                    </div>
                    <p class="text-slate-500 ml-14">Craft engaging assignments for your students with ease</p>
                </div>
                <div class="flex gap-3">
                    <button class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-xl text-sm font-medium transition-all btn-hover shadow-sm" id="theme-toggle-fake">
                        <i class="fas fa-palette mr-2 text-orange-400"></i> Light Mode
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($error_msg)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-5 py-4 rounded-xl mb-6 flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span><?= htmlspecialchars($error_msg) ?></span>
                    </div>
                    <button class="close-alert text-red-400 hover:text-red-600">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 px-5 py-4 rounded-xl mb-6 flex items-center justify-between shadow-sm">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-green-500"></i>
                        <span><?= htmlspecialchars($_SESSION['success_msg']) ?></span>
                    </div>
                    <button class="close-alert text-green-400 hover:text-green-600">&times;</button>
                    <?php unset($_SESSION['success_msg']); ?>
                </div>
            <?php endif; ?>

            <div class="grid lg:grid-cols-3 gap-8">
                <!-- Main Form Column (2/3 width) -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Term Info Card -->
                    <div class="card-modern rounded-2xl p-5 flex items-center gap-3 text-sm">
                        <i class="fas fa-calendar-alt text-orange-400 text-lg"></i>
                        <span class="text-slate-600">Assignments linked to active term: </span>
                        <span class="font-semibold text-slate-800 bg-orange-50 px-3 py-1 rounded-full text-xs"><?= htmlspecialchars($active_term_name) ?></span>
                        <?php if ($active_academic_year): ?>
                            <span class="text-slate-400">• <?= htmlspecialchars($active_academic_year) ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Main Form -->
                    <form id="assignment-form" enctype="multipart/form-data" method="POST" class="card-modern rounded-2xl p-6 space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" id="questions_json" name="questions_json" value="[]">
                        <input type="hidden" name="term_id" value="<?= $active_term_id ?>">

                        <!-- Class & Subject Row -->
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Class <span class="text-orange-500">*</span></label>
                                <select id="class_id" name="class_id" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" required>
                                    <option value="">Loading classes...</option>
                                </select>
                                <div id="class-loading" class="hidden text-orange-500 text-xs mt-1"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Subject <span class="text-orange-500">*</span></label>
                                <select id="subject_id" name="subject_id" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" required>
                                    <option value="">Loading subjects...</option>
                                </select>
                                <div id="subject-loading" class="hidden text-orange-500 text-xs mt-1"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                            </div>
                        </div>

                        <!-- Title & Type Row -->
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Assignment Title <span class="text-orange-500">*</span></label>
                                <input type="text" name="title" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" placeholder="e.g., Newton's Laws Quiz" required>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Type <span class="text-orange-500">*</span></label>
                                <select name="type" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" required>
                                    <option value="">Select Type</option>
                                    <option value="quiz">📝 Quiz</option>
                                    <option value="test">📚 Test</option>
                                    <option value="homework">🏠 Homework</option>
                                    <option value="classwork">🏫 Classwork</option>
                                    <option value="group_work">👥 Group Work</option>
                                    <option value="project">🚀 Project</option>
                                </select>
                            </div>
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Description</label>
                            <textarea name="description" id="description" rows="4" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" placeholder="Provide instructions, rubric, or additional context..."></textarea>
                            <div class="text-right text-xs text-slate-400 mt-1"><span id="desc-counter">0</span> characters</div>
                        </div>

                        <!-- AI Question Generator -->
                        <div class="ai-glow rounded-xl p-5">
                            <div class="flex items-center gap-2 mb-4">
                                <div class="h-8 w-8 rounded-full bg-orange-100 flex items-center justify-center">
                                    <i class="fas fa-robot text-orange-500 text-sm"></i>
                                </div>
                                <span class="font-bold text-slate-800">AI Question Generator</span>
                                <span class="text-xs bg-white/60 px-2 py-0.5 rounded-full text-slate-500 ml-2">Gemini AI</span>
                            </div>
                            <div class="grid md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Topic (optional)</label>
                                    <input type="text" id="ai_topic" class="w-full px-4 py-2 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" placeholder="e.g., Photosynthesis">
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Number of Questions (1-20)</label>
                                    <input type="number" id="ai_num" value="5" min="1" max="20" class="w-full px-4 py-2 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700">
                                </div>
                            </div>
                            <button type="button" id="ai-generate" class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-xl text-sm font-medium transition-all btn-hover shadow-sm">
                                <i class="fas fa-magic mr-2"></i> Generate Questions
                            </button>
                            <div id="ai-loading" class="hidden text-orange-600 mt-3 text-sm">Generating... <i class="fas fa-spinner fa-spin ml-1"></i></div>
                        </div>

                        <!-- Questions Section -->
                        <div>
                            <div class="flex justify-between items-center mb-3">
                                <label class="text-sm font-semibold text-slate-700">Questions Bank</label>
                                <div class="flex gap-2">
                                    <button type="button" id="add-question" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg text-xs font-medium transition-all">
                                        <i class="fas fa-plus mr-1"></i> Add Question
                                    </button>
                                    <button type="button" id="clear-questions" class="bg-slate-100 hover:bg-red-50 text-slate-600 hover:text-red-600 px-3 py-1.5 rounded-lg text-xs font-medium transition-all">
                                        <i class="fas fa-trash-alt mr-1"></i> Clear All
                                    </button>
                                </div>
                            </div>
                            <div id="questions-container" class="space-y-3 max-h-96 overflow-y-auto pr-1"></div>
                            <p class="text-xs text-slate-400 mt-2">Questions will appear in the assignment as a list for students.</p>
                        </div>

                        <!-- Attachments Tabs -->
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Attachment (Optional)</label>
                            <div class="flex gap-4 border-b border-slate-200 mb-4">
                                <button type="button" class="tab-btn text-slate-500 font-medium pb-2 border-b-2 border-transparent hover:text-orange-600 transition" data-type="file">📄 File Upload</button>
                                <button type="button" class="tab-btn text-slate-500 font-medium pb-2 border-b-2 border-transparent hover:text-orange-600 transition" data-type="link">🔗 External Link</button>
                            </div>

                            <div id="attach-file" class="attach-option">
                                <div id="dropzone" class="border-2 border-dashed border-slate-300 rounded-xl p-6 text-center hover:border-orange-400 transition cursor-pointer bg-slate-50/30 dropzone-modern">
                                    <i class="fas fa-cloud-upload-alt text-3xl text-slate-400 mb-2"></i>
                                    <p class="text-slate-500 text-sm">Drag & drop or <span class="text-orange-500 font-medium">browse</span> files</p>
                                    <input type="file" name="file" id="file-input" class="hidden" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                </div>
                                <div id="file-preview" class="mt-3 hidden">
                                    <div class="flex items-center justify-between bg-slate-50 p-3 rounded-xl border border-slate-200">
                                        <span id="file-name" class="text-sm text-slate-700 truncate max-w-xs"></span>
                                        <button type="button" id="remove-file" class="text-red-400 hover:text-red-600 text-sm">Remove</button>
                                    </div>
                                </div>
                            </div>

                            <div id="attach-link" class="attach-option hidden">
                                <input type="url" name="link_url" id="link_url" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" placeholder="https://drive.google.com/...">
                                <p class="text-xs text-slate-400 mt-1">Supports Google Drive, YouTube, or any resource link</p>
                            </div>
                        </div>

                        <!-- Settings Grid -->
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Max Points</label>
                                <input type="number" name="max_points" value="100" step="0.01" min="0" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Due Date</label>
                                <input type="date" name="due_date" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <!-- Group Assignment Toggle -->
                        <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl">
                            <input type="checkbox" id="is_group_assignment" name="is_group_assignment" class="w-5 h-5 text-orange-500 rounded focus:ring-orange-200 border-slate-300">
                            <label for="is_group_assignment" class="font-medium text-slate-700">This is a Group Assignment</label>
                        </div>
                        
                        <div id="group-container" class="hidden">
                            <select id="group_id" name="group_id" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700">
                                <option value="">Select Group...</option>
                            </select>
                            <div id="group-loading" class="hidden text-orange-500 text-xs mt-1">Loading groups...</div>
                        </div>

                        <!-- Status & Tags -->
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Status</label>
                                <select name="status" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700">
                                    <option value="draft">📌 Draft (Invisible to students)</option>
                                    <option value="published">✅ Published (Visible to students)</option>
                                    <option value="closed">🔒 Closed (No submissions)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Tags</label>
                                <input type="text" name="tags" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:border-orange-400 input-fancy bg-white text-slate-700" placeholder="math, algebra, chapter-2">
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                            <button type="button" id="preview-btn" class="px-5 py-2.5 border border-slate-300 text-slate-700 rounded-xl text-sm font-medium hover:bg-slate-50 transition-all btn-hover">
                                <i class="fas fa-eye mr-2"></i> Scroll to Preview
                            </button>
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2.5 rounded-xl text-sm font-medium transition-all btn-hover shadow-sm">
                                <i class="fas fa-save mr-2"></i> Create Assignment
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Live Preview Column (1/3 width) -->
                <div class="lg:col-span-1">
                    <div class="preview-panel sticky top-24 p-5 shadow-sm">
                        <div class="flex items-center gap-2 mb-4 pb-2 border-b border-slate-100">
                            <i class="fas fa-eye text-orange-400"></i>
                            <h3 class="font-bold text-slate-800">Live Preview</h3>
                            <span class="text-xs text-slate-400 ml-auto">Real-time</span>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <div id="preview-title" class="text-lg font-bold text-slate-800 flex items-center gap-2">—</div>
                                <div id="preview-meta" class="text-sm text-slate-500 mt-1">—</div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Description</div>
                                <div id="preview-desc" class="text-slate-600 text-sm leading-relaxed">—</div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-400 uppercase tracking-wide mb-2">Questions</div>
                                <div id="preview-questions" class="text-slate-600 text-sm space-y-1">—</div>
                            </div>
                            <div class="flex flex-wrap gap-2 items-center border-t border-slate-100 pt-3">
                                <i class="fas fa-paperclip text-slate-400 text-xs"></i>
                                <span id="preview-attachment" class="text-sm text-slate-500">—</span>
                            </div>
                            <div class="text-xs text-slate-400 flex items-center gap-1">
                                <i class="far fa-calendar-alt"></i>
                                <span id="preview-due">Due: —</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ?>
    <?php ob_end_flush(); ?>

    <script>
        // Remove loading overlay after page load
        window.addEventListener('load', () => {
            setTimeout(() => {
                const overlay = document.getElementById('loading-overlay');
                if(overlay) overlay.classList.add('hidden');
            }, 200);
        });

        // Auto-dismiss alerts
        document.querySelectorAll('.close-alert').forEach(btn => {
            btn.addEventListener('click', () => btn.closest('[class*="bg-"]')?.remove());
        });
        setTimeout(() => {
            document.querySelectorAll('[class*="bg-"]:has(.close-alert)').forEach(el => el.remove());
        }, 5000);

        // DOM Elements
        const form = document.getElementById('assignment-form');
        const classSelect = document.getElementById('class_id');
        const subjectSelect = document.getElementById('subject_id');
        const groupSelect = document.getElementById('group_id');
        const groupContainer = document.getElementById('group-container');
        const questionsContainer = document.getElementById('questions-container');
        const questionsJson = document.getElementById('questions_json');
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('file-input');
        const filePreview = document.getElementById('file-preview');
        const fileNameSpan = document.getElementById('file-name');
        const removeFileBtn = document.getElementById('remove-file');
        const isGroupChk = document.getElementById('is_group_assignment');
        const descBox = document.getElementById('description');
        const linkInput = document.getElementById('link_url');
        const aiGenerateBtn = document.getElementById('ai-generate');
        const aiLoading = document.getElementById('ai-loading');
        const aiTopic = document.getElementById('ai_topic');
        const aiNum = document.getElementById('ai_num');
        const classLoading = document.getElementById('class-loading');
        const subjectLoading = document.getElementById('subject-loading');
        const groupLoading = document.getElementById('group-loading');
        const descCounter = document.getElementById('desc-counter');
        const previewBtn = document.getElementById('preview-btn');
        const addQuestionBtn = document.getElementById('add-question');
        const clearQuestionsBtn = document.getElementById('clear-questions');

        // Description counter
        descBox.addEventListener('input', () => descCounter.textContent = descBox.value.length);
        descCounter.textContent = descBox.value.length;

        // Fetch classes & subjects
        function loadTeacherOptions() {
            classLoading.classList.remove('hidden');
            subjectLoading.classList.remove('hidden');
            fetch('?action=get_teacher_options')
                .then(r => r.json())
                .then(data => {
                    classLoading.classList.add('hidden');
                    subjectLoading.classList.add('hidden');
                    if (data.error) {
                        console.error(data.error);
                        classSelect.innerHTML = '<option value="">Error loading classes</option>';
                        subjectSelect.innerHTML = '<option value="">Error loading subjects</option>';
                        return;
                    }
                    classSelect.innerHTML = '<option value="">Select Class...</option>';
                    if (data.classes && data.classes.length) {
                        data.classes.forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = c.class_name;
                            classSelect.appendChild(opt);
                        });
                    } else {
                        classSelect.innerHTML += '<option disabled>No classes found</option>';
                    }

                    subjectSelect.innerHTML = '<option value="">Select Subject...</option>';
                    if (data.subjects && data.subjects.length) {
                        data.subjects.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = s.id;
                            let display = s.subject_name;
                            if (s.department_name) display += ` (${s.department_name})`;
                            opt.textContent = display;
                            subjectSelect.appendChild(opt);
                        });
                    } else {
                        subjectSelect.innerHTML += '<option disabled>No subjects found</option>';
                    }
                    updatePreview();
                })
                .catch(err => {
                    classLoading.classList.add('hidden');
                    subjectLoading.classList.add('hidden');
                    console.error('Error loading options:', err);
                    classSelect.innerHTML = '<option value="">Network error</option>';
                    subjectSelect.innerHTML = '<option value="">Network error</option>';
                });
        }

        // Load groups for class
        function loadGroups(classId) {
            if (!classId) {
                groupSelect.innerHTML = '<option value="">Select Group...</option>';
                return;
            }
            groupLoading.classList.remove('hidden');
            fetch(`?action=get_groups&class_id=${classId}`)
                .then(r => r.json())
                .then(data => {
                    groupLoading.classList.add('hidden');
                    groupSelect.innerHTML = '<option value="">Select Group...</option>';
                    if (data.groups && data.groups.length) {
                        data.groups.forEach(g => {
                            const opt = document.createElement('option');
                            opt.value = g.id;
                            opt.textContent = g.name;
                            groupSelect.appendChild(opt);
                        });
                    } else {
                        groupSelect.innerHTML += '<option disabled>No groups found</option>';
                    }
                })
                .catch(err => {
                    groupLoading.classList.add('hidden');
                    console.error('Error loading groups:', err);
                    groupSelect.innerHTML = '<option value="">Error loading groups</option>';
                });
        }

        loadTeacherOptions();

        classSelect.addEventListener('change', () => {
            if (isGroupChk.checked && classSelect.value) loadGroups(classSelect.value);
            updatePreview();
        });

        isGroupChk.addEventListener('change', () => {
            groupContainer.classList.toggle('hidden', !isGroupChk.checked);
            if (isGroupChk.checked && classSelect.value) loadGroups(classSelect.value);
            updatePreview();
        });

        // AI Generate
        aiGenerateBtn.addEventListener('click', async () => {
            const subjectId = subjectSelect.value;
            if (!subjectId) {
                alert('Please select a subject first.');
                return;
            }
            const subjectName = subjectSelect.options[subjectSelect.selectedIndex]?.text.split(' (')[0] || '';
            const topic = aiTopic.value.trim();
            const num = parseInt(aiNum.value) || 5;
            const description = descBox.value;

            aiLoading.classList.remove('hidden');
            aiGenerateBtn.disabled = true;

            const formData = new FormData();
            formData.append('subject_name', subjectName);
            formData.append('topic', topic);
            formData.append('description', description);
            formData.append('num_questions', num);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

            try {
                const res = await fetch('?action=generate_questions', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.error) throw new Error(data.error);
                if (data.questions && data.questions.length) {
                    data.questions.forEach(q => { if (q.trim()) addQuestion(q.trim()); });
                } else {
                    alert('No questions generated. Try different input.');
                }
            } catch (err) {
                console.error(err);
                alert('AI generation failed: ' + err.message);
            } finally {
                aiLoading.classList.add('hidden');
                aiGenerateBtn.disabled = false;
            }
        });

        // Question Management
        let questionCounter = 0;
        function addQuestion(text = '') {
            questionCounter++;
            const div = document.createElement('div');
            div.className = 'flex gap-3 items-start p-3 question-item-modern';
            div.innerHTML = `
                <span class="font-semibold text-orange-500 w-7 pt-1 text-sm">${questionCounter}.</span>
                <input type="text" class="flex-1 px-3 py-2 bg-white border border-slate-200 rounded-lg focus:ring-2 focus:ring-orange-200 focus:border-orange-400 text-slate-700 text-sm question-text" placeholder="Enter question..." value="${escapeHtml(text)}">
                <button type="button" class="text-slate-400 hover:text-red-500 transition-all"><i class="fas fa-trash-alt"></i></button>
            `;
            const input = div.querySelector('.question-text');
            const rmBtn = div.querySelector('button');
            rmBtn.addEventListener('click', () => {
                div.remove();
                renumberQuestions();
                updatePreview();
            });
            input.addEventListener('input', updatePreview);
            questionsContainer.appendChild(div);
            updatePreview();
        }

        function renumberQuestions() {
            const items = questionsContainer.querySelectorAll('.question-item-modern');
            items.forEach((el, idx) => {
                const span = el.querySelector('span:first-child');
                if(span) span.textContent = (idx + 1) + '.';
            });
            questionCounter = items.length;
        }

        function clearAllQuestions() {
            if (confirm('Are you sure you want to delete all questions?')) {
                questionsContainer.innerHTML = '';
                questionCounter = 0;
                updatePreview();
            }
        }
        addQuestionBtn.addEventListener('click', () => addQuestion());
        clearQuestionsBtn.addEventListener('click', clearAllQuestions);

        // File upload handling with modern dropzone
        ['dragover', 'dragenter'].forEach(ev => dropzone.addEventListener(ev, e => {
            e.preventDefault();
            dropzone.classList.add('drag-over');
        }));
        ['dragleave', 'dragend', 'drop'].forEach(ev => dropzone.addEventListener(ev, () => dropzone.classList.remove('drag-over')));
        dropzone.addEventListener('drop', e => {
            e.preventDefault();
            const file = e.dataTransfer.files[0];
            if (file) handleFile(file);
        });
        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files[0]) handleFile(fileInput.files[0]);
        });

        function handleFile(file) {
            if (file.size > 5 * 1024 * 1024) {
                alert('File must be under 5MB');
                return;
            }
            fileNameSpan.textContent = file.name;
            filePreview.classList.remove('hidden');
            updatePreview();
        }
        removeFileBtn.addEventListener('click', () => {
            fileInput.value = '';
            filePreview.classList.add('hidden');
            updatePreview();
        });

        // Attachment tabs
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('border-orange-500', 'text-orange-600'));
                btn.classList.add('border-orange-500', 'text-orange-600');
                const type = btn.dataset.type;
                document.getElementById('attach-file').classList.toggle('hidden', type !== 'file');
                document.getElementById('attach-link').classList.toggle('hidden', type !== 'link');
                updatePreview();
            });
        });
        // Activate first tab
        document.querySelector('.tab-btn').classList.add('border-orange-500', 'text-orange-600');

        // Live Preview updates
        let previewTimer;
        function updatePreview() {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(() => {
                const title = form.title?.value.trim() || '—';
                const type = form.type?.options[form.type.selectedIndex]?.text || '—';
                const className = classSelect.selectedOptions[0]?.text || '—';
                const subjectName = subjectSelect.selectedOptions[0]?.text.split(' (')[0] || '—';
                const desc = descBox.value || '—';
                const dueDate = form.due_date?.value || 'Not set';

                const qItems = Array.from(questionsContainer.querySelectorAll('.question-text'));
                const qHtml = qItems.map((inp, i) => {
                    const txt = inp.value.trim();
                    return txt ? `<li class="ml-4 mb-1">${i+1}. ${escapeHtml(txt)}</li>` : '';
                }).filter(Boolean).join('');
                
                let attach = '—';
                if (fileInput.files[0]) {
                    attach = `<span class="inline-flex items-center gap-1 bg-slate-100 px-2 py-1 rounded-full text-xs">📎 ${escapeHtml(fileInput.files[0].name)}</span>`;
                } else if (linkInput.value.trim()) {
                    attach = `<a href="${linkInput.value}" target="_blank" class="text-orange-500 underline text-sm">Open Link</a>`;
                }

                document.getElementById('preview-title').innerHTML = `<span class="truncate">${escapeHtml(title)}</span> <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full">${escapeHtml(type)}</span>`;
                document.getElementById('preview-meta').innerHTML = `<i class="fas fa-graduation-cap text-xs mr-1"></i> ${escapeHtml(className)} • ${escapeHtml(subjectName)}`;
                document.getElementById('preview-desc').innerHTML = escapeHtml(desc).replace(/\n/g, '<br>') || '—';
                document.getElementById('preview-questions').innerHTML = qHtml ? `<ul class="list-disc space-y-1">${qHtml}</ul>` : '<span class="italic text-slate-400">No questions added</span>';
                document.getElementById('preview-attachment').innerHTML = attach;
                document.getElementById('preview-due').innerHTML = `Due: ${dueDate === 'Not set' ? '—' : dueDate}`;
            }, 300);
        }

        form.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('input', updatePreview);
            el.addEventListener('change', updatePreview);
        });
        previewBtn.addEventListener('click', () => {
            document.querySelector('.preview-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        // Submit packing
        form.addEventListener('submit', function() {
            const questions = Array.from(questionsContainer.querySelectorAll('.question-text'))
                .map(inp => {
                    const txt = inp.value.trim();
                    return txt ? { question_text: txt } : null;
                })
                .filter(Boolean);
            questionsJson.value = questions.length ? JSON.stringify(questions) : '[]';
        });

        // Helper
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Start with one empty question
        addQuestion();

        // Fake theme toggle button
        document.getElementById('theme-toggle-fake')?.addEventListener('click', () => {
            alert("Light mode is always enabled. Enjoy the bright interface!");
        });
    </script>
</body>
</html>