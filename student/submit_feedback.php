<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: /login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $csrf_token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        die('Invalid CSRF token.');
    }
    $stmt->close();

    $feedback = trim($_POST['feedback'] ?? '');
    $rating = $_POST['rating'] ?? '';

    if ($feedback && in_array($rating, ['1', '2', '3', '4', '5'])) {
        $stmt = $conn->prepare("INSERT INTO student_feedback (user_id, feedback, rating, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $feedback, $rating);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Feedback submitted successfully!";
            header("Location: /dashboard.php");
            exit();
        } else {
            $_SESSION['error']("Error submitting feedback.");
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "Please provide valid feedback and rating.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Feedback Submission</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
<body class="bg-gray-100 dark:bg-gray-900">
    <div class="container mx-auto px-4 py-8">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">Feedback</h2>
        <?php if (isset($_SESSION['message'])): ?>
            <p class="text-green-600 p-2"><?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?></p>
            <a href="/dashboard.php" class="text-blue-500 hover:underline">Back to Dashboard</a>
        <?php elseif (isset($_SESSION['error'])): ?>
            <p class="text-red-600 p-2"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php $conn->close(); ?>