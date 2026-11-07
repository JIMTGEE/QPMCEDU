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

// Fetch teacher details including department_id
$stmt = $conn->prepare("SELECT name, email, department_id FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed for teacher details: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch teacher details.";
    header("Location: /login.php");
    exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$teacher) {
    error_log("No teacher found for user_id: $user_id");
    $_SESSION['error_msg'] = "System error: Teacher profile not found.";
    header("Location: /login.php");
    exit;
}
$department_id = $teacher['department_id'];

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
if (!$stmt) {
    error_log("Prepare failed for CSRF token: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to generate security token.";
    header("Location: /login.php");
    exit;
}
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Fetch all classes from the database
$stmt = $conn->prepare("SELECT id, class_name FROM classes ORDER BY class_name");
if (!$stmt) {
    error_log("Prepare failed for classes: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch classes.";
    header("Location: /login.php");
    exit;
}
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch subjects based on teacher's department
$stmt = $conn->prepare("SELECT id, subject_name FROM subjects WHERE department_id = ? AND status = 'Active' ORDER BY subject_name");
if (!$stmt) {
    error_log("Prepare failed for subjects: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch subjects.";
    header("Location: /login.php");
    exit;
}
$stmt->bind_param("i", $department_id);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch distinct terms for the academic year from academic_calendar
$stmt = $conn->prepare("SELECT DISTINCT term_name, start_date, end_date FROM academic_calendar WHERE year = ? ORDER BY start_date");
if (!$stmt) {
    error_log("Prepare failed for terms: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch terms.";
    header("Location: /login.php");
    exit;
}
$stmt->bind_param("s", $academic_year);
$stmt->execute();
$terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle form submission
$errors = [];
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token_post = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $term_name = filter_input(INPUT_POST, 'term_name', FILTER_SANITIZE_STRING);
    $exam_date = filter_input(INPUT_POST, 'exam_date', FILTER_SANITIZE_STRING);
    $start_time = filter_input(INPUT_POST, 'start_time', FILTER_SANITIZE_STRING) ?: null;
    $end_time = filter_input(INPUT_POST, 'end_time', FILTER_SANITIZE_STRING) ?: null;
    $room = filter_input(INPUT_POST, 'room', FILTER_SANITIZE_STRING) ?: null;
    $instructions = filter_input(INPUT_POST, 'instructions', FILTER_SANITIZE_STRING) ?: null;

    // Validate CSRF token
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    if (!$stmt) {
        error_log("Prepare failed for CSRF validation: " . $conn->error);
        $errors[] = "System error: Unable to validate security token.";
    } else {
        $stmt->bind_param("is", $user_id, $csrf_token_post);
        $stmt->execute();
        $csrf_valid = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$csrf_valid) {
            $errors[] = "Invalid or expired security token.";
        }
    }

    // Validate inputs
    if (!$class_id || !$subject_id || !$term_name || !$exam_date) {
        $errors[] = "All required fields must be filled.";
    } elseif (strtotime($exam_date) <= strtotime(date('Y-m-d'))) {
        $errors[] = "Exam date must be in the future.";
    } elseif ($start_time && $end_time && strtotime($end_time) <= strtotime($start_time)) {
        $errors[] = "End time must be after start time.";
    }

    // Verify class exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM classes WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed for class validation: " . $conn->error);
            $errors[] = "System error: Unable to validate class.";
        } else {
            $stmt->bind_param("i", $class_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $errors[] = "Invalid class selected.";
            }
            $stmt->close();
        }

        // Verify subject exists and is active in teacher's department
        $stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND department_id = ? AND status = 'Active'");
        if (!$stmt) {
            error_log("Prepare failed for subject validation: " . $conn->error);
            $errors[] = "System error: Unable to validate subject.";
        } else {
            $stmt->bind_param("ii", $subject_id, $department_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $errors[] = "Invalid or inactive subject selected.";
            }
            $stmt->close();
        }

        // Fetch term_id from academic_calendar and validate exam date within term
        $stmt = $conn->prepare("SELECT id, start_date, end_date FROM academic_calendar WHERE term_name = ? AND year = ?");
        if (!$stmt) {
            error_log("Prepare failed for term validation: " . $conn->error);
            $errors[] = "System error: Unable to validate term.";
        } else {
            $stmt->bind_param("ss", $term_name, $academic_year);
            $stmt->execute();
            $term = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$term) {
                $errors[] = "Invalid term selected.";
            } elseif (strtotime($exam_date) < strtotime($term['start_date']) || strtotime($exam_date) > strtotime($term['end_date'])) {
                $errors[] = "Exam date must be within the term period (" . $term['start_date'] . " to " . $term['end_date'] . ").";
            }
        }
    }

    // Insert exam schedule
    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO exam_schedules (class_id, subject_id, term_id, exam_date, start_time, end_time, room, instructions, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) {
            error_log("Prepare failed for exam schedule insertion: " . $conn->error);
            $errors[] = "System error: Unable to create exam schedule.";
        } else {
            $stmt->bind_param("iiisssss", $class_id, $subject_id, $term['id'], $exam_date, $start_time, $end_time, $room, $instructions);
            if ($stmt->execute()) {
                logActivity($conn, $user_id, "Created Exam Schedule", "Exam schedule created for class_id: $class_id, subject_id: $subject_id");
                // Delete used CSRF token
                $stmt = $conn->prepare("DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?");
                $stmt->bind_param("is", $user_id, $csrf_token_post);
                $stmt->execute();
                $stmt->close();
                $success_msg = "Exam schedule created successfully!";
                $_SESSION['success_msg'] = $success_msg;
                header("Location: /teacher/manage_exam_schedules.php");
                exit;
            } else {
                error_log("Insert failed: " . $stmt->error);
                $errors[] = "Failed to create exam schedule.";
            }
            $stmt->close();
        }
    }

    // Clean up CSRF token if there were errors but token was valid
    if (!empty($errors) && $csrf_valid) {
        $stmt = $conn->prepare("DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?");
        $stmt->bind_param("is", $user_id, $csrf_token_post);
        $stmt->execute();
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Exam Schedule - QueensPa School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #4f46e5; /* Indigo-600 */
            --secondary-color: #7c3aed; /* Purple-600 */
            --accent-color: #10b981; /* Emerald-500 */
            --text-color: #1f2937; /* Gray-800 */
            --bg-color: #f3f4f6; /* Gray-100 */
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            background-image: radial-gradient(circle, rgba(79, 70, 229, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        .gradient-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }
        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
        }
        .dark {
            --bg-color: #111827; /* Gray-900 */
            --text-color: #e5e7eb; /* Gray-200 */
            background-color: var(--bg-color);
            color: var(--text-color);
            background-image: radial-gradient(circle, rgba(79, 70, 229, 0.2) 1px, transparent 1px);
        }
        .dark .card {
            background: #1f2937; /* Gray-800 */
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }
        .dark .gradient-header {
            background: linear-gradient(135deg, #4338ca, #6d28d9);
        }
        .dark input, .dark select, .dark textarea {
            background: #374151; /* Gray-700 */
            border-color: #4b5563; /* Gray-600 */
            color: #e5e7eb;
        }
        .dark input:focus, .dark select:focus, .dark textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }
        button, a.btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #4338ca;
            transform: scale(1.05);
        }
        .btn-accent {
            background-color: var(--accent-color);
            color: white;
        }
        .btn-accent:hover {
            background-color: #059669;
            transform: scale(1.05);
        }
        input, select, textarea {
            border: 1px solid #d1d5db; /* Gray-300 */
            border-radius: 0.5rem;
            padding: 0.75rem;
            background: white;
            transition: all 0.3s ease;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
            outline: none;
        }
        .error {
            color: #dc2626; /* Red-600 */
            font-size: 0.875rem;
            margin-top: 0.25rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .error.show {
            opacity: 1;
        }
        .required::after {
            content: '*';
            color: #dc2626;
            margin-left: 0.25rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            display: block;
        }
        .tooltip {
            position: relative;
        }
        .tooltip::after {
            content: attr(data-tooltip);
            position: absolute;
            background: #1f2937;
            color: white;
            font-size: 0.75rem;
            padding: 0.5rem;
            border-radius: 0.25rem;
            top: -2.5rem;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        .tooltip:hover::after {
            opacity: 1;
        }
        .progress-ring {
            display: none;
            position: fixed;
            inset: 0;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.5);
            z-index: 50;
        }
        .progress-ring.active {
            display: flex;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            width: 3rem;
            height: 3rem;
            border: 4px solid var(--primary-color);
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        .details-box {
            background: #f9fafb; /* Gray-50 */
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
        }
        .dark .details-box {
            background: #374151; /* Gray-700 */
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: 16rem;
            background: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 20;
        }
        .sidebar.open {
            transform: translateX(0);
        }
        @media (min-width: 1024px) {
            .sidebar {
                transform: translateX(0);
            }
        }
        .progress-bar {
            height: 0.25rem;
            background: var(--primary-color);
            transition: width 0.3s ease;
        }
    </style>
</head>
<body class="min-h-screen flex">
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar">
        <div class="p-4">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Teacher Dashboard</h2>
            <nav class="mt-6">
                <ul>
                    <li><a href="/teacher/dashboard.php" class="block py-2 px-4 text-gray-600 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-gray-600 rounded">Dashboard</a></li>
                    <li><a href="/teacher/manage_exam_schedules.php" class="block py-2 px-4 text-gray-600 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-gray-600 rounded">Manage Exams</a></li>
                    <li><a href="/teacher/submit_grades.php" class="block py-2 px-4 text-gray-600 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-gray-600 rounded">Submit Grades</a></li>
                    <li><a href="/logout.php" class="block py-2 px-4 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-600 rounded">Logout</a></li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
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

        <main class="container mx-auto p-6 flex-grow">
            <!-- Hero Section -->
            <section class="mb-8">
                <div class="gradient-header text-white p-6 rounded-xl shadow-lg">
                    <h1 class="text-2xl sm:text-3xl font-bold">Schedule an Exam, <?php echo htmlspecialchars($teacher['name']); ?>!</h1>
                    <p class="text-base mt-2">Plan your exam for <?php echo htmlspecialchars($academic_year); ?> with ease.</p>
                </div>
            </section>

            <!-- Create Exam Form -->
            <section class="card p-6">
                <div id="progress-ring" class="progress-ring">
                    <div class="spinner"></div>
                </div>
                <h2 class="text-xl font-bold mb-4 text-gray-800 dark:text-gray-100">
                    <i class="fas fa-calendar-plus mr-2 text-indigo-500"></i>Create Exam Schedule
                </h2>
                <div id="progress-bar" class="progress-bar w-0 mb-4"></div>
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                        <p class="font-bold">Errors:</p>
                        <ul class="list-disc pl-5">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($success_msg): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg" role="alert">
                        <p class="font-bold">Success:</p>
                        <p><?php echo htmlspecialchars($success_msg); ?></p>
                    </div>
                <?php endif; ?>
                <form id="createExamForm" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <label for="class_id" class="required">Class</label>
                        <select id="class_id" name="class_id" class="w-full tooltip" data-tooltip="Select the class for the exam" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo isset($_POST['class_id']) && $_POST['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="subject_id" class="required">Subject</label>
                        <select id="subject_id" name="subject_id" class="w-full tooltip" data-tooltip="Choose the subject for the exam" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo isset($_POST['subject_id']) && $_POST['subject_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="term_name" class="required">Term</label>
                        <select id="term_name" name="term_name" class="w-full tooltip" data-tooltip="Select the academic term" required>
                            <option value="">Select Term</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo htmlspecialchars($term['term_name']); ?>" <?php echo isset($_POST['term_name']) && $_POST['term_name'] == $term['term_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($term['term_name'] . ' (' . $term['start_date'] . ' - ' . $term['end_date'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="exam_date" class="required">Exam Date</label>
                        <input type="date" id="exam_date" name="exam_date" class="w-full tooltip" data-tooltip="Choose a future date within the term period" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo isset($_POST['exam_date']) ? htmlspecialchars($_POST['exam_date']) : ''; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="start_time">Start Time</label>
                        <input type="time" id="start_time" name="start_time" class="w-full tooltip" data-tooltip="Set the exam start time" value="<?php echo isset($_POST['start_time']) ? htmlspecialchars($_POST['start_time']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_time">End Time</label>
                        <input type="time" id="end_time" name="end_time" class="w-full tooltip" data-tooltip="Set the exam end time" value="<?php echo isset($_POST['end_time']) ? htmlspecialchars($_POST['end_time']) : ''; ?>">
                    </div>
                    <div class="form-group md:col-span-2">
                        <label for="room">Room</label>
                        <input type="text" id="room" name="room" class="w-full tooltip" data-tooltip="Enter the exam room (e.g., Room 101)" placeholder="e.g., Room 101" value="<?php echo isset($_POST['room']) ? htmlspecialchars($_POST['room']) : ''; ?>">
                    </div>
                    <div class="form-group md:col-span-2">
                        <label for="instructions">Instructions</label>
                        <textarea id="instructions" name="instructions" class="w-full tooltip" data-tooltip="Provide any special instructions for the exam" rows="4" placeholder="Enter exam instructions..."><?php echo isset($_POST['instructions']) ? htmlspecialchars($_POST['instructions']) : ''; ?></textarea>
                    </div>
                    <div class="md:col-span-2 details-box" id="term-details"></div>
                    <div class="md:col-span-2 details-box" id="students-list"></div>
                    <div class="md:col-span-2 flex justify-end space-x-4 mt-4">
                        <a href="/teacher/manage_exam_schedules.php" class="btn btn-accent">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Exam</button>
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
                <footer class="gradient-header text-white p-6 mt-auto">
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
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Sidebar Toggle
            const sidebar = document.getElementById('sidebar');
            const toggleSidebar = () => sidebar.classList.toggle('open');
            const sidebarBtn = document.createElement('button');
            sidebarBtn.innerHTML = '<i class="fas fa-bars"></i>';
            sidebarBtn.className = 'fixed z-50 top-4 left-4 btn btn-primary lg:hidden';
            sidebarBtn.setAttribute('aria-label', 'Toggle sidebar');
            document.body.appendChild(sidebarBtn);
            sidebarBtn.addEventListener('click', toggleSidebar);

            // Dark Mode Toggle
            const darkModeToggle = document.createElement('button');
            darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            darkModeToggle.className = 'fixed z-50 bottom-6 right-6 btn btn-primary';
            darkModeToggle.setAttribute('aria-label', 'Toggle dark mode');
            document.body.appendChild(darkModeToggle);
            darkModeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                localStorage.setItem('darkMode', document.body.classList.contains('dark'));
            });
            if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark');

            // Client-side validation and progress bar
            const form = document.getElementById('createExamForm');
            const startTime = document.getElementById('start_time');
            const endTime = document.getElementById('end_time');
            const examDate = document.getElementById('exam_date');
            const termSelect = document.getElementById('term_name');
            const classSelect = document.getElementById('class_id');
            const subjectSelect = document.getElementById('subject_id');
            const progressRing = document.getElementById('progress-ring');
            const progressBar = document.getElementById('progress-bar');

            const updateProgressBar = () => {
                const inputs = [classSelect, subjectSelect, termSelect, examDate, startTime, endTime];
                const filledInputs = inputs.filter(input => input.value).length;
                const progress = (filledInputs / inputs.length) * 100;
                progressBar.style.width = `${progress}%`;
            };

            form.addEventListener('submit', (e) => {
                document.querySelectorAll('.error').forEach(el => el.remove());

                if (startTime.value && endTime.value) {
                    const start = new Date(`1970-01-01T${startTime.value}:00`);
                    const end = new Date(`1970-01-01T${endTime.value}:00`);
                    if (end <= start) {
                        e.preventDefault();
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'error show';
                        errorDiv.textContent = 'End time must be after start time.';
                        endTime.parentElement.appendChild(errorDiv);
                        setTimeout(() => errorDiv.remove(), 3000);
                        return;
                    }
                }

                progressRing.classList.add('active');
            });

            [startTime, endTime, examDate, termSelect, classSelect, subjectSelect].forEach(input => {
                input.addEventListener('change', () => {
                    const existingError = input.parentElement.querySelector('.error');
                    if (existingError) existingError.remove();
                    updateProgressBar();
                });
            });

            // Dynamic fetch for term details
            termSelect.addEventListener('change', function() {
                const termName = this.value;
                const termDetails = document.getElementById('term-details');
                termDetails.innerHTML = '';
                if (termName) {
                    fetch(`/teacher/get_term_details.php?term_name=${encodeURIComponent(termName)}&academic_year=<?php echo urlencode($academic_year); ?>`)
                        .then(response => response.json())
                        .then(data => {
                            if (data) {
                                let html = `<h3 class="text-lg font-semibold">Term Details:</h3>
                                            <p><strong>Start Date:</strong> ${data.start_date || 'N/A'}</p>
                                            <p><strong>End Date:</strong> ${data.end_date || 'N/A'}</p>`;
                                termDetails.innerHTML = html;
                                examDate.setAttribute('min', data.start_date);
                                examDate.setAttribute('max', data.end_date);
                            } else {
                                termDetails.innerHTML = '<p class="text-red-500">No details found for this term.</p>';
                                examDate.removeAttribute('min');
                                examDate.removeAttribute('max');
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching term details:', error);
                            termDetails.innerHTML = '<p class="text-red-500">Error loading term details.</p>';
                            examDate.removeAttribute('min');
                            examDate.removeAttribute('max');
                        });
                } else {
                    examDate.removeAttribute('min');
                    examDate.removeAttribute('max');
                }
                updateProgressBar();
            });

            // Dynamic fetch for students in class
            classSelect.addEventListener('change', function() {
                const classId = this.value;
                const studentsList = document.getElementById('students-list');
                studentsList.innerHTML = '';
                if (classId) {
                    fetch(`/teacher/get_students.php?class_id=${classId}&academic_year=<?php echo urlencode($academic_year); ?>`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length > 0) {
                                let html = `<h3 class="text-lg font-semibold">Students in this Class:</h3><ul class="list-disc pl-5">`;
                                data.forEach(student => {
                                    html += `<li>${student.name}</li>`;
                                });
                                html += '</ul>';
                                studentsList.innerHTML = html;
                            } else {
                                studentsList.innerHTML = '<p class="text-yellow-500">No students found in this class.</p>';
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching students:', error);
                            studentsList.innerHTML = '<p class="text-red-500">Error loading students.</p>';
                        });
                }
                updateProgressBar();
            });

            // Initial progress bar update
            updateProgressBar();
        });
    </script>
</body>
</html>