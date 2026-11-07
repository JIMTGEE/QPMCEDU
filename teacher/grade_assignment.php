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
        error_log("Activity: [user_id=$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Check database connection
if (!isset($conn) || !$conn || $conn->connect_error) {
    error_log("Database connection error: " . ($conn->connect_error ?? 'Unknown'));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate teacher_id
$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, department_id FROM users WHERE id = ? AND role = 'teacher'";
$user_stmt = $conn->prepare($user_check_sql);
if ($user_stmt) {
    $user_stmt->bind_param("i", $teacher_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid teacher_id: $teacher_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $department_id = $user['department_id'];
    $user_stmt->close();
} else {
    error_log("Failed to prepare user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: cannot validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Access Grade Assignment Page", "Teacher accessed grade assignment page");

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Validate parameters (accept 'id' from manage_assignments.php)
$assignment_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$submission_id = filter_input(INPUT_GET, 'submission_id', FILTER_VALIDATE_INT);
if (!$assignment_id || !$submission_id) {
    error_log("Invalid parameters: id=$assignment_id, submission_id=$submission_id");
    $_SESSION['error_msg'] = "Invalid assignment or submission ID.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// Fetch assignment
$assignment_sql = "SELECT a.id, a.title, a.description, a.max_points, a.questions, a.is_group_assignment, a.group_id, 
                          a.due_date, c.class_name, s.subject_name, g.name AS group_name
                   FROM assignments a
                   LEFT JOIN classes c ON a.class_id = c.id
                   LEFT JOIN subjects s ON a.subject_id = s.id
                   LEFT JOIN groups g ON a.group_id = g.id
                   WHERE a.id = ? AND a.teacher_id = ?";
$assignment_stmt = $conn->prepare($assignment_sql);
$assignment_stmt->bind_param("ii", $assignment_id, $teacher_id);
$assignment_stmt->execute();
$assignment_result = $assignment_stmt->get_result();
if ($assignment_result->num_rows === 0) {
    error_log("Assignment not found: assignment_id=$assignment_id, teacher_id=$teacher_id");
    $_SESSION['error_msg'] = "Assignment not found or not accessible.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}
$assignment = $assignment_result->fetch_assoc();
$assignment_stmt->close();

// Fetch submission
$submission_sql = "SELECT asub.id, asub.user_id, asub.group_id, asub.answers, asub.file_paths, asub.status, 
                          asub.submission_date, asub.score, asub.feedback, u.first_name, u.last_name, g.name AS group_name
                   FROM assignment_submissions asub
                   LEFT JOIN users u ON asub.user_id = u.id
                   LEFT JOIN groups g ON asub.group_id = g.id
                   WHERE asub.id = ? AND asub.assignment_id = ?";
$submission_stmt = $conn->prepare($submission_sql);
$submission_stmt->bind_param("ii", $submission_id, $assignment_id);
$submission_stmt->execute();
$submission_result = $submission_stmt->get_result();
if ($submission_result->num_rows === 0) {
    error_log("Submission not found: submission_id=$submission_id, assignment_id=$assignment_id");
    $_SESSION['error_msg'] = "Submission not found.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}
$submission = $submission_result->fetch_assoc();
$submission_stmt->close();

// Parse answers
$answers = [];
if ($submission['answers']) {
    $decoded = json_decode($submission['answers'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $a) {
            if (isset($a['question_text'], $a['answer']) && trim($a['question_text'])) {
                $answers[] = [
                    'question_text' => $a['question_text'],
                    'answer' => $a['answer'],
                    'type' => $a['type'] ?? 'text'
                ];
            }
        }
    } else {
        error_log("Invalid answers JSON: submission_id=$submission_id, error=" . json_last_error_msg());
    }
}

// Parse file paths
$file_paths = $submission['file_paths'] ? explode(',', $submission['file_paths']) : [];

// Calculate grading progress
$progress_sql = "SELECT COUNT(*) AS total, SUM(is_graded) AS graded
                 FROM assignment_submissions WHERE assignment_id = ?";
$progress_stmt = $conn->prepare($progress_sql);
$progress_stmt->bind_param("i", $assignment_id);
$progress_stmt->execute();
$progress_result = $progress_stmt->get_result();
$progress = $progress_result->fetch_assoc();
$progress_percent = $progress['total'] > 0 ? ($progress['graded'] / $progress['total']) * 100 : 0;
$progress_stmt->close();

// Handle form submission
$error_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
            throw new Exception("Invalid CSRF token.");
        }

        // Validate score
        $score = filter_input(INPUT_POST, 'score', FILTER_VALIDATE_FLOAT);
        if ($score === false || $score < 0 || $score > $assignment['max_points']) {
            throw new Exception("Score must be between 0 and {$assignment['max_points']}.");
        }

        // Sanitize feedback
        $feedback = trim(htmlspecialchars($_POST['feedback'] ?? ''));

        // Update submission
        $update_sql = "UPDATE assignment_submissions 
                       SET score = ?, feedback = ?, status = 'graded', is_graded = 1, 
                           graded_by = ?, graded_at = NOW(), updated_at = NOW()
                       WHERE id = ? AND assignment_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("dsiii", $score, $feedback, $teacher_id, $submission_id, $assignment_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Log activity
        logActivity($conn, $teacher_id, "Graded Submission", 
                    "Graded submission ID $submission_id for assignment ID $assignment_id with score $score");

        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $conn->commit();
        $_SESSION['success_msg'] = "Submission graded successfully.";
        header("Location: /teacher/manage_assignments.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Grading error: " . $e->getMessage());
        $error_msg = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Grade student assignment submissions for QueensPa School.">
    <meta name="keywords" content="QueensPa School, grade assignments, teacher dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Grade Assignment - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
                        sans: ['Poppins', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        primary: {
                            50: '#e6f4fa', 500: '#0288d1', 600: '#0270a8',
                        },
                        accent: {
                            500: '#4caf50', 600: '#43a047',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.5s ease-out',
                    },
                    keyframes: {
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                    },
                },
            },
        };
    </script>

    <style>
        body {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .accordion-header:hover {
            background-color: #f1f5f9;
        }
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.1);
            border-color: #0288d1;
        }
        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875rem;
        }
        .is-invalid ~ .invalid-feedback {
            display: block;
        }
        .modal {
            transition: opacity 0.3s ease;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50 font-sans">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    if (!file_exists($header_path)) {
        error_log("Header missing: $header_path");
        $_SESSION['error_msg'] = "System error: page header not found.";
        header("Location: /login.php");
        exit();
    }
    include $header_path;
    ?>

    <main class="container mx-auto px-4 py-12 max-w-5xl">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-center mb-8 animate-slide-up">
            <h1 class="text-3xl font-bold text-gray-800 flex items-center">
                <i class="fas fa-clipboard-check text-primary-500 mr-3"></i>
                Grade: <?= htmlspecialchars($assignment['title']) ?>
            </h1>
            <a href="/teacher/manage_assignments.php" class="mt-4 sm:mt-0 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center" aria-label="Back to assignments">
                <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
            </a>
        </div>

        <!-- Messages -->
        <?php if ($error_msg): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up" role="alert">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up" role="alert">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['success_msg']) ?>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Grading Progress -->
        <section class="bg-white rounded-xl shadow-md p-6 mb-8 animate-slide-up">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Grading Progress</h2>
            <div class="flex items-center justify-between mb-2">
                <span class="text-gray-600">
                    <?= $progress['graded'] ?> of <?= $progress['total'] ?> submissions graded
                </span>
                <span class="text-sm text-gray-500">
                    <?= number_format($progress_percent, 1) ?>% complete
                </span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
                <div class="bg-primary-500 h-2.5 rounded-full" style="width: <?= $progress_percent ?>%"></div>
            </div>
        </section>

        <!-- Submission Details -->
        <section class="bg-white rounded-xl shadow-md p-6 mb-8 animate-slide-up">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Submission Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-700">Class</p>
                    <p class="text-gray-600"><?= htmlspecialchars($assignment['class_name'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-700">Subject</p>
                    <p class="text-gray-600"><?= htmlspecialchars($assignment['subject_name'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-700">Submitted By</p>
                    <p class="text-gray-600">
                        <?= $assignment['is_group_assignment']
                            ? htmlspecialchars($submission['group_name'] ?? 'N/A') . ' (Group)'
                            : htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']) ?>
                    </p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-700">Submission Date</p>
                    <p class="text-gray-600">
                        <?= $submission['submission_date'] ? date('Y-m-d H:i:s', strtotime($submission['submission_date'])) : 'N/A' ?>
                    </p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-700">Status</p>
                    <p class="text-gray-600 capitalize"><?= htmlspecialchars($submission['status']) ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-700">Max Points</p>
                    <p class="text-gray-600"><?= number_format($assignment['max_points'], 2) ?></p>
                </div>
            </div>
        </section>

        <!-- Submission Content -->
        <section class="bg-white rounded-xl shadow-md p-6 mb-8 animate-slide-up">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Submission Content</h2>
            <!-- Accordion -->
            <div class="space-y-2">
                <!-- Answers -->
                <?php if (!empty($answers)): ?>
                    <div class="accordion">
                        <div class="accordion-header flex justify-between items-center p-4 bg-gray-50 rounded-lg cursor-pointer">
                            <h3 class="text-lg font-medium text-gray-800">Student Answers</h3>
                            <i class="fas fa-chevron-down transition-transform"></i>
                        </div>
                        <div class="accordion-content p-4 hidden">
                            <?php foreach ($answers as $index => $answer): ?>
                                <div class="mb-4">
                                    <h4 class="text-md font-medium text-gray-700">Question <?= $index + 1 ?></h4>
                                    <p class="text-gray-600 mb-2"><?= htmlspecialchars($answer['question_text']) ?></p>
                                    <p class="text-gray-600 bg-gray-50 p-3 rounded-lg"><?= htmlspecialchars($answer['answer']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Attachments -->
                <?php if (!empty($file_paths)): ?>
                    <div class="accordion">
                        <div class="accordion-header flex justify-between items-center p-4 bg-gray-50 rounded-lg cursor-pointer">
                            <h3 class="text-lg font-medium text-gray-800">Attachments</h3>
                            <i class="fas fa-chevron-down transition-transform"></i>
                        </div>
                        <div class="accordion-content p-4 hidden">
                            <?php foreach ($file_paths as $file_path): ?>
                                <a href="<?= htmlspecialchars($file_path) ?>" class="text-primary-500 hover:text-primary-600 flex items-center mb-2" target="_blank" aria-label="Download file">
                                    <i class="fas fa-download mr-2"></i>
                                    <?= htmlspecialchars(basename($file_path)) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Grading Form -->
        <section class="bg-white rounded-xl shadow-md p-6 animate-slide-up">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Grade Submission</h2>
            <form id="grading-form" action="grade_assignment.php?id=<?= $assignment_id ?>&submission_id=<?= $submission_id ?>" method="POST" class="space-y-6" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div>
                    <label for="score" class="block text-sm font-medium text-gray-700 mb-1">
                        Score <span class="text-red-500">*</span>
                    </label>
                    <input type="number" id="score" name="score" step="0.01" min="0" max="<?= $assignment['max_points'] ?>" 
                           value="<?= htmlspecialchars($submission['score'] ?? '') ?>" 
                           class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" 
                           required placeholder="Enter score" aria-label="Score">
                    <div class="invalid-feedback">
                        Please enter a score between 0 and <?= $assignment['max_points'] ?>.
                    </div>
                </div>
                <div>
                    <label for="feedback" class="block text-sm font-medium text-gray-700 mb-1">Feedback</label>
                    <textarea id="feedback" name="feedback" rows="5" 
                              class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" 
                              placeholder="Enter feedback" aria-label="Feedback"><?= htmlspecialchars($submission['feedback'] ?? '') ?></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="window.location.href='/teacher/manage_assignments.php'" 
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium" 
                            aria-label="Cancel">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </button>
                    <button type="button" id="submit-grade-btn" 
                            class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium" 
                            aria-label="Submit grade">
                        <i class="fas fa-save mr-2"></i> Submit Grade
                    </button>
                </div>
            </form>
        </section>
    </main>

    <!-- Confirmation Modal -->
    <div id="confirm-modal" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-lg p-6 max-w-md w-full">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Confirm Grading</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to submit this grade? This action cannot be undone.</p>
            <div class="flex justify-end space-x-3">
                <button id="cancel-modal" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Cancel
                </button>
                <button id="confirm-submit" class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Footer missing: $footer_path");
        $_SESSION['error_msg'] = "System error: page footer not found.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    $conn->close();
    ob_end_flush();
    ?>

    <script>
        $(document).ready(function() {
            const loadingOverlay = $('#loading-overlay');
            const form = $('#grading-form');
            const scoreInput = $('#score');
            const maxPoints = <?= $assignment['max_points'] ?>;
            const confirmModal = $('#confirm-modal');
            const submitBtn = $('#submit-grade-btn');
            const confirmSubmit = $('#confirm-submit');
            const cancelModal = $('#cancel-modal');

            // Show loading overlay
            loadingOverlay.removeClass('hidden');
            setTimeout(() => loadingOverlay.addClass('hidden'), 500);

            // Accordion toggle
            $('.accordion-header').on('click', function() {
                const content = $(this).next('.accordion-content');
                const icon = $(this).find('i');
                content.slideToggle(300);
                icon.toggleClass('fa-chevron-down fa-chevron-up');
            });

            // Form validation
            function validateForm() {
                const score = parseFloat(scoreInput.val());
                if (isNaN(score) || score < 0 || score > maxPoints) {
                    scoreInput.addClass('is-invalid');
                    return false;
                }
                scoreInput.removeClass('is-invalid');
                return true;
            }

            // Real-time score validation
            scoreInput.on('input', function() {
                validateForm();
            });

            // Show confirmation modal
            submitBtn.on('click', function() {
                if (validateForm()) {
                    confirmModal.removeClass('hidden');
                }
            });

            // Confirm submission
            confirmSubmit.on('click', function() {
                loadingOverlay.removeClass('hidden');
                form.submit();
            });

            // Close modal
            cancelModal.on('click', function() {
                confirmModal.addClass('hidden');
            });

            // Auto-dismiss alerts
            $('[role="alert"]').each(function() {
                setTimeout(() => {
                    $(this).fadeOut(500, () => $(this).remove());
                }, 5000);
            });
        });
    </script>
</body>
</html>