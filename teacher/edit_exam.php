<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Verify user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: /login.php?error=unauthorized");
    exit;
}

$user_id = $_SESSION['user_id'];
$academic_year = $_SESSION['academic_year'] ?? '2024/2025';
$exam_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch teacher details
$stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch exam details
$exam = null;
if ($exam_id) {
    $stmt = $conn->prepare("
        SELECT es.id, es.class_id, c.class_name, es.subject_id, s.subject_name, ac.term_name, es.exam_date, es.start_time, es.end_time, es.room, es.instructions
        FROM exam_schedules es
        JOIN classes c ON es.class_id = c.id
        JOIN subjects s ON es.subject_id = s.id
        JOIN academic_calendar ac ON es.term_id = ac.id
        WHERE es.id = ? AND c.teacher_id = ?
    ");
    $stmt->bind_param("ii", $exam_id, $user_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$exam) {
    $_SESSION['error_msg'] = "Invalid or unauthorized exam schedule.";
    header("Location: manage_exam_schedules.php");
    exit;
}

// Fetch classes, subjects, and terms
$stmt = $conn->prepare("SELECT id, class_name FROM classes WHERE teacher_id = ? AND academic_year = ?");
$stmt->bind_param("is", $user_id, $academic_year);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT id, subject_name FROM subjects WHERE teacher_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT DISTINCT term_name FROM academic_calendar WHERE year = ?");
$stmt->bind_param("s", $academic_year);
$stmt->execute();
$terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Handle form submission
$errors = [];
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token_post = $_POST['csrf_token'] ?? '';
    $class_id = $_POST['class_id'] ?? '';
    $subject_id = $_POST['subject_id'] ?? '';
    $term_id = $_POST['term_id'] ?? '';
    $exam_date = $_POST['exam_date'] ?? '';
    $start_time = $_POST['start_time'] ?? null;
    $end_time = $_POST['end_time'] ?? null;
    $room = $_POST['room'] ?? null;
    $instructions = $_POST['instructions'] ?? null;

    // Validate CSRF token
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $csrf_token_post);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $errors[] = "Invalid CSRF token.";
    }
    $stmt->close();

    // Validate inputs
    if (!$class_id || !$subject_id || !$term_id || !$exam_date) {
        $errors[] = "All required fields must be filled.";
    } elseif (strtotime($exam_date) < strtotime(date('Y-m-d'))) {
        $errors[] = "Exam date must be in the future.";
    }

    // Verify teacher owns class and subject
    $stmt = $conn->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $class_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $errors[] = "Invalid class selected.";
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $subject_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $errors[] = "Invalid subject selected.";
    }
    $stmt->close();

    // Fetch term_id from academic_calendar
    $stmt = $conn->prepare("SELECT id FROM academic_calendar WHERE term_name = ? AND year = ?");
    $stmt->bind_param("ss", $term_id, $academic_year);
    $stmt->execute();
    $term = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$term) {
        $errors[] = "Invalid term selected.";
    }

    // Update exam schedule
    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE exam_schedules
            SET class_id = ?, subject_id = ?, term_id = ?, exam_date = ?, start_time = ?, end_time = ?, room = ?, instructions = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("iiisssssi", $class_id, $subject_id, $term['id'], $exam_date, $start_time, $end_time, $room, $instructions, $exam_id);
        if ($stmt->execute()) {
            logActivity($conn, $user_id, "Updated Exam Schedule", "Exam schedule updated for id: $exam_id");
            $success = true;
            $_SESSION['success_msg'] = "Exam schedule updated successfully.";
            header("Location: /manage_exam_schedules.php");
            exit;
        } else {
            $errors[] = "Failed to update exam schedule.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Exam Schedule - QueensPa School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://www.w3schools.com/w3css/4/w3.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/js-confetti@0.12.0/dist/js-confetti.browser.js" defer></script>
    <style>
        :root {
            --primary-color: #f97316;
            --secondary-color: #fb923c;
            --accent-color: #34d399;
            --text-color: #1f2937;
            --bg-color: #fefce8;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
        }
        .gradient-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }
        .card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            @apply w3-card w3-hover-shadow bg-white;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        .dark {
            --bg-color: #1f2937;
            --text-color: #e5e7eb;
            @apply bg-gray-900 text-gray-100;
        }
        .dark .card {
            @apply bg-gray-800 text-gray-100 border-gray-700;
        }
        .dark .gradient-header {
            @apply bg-gradient-to-r from-orange-600 to-orange-400;
        }
        .dark input, .dark select, .dark textarea {
            @apply text-gray-200 bg-gray-700 border-gray-600;
        }
        button, a.btn {
            @apply font-medium text-white rounded-lg px-4 py-2;
        }
        .btn-primary {
            @apply bg-orange-500 hover:bg-orange-600;
        }
        .btn-accent {
            @apply bg-green-500 hover:bg-green-600;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Header -->
    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    try {
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

    <main class="container mx-auto p-4 sm:p-6">
        <!-- Welcome Section -->
        <section class="mb-8">
            <div class="gradient-header text-white p-6 rounded-lg shadow-lg w3-animate-zoom">
                <h1 class="text-3xl sm:text-4xl font-bold">Edit Exam Schedule, <?php echo htmlspecialchars($teacher['name']); ?>!</h1>
                <p class="text-lg">Update exam schedule for <?php echo htmlspecialchars($academic_year); ?></p>
            </div>
        </section>

        <!-- Edit Exam Form -->
        <section class="mb-8 w3-container card p-6">
            <h2 class="text-2xl font-bold mb-4 text-gray-800 dark:text-gray-100"><i class="fas fa-edit mr-2"></i>Edit Exam Schedule</h2>
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <p class="font-bold">Error</p>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form id="editExamForm" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div>
                    <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Class</label>
                    <select id="class_id" name="class_id" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $exam['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="subject_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Subject</label>
                    <select id="subject_id" name="subject_id" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo $exam['subject_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="term_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Term</label>
                    <select id="term_id" name="term_id" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" required>
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?php echo htmlspecialchars($term['term_name']); ?>" <?php echo $exam['term_name'] == $term['term_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($term['term_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="exam_date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Exam Date</label>
                    <input type="date" id="exam_date" name="exam_date" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" value="<?php echo htmlspecialchars($exam['exam_date']); ?>" required>
                </div>
                <div>
                    <label for="start_time" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Start Time</label>
                    <input type="time" id="start_time" name="start_time" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" value="<?php echo htmlspecialchars($exam['start_time'] ?? ''); ?>">
                </div>
                <div>
                    <label for="end_time" class="block text-sm font-medium text-gray-700 dark:text-gray-200">End Time</label>
                    <input type="time" id="end_time" name="end_time" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" value="<?php echo htmlspecialchars($exam['end_time'] ?? ''); ?>">
                </div>
                <div>
                    <label for="room" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Room</label>
                    <input type="text" id="room" name="room" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" placeholder="e.g., Room 101" value="<?php echo htmlspecialchars($exam['room'] ?? ''); ?>">
                </div>
                <div>
                    <label for="instructions" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Instructions</label>
                    <textarea id="instructions" name="instructions" class="w-full p-2 border rounded-lg text-gray-800 dark:text-gray-200 dark:bg-gray-700" rows="4" placeholder="Enter exam instructions..."><?php echo htmlspecialchars($exam['instructions'] ?? ''); ?></textarea>
                </div>
                <div class="flex justify-end space-x-2">
                    <a href="/manage_exam_schedules.php" class="btn btn-accent">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Exam</button>
                </div>
            </form>
        </section>
    </main>

    <!-- Footer -->
    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    try {
        if (!file_exists($footer_path)) {
            ?>
            <footer class="gradient-header text-white p-6">
                <div class="container mx-auto text-center">
                    <p class="mb-2">© 2025 QueensPa School. All rights reserved.</p>
                    <div class="flex justify-center space-x-4 mb-2">
                        <a href="https://facebook.com/profile" class="text-white hover:underline"><i class="fab fa-facebook mr-1"></i>Facebook Profile</a>
                        <a href="https://facebook.com/page" class="text-white hover:underline"><i class="fab fa-facebook-square mr-1"></i>Facebook Page</a>
                        <a href="https://twitter.com" class="text-white hover:underline"><i class="fab fa-twitter mr-1"></i>Twitter</a>
                        <a href="https://instagram.com" class="text-white hover:underline"><i class="fab fa-instagram mr-1"></i>Instagram</a>
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
        // Initialize Confetti
        const confetti = new JSConfetti();

        // Dark Mode Toggle
        document.addEventListener('DOMContentLoaded', () => {
            const darkModeToggle = document.createElement('button');
            darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            darkModeToggle.className = 'fixed bottom-4 right-4 btn btn-primary';
            darkModeToggle.setAttribute('aria-label', 'Toggle dark mode');
            document.body.appendChild(darkModeToggle);
            darkModeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                localStorage.setItem('darkMode', document.body.classList.contains('dark'));
            });
            if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark');

            // Real-time Date Validation
            document.getElementById('exam_date').addEventListener('change', (e) => {
                const today = new Date().toISOString().split('T')[0];
                if (e.target.value < today) {
                    e.target.setCustomValidity('Exam date must be in the future.');
                } else {
                    e.target.setCustomValidity('');
                }
            });

            // Confetti on Success
            <?php if ($success): ?>
                confetti.addConfetti({ confettiNumber: 100 });
            <?php endif; ?>
        });
    </script>
</body>
</html>