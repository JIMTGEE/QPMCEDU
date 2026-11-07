<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher exists and fetch department
$teacher_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Teacher not found for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}
$teacher_department_id = $teacher['department_id'];

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Edit Lesson Plan Page', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed edit lesson plan page";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log page access: " . $log_stmt->error);
}
$log_stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

// Get lesson plan ID from URL
$plan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$plan_id || $plan_id <= 0) {
    $_SESSION['error_msg'] = "Invalid lesson plan ID.";
    header("Location: /teacher/lesson_plan.php");
    exit();
}

// Fetch lesson plan details
$plan_sql = "SELECT tn.*, cs.id AS schedule_id, c.class_name, s.subject_name, ac.term_name,
             CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
             FROM teacher_notes tn
             JOIN class_schedules cs ON tn.class_id = cs.class_id AND tn.term_id = cs.term_id AND tn.subject_id = cs.subject_id
             JOIN classes c ON tn.class_id = c.id
             LEFT JOIN subjects s ON tn.subject_id = s.id
             JOIN academic_calendar ac ON tn.term_id = ac.id
             WHERE tn.id = ? AND tn.teacher_id = ?";
$plan_stmt = $conn->prepare($plan_sql);
$plan_stmt->bind_param("ii", $plan_id, $teacher_id);
$plan_stmt->execute();
$plan = $plan_stmt->get_result()->fetch_assoc();
$plan_stmt->close();

if (!$plan) {
    error_log("Lesson plan not found for ID: $plan_id, teacher_id: $teacher_id");
    $_SESSION['error_msg'] = "Lesson plan not found or you do not have permission to edit it.";
    header("Location: /teacher/lesson_plan.php");
    exit();
}

// Handle AJAX request for schedule details (same as lesson_plan.php)
if (isset($_GET['action']) && $_GET['action'] === 'get_schedule_details') {
    $schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
    $response = ['schedule' => null, 'error' => '', 'debug' => ''];

    if ($schedule_id <= 0) {
        $response['error'] = 'Invalid schedule ID.';
        $response['debug'] = 'Schedule ID: ' . $schedule_id;
    } else {
        $schedule_sql = "SELECT cs.id, cs.class_id, cs.subject_id, cs.term_id, cs.department_id,
                        c.class_name, s.subject_name, ac.term_name, d.name AS department_name,
                        CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                        FROM class_schedules cs
                        JOIN classes c ON cs.class_id = c.id
                        LEFT JOIN subjects s ON cs.subject_id = s.id
                        JOIN academic_calendar ac ON cs.term_id = ac.id
                        LEFT JOIN departments d ON cs.department_id = d.id
                        WHERE cs.id = ? AND cs.teacher_id = ?";
        $schedule_stmt = $conn->prepare($schedule_sql);
        $schedule_stmt->bind_param("ii", $schedule_id, $teacher_id);
        if (!$schedule_stmt->execute()) {
            $response['error'] = 'Query execution failed.';
            $response['debug'] = 'SQL Error: ' . $schedule_stmt->error;
            error_log("Schedule query execution error: " . $schedule_stmt->error);
        } else {
            $schedule = $schedule_stmt->get_result()->fetch_assoc();
            if ($schedule) {
                $response['schedule'] = [
                    'class_id' => $schedule['class_id'],
                    'class_name' => $schedule['class_name'],
                    'subject_id' => $schedule['subject_id'] ?? 0,
                    'subject_name' => $schedule['subject_name'] ?? 'N/A',
                    'term_id' => $schedule['term_id'],
                    'term_name' => $schedule['term_name'],
                    'department_id' => $schedule['department_id'] ?? $teacher_department_id,
                    'department_name' => $schedule['department_name'] ?? 'N/A',
                    'time_slot' => $schedule['time_slot']
                ];
                $response['debug'] = 'Schedule found for ID: ' . $schedule_id;
            } else {
                $response['error'] = 'No schedule found for this ID.';
                $response['debug'] = 'Schedule ID: ' . $schedule_id . ', Teacher ID: ' . $teacher_id;
            }
        }
        $schedule_stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Fetch teacher's schedules (same as lesson_plan.php)
$schedules_sql = "SELECT cs.id, c.class_name, s.subject_name, ac.term_name, 
                 CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                 FROM class_schedules cs
                 JOIN classes c ON cs.class_id = c.id
                 LEFT JOIN subjects s ON cs.subject_id = s.id
                 JOIN academic_calendar ac ON cs.term_id = ac.id
                 WHERE cs.teacher_id = ? AND ac.start_date <= CURDATE() AND ac.end_date >= CURDATE()
                 ORDER BY cs.day_of_week, cs.start_time LIMIT 100";
$schedules_stmt = $conn->prepare($schedules_sql);
$schedules_stmt->bind_param("i", $teacher_id);
$schedules_stmt->execute();
$schedules = $schedules_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$schedules_stmt->close();

// Handle form submission
$errors = [];
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW) ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $posted_csrf)) {
        $errors[] = "Invalid CSRF token. Please try again.";
    } else {
        $csrf_check_sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
        $csrf_check_stmt = $conn->prepare($csrf_check_sql);
        $csrf_check_stmt->bind_param("is", $teacher_id, $posted_csrf);
        $csrf_check_stmt->execute();
        $csrf_valid = $csrf_check_stmt->get_result()->num_rows > 0;
        $csrf_check_stmt->close();

        if (!$csrf_valid) {
            $errors[] = "Invalid or expired CSRF token.";
        }
    }

    if (empty($errors)) {
        $action = $_POST['action'] ?? '';
        if ($action === 'submit') {
            $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT);
            $schedule_id = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $term_id = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT);
            $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
            $strand = trim(filter_input(INPUT_POST, 'strand', FILTER_UNSAFE_RAW) ?? '');
            $sub_strand = trim(filter_input(INPUT_POST, 'sub_strand', FILTER_UNSAFE_RAW) ?? '');
            $content_standard = trim(filter_input(INPUT_POST, 'content_standard', FILTER_UNSAFE_RAW) ?? '');
            $indicator = trim(filter_input(INPUT_POST, 'indicator', FILTER_UNSAFE_RAW) ?? '');
            $objectives = trim(filter_input(INPUT_POST, 'objectives', FILTER_UNSAFE_RAW) ?? '');
            $reference = trim(filter_input(INPUT_POST, 'reference', FILTER_UNSAFE_RAW) ?? '');
            $resources = trim(filter_input(INPUT_POST, 'resources', FILTER_UNSAFE_RAW) ?? '');
            $keywords = trim(filter_input(INPUT_POST, 'keywords', FILTER_UNSAFE_RAW) ?? '');
            $starter = trim(filter_input(INPUT_POST, 'starter', FILTER_UNSAFE_RAW) ?? '');
            $presentation = trim(filter_input(INPUT_POST, 'presentation', FILTER_UNSAFE_RAW) ?? '');
            $summary = trim(filter_input(INPUT_POST, 'summary', FILTER_UNSAFE_RAW) ?? '');

            // Validate inputs (same as lesson_plan.php)
            if (!$week || $week < 1 || $week > 16) {
                $errors[] = "Invalid week number (1-16 required).";
            }
            if (!$schedule_id) {
                $errors[] = "Invalid schedule selected.";
            }
            if (!$class_id) {
                $errors[] = "Invalid class selected.";
            }
            if (!$term_id) {
                $errors[] = "Invalid term selected.";
            }
            if (!$subject_id) {
                $errors[] = "Invalid subject selected.";
            }
            if (!$department_id) {
                $errors[] = "Invalid department selected.";
            }
            if (empty($strand) || !preg_match('/^Strand \d{1,2}$/', $strand)) {
                $errors[] = "Invalid strand selected.";
            }
            if (empty($sub_strand) || !preg_match('/^Sub-Strand \d{1,2}$/', $sub_strand)) {
                $errors[] = "Invalid sub-strand selected.";
            }
            if (empty($content_standard) || !preg_match('/^Content Standard \d{1,2}$/', $content_standard)) {
                $errors[] = "Invalid content standard selected.";
            }
            if (empty($indicator) || !preg_match('/^Indicator \d{1,2}$/', $indicator)) {
                $errors[] = "Invalid indicator selected.";
            }
            if (empty($objectives)) {
                $errors[] = "Objectives are required.";
            }
            if (empty($reference)) {
                $errors[] = "Reference is required.";
            }
            if (empty($resources)) {
                $errors[] = "Resources are required.";
            }
            if (empty($keywords)) {
                $errors[] = "Keywords are required.";
            }
            if (empty($starter)) {
                $errors[] = "Starter is required.";
            }
            if (empty($presentation)) {
                $errors[] = "Presentation is required.";
            }
            if (strlen($presentation) > 5000) {
                $errors[] = "Presentation cannot exceed 5000 characters.";
            }
            if (empty($summary)) {
                $errors[] = "Summary is required.";
            }

            if (empty($errors)) {
                // Verify lesson plan still exists and belongs to teacher
                $check_sql = "SELECT id FROM teacher_notes WHERE id = ? AND teacher_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ii", $plan_id, $teacher_id);
                $check_stmt->execute();
                $plan_exists = $check_stmt->get_result()->num_rows > 0;
                $check_stmt->close();

                if (!$plan_exists) {
                    $errors[] = "Lesson plan not found or you do not have permission to edit it.";
                } else {
                    $conn->begin_transaction();
                    try {
                        $update_sql = "UPDATE teacher_notes SET
                            week = ?, class_id = ?, term_id = ?, subject_id = ?, strand = ?, sub_strand = ?,
                            content_standard = ?, indicator = ?, objectives = ?, reference = ?, resources = ?,
                            keywords = ?, starter = ?, presentation = ?, summary = ?, updated_at = NOW()
                            WHERE id = ? AND teacher_id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param(
                            "iiiisssssssssssii",
                            $week, $class_id, $term_id, $subject_id, $strand, $sub_strand,
                            $content_standard, $indicator, $objectives, $reference, $resources,
                            $keywords, $starter, $presentation, $summary, $plan_id, $teacher_id
                        );
                        if ($update_stmt->execute()) {
                            $success_msg = "Lesson plan updated successfully!";
                            $log_desc = "Teacher updated lesson plan ID: $plan_id, class_id: $class_id, week: $week, subject_id: $subject_id";
                            $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Updated Lesson Plan', ?, ?, ?, NOW())");
                            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                            if (!$log_stmt->execute()) {
                                error_log("Failed to log lesson plan update: " . $log_stmt->error);
                            }
                            $log_stmt->close();
                            $conn->commit();
                            $_SESSION['success_msg'] = $success_msg;
                            header("Location: /teacher/lesson_plan.php");
                            exit();
                        } else {
                            $errors[] = "Failed to update lesson plan: " . $conn->error;
                            error_log("Lesson plan update error: " . $conn->error);
                            $conn->rollback();
                        }
                        $update_stmt->close();
                    } catch (Exception $e) {
                        $conn->rollback();
                        $errors[] = "Error updating lesson plan: " . $e->getMessage();
                        error_log("Lesson plan update exception: " . $e->getMessage());
                    }
                }
            }
        } elseif ($action === 'cancel') {
            header("Location: /teacher/lesson_plan.php");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit lesson plans for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, lesson plan, teacher portal, edit">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Edit Lesson Plan">
    <meta property="og:description" content="Teacher interface for editing lesson plans in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/edit_plan.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Edit Lesson Plan">
    <meta name="twitter:description" content="Teacher interface for editing lesson plans in QueensPa School.">
    <title>Edit Lesson Plan - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 1400px;
            padding: 2rem;
        }
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 2rem;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
            color: #fff;
        }
        .card-header h2, .card-header h3 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            animation: animate__pulse 0.5s;
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0.75rem;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.2);
            outline: none;
        }
        .alert {
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .section-card {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .schedule-info {
            background-color: #e9ecef;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        #schedule-container {
            min-height: 100px;
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        .loading-spinner {
            display: none;
            color: var(--primary-color);
            margin-left: 10px;
        }
        .invalid-feedback {
            display: none;
            font-size: 0.85rem;
        }
        .was-validated .form-control:invalid ~ .invalid-feedback,
        .was-validated .form-select:invalid ~ .invalid-feedback,
        .was-validated .form-select:invalid + .loading-spinner + .invalid-feedback {
            display: block;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .character-counter {
            font-size: 0.85rem;
            color: #666;
        }
        .character-counter.warning {
            color: var(--primary-color);
        }
        #loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        #loading-spinner {
            color: var(--primary-color);
            font-size: 2rem;
        }
        @media (max-width: 991.98px) {
            .container {
                padding: 1rem;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (min-width: 992px) {
            .basic-info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 2rem;
            }
        }
        @media (max-width: 575.98px) {
            .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
            .card-header h2, .card-header h3 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div id="loading-overlay" role="status" aria-label="Loading">
        <i class="fas fa-spinner fa-spin" id="loading-spinner" aria-hidden="true"></i>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>
    <div class="container my-5">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <section class="card mb-5 animate__animated animate__fadeIn">
            <div class="card-header">
                <h2>Edit Lesson Plan</h2>
            </div>
            <div class="card-body">
                <?php if (empty($schedules)): ?>
                    <div class="alert alert-danger" role="alert">No active schedules available. Please contact your administrator.</div>
                <?php else: ?>
                    <div class="section-card">
                        <form method="POST" action="" onsubmit="return validateForm() && showLoading()" novalidate id="lessonPlanForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="submit">
                            <!-- Basic Information -->
                            <div class="section-card">
                                <h5 class="mb-3">Basic Information</h5>
                                <div class="basic-info mb-4">
                                    <div>
                                        <label class="form-label" for="schedule_id">Schedule <span class="text-danger">*</span></label>
                                        <select class="form-select" id="schedule_id" name="schedule_id" required data-bs-toggle="tooltip" title="Select a schedule">
                                            <option value="">Select Schedule</option>
                                            <?php foreach ($schedules as $schedule): ?>
                                                <option value="<?php echo $schedule['id']; ?>" <?php echo $schedule['id'] == $plan['schedule_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($schedule['class_name'] . ' - ' . 
                                                        ($schedule['subject_name'] ?? 'N/A') . ' - ' . 
                                                        $schedule['term_name'] . ' (' . $schedule['time_slot'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="loading-spinner schedule-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                        <div class="invalid-feedback">Please select a schedule.</div>
                                    </div>
                                    <div>
                                        <label class="form-label" for="week">Week <span class="text-danger">*</span></label>
                                        <select class="form-select" id="week" name="week" required data-bs-toggle="tooltip" title="Select the week for this lesson">
                                            <option value="">Select Week</option>
                                            <?php for ($i = 1; $i <= 16; $i++): ?>
                                                <option value="<?php echo $i; ?>" <?php echo $i == $plan['week'] ? 'selected' : ''; ?>>Week <?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a week.</div>
                                    </div>
                                </div>
                                <input type="hidden" id="class_id" name="class_id" value="<?php echo htmlspecialchars($plan['class_id']); ?>" required>
                                <input type="hidden" id="subject_id" name="subject_id" value="<?php echo htmlspecialchars($plan['subject_id']); ?>">
                                <input type="hidden" id="term_id" name="term_id" value="<?php echo htmlspecialchars($plan['term_id']); ?>" required>
                                <input type="hidden" id="department_id" name="department_id" value="<?php echo htmlspecialchars($teacher_department_id); ?>" required>
                                <div id="schedule-container">
                                    <div class="schedule-info">
                                        <h6>Selected Schedule</h6>
                                        <p><strong>Class:</strong> <?php echo htmlspecialchars($plan['class_name']); ?></p>
                                        <p><strong>Subject:</strong> <?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></p>
                                        <p><strong>Department:</strong> <?php echo htmlspecialchars($teacher['name']); ?></p>
                                        <p><strong>Term:</strong> <?php echo htmlspecialchars($plan['term_name']); ?></p>
                                        <p><strong>Time:</strong> <?php echo htmlspecialchars($plan['time_slot']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <!-- Curriculum Details -->
                            <div class="section-card">
                                <h5 class="mb-3">Curriculum Details</h5>
                                <div class="form-grid">
                                    <div>
                                        <label class="form-label" for="strand">Strand <span class="text-danger">*</span></label>
                                        <select class="form-select" id="strand" name="strand" required data-bs-toggle="tooltip" title="Select the strand">
                                            <option value="">Select Strand</option>
                                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                                <option value="Strand <?php echo $i; ?>" <?php echo $plan['strand'] == "Strand $i" ? 'selected' : ''; ?>>Strand <?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a strand.</div>
                                    </div>
                                    <div>
                                        <label class="form-label" for="sub_strand">Sub-Strand <span class="text-danger">*</span></label>
                                        <select class="form-select" id="sub_strand" name="sub_strand" required data-bs-toggle="tooltip" title="Select the sub-strand">
                                            <option value="">Select Sub-Strand</option>
                                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                                <option value="Sub-Strand <?php echo $i; ?>" <?php echo $plan['sub_strand'] == "Sub-Strand $i" ? 'selected' : ''; ?>>Sub-Strand <?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a sub-strand.</div>
                                    </div>
                                    <div>
                                        <label class="form-label" for="content_standard">Content Standard <span class="text-danger">*</span></label>
                                        <select class="form-select" id="content_standard" name="content_standard" required data-bs-toggle="tooltip" title="Select the content standard">
                                            <option value="">Select Content Standard</option>
                                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                                <option value="Content Standard <?php echo $i; ?>" <?php echo $plan['content_standard'] == "Content Standard $i" ? 'selected' : ''; ?>>Content Standard <?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a content standard.</div>
                                    </div>
                                    <div>
                                        <label class="form-label" for="indicator">Indicator <span class="text-danger">*</span></label>
                                        <select class="form-select" id="indicator" name="indicator" required data-bs-toggle="tooltip" title="Select the indicator">
                                            <option value="">Select Indicator</option>
                                            <?php for ($i = 0; $i <= 10; $i++): ?>
                                                <option value="Indicator <?php echo $i; ?>" <?php echo $plan['indicator'] == "Indicator $i" ? 'selected' : ''; ?>>Indicator <?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select an indicator.</div>
                                    </div>
                                </div>
                            </div>
                            <!-- Lesson Details -->
                            <div class="section-card">
                                <h5 class="mb-3">Lesson Details</h5>
                                <div class="mb-3">
                                    <label class="form-label" for="objectives">Objectives <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="objectives" name="objectives" rows="3" required data-bs-toggle="tooltip" title="Enter lesson objectives"><?php echo htmlspecialchars($plan['objectives']); ?></textarea>
                                    <div class="invalid-feedback">Objectives are required.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="reference">Reference <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="reference" name="reference" rows="3" required data-bs-toggle="tooltip" title="Enter references"><?php echo htmlspecialchars($plan['reference']); ?></textarea>
                                    <div class="invalid-feedback">Reference is required.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="resources">Resources <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="resources" name="resources" rows="3" required data-bs-toggle="tooltip" title="Enter resources"><?php echo htmlspecialchars($plan['resources']); ?></textarea>
                                    <div class="invalid-feedback">Resources are required.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="keywords">Keywords <span class="text-danger">*</span></label>
                                    <input class="form-control" id="keywords" name="keywords" value="<?php echo htmlspecialchars($plan['keywords']); ?>" placeholder="e.g., critical thinking, collaboration" required data-bs-toggle="tooltip" title="Enter keywords">
                                    <div class="invalid-feedback">Keywords are required.</div>
                                </div>
                            </div>
                            <!-- Lesson Delivery -->
                            <div class="section-card">
                                <h5 class="mb-3">Lesson Delivery</h5>
                                <div class="mb-3">
                                    <label class="form-label" for="starter">Starter <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="starter" name="starter" rows="3" required data-bs-toggle="tooltip" title="Enter lesson starter"><?php echo htmlspecialchars($plan['starter']); ?></textarea>
                                    <div class="invalid-feedback">Starter is required.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="presentation">Presentation <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="presentation" name="presentation" rows="6" maxlength="5000" required data-bs-toggle="tooltip" title="Enter lesson presentation"><?php echo htmlspecialchars($plan['presentation']); ?></textarea>
                                    <div class="invalid-feedback">Presentation is required.</div>
                                    <small id="presentation-counter" class="character-counter">0/5000 characters</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="summary">Summary <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="summary" name="summary" rows="3" required data-bs-toggle="tooltip" title="Enter lesson summary"><?php echo htmlspecialchars($plan['summary']); ?></textarea>
                                    <div class="invalid-feedback">Summary is required.</div>
                                </div>
                            </div>
                            <!-- Form Actions -->
                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary" id="submit-btn">Submit</button>
                                <button type="submit" name="action" value="cancel" class="btn btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous"></script>
    <script>
        function showLoading() {
            document.getElementById('loading-overlay').style.display = 'flex';
            return true;
        }

        function validateForm() {
            const form = document.getElementById('lessonPlanForm');
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return false;
            }

            const classId = $('#class_id').val();
            const termId = $('#term_id').val();
            const departmentId = $('#department_id').val();
            if (!classId || !termId || !departmentId) {
                alert('Please select a valid schedule.');
                $('#schedule_id').focus();
                return false;
            }

            return confirm('Are you sure you want to update this lesson plan?');
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('loading-overlay').style.display = 'none';
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            const presentationTextarea = document.getElementById('presentation');
            const counter = document.getElementById('presentation-counter');
            function updateCounter() {
                const length = presentationTextarea.value.length;
                counter.textContent = `${length}/5000 characters`;
                if (length > 4500) {
                    counter.classList.add('warning');
                } else {
                    counter.classList.remove('warning');
                }
            }
            presentationTextarea.addEventListener('input', updateCounter);
            updateCounter();
        });

        $(document).ready(function() {
            // Trigger schedule details load on page load
            const initialScheduleId = $('#schedule_id').val();
            if (initialScheduleId) {
                $('#schedule_id').trigger('change');
            }

            $('#schedule_id').on('change', function() {
                const scheduleId = $(this).val();
                const scheduleContainer = $('#schedule-container');
                const classInput = $('#class_id');
                const subjectInput = $('#subject_id');
                const termInput = $('#term_id');
                const departmentInput = $('#department_id');
                const scheduleSpinner = $('.schedule-spinner');
                const submitBtn = $('#submit-btn');

                classInput.val('');
                subjectInput.val('');
                termInput.val('');
                departmentInput.val('');
                scheduleContainer.empty();
                submitBtn.prop('disabled', true);

                if (scheduleId) {
                    scheduleSpinner.show();
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_schedule_details',
                        type: 'GET',
                        data: { schedule_id: scheduleId },
                        dataType: 'json',
                        success: function(response) {
                            scheduleSpinner.hide();
                            console.log('Schedule response:', response);
                            if (response.error) {
                                scheduleContainer.html(
                                    `<div class="alert alert-warning">${response.error} (${response.debug})</div>`
                                );
                            } else if (response.schedule) {
                                classInput.val(response.schedule.class_id);
                                subjectInput.val(response.schedule.subject_id || '');
                                termInput.val(response.schedule.term_id);
                                departmentInput.val(response.schedule.department_id);

                                const html = `
                                    <div class="schedule-info">
                                        <h6>Selected Schedule</h6>
                                        <p><strong>Class:</strong> ${response.schedule.class_name}</p>
                                        <p><strong>Subject:</strong> ${response.schedule.subject_name}</p>
                                        <p><strong>Department:</strong> ${response.schedule.department_name}</p>
                                        <p><strong>Term:</strong> ${response.schedule.term_name}</p>
                                        <p><strong>Time:</strong> ${response.schedule.time_slot}</p>
                                    </div>
                                `;
                                scheduleContainer.html(html);
                                submitBtn.prop('disabled', !response.schedule.department_id);
                            } else {
                                scheduleContainer.html(
                                    '<div class="alert alert-warning">No schedule details available.</div>'
                                );
                            }
                        },
                        error: function(xhr, status, error) {
                            scheduleSpinner.hide();
                            console.error('Schedule AJAX error:', status, error, xhr.responseText);
                            scheduleContainer.html(
                                '<div class="alert alert-danger">Error fetching schedule details. Please try again.</div>'
                            );
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>