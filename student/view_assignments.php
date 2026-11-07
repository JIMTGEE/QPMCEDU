<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to students only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a student.";
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
$student_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'student' AND status = 'active'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $student_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid student_id in session: $student_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $student_name = $user['name'];
    $student_department_id = $user['department_id'];
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
logActivity($conn, $student_id, "Accessed View Assignment Page", "Student accessed view assignment page");

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch assignment details
$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    error_log("Invalid or missing assignment_id: " . ($_GET['assignment_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Invalid assignment ID.";
    header("Location: /student/manage_student_assignments.php");
    exit();
}

$assignment_sql = "SELECT a.id, a.title, a.description, a.type, a.max_points, a.due_date, a.status, 
                          a.is_group_assignment, a.group_id, a.file_path, a.questions, 
                          c.class_name, s.subject_name, g.name as group_name
                   FROM assignments a
                   LEFT JOIN classes c ON a.class_id = c.id
                   LEFT JOIN subjects s ON a.subject_id = s.id
                   LEFT JOIN student_classes sc ON a.class_id = sc.class_id
                   LEFT JOIN group_members gm ON a.group_id = gm.group_id
                   LEFT JOIN groups g ON a.group_id = g.id
                   WHERE a.id = ? 
                   AND a.status = 'published'
                   AND (a.due_date IS NULL OR a.due_date >= CURDATE())
                   AND (sc.user_id = ? OR gm.user_id = ?)";
$assignment_stmt = $conn->prepare($assignment_sql);
$assignment_stmt->bind_param("iii", $assignment_id, $student_id, $student_id);
$assignment_stmt->execute();
$assignment_result = $assignment_stmt->get_result();
if ($assignment_result->num_rows === 0) {
    error_log("Assignment not found or not accessible: assignment_id=$assignment_id, student_id=$student_id");
    $_SESSION['error_msg'] = "Assignment not found or not accessible.";
    header("Location: /student/manage_student_assignments.php");
    exit();
}
$assignment = $assignment_result->fetch_assoc();
$assignment_stmt->close();

// Check for existing submission
$submission_sql = "SELECT id, status, score, feedback FROM assignment_submissions 
                   WHERE assignment_id = ? 
                   AND (user_id = ? OR group_id = ?)
                   ORDER BY id DESC LIMIT 1";
$submission_stmt = $conn->prepare($submission_sql);
$submission_stmt->bind_param("iii", $assignment_id, $student_id, $assignment['group_id']);
$submission_stmt->execute();
$submission_result = $submission_stmt->get_result();
$existing_submission = $submission_result->fetch_assoc();
$submission_stmt->close();

$has_submission = !empty($existing_submission);
$submission_id = $existing_submission['id'] ?? null;

// If already graded, show feedback directly (no form)
$show_feedback = false;
$submission_score = null;
$submission_feedback = null;
$submission_per_question_feedback = [];
$submission_overall_feedback = '';

if ($has_submission && $existing_submission['status'] !== 'pending') {
    $show_feedback = true;
    $submission_score = $existing_submission['score'];
    // Parse feedback JSON
    $feedback_data = json_decode($existing_submission['feedback'], true);
    if (is_array($feedback_data)) {
        $submission_overall_feedback = $feedback_data['overall'] ?? '';
        $submission_per_question_feedback = $feedback_data['per_question'] ?? [];
    } else {
        $submission_overall_feedback = $existing_submission['feedback'];
    }
}

// Parse questions
$questions = [];
if ($assignment['questions']) {
    $decoded = json_decode($assignment['questions'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $q) {
            if (isset($q['question_text']) && !empty(trim($q['question_text']))) {
                $questions[] = ['question_text' => $q['question_text'], 'type' => 'text'];
            } else {
                error_log("Invalid question structure for assignment_id=$assignment_id: " . json_encode($q));
            }
        }
    } else {
        error_log("Failed to decode questions JSON for assignment_id=$assignment_id: " . json_last_error_msg());
    }
}

// -------------------------------------------------------------------------
// AI Grading Function
// -------------------------------------------------------------------------
function gradeWithAI($assignment_title, $assignment_description, $questions, $answers, $max_points) {
    // Build prompt
    $prompt = "You are an AI assistant that grades student assignments. Below is the assignment details and the student's answers.\n\n";
    $prompt .= "Assignment Title: {$assignment_title}\n";
    $prompt .= "Assignment Description: {$assignment_description}\n";
    $prompt .= "Maximum Points: {$max_points}\n\n";
    $prompt .= "Questions and Student Answers:\n";
    foreach ($questions as $index => $question) {
        $prompt .= "Question " . ($index + 1) . ": " . $question['question_text'] . "\n";
        $prompt .= "Student Answer: " . ($answers[$index]['answer'] ?? 'No answer provided') . "\n\n";
    }
    $prompt .= "Please evaluate the answers and provide:\n";
    $prompt .= "1. A score out of {$max_points} (as a number with up to 2 decimal places)\n";
    $prompt .= "2. Brief feedback for each question (one sentence per question)\n";
    $prompt .= "3. Overall feedback for the student (max 200 words)\n\n";
    $prompt .= "Return your response in the following JSON format:\n";
    $prompt .= "{\n";
    $prompt .= "  \"score\": numeric,\n";
    $prompt .= "  \"per_question_feedback\": [\"feedback for Q1\", \"feedback for Q2\", ...],\n";
    $prompt .= "  \"overall_feedback\": \"text\"\n";
    $prompt .= "}\n";
    $prompt .= "Make sure the score is between 0 and {$max_points}. Do not include any other text.";

    // API call
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("AI grading HTTP error: $httpCode, response: $result");
        return false;
    }

    $data = json_decode($result, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($text)) {
        error_log("AI grading empty response");
        return false;
    }

    // Attempt to extract JSON from response (some models may wrap in markdown)
    if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $matches)) {
        $text = $matches[1];
    } elseif (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
        $text = $matches[0];
    }

    $grading = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("AI grading JSON parse error: " . json_last_error_msg() . " - Response: $text");
        return false;
    }

    // Validate required fields
    if (!isset($grading['score']) || !is_numeric($grading['score'])) {
        error_log("AI grading missing score or not numeric");
        return false;
    }

    $score = floatval($grading['score']);
    // Cap score to max_points
    $score = min($max_points, max(0, $score));

    $overall_feedback = $grading['overall_feedback'] ?? 'Your assignment has been graded. Please review the feedback.';
    $per_question_feedback = $grading['per_question_feedback'] ?? [];

    return [
        'score' => $score,
        'overall_feedback' => $overall_feedback,
        'per_question_feedback' => $per_question_feedback
    ];
}

// -------------------------------------------------------------------------
// Handle Form Submission
// -------------------------------------------------------------------------
$error_msg = null;
$success_msg = null;
$submission_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$show_feedback) {
    $conn->begin_transaction();
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            error_log("CSRF token validation failed: Expected " . ($_SESSION['csrf_token'] ?? 'none') . ", Received " . ($_POST['csrf_token'] ?? 'none'));
            throw new Exception("Invalid CSRF token.");
        }

        // Collect answers
        $answers = [];
        if (!empty($questions)) {
            foreach ($questions as $index => $question) {
                $answer_key = "answer_$index";
                $answer = trim(filter_input(INPUT_POST, $answer_key, FILTER_UNSAFE_RAW) ?? '');
                if (empty($answer)) {
                    throw new Exception("Answer for question " . ($index + 1) . " is required.");
                }
                $answers[] = [
                    'question_text' => $question['question_text'],
                    'type' => $question['type'],
                    'answer' => $answer
                ];
            }
        }
        $answers_json = json_encode($answers);
        if ($answers_json === false) {
            throw new Exception("Failed to encode answers.");
        }

        // Handle file upload
        $file_paths = [];
        $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $max_file_size = 5 * 1024 * 1024; // 5MB
        $max_total_length = 500; // Assumed VARCHAR(500) for file_paths
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/Submissions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        if (!empty($_FILES['files']['name'][0])) {
            foreach ($_FILES['files']['name'] as $key => $name) {
                if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_extensions)) {
                        throw new Exception("Invalid file type for $name. Allowed: pdf, doc, docx, jpg, png.");
                    }
                    if ($_FILES['files']['size'][$key] > $max_file_size) {
                        throw new Exception("File $name exceeds 5MB limit.");
                    }
                    $file_name = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $file_path = '/Uploads/Submissions/' . $file_name;
                    if (!move_uploaded_file($_FILES['files']['tmp_name'][$key], $_SERVER['DOCUMENT_ROOT'] . $file_path)) {
                        throw new Exception("Failed to upload file: $name");
                    }
                    $file_paths[] = $file_path;
                } elseif ($_FILES['files']['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                    throw new Exception("Error uploading file: $name (Error code: {$_FILES['files']['error'][$key]}).");
                }
            }
        }
        $file_paths_str = !empty($file_paths) ? implode(',', $file_paths) : null;
        if ($file_paths_str && strlen($file_paths_str) > $max_total_length) {
            throw new Exception("Total file paths length exceeds limit.");
        }

        // Insert initial submission (pending)
        // Note: In group assignments, user_id is NULL; adjust if DB requires NOT NULL (modify column or set to 0)
        $group_id = $assignment['is_group_assignment'] ? $assignment['group_id'] : null;
        $user_id = $assignment['is_group_assignment'] ? null : $student_id;
        // If user_id must be NOT NULL, set to 0 for group submissions
        // Uncomment next line if DB requires integer
        // $user_id = $user_id ?? 0;

        $insert_sql = "INSERT INTO assignment_submissions (assignment_id, user_id, group_id, answers, file_paths, status, submission_date, created_at) 
                       VALUES (?, ?, ?, ?, ?, 'submitted', NOW(), NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        // Bind parameters: 5 placeholders -> 5 variables, type string "iiiss" (integer, integer, integer, string, string)
        // Note: user_id and group_id are integers (can be null, but bind_param expects integer)
        $insert_stmt->bind_param("iiiss", $assignment_id, $user_id, $group_id, $answers_json, $file_paths_str);
        $insert_stmt->execute();
        $submission_id = $conn->insert_id;
        $insert_stmt->close();

        // AI Grading (if there are questions)
        $ai_score = null;
        $ai_feedback = null;
        $per_question_feedback = [];
        if (!empty($questions)) {
            $grading_result = gradeWithAI(
                $assignment['title'],
                $assignment['description'] ?? '',
                $questions,
                $answers,
                floatval($assignment['max_points'])
            );
            if ($grading_result !== false) {
                $ai_score = $grading_result['score'];
                $ai_feedback = [
                    'overall' => $grading_result['overall_feedback'],
                    'per_question' => $grading_result['per_question_feedback']
                ];
                $per_question_feedback = $grading_result['per_question_feedback'];
            } else {
                error_log("AI grading failed for submission ID $submission_id");
            }
        }

        // Update submission with grading results
        if ($ai_score !== null) {
            $feedback_json = json_encode($ai_feedback);
            $update_sql = "UPDATE assignment_submissions 
                           SET score = ?, feedback = ?, graded_by = 0, graded_at = NOW(), status = 'graded', is_graded = 1
                           WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("dsi", $ai_score, $feedback_json, $submission_id);
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            // If AI failed, leave as submitted (teacher will grade later)
        }

        // Log activity
        logActivity($conn, $student_id, "Submitted Assignment", "Assignment ID $assignment_id submitted" . ($assignment['is_group_assignment'] ? " for group ID $group_id" : "") . (($ai_score !== null) ? " with AI score $ai_score" : ""));

        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $conn->commit();

        // Prepare feedback to display on same page
        if ($ai_score !== null) {
            $submission_result = [
                'score' => $ai_score,
                'overall_feedback' => $ai_feedback['overall'],
                'per_question_feedback' => $per_question_feedback,
                'max_points' => $assignment['max_points']
            ];
            $success_msg = "Assignment submitted and graded successfully!";
        } else {
            $success_msg = "Assignment submitted successfully! It will be reviewed by your teacher.";
        }
        // Mark that we should show feedback (if AI succeeded)
        if ($ai_score !== null) {
            $show_feedback = true;
            $submission_score = $ai_score;
            $submission_overall_feedback = $ai_feedback['overall'];
            $submission_per_question_feedback = $per_question_feedback;
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Submission error: " . $e->getMessage());
        $error_msg = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="<?= isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and submit assignments for QueensPa School students.">
    <title>View Assignment - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

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
                        success: { 500: '#10b981', 600: '#059669' },
                        warning: { 500: '#f59e0b', 600: '#d97706' },
                        danger: { 500: '#ef4444', 600: '#dc2626' },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'slide-up': 'slideUp 0.4s ease-out',
                        'scale-in': 'scaleIn 0.3s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideUp: { '0%': { transform: 'translateY(20px)', opacity: '0' }, '100%': { transform: 'translateY(0)', opacity: '1' } },
                        scaleIn: { '0%': { transform: 'scale(0.95)', opacity: '0' }, '100%': { transform: 'scale(1)', opacity: '1' } },
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
        .form-control:focus {
            outline: none;
            ring: 4px solid rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }
        .btn-primary {
            transition: all 0.2s;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .question-card {
            transition: all 0.3s ease;
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
            background: #f1f1f1;
            border-radius: 4px;
        }
        .dark ::-webkit-scrollbar-track {
            background: #1f2937;
        }
        ::-webkit-scrollbar-thumb {
            background: #3b82f6;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #2563eb;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 transition-colors duration-300">
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 flex flex-col items-center space-y-3 animate-pulse-slow">
            <i class="fas fa-spinner fa-spin text-primary-500 text-4xl"></i>
            <p class="text-gray-700 dark:text-gray-300">Processing submission...</p>
        </div>
    </div>

    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    if (file_exists($header_path)) {
        include $header_path;
    } else {
        error_log("Student header file missing: $header_path");
        echo '<div class="bg-red-100 text-red-700 p-4">System error: Header missing</div>';
    }
    ?>

    <div class="container mx-auto px-4 py-12 max-w-7xl">
        <!-- Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 animate-fade-in">
            <div>
                <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 dark:text-white">
                    <i class="fas fa-clipboard-list text-primary-500 mr-3"></i> <?= htmlspecialchars($assignment['title']) ?>
                </h1>
                <p class="text-gray-600 dark:text-gray-400 mt-2"><?= $show_feedback ? 'Review your graded assignment' : 'Answer the questions and submit your work' ?></p>
            </div>
            <div class="flex space-x-3 mt-4 lg:mt-0">
                <button id="dark-mode-toggle" 
                        class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center shadow-sm hover:shadow">
                    <i class="fas fa-moon mr-2"></i> <span id="dark-mode-text">Dark Mode</span>
                </button>
                <a href="/student/manage_student_assignments.php" 
                   class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center shadow-sm hover:shadow">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($error_msg)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-slide-up dark:bg-red-900 dark:border-red-700 dark:text-red-200">
                <div class="flex items-center"><i class="fas fa-exclamation-circle mr-2"></i><span><?= htmlspecialchars($error_msg) ?></span></div>
                <button class="close-alert text-red-500 hover:text-red-700 dark:text-red-300">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-slide-up dark:bg-green-900 dark:border-green-700 dark:text-green-200">
                <div class="flex items-center"><i class="fas fa-check-circle mr-2"></i><span><?= htmlspecialchars($success_msg) ?></span></div>
                <button class="close-alert text-green-500 hover:text-green-700">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Assignment Details Card -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-8 animate-slide-up">
            <div class="bg-gradient-to-r from-primary-500 to-primary-700 px-6 py-4">
                <h2 class="text-white font-semibold text-xl flex items-center">
                    <i class="fas fa-info-circle mr-2"></i> Assignment Details
                </h2>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Class</p>
                        <p class="text-gray-800 dark:text-white"><?= htmlspecialchars($assignment['class_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Subject</p>
                        <p class="text-gray-800 dark:text-white"><?= htmlspecialchars($assignment['subject_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Type</p>
                        <p class="text-gray-800 dark:text-white"><?= ucfirst($assignment['type']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Max Points</p>
                        <p class="text-gray-800 dark:text-white"><?= number_format($assignment['max_points'], 2) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Due Date</p>
                        <p class="text-gray-800 dark:text-white">
                            <?= $assignment['due_date'] ? date('F j, Y', strtotime($assignment['due_date'])) : 'Not set' ?>
                            <?php if ($assignment['due_date'] && strtotime($assignment['due_date']) < time()): ?>
                                <span class="ml-2 text-danger-500 text-xs"><i class="fas fa-exclamation-triangle"></i> Overdue</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Assignment Type</p>
                        <p class="text-gray-800 dark:text-white">
                            <?= $assignment['is_group_assignment'] ? '<i class="fas fa-users text-primary-500 mr-1"></i> Group (' . htmlspecialchars($assignment['group_name'] ?? 'N/A') . ')' : '<i class="fas fa-user mr-1"></i> Individual' ?>
                        </p>
                    </div>
                    <?php if ($assignment['file_path']): ?>
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Attachment</p>
                            <a href="<?= htmlspecialchars($assignment['file_path']) ?>" class="text-primary-500 hover:text-primary-600" target="_blank">
                                <i class="fas fa-download mr-1"></i> Download File
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($assignment['description'])): ?>
                    <div class="mt-4">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Description</p>
                        <p class="text-gray-800 dark:text-white"><?= nl2br(htmlspecialchars($assignment['description'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($show_feedback && $submission_score !== null): ?>
            <!-- Feedback Section -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-8 animate-slide-up">
                <div class="bg-gradient-to-r from-success-500 to-success-700 px-6 py-4">
                    <h2 class="text-white font-semibold text-xl flex items-center">
                        <i class="fas fa-star mr-2"></i> Your Results
                    </h2>
                </div>
                <div class="p-6 space-y-6">
                    <div class="text-center p-4 bg-gray-100 dark:bg-gray-700 rounded-lg">
                        <p class="text-lg font-medium">Score: <span class="text-2xl font-bold text-success-600 dark:text-success-400"><?= number_format($submission_score, 2) ?></span> / <?= number_format($assignment['max_points'], 2) ?></p>
                    </div>

                    <?php if (!empty($submission_per_question_feedback)): ?>
                        <div>
                            <h3 class="text-lg font-semibold mb-3 text-gray-800 dark:text-white">Per‑Question Feedback</h3>
                            <div class="space-y-4">
                                <?php foreach ($submission_per_question_feedback as $idx => $feedback): ?>
                                    <div class="border-l-4 border-primary-500 pl-4">
                                        <p class="font-medium text-gray-700 dark:text-gray-300">Question <?= $idx+1 ?></p>
                                        <p class="text-gray-600 dark:text-gray-400"><?= htmlspecialchars($feedback) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div>
                        <h3 class="text-lg font-semibold mb-2 text-gray-800 dark:text-white">Overall Feedback</h3>
                        <p class="text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg"><?= nl2br(htmlspecialchars($submission_overall_feedback)) ?></p>
                    </div>

                    <div class="text-center mt-6">
                        <a href="/student/manage_student_assignments.php" class="bg-primary-500 hover:bg-primary-600 text-white px-6 py-2 rounded-lg inline-flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
                        </a>
                    </div>
                </div>
            </div>
        <?php elseif ($show_feedback && $existing_submission['status'] === 'submitted'): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-hourglass-half mr-2"></i> Your submission is pending review by the teacher. Please check back later.
            </div>
        <?php else: ?>
            <!-- Submission Form -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden animate-slide-up">
                <div class="bg-gradient-to-r from-success-500 to-success-700 px-6 py-4">
                    <h2 class="text-white font-semibold text-xl flex items-center">
                        <i class="fas fa-question-circle mr-2"></i> Questions
                    </h2>
                </div>
                <div class="p-6">
                    <form id="submission-form" action="view_assignments.php?assignment_id=<?= $assignment_id ?>" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <?php if (empty($questions)): ?>
                            <p class="text-gray-500 dark:text-gray-400">No questions available for this assignment. You may upload supporting files if needed.</p>
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Upload Files (Optional)</label>
                                <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-8 text-center hover:border-primary-500 transition cursor-pointer" id="dropzone">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                                    <p class="text-gray-600 dark:text-gray-400">Drag & drop or <span class="text-primary-500 font-medium">browse</span></p>
                                    <input type="file" name="files[]" id="file-input" class="hidden" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                </div>
                                <div id="file-preview" class="mt-4 hidden">
                                    <div class="flex flex-wrap gap-2"></div>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Supported: PDF, DOC, DOCX, JPG, PNG (Max 5MB each)</p>
                            </div>
                            <div class="flex justify-end mt-6">
                                <button type="submit" id="submit-btn" class="bg-success-500 hover:bg-success-600 text-white px-6 py-2 rounded-lg text-sm font-medium transition-all flex items-center btn-primary">
                                    <i class="fas fa-save mr-2"></i> Submit Assignment
                                </button>
                            </div>
                        <?php else: ?>
                            <div id="questions-container" class="space-y-6">
                                <?php foreach ($questions as $index => $question): ?>
                                    <div class="question-card border border-gray-200 dark:border-gray-700 rounded-lg p-4 <?= $index > 0 ? 'hidden' : '' ?>" data-index="<?= $index ?>">
                                        <div class="flex justify-between items-center mb-3">
                                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Question <?= $index + 1 ?></h3>
                                            <span class="text-sm text-gray-500 dark:text-gray-400"><?= count($questions) ?> questions total</span>
                                        </div>
                                        <p class="text-gray-700 dark:text-gray-300 mb-4"><?= htmlspecialchars($question['question_text']) ?></p>
                                        <textarea name="answer_<?= $index ?>" 
                                                  class="form-control w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:text-white transition" 
                                                  rows="5" 
                                                  placeholder="Type your answer here..." 
                                                  required></textarea>
                                        <div class="invalid-feedback">This field is required.</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-6">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Upload Supporting Files (Optional)</label>
                                <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center hover:border-primary-500 transition cursor-pointer" id="dropzone">
                                    <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                                    <p class="text-gray-600 dark:text-gray-400">Drag & drop or <span class="text-primary-500 font-medium">browse</span></p>
                                    <input type="file" name="files[]" id="file-input" class="hidden" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                </div>
                                <div id="file-preview" class="mt-3 hidden">
                                    <div class="flex flex-wrap gap-2"></div>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Supported: PDF, DOC, DOCX, JPG, PNG (Max 5MB each)</p>
                            </div>

                            <div class="flex justify-between items-center mt-8">
                                <button type="button" id="prev-question" class="bg-gray-500 hover:bg-gray-600 text-white px-5 py-2 rounded-lg text-sm font-medium transition-all btn-primary hidden">
                                    <i class="fas fa-chevron-left mr-2"></i> Previous
                                </button>
                                <button type="button" id="next-question" class="bg-primary-500 hover:bg-primary-600 text-white px-5 py-2 rounded-lg text-sm font-medium transition-all btn-primary">
                                    Next <i class="fas fa-chevron-right ml-2"></i>
                                </button>
                                <button type="submit" id="submit-btn" class="bg-success-500 hover:bg-success-600 text-white px-6 py-2 rounded-lg text-sm font-medium transition-all btn-primary hidden">
                                    <i class="fas fa-save mr-2"></i> Submit Assignment
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        error_log("Student footer file missing: $footer_path");
        echo '<div class="bg-red-100 text-red-700 p-4">System error: Footer missing</div>';
    }
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script>
        $(document).ready(function() {
            const loadingOverlay = $('#loading-overlay');
            const questions = $('.question-card');
            const prevBtn = $('#prev-question');
            const nextBtn = $('#next-question');
            const submitBtn = $('#submit-btn');
            const fileUploadContainer = $('#file-preview');
            let currentQuestion = 0;

            // Dark mode toggle
            const themeToggle = $('#dark-mode-toggle');
            const html = document.documentElement;
            function setDarkMode(isDark) {
                if (isDark) {
                    html.classList.add('dark');
                    localStorage.setItem('darkMode', 'enabled');
                    themeToggle.html('<i class="fas fa-sun mr-2"></i> Light Mode');
                } else {
                    html.classList.remove('dark');
                    localStorage.setItem('darkMode', 'disabled');
                    themeToggle.html('<i class="fas fa-moon mr-2"></i> Dark Mode');
                }
            }
            const savedDarkMode = localStorage.getItem('darkMode');
            if (savedDarkMode === 'enabled' || (savedDarkMode === null && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                setDarkMode(true);
            } else {
                setDarkMode(false);
            }
            themeToggle.on('click', () => {
                setDarkMode(!html.classList.contains('dark'));
            });

            // Auto-dismiss alerts
            $('.close-alert').on('click', function() {
                $(this).closest('[class*="bg-"]').fadeOut(300);
            });
            setTimeout(() => {
                $('[class*="bg-"]:has(.close-alert)').fadeOut(500);
            }, 5000);

            // File upload drag & drop
            const dropzone = $('#dropzone');
            const fileInput = $('#file-input');
            let selectedFiles = [];

            function updateFilePreview() {
                const container = fileUploadContainer.find('.flex-wrap');
                container.empty();
                if (selectedFiles.length === 0) {
                    fileUploadContainer.addClass('hidden');
                    return;
                }
                fileUploadContainer.removeClass('hidden');
                selectedFiles.forEach((file, index) => {
                    const fileItem = $(`
                        <div class="bg-gray-100 dark:bg-gray-700 rounded-lg px-3 py-2 flex items-center justify-between text-sm">
                            <span class="truncate max-w-[200px]">${escapeHtml(file.name)}</span>
                            <button type="button" class="remove-file text-danger-500 hover:text-danger-700 ml-2" data-index="${index}">
                                <i class="fas fa-times-circle"></i>
                            </button>
                        </div>
                    `);
                    container.append(fileItem);
                });
                // Attach remove handlers
                $('.remove-file').on('click', function() {
                    const idx = $(this).data('index');
                    selectedFiles.splice(idx, 1);
                    updateFilePreview();
                    // Update actual file input
                    const dataTransfer = new DataTransfer();
                    selectedFiles.forEach(f => dataTransfer.items.add(f));
                    fileInput[0].files = dataTransfer.files;
                });
            }

            dropzone.on('click', () => fileInput.click());
            fileInput.on('change', function() {
                selectedFiles = Array.from(this.files);
                updateFilePreview();
            });
            dropzone.on('dragover', (e) => {
                e.preventDefault();
                dropzone.addClass('border-primary-500');
            });
            dropzone.on('dragleave', () => dropzone.removeClass('border-primary-500'));
            dropzone.on('drop', (e) => {
                e.preventDefault();
                dropzone.removeClass('border-primary-500');
                const files = Array.from(e.originalEvent.dataTransfer.files);
                selectedFiles = selectedFiles.concat(files);
                updateFilePreview();
                // Update input
                const dataTransfer = new DataTransfer();
                selectedFiles.forEach(f => dataTransfer.items.add(f));
                fileInput[0].files = dataTransfer.files;
            });

            // Navigation logic (if questions exist)
            if (questions.length > 0) {
                function showQuestion(index) {
                    questions.addClass('hidden').eq(index).removeClass('hidden');
                    prevBtn.toggleClass('hidden', index === 0);
                    nextBtn.toggleClass('hidden', index === questions.length - 1);
                    submitBtn.toggleClass('hidden', index !== questions.length - 1);
                    currentQuestion = index;
                }

                function validateCurrentQuestion() {
                    const current = questions.eq(currentQuestion);
                    const textarea = current.find('textarea');
                    const isValid = textarea.val().trim() !== '';
                    textarea.toggleClass('is-invalid', !isValid);
                    return isValid;
                }

                prevBtn.on('click', () => {
                    if (currentQuestion > 0) {
                        showQuestion(currentQuestion - 1);
                    }
                });

                nextBtn.on('click', () => {
                    if (validateCurrentQuestion() && currentQuestion < questions.length - 1) {
                        showQuestion(currentQuestion + 1);
                    }
                });

                // Real-time validation
                questions.find('textarea').on('input', function() {
                    if ($(this).val().trim()) {
                        $(this).removeClass('is-invalid');
                    }
                });

                // Initialize
                showQuestion(0);
            } else {
                // No questions, just submit button
                submitBtn.removeClass('hidden');
                prevBtn.addClass('hidden');
                nextBtn.addClass('hidden');
            }

            // Form submission
            $('#submission-form').on('submit', function(e) {
                if (questions.length > 0 && !validateCurrentQuestion()) {
                    e.preventDefault();
                    return false;
                }
                loadingOverlay.removeClass('hidden');
                $('#submit-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Submitting...');
            });

            // Hide loading overlay after page load
            setTimeout(() => loadingOverlay.addClass('hidden'), 300);

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }
        });
    </script>
</body>
</html>