<?php
ob_start();
session_start();

// Restrict to admins
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an admin.";
    header("Location: /login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Set time zone
date_default_timezone_set('Africa/Accra');

// Initialize variables
$admin_id = (int)$_SESSION['user_id'];
$success_msg = $error_msg = "";
$current_day = date('l'); // e.g., Tuesday
$current_time = date('H:i:s');
$current_date = date('Y-m-d');
$current_timestamp = date('Y-m-d H:i:s');

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $admin_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Fetch teachers
$teachers_sql = "SELECT u.id, u.name AS teacher_name, d.name AS department_name, u.department_id
                 FROM users u
                 LEFT JOIN departments d ON u.department_id = d.id
                 WHERE u.role = 'teacher'
                 ORDER BY u.name";
$teachers_stmt = $conn->prepare($teachers_sql);
$teachers_stmt->execute();
$teachers_result = $teachers_stmt->get_result();
$teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);
$teachers_stmt->close();

// Define observation indicators
$indicators = [
    1 => "Clarity of lesson objectives",
    2 => "Teacher's preparation and organization",
    3 => "Use of teaching and learning resources",
    4 => "Student engagement and participation",
    5 => "Classroom management and discipline",
    6 => "Pacing and time management",
    7 => "Differentiation for diverse learners",
    8 => "Assessment and feedback during the lesson",
    9 => "Teacher's communication skills",
    10 => "Overall lesson delivery effectiveness",
];

// Handle AJAX request for current schedule and lesson plan
if (isset($_GET['action']) && $_GET['action'] === 'get_current_schedule') {
    $teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
    $response = ['schedule' => null, 'lesson_plan' => null, 'error' => '', 'debug' => ''];

    if ($teacher_id <= 0) {
        $response['error'] = 'Invalid teacher ID.';
        $response['debug'] = 'Teacher ID: ' . $teacher_id;
    } else {
        // Fetch teacher's department as fallback
        $teacher_sql = "SELECT department_id, name FROM users WHERE id = ? AND role = 'teacher'";
        $teacher_stmt = $conn->prepare($teacher_sql);
        $teacher_stmt->bind_param("i", $teacher_id);
        $teacher_stmt->execute();
        $teacher_result = $teacher_stmt->get_result();
        $teacher = $teacher_result->fetch_assoc();
        $teacher_stmt->close();
        $fallback_department_id = $teacher['department_id'] ?? 0;

        // Fetch current schedule with class department
        $schedule_sql = "SELECT cs.*, c.class_name, c.department_id AS class_department_id, s.subject_name, ac.term_name, 
                            d.name AS department_name, CONCAT(cs.start_time, ' - ', cs.end_time) AS time_slot,
                            c.id AS class_id, s.id AS subject_id, ac.id AS term_id
                            FROM class_schedules cs
                            JOIN classes c ON cs.class_id = c.id
                            LEFT JOIN subjects s ON cs.subject_id = s.id
                            JOIN academic_calendar ac ON cs.term_id = ac.id
                            LEFT JOIN departments d ON c.department_id = d.id
                            WHERE cs.teacher_id = ? 
                            AND cs.day_of_week = ?
                            AND cs.start_time <= ? 
                            AND cs.end_time >= ?
                            AND ac.start_date <= ? 
                            AND ac.end_date >= ?
                            LIMIT 1";
        $schedule_stmt = $conn->prepare($schedule_sql);
        $schedule_stmt->bind_param("isssss", $teacher_id, $current_day, $current_time, $current_time, 
                                  $current_timestamp, $current_timestamp);
        if (!$schedule_stmt->execute()) {
            $response['error'] = 'Query execution failed.';
            $response['debug'] = 'SQL Error: ' . $schedule_stmt->error;
            error_log("Schedule query error for teacher_id: $teacher_id: " . $schedule_stmt->error);
        } else {
            $schedule_result = $schedule_stmt->get_result();
            $schedule = $schedule_result->fetch_assoc();

            if ($schedule) {
                $response['schedule'] = [
                    'class_id' => $schedule['class_id'],
                    'class_name' => $schedule['class_name'],
                    'subject_id' => $schedule['subject_id'] ?? 0,
                    'subject_name' => $schedule['subject_name'] ?? 'N/A',
                    'department_id' => $schedule['class_department_id'] ?? $fallback_department_id,
                    'department_name' => $schedule['department_name'] ?? 'N/A',
                    'time_slot' => $schedule['time_slot'],
                    'term_id' => $schedule['term_id'],
                    'term_name' => $schedule['term_name']
                ];

                // Check for lesson plan in teacher_notes
                if ($schedule['subject_id']) {
                    $lesson_plan_sql = "SELECT id, objectives, keywords AS topic
                                      FROM teacher_notes
                                      WHERE teacher_id = ?
                                      AND class_id = ?
                                      AND subject_id = ?
                                      AND term_id = ?
                                      AND DATE(created_at) = ?
                                      LIMIT 1";
                    $lesson_plan_stmt = $conn->prepare($lesson_plan_sql);
                    $lesson_plan_stmt->bind_param("iiiss", $teacher_id, $schedule['class_id'], 
                                                 $schedule['subject_id'], $schedule['term_id'], $current_date);
                    if (!$lesson_plan_stmt->execute()) {
                        $response['debug'] .= ' Lesson Plan SQL Error: ' . $lesson_plan_stmt->error;
                        error_log("Lesson plan query error for teacher_id: $teacher_id: " . $lesson_plan_stmt->error);
                    } else {
                        $lesson_plan_result = $lesson_plan_stmt->get_result();
                        $lesson_plan = $lesson_plan_result->fetch_assoc();
                        if ($lesson_plan) {
                            $response['lesson_plan'] = [
                                'id' => $lesson_plan['id'],
                                'topic' => $lesson_plan['topic'],
                                'objectives' => $lesson_plan['objectives']
                            ];
                        }
                    }
                    $lesson_plan_stmt->close();
                }
            } else {
                $response['error'] = 'No active schedule found for this teacher at the current time.';
                $response['debug'] = 'Teacher ID: ' . $teacher_id . ', Day: ' . $current_day . ', Time: ' . $current_time;
                $response['schedule'] = [
                    'department_id' => $fallback_department_id,
                    'department_name' => $teacher['department_name'] ?? 'N/A'
                ];
            }
        }
        $schedule_stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_observation'])) {
    // Verify CSRF token
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW) ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $posted_csrf)) {
        $error_msg = "Invalid CSRF token. Please try again.";
        error_log("Invalid CSRF token for observation submission by admin_id: $admin_id");
    } else {
        $csrf_check_sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
        $csrf_check_stmt = $conn->prepare($csrf_check_sql);
        $csrf_check_stmt->bind_param("is", $admin_id, $posted_csrf);
        $csrf_check_stmt->execute();
        $csrf_valid = $csrf_check_stmt->get_result()->num_rows > 0;
        $csrf_check_stmt->close();

        if (!$csrf_valid) {
            $error_msg = "Invalid or expired CSRF token.";
            error_log("Invalid/expired CSRF token for admin_id: $admin_id");
        } else {
            $teacher_id = (int)$_POST['teacher_id'];
            $class_id = (int)$_POST['class_id'];
            $subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
            $term_id = (int)$_POST['term_id'];
            $department_id = (int)$_POST['department_id'];
            $lesson_plan_id = isset($_POST['lesson_plan_id']) && $_POST['lesson_plan_id'] !== '' ? (int)$_POST['lesson_plan_id'] : null;
            $observation_date = $_POST['observation_date'];
            $lesson_topic = mysqli_real_escape_string($conn, $_POST['lesson_topic']);
            $scores = [];
            for ($i = 1; $i <= 10; $i++) {
                $scores[$i] = isset($_POST["score_$i"]) ? (int)$_POST["score_$i"] : 0;
            }
            $remarks = $_POST['remarks'] ? mysqli_real_escape_string($conn, $_POST['remarks']) : '';
            $field_notes = $_POST['field_notes'] ? mysqli_real_escape_string($conn, $_POST['field_notes']) : '';
            $question_log = $_POST['question_log'] ? mysqli_real_escape_string($conn, $_POST['question_log']) : '';
            $interaction_map = $_POST['interaction_map'] ? mysqli_real_escape_string($conn, $_POST['interaction_map']) : '';

            // Validation
            $validation_errors = [];
            if (!$teacher_id) $validation_errors[] = "Teacher ID is missing.";
            if (!$class_id) $validation_errors[] = "Class ID is missing.";
            if (!$term_id) $validation_errors[] = "Term ID is missing.";
            if (!$department_id) $validation_errors[] = "Department ID could not be determined.";
            if (!$observation_date) $validation_errors[] = "Observation date is missing.";
            if (!$lesson_topic) $validation_errors[] = "Lesson topic is missing.";
            foreach ($scores as $i => $score) {
                if ($score < 0 || $score > 5) {
                    $validation_errors[] = "Invalid score for criterion $i: $score.";
                }
            }

            if (!empty($validation_errors)) {
                $error_msg = "Validation errors: " . implode(" ", $validation_errors);
                error_log("Observation submission failed for admin_id: $admin_id: " . $error_msg);
                logActivity($conn, $admin_id, "Error", "Observation failed: " . $error_msg);
            } else {
                // Insert observation
                $insert_sql = "INSERT INTO lesson_observations (
                    teacher_id, class_id, subject_id, term_id, observation_date, lesson_topic,
                    score_1, score_2, score_3, score_4, score_5, score_6, score_7, score_8, score_9, score_10,
                    remarks, field_notes, question_log, interaction_map, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "iiisssiiiiiiiiisssss",
                    $teacher_id, $class_id, $subject_id, $term_id, $observation_date, $lesson_topic,
                    $scores[1], $scores[2], $scores[3], $scores[4], $scores[5],
                    $scores[6], $scores[7], $scores[8], $scores[9], $scores[10],
                    $remarks, $field_notes, $question_log, $interaction_map
                );
                try {
                    $conn->begin_transaction();
                    $insert_stmt->execute();
                    logActivity($conn, $admin_id, "Added Lesson Observation", 
                               "Teacher ID: $teacher_id, Class ID: $class_id, Subject ID: " . ($subject_id ?? 'None') . 
                               ", Term ID: $term_id, Lesson Plan ID: " . ($lesson_plan_id ?? 'None') . 
                               ", Department ID: $department_id");
                    $success_msg = "Lesson observation submitted successfully! Redirecting...";
                    $conn->commit();
                    $_POST = [];
                    header("refresh:2;url=analysis.php");
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error submitting observation: " . $e->getMessage();
                    error_log("Observation error for admin_id: $admin_id: " . $e->getMessage());
                    logActivity($conn, $admin_id, "Error", 
                               "Failed to submit observation for Teacher ID: $teacher_id: " . $e->getMessage());
                }
                $insert_stmt->close();
            }
        }
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Lesson observation system for QueensPa School.">
    <meta name="keywords" content="QueensPa School, lesson observation, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Lesson Observation">
    <meta property="og:description" content="Admin interface for observing lessons in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/observe_lesson.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Lesson Observation">
    <meta name="twitter:description" content="Admin interface for observing lessons in QueensPa School.">
    <title>Observe Lesson - QueensPa School Admin</title>
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
        .card-header h3 {
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
        .schedule-info, .lesson-plan-info {
            background-color: #e9ecef;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        #schedule-container {
            min-height: 100px;
        }
        .criterion-label {
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
        .lesson-plan-status {
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .criteria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
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
            .criteria-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (min-width: 992px) {
            .form-section {
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
            .card-header h3 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div id="loading-overlay" role="status" aria-label="Loading">
        <i class="fas fa-spinner fa-spin" id="loading-spinner" aria-hidden="true"></i>
    </div>
    <div class="content" id="content">
        <main class="container my-5">
            <section class="card animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3><i class="fas fa-chalkboard-teacher me-2"></i>Observe Lesson</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Observation Form -->
                    <div class="section-card animate__animated animate__fadeInUp">
                        <h5 class="mb-3">Lesson Observation Form</h5>
                        <form method="POST" onsubmit="return validateForm() && showLoading()" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="submit_observation" value="1">
                            <div class="form-section mb-4">
                                <div>
                                    <label for="teacher_id" class="form-label">Teacher <span class="text-danger">*</span></label>
                                    <select class="form-select" id="teacher_id" name="teacher_id" required data-bs-toggle="tooltip" title="Select the teacher to observe">
                                        <option value="">Select Teacher</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>" data-teacher-department-id="<?php echo $teacher['department_id']; ?>">
                                                <?php echo htmlspecialchars($teacher['teacher_name'] . ' (' . ($teacher['department_name'] ?? 'N/A') . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="loading-spinner teacher-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                    <div class="invalid-feedback">Please select a teacher.</div>
                                </div>
                                <div>
                                    <label for="observation_date" class="form-label">Date of Observation <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="observation_date" name="observation_date" required data-bs-toggle="tooltip" title="Select the observation date">
                                    <div class="invalid-feedback">Please select a date.</div>
                                </div>
                            </div>

                            <!-- Hidden inputs -->
                            <input type="hidden" id="class_id" name="class_id" required>
                            <input type="hidden" id="subject_id" name="subject_id">
                            <input type="hidden" id="term_id" name="term_id" required>
                            <input type="hidden" id="department_id" name="department_id" required>
                            <input type="hidden" id="lesson_plan_id" name="lesson_plan_id">

                            <div id="schedule-container" class="mb-4"></div>

                            <div class="mb-3">
                                <label for="lesson_topic" class="form-label">Lesson Topic <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="lesson_topic" name="lesson_topic" placeholder="e.g., Introduction to Algebra" required data-bs-toggle="tooltip" title="Enter the lesson topic">
                                <div class="invalid-feedback">Please enter the lesson topic.</div>
                            </div>

                            <!-- Observation Indicators -->
                            <h6 class="mb-3">Observation Indicators (Score 0-5)</h6>
                            <div class="criteria-grid">
                                <?php foreach ($indicators as $index => $indicator): ?>
                                    <div>
                                        <label for="score_<?php echo $index; ?>" class="criterion-label"><?php echo htmlspecialchars($indicator); ?> <span class="text-danger">*</span></label>
                                        <select class="form-select" id="score_<?php echo $index; ?>" name="score_<?php echo $index; ?>" required data-bs-toggle="tooltip" title="Select score for <?php echo htmlspecialchars($indicator); ?>">
                                            <option value="" disabled selected>Select Score</option>
                                            <?php for ($score = 0; $score <= 5; $score++): ?>
                                                <option value="<?php echo $score; ?>"><?php echo $score; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a score.</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <h6 class="mt-4 mb-3">Observation Details</h6>
                            <div class="mb-3">
                                <label for="remarks" class="form-label">General Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="3" placeholder="Overall comments on the lesson..." data-bs-toggle="tooltip" title="Optional general remarks"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="field_notes" class="form-label">Field Notes</label>
                                <textarea class="form-control" id="field_notes" name="field_notes" rows="3" placeholder="e.g., 10:05 AM: Teacher writes equation on board. 10:07 AM: Students raise hands to answer." data-bs-toggle="tooltip" title="Record time and observations without opinion"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="question_log" class="form-label">Question Log</label>
                                <textarea class="form-control" id="question_log" name="question_log" rows="3" placeholder="e.g., Teacher: 'What is 2+2?' Learner: 'Can we use calculators?' Task: Solve 5 equations on page 10." data-bs-toggle="tooltip" title="Record questions and tasks"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="interaction_map" class="form-label">Interaction Map</label>
                                <textarea class="form-control" id="interaction_map" name="interaction_map" rows="3" placeholder="e.g., Teacher calls on Student A to answer, Student B interrupts with a question, teacher redirects to group discussion." data-bs-toggle="tooltip" title="Record teacher-learner interactions"></textarea>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary" id="submit-btn" disabled data-bs-toggle="tooltip" title="Submit the observation">
                                    <i class="fas fa-save me-2"></i>Submit Observation
                                </button>
                                <a href="analysis.php" class="btn btn-secondary ms-2">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous"></script>
    <script>
        function showLoading() {
            document.getElementById('loading-overlay').style.display = 'flex';
            return true;
        }

        function validateForm() {
            const form = document.querySelector('form');
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return false;
            }

            const classId = $('#class_id').val();
            const termId = $('#term_id').val();
            const departmentId = $('#department_id').val();
            const observationDate = $('#observation_date').val();
            const lessonTopic = $('#lesson_topic').val();
            if (!classId) {
                alert('No valid class found for the selected teacher\'s schedule.');
                $('#teacher_id').focus();
                return false;
            }
            if (!termId) {
                alert('No valid term found for the selected teacher\'s schedule.');
                $('#teacher_id').focus();
                return false;
            }
            if (!departmentId) {
                alert('No valid department found for the selected class.');
                $('#teacher_id').focus();
                return false;
            }
            if (!observationDate) {
                alert('Please select an observation date.');
                $('#observation_date').focus();
                return false;
            }
            if (!lessonTopic) {
                alert('Please enter a lesson topic.');
                $('#lesson_topic').focus();
                return false;
            }

            const scoreSelects = document.querySelectorAll('select[name^="score_"]');
            for (let select of scoreSelects) {
                if (!select.value && select.value !== '0') {
                    alert('Please select a score for all indicators.');
                    select.focus();
                    return false;
                }
            }
            return confirm('Are you sure you want to submit this observation?');
        }

        $(document).ready(function() {
            $('#teacher_id').change(function() {
                const teacherId = $(this).val();
                const scheduleContainer = $('#schedule-container');
                const lessonPlanInput = $('#lesson_plan_id');
                const classInput = $('#class_id');
                const subjectInput = $('#subject_id');
                const termInput = $('#term_id');
                const departmentInput = $('#department_id');
                const lessonTopicInput = $('#lesson_topic');
                const teacherSpinner = $('.teacher-spinner');
                const submitBtn = $('#submit-btn');
                const selectedOption = $(this).find('option:selected');
                const fallbackDepartmentId = selectedOption.data('teacher-department-id') || 0;

                classInput.val('');
                subjectInput.val('');
                termInput.val('');
                departmentInput.val('');
                lessonPlanInput.val('');
                lessonTopicInput.val('');
                scheduleContainer.empty();
                submitBtn.prop('disabled', true);

                if (teacherId) {
                    teacherSpinner.show();
                    console.log('Fetching schedule for teacher_id:', teacherId);
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>?action=get_current_schedule',
                        type: 'GET',
                        data: { teacher_id: teacherId },
                        dataType: 'json',
                        success: function(response) {
                            teacherSpinner.hide();
                            console.log('Schedule response:', response);
                            if (response.error) {
                                scheduleContainer.html(
                                    `<div class="alert alert-warning">${response.error} (${response.debug})</div>`
                                );
                                if (response.schedule && response.schedule.department_id) {
                                    departmentInput.val(response.schedule.department_id);
                                    console.log('Set department_id (fallback):', response.schedule.department_id);
                                    scheduleContainer.append(
                                        `<div class="alert alert-info">Using teacher's department as fallback.</div>`
                                    );
                                }
                            } else if (response.schedule) {
                                classInput.val(response.schedule.class_id || '');
                                subjectInput.val(response.schedule.subject_id || '');
                                termInput.val(response.schedule.term_id || '');
                                departmentInput.val(response.schedule.department_id || fallbackDepartmentId);
                                console.log('Set department_id (class):', response.schedule.department_id || fallbackDepartmentId);

                                let html = `
                                    <div class="schedule-info">
                                        <h6>Current Schedule</h6>
                                        <p><strong>Class:</strong> ${response.schedule.class_name || 'N/A'}</p>
                                        <p><strong>Subject:</strong> ${response.schedule.subject_name || 'N/A'}</p>
                                        <p><strong>Department:</strong> ${response.schedule.department_name || 'N/A'}</p>
                                        <p><strong>Time:</strong> ${response.schedule.time_slot || 'N/A'}</p>
                                        <p><strong>Term:</strong> ${response.schedule.term_name || 'N/A'}</p>
                                    </div>
                                `;
                                if (response.lesson_plan) {
                                    html += `
                                        <div class="lesson-plan-info">
                                            <h6>Lesson Plan</h6>
                                            <p><strong>Topic:</strong> ${response.lesson_plan.topic}</p>
                                            <p><strong>Objectives:</strong> ${response.lesson_plan.objectives}</p>
                                            <p class="lesson-plan-status text-success">Lesson plan available</p>
                                        </div>
                                    `;
                                    lessonPlanInput.val(response.lesson_plan.id);
                                    lessonTopicInput.val(response.lesson_plan.topic);
                                } else {
                                    html += `
                                        <div class="lesson-plan-info">
                                            <p class="lesson-plan-status text-warning">No lesson plan found for this class and date.</p>
                                        </div>
                                    `;
                                    lessonPlanInput.val('');
                                }
                                scheduleContainer.html(html);
                                submitBtn.prop('disabled', !departmentInput.val() || !classInput.val() || !termInput.val());
                            } else {
                                scheduleContainer.html(`
                                    <div class="alert alert-warning">
                                        No active class scheduled for this teacher at the current time 
                                        (<?php echo $current_day . ', ' . date('H:i'); ?>).
                                    </div>
                                `);
                                if (fallbackDepartmentId) {
                                    departmentInput.val(fallbackDepartmentId);
                                    console.log('Set department_id (no schedule fallback):', fallbackDepartmentId);
                                    scheduleContainer.append(
                                        `<div class="alert alert-info">Using teacher's department as fallback.</div>`
                                    );
                                    submitBtn.prop('disabled', !departmentInput.val());
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            teacherSpinner.hide();
                            console.error('AJAX error:', status, error, xhr.responseText);
                            scheduleContainer.html(
                                '<div class="alert alert-danger">Error fetching schedule. Please try again.</div>'
                            );
                            if (fallbackDepartmentId) {
                                departmentInput.val(fallbackDepartmentId);
                                console.log('Set department_id (error fallback):', fallbackDepartmentId);
                                scheduleContainer.append(
                                    `<div class="alert alert-info">Using teacher's department as fallback.</div>`
                                );
                                submitBtn.prop('disabled', !departmentInput.val());
                            }
                        }
                    });
                } else {
                    console.log('No teacher selected, resetting fields');
                }
            });

            document.addEventListener('DOMContentLoaded', () => {
                document.getElementById('loading-overlay').style.display = 'none';
                const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

                setTimeout(() => {
                    document.querySelectorAll('.alert').forEach(alert => {
                        bootstrap.Alert.getOrCreateInstance(alert).close();
                    });
                }, 5000);
            });
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>