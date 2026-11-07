<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate inputs
$teacher_id = (int)$_SESSION['user_id'];
$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    $_SESSION['error_msg'] = "Invalid assignment ID.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// Verify teacher ownership
$verify_sql = "SELECT id, title, max_points, is_group_assignment, questions, subject_id, class_id 
               FROM assignments WHERE id = ? AND teacher_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
if (!$verify_stmt) {
    $_SESSION['error_msg'] = "System error: Failed to verify assignment.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}
$verify_stmt->bind_param("ii", $assignment_id, $teacher_id);
$verify_stmt->execute();
$assignment = $verify_stmt->get_result()->fetch_assoc();
$verify_stmt->close();

if (!$assignment) {
    $_SESSION['error_msg'] = "Assignment not found or you do not have permission.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
logActivity($conn, $teacher_id, "Accessed Submissions", "Viewed submissions for assignment ID $assignment_id");

// Handle grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade_submission') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_msg'] = "Invalid security token. Please refresh and try again.";
        header("Location: /teacher/manage_submissions.php?assignment_id=$assignment_id");
        exit();
    }
    
    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    $score = filter_input(INPUT_POST, 'score', FILTER_VALIDATE_FLOAT);
    $feedback = trim($_POST['feedback'] ?? '');
    
    if (!$submission_id || $score === false || $score === null) {
        $_SESSION['error_msg'] = "Invalid submission data.";
        header("Location: /teacher/manage_submissions.php?assignment_id=$assignment_id");
        exit();
    }
    
    // Validate score
    $max_points = (float)$assignment['max_points'];
    if ($score < 0 || $score > $max_points) {
        $_SESSION['error_msg'] = "Score must be between 0 and $max_points.";
        header("Location: /teacher/manage_submissions.php?assignment_id=$assignment_id");
        exit();
    }
    
    // Update the submission
    $update_sql = "UPDATE assignment_submissions 
                   SET score = ?, feedback = ?, graded_by = ?, graded_at = NOW(), 
                       status = 'graded', is_graded = 1, updated_at = NOW() 
                   WHERE id = ? AND assignment_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    if ($update_stmt) {
        $update_stmt->bind_param("dsiii", $score, $feedback, $teacher_id, $submission_id, $assignment_id);
        if ($update_stmt->execute()) {
            logActivity($conn, $teacher_id, "Graded Submission", "Graded submission #$submission_id. Score: $score/$max_points");
            $_SESSION['success_msg'] = "Submission graded successfully!";
        } else {
            $_SESSION['error_msg'] = "Failed to save grade: " . $update_stmt->error;
        }
        $update_stmt->close();
    } else {
        $_SESSION['error_msg'] = "Database error: " . $conn->error;
    }
    
    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header("Location: /teacher/manage_submissions.php?assignment_id=$assignment_id");
    exit();
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch all submissions for this assignment
$submissions_sql = "SELECT 
                        asub.id, asub.assignment_id, asub.user_id, asub.group_id,
                        asub.answers, asub.submission_text, asub.file_paths,
                        asub.submission_date, asub.late_submission_reason,
                        asub.score, asub.feedback, asub.status, asub.is_graded,
                        asub.graded_at, asub.created_at, asub.updated_at,
                        u.name as student_name, u.email as student_email,
                        c.class_name, s.subject_name
                    FROM assignment_submissions asub
                    JOIN users u ON asub.user_id = u.id
                    LEFT JOIN classes c ON (SELECT class_id FROM assignments WHERE id = ?) = c.id
                    LEFT JOIN subjects s ON (SELECT subject_id FROM assignments WHERE id = ?) = s.id
                    WHERE asub.assignment_id = ?
                    ORDER BY asub.submission_date DESC";
$submissions_stmt = $conn->prepare($submissions_sql);
if (!$submissions_stmt) {
    $_SESSION['error_msg'] = "Failed to fetch submissions: " . $conn->error;
    $submissions = [];
} else {
    $submissions_stmt->bind_param("iii", $assignment_id, $assignment_id, $assignment_id);
    $submissions_stmt->execute();
    $result = $submissions_stmt->get_result();
    $submissions = $result->fetch_all(MYSQLI_ASSOC);
    $submissions_stmt->close();
}

// Parse questions from assignment
$questions = json_decode($assignment['questions'] ?? '[]', true);
if (!is_array($questions)) $questions = [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage and grade student submissions at QueensPa School.">
    <title>Manage Submissions - <?= htmlspecialchars($assignment['title']) ?> | QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: { brand: { 500: '#3b82f6', 600: '#2563eb' } }
                }
            }
        };
    </script>
</head>
<body class="min-h-screen bg-gray-50">
    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    if (file_exists($header_path)) include $header_path;
    else echo '<header class="bg-blue-600 text-white p-4"><h1>QueensPa School</h1></header>';
    ?>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                    <i class="fas fa-clipboard-check text-brand-500"></i>
                    Manage Submissions
                </h1>
                <p class="text-gray-600 mt-1">
                    Assignment: <strong><?= htmlspecialchars($assignment['title']) ?></strong> 
                    (Max: <?= number_format($assignment['max_points'], 1) ?> points)
                </p>
            </div>
            <a href="/teacher/manage_assignments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                <i class="fas fa-arrow-left mr-2"></i>Back to Assignments
            </a>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($_SESSION['error_msg']) ?>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-check-circle mr-2"></i> <?= htmlspecialchars($_SESSION['success_msg']) ?>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow-sm border p-4 text-center">
                <div class="text-2xl font-bold text-gray-800"><?= count($submissions) ?></div>
                <div class="text-xs text-gray-500">Total Submissions</div>
            </div>
            <?php 
            $graded = 0; $pending = 0;
            foreach ($submissions as $sub) {
                if ($sub['is_graded']) $graded++;
                else $pending++;
            }
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-yellow-100 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600"><?= $pending ?></div>
                <div class="text-xs text-gray-500">Pending</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-green-100 p-4 text-center">
                <div class="text-2xl font-bold text-green-600"><?= $graded ?></div>
                <div class="text-xs text-gray-500">Graded</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">
                    <?= count($submissions) > 0 ? round(($graded / count($submissions)) * 100) : 0 ?>%
                </div>
                <div class="text-xs text-gray-500">Progress</div>
            </div>
        </div>

        <!-- Submissions List -->
        <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">
            <div class="p-4 border-b bg-gray-50 flex items-center justify-between">
                <h2 class="font-semibold text-gray-800">Student Submissions</h2>
                <?php if (!empty($questions)): ?>
                    <span class="text-xs text-gray-500"><?= count($questions) ?> question(s)</span>
                <?php endif; ?>
            </div>
            
            <?php if (empty($submissions)): ?>
                <div class="p-12 text-center">
                    <div class="text-5xl mb-3">📭</div>
                    <h3 class="text-lg font-semibold text-gray-700">No Submissions Yet</h3>
                    <p class="text-gray-500 text-sm">Students haven't submitted any work for this assignment.</p>
                </div>
            <?php else: ?>
                <div class="divide-y">
                    <?php foreach ($submissions as $submission): 
                        $studentAnswers = json_decode($submission['answers'] ?? '[]', true);
                        $files = $submission['file_paths'] ? explode(',', $submission['file_paths']) : [];
                        $isLate = $submission['status'] === 'late';
                    ?>
                        <div class="p-4 hover:bg-gray-50 transition">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3">
                                        <div class="bg-brand-100 text-brand-600 w-10 h-10 rounded-full flex items-center justify-center font-bold">
                                            <?= strtoupper(substr($submission['student_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-gray-900"><?= htmlspecialchars($submission['student_name']) ?></h3>
                                            <p class="text-xs text-gray-500">
                                                <?= htmlspecialchars($submission['student_email']) ?> • 
                                                Submitted: <?= date('M j, Y g:i A', strtotime($submission['submission_date'])) ?>
                                                <?php if ($isLate): ?>
                                                    <span class="text-red-500 ml-2">⚠️ Late</span>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($submission['late_submission_reason']): ?>
                                                <p class="text-xs text-red-500 mt-1">Reason: <?= htmlspecialchars($submission['late_submission_reason']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Answers Preview -->
                                    <?php if (!empty($studentAnswers)): ?>
                                        <div class="mt-3 ml-13 space-y-2">
                                            <?php foreach ($studentAnswers as $index => $answer): 
                                                $qText = $answer['question_text'] ?? "Question " . ($index + 1);
                                                $ansText = $answer['answer'] ?? 'No answer';
                                            ?>
                                                <div class="bg-gray-50 rounded-lg p-3">
                                                    <p class="text-xs font-medium text-gray-500"><?= htmlspecialchars($qText) ?></p>
                                                    <p class="text-sm text-gray-800 mt-1"><?= nl2br(htmlspecialchars($ansText)) ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Submission Text -->
                                    <?php if ($submission['submission_text'] && empty($studentAnswers)): ?>
                                        <div class="mt-3 bg-gray-50 rounded-lg p-3">
                                            <p class="text-xs font-medium text-gray-500">Submission:</p>
                                            <p class="text-sm text-gray-800 mt-1"><?= nl2br(htmlspecialchars($submission['submission_text'])) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Files -->
                                    <?php if (!empty($files)): ?>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <?php foreach ($files as $file): ?>
                                                <a href="<?= htmlspecialchars($file) ?>" target="_blank" 
                                                   class="bg-blue-50 text-blue-700 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-100 transition flex items-center gap-1">
                                                    <i class="fas fa-download"></i> <?= basename($file) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Grading Section -->
                                <div class="sm:w-64 flex-shrink-0">
                                    <?php if ($submission['is_graded']): ?>
                                        <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                            <div class="text-center">
                                                <div class="text-2xl font-bold text-green-700">
                                                    <?= number_format($submission['score'], 1) ?>/<?= number_format($assignment['max_points'], 1) ?>
                                                </div>
                                                <div class="text-xs text-green-600 mt-1">
                                                    Graded: <?= date('M j', strtotime($submission['graded_at'])) ?>
                                                </div>
                                                <?php if ($submission['feedback']): ?>
                                                    <p class="text-xs text-gray-600 mt-2 bg-white rounded p-2">
                                                        <?= htmlspecialchars($submission['feedback']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 space-y-3">
                                            <input type="hidden" name="action" value="grade_submission">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="submission_id" value="<?= $submission['id'] ?>">
                                            
                                            <div>
                                                <label class="block text-xs font-medium text-gray-700 mb-1">
                                                    Score (Max: <?= number_format($assignment['max_points'], 1) ?>)
                                                </label>
                                                <input type="number" name="score" 
                                                       min="0" max="<?= $assignment['max_points'] ?>" 
                                                       step="0.1" required
                                                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500"
                                                       placeholder="0.0">
                                            </div>
                                            
                                            <div>
                                                <label class="block text-xs font-medium text-gray-700 mb-1">Feedback</label>
                                                <textarea name="feedback" rows="2"
                                                          class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 resize-none"
                                                          placeholder="Optional feedback..."></textarea>
                                            </div>
                                            
                                            <button type="submit" 
                                                    class="w-full bg-brand-500 hover:bg-brand-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                                                <i class="fas fa-check mr-1"></i> Submit Grade
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (file_exists($footer_path)) include $footer_path;
    else echo '<footer class="bg-gray-800 text-white p-4 text-center mt-8">© 2026 QueensPa School</footer>';
    
    mysqli_close($conn);
    ob_end_flush();
    ?>
</body>
</html>