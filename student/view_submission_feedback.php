<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Restrict to students only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: /login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

$submission_id = filter_input(INPUT_GET, 'submission_id', FILTER_VALIDATE_INT);
if (!$submission_id) {
    $_SESSION['error_msg'] = "Invalid submission ID.";
    header("Location: /student/manage_student_assignments.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$sql = "SELECT s.*, a.title as assignment_title, a.max_points, a.due_date
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        WHERE s.id = ? AND (s.user_id = ? OR s.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?))";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $submission_id, $student_id, $student_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$submission) {
    $_SESSION['error_msg'] = "Submission not found or access denied.";
    header("Location: /student/manage_student_assignments.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en" class="<?= isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <title>Submission Feedback - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php'; ?>
    
    <div class="container mx-auto px-4 py-12 max-w-4xl">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-success-500 to-success-700 px-6 py-4">
                <h1 class="text-2xl font-bold text-white">Assignment Feedback</h1>
            </div>
            <div class="p-6">
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-white"><?= htmlspecialchars($submission['assignment_title']) ?></h2>
                    <p class="text-gray-600 dark:text-gray-400">Submitted on <?= date('F j, Y', strtotime($submission['submission_date'])) ?></p>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Score</p>
                        <p class="text-3xl font-bold <?= $submission['score'] !== null ? 'text-success-600' : 'text-gray-400' ?>">
                            <?= $submission['score'] !== null ? $submission['score'] . ' / ' . $submission['max_points'] : 'Pending' ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                        <p class="text-lg font-medium">
                            <?php if ($submission['status'] === 'graded'): ?>
                                <span class="text-success-600">Graded</span>
                            <?php elseif ($submission['status'] === 'late'): ?>
                                <span class="text-warning-600">Late</span>
                            <?php else: ?>
                                <span class="text-gray-500">Submitted</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($submission['feedback'])): ?>
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-800 dark:text-white mb-2">Feedback from AI</h3>
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <?= nl2br(htmlspecialchars($submission['feedback'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="flex justify-end">
                    <a href="/student/manage_student_assignments.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm transition">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php'; ?>
</body>
</html>