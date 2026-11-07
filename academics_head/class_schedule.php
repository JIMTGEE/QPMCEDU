<?php
// /admin/class_schedules.php

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Connection failed: " . mysqli_connect_error());
}

// Set time zone
date_default_timezone_set('Africa/Accra');

// Initialize variables
$department_filter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$view_mode = isset($_GET['view']) && $_GET['view'] === 'timetable' ? 'timetable' : 'list';
$success_msg = $error_msg = "";

// Days of the week (Monday to Friday)
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Fetch terms from academic_calendar
$terms_sql = "SELECT id, term_name FROM academic_calendar ORDER BY term_name";
$terms_stmt = $conn->prepare($terms_sql);
$terms_stmt->execute();
$terms_result = $terms_stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
$terms_stmt->close();

// Fetch teachers
$teachers_sql = "SELECT id, name FROM users WHERE user_role = 'teacher' ORDER BY name";
$teachers_stmt = $conn->prepare($teachers_sql);
$teachers_stmt->execute();
$teachers_result = $teachers_stmt->get_result();
$teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);
$teachers_stmt->close();

// Handle AJAX request for classes by department
if (isset($_GET['action']) && $_GET['action'] === 'get_classes_by_department') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['classes' => [], 'error' => '', 'debug' => ''];
    if ($department_id <= 0) {
        $response['error'] = 'Invalid department ID.';
        $response['debug'] = 'Department ID: ' . $department_id;
    } else {
        $classes_sql = "SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name";
        $classes_stmt = $conn->prepare($classes_sql);
        if (!$classes_stmt) {
            $response['error'] = 'Database query preparation failed.';
            $response['debug'] = 'SQL Error: ' . $conn->error;
            error_log("Classes query error: " . $conn->error);
        } else {
            $classes_stmt->bind_param("i", $department_id);
            if (!$classes_stmt->execute()) {
                $response['error'] = 'Query execution failed.';
                $response['debug'] = 'SQL Error: ' . $classes_stmt->error;
                error_log("Classes query execution error: " . $classes_stmt->error);
            } else {
                $classes_result = $classes_stmt->get_result();
                while ($class = $classes_result->fetch_assoc()) {
                    $response['classes'][] = $class;
                }
                $response['debug'] = 'Classes found: ' . count($response['classes']) . ' for department_id=' . $department_id;
                if (empty($response['classes'])) {
                    $response['error'] = 'No classes available for this department.';
                }
            }
            $classes_stmt->close();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle AJAX request for subjects by department
if (isset($_GET['action']) && $_GET['action'] === 'get_subjects_by_department') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['subjects' => [], 'error' => '', 'debug' => ''];
    if ($department_id <= 0) {
        $response['error'] = 'Invalid department ID.';
        $response['debug'] = 'Department ID: ' . $department_id;
    } else {
        $subjects_sql = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name";
        $subjects_stmt = $conn->prepare($subjects_sql);
        if (!$subjects_stmt) {
            $response['error'] = 'Database query preparation failed.';
            $response['debug'] = 'SQL Error: ' . $conn->error;
            error_log("Subjects query error: " . $conn->error);
        } else {
            $subjects_stmt->bind_param("i", $department_id);
            if (!$subjects_stmt->execute()) {
                $response['error'] = 'Query execution failed.';
                $response['debug'] = 'SQL Error: ' . $subjects_stmt->error;
                error_log("Subjects query execution error: " . $subjects_stmt->error);
            } else {
                $subjects_result = $subjects_stmt->get_result();
                while ($subject = $subjects_result->fetch_assoc()) {
                    $response['subjects'][] = $subject;
                }
                $response['debug'] = 'Subjects found: ' . count($response['subjects']) . ' for department_id=' . $department_id;
                if (empty($response['subjects'])) {
                    $response['error'] = 'No subjects available for this department.';
                }
            }
            $subjects_stmt->close();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle submit schedules (add new schedules)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_schedules'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
        error_log("CSRF token validation failed in class_schedules.php");
    } else {
        $schedules = $_POST['schedules'] ?? [];
        $errors = [];

        if (empty($schedules)) {
            $error_msg = "No schedules provided.";
        } else {
            $conn->begin_transaction();
            try {
                foreach ($schedules as $index => $schedule) {
                    $teacher_id = (int)($schedule['teacher_id'] ?? 0);
                    $department_id = (int)($schedule['department_id'] ?? 0);
                    $class_id = (int)($schedule['class_id'] ?? 0);
                    $subject_id = (int)($schedule['subject_id'] ?? 0);
                    $term_id = (int)($schedule['term_id'] ?? 0);
                    $day_of_week = $schedule['day_of_week'] ?? '';
                    $start_time = $schedule['start_time'] ?? '';
                    $end_time = $schedule['end_time'] ?? '';

                    // Validate inputs
                    if (!$teacher_id || !$department_id || !$class_id || !$subject_id || !$term_id || 
                        !in_array($day_of_week, $days_of_week) || !$start_time || !$end_time) {
                        $errors[] = "Schedule " . ($index + 1) . ": All fields are required.";
                        continue;
                    }
                    if (strtotime($end_time) <= strtotime($start_time)) {
                        $errors[] = "Schedule " . ($index + 1) . ": End time must be after start time.";
                        continue;
                    }

                    // Validate teacher, department, class, subject, and term
                    $teacher_sql = "SELECT id FROM users WHERE id = ? AND user_role = 'teacher'";
                    $teacher_stmt = $conn->prepare($teacher_sql);
                    $teacher_stmt->bind_param("i", $teacher_id);
                    $teacher_stmt->execute();
                    $teacher_result = $teacher_stmt->get_result();
                    $teacher_exists = $teacher_result->num_rows > 0;
                    $teacher_stmt->close();

                    $dept_sql = "SELECT id FROM departments WHERE id = ?";
                    $dept_stmt = $conn->prepare($dept_sql);
                    $dept_stmt->bind_param("i", $department_id);
                    $dept_stmt->execute();
                    $dept_result = $dept_stmt->get_result();
                    $dept_exists = $dept_result->num_rows > 0;
                    $dept_stmt->close();

                    $class_sql = "SELECT department_id FROM classes WHERE id = ?";
                    $class_stmt = $conn->prepare($class_sql);
                    $class_stmt->bind_param("i", $class_id);
                    $class_stmt->execute();
                    $class_result = $class_stmt->get_result();
                    $class = $class_result->fetch_assoc();
                    $class_department_id = $class ? $class['department_id'] : null;
                    $class_stmt->close();

                    $subject_sql = "SELECT department_id FROM subjects WHERE id = ?";
                    $subject_stmt = $conn->prepare($subject_sql);
                    $subject_stmt->bind_param("i", $subject_id);
                    $subject_stmt->execute();
                    $subject_result = $subject_stmt->get_result();
                    $subject = $subject_result->fetch_assoc();
                    $subject_department_id = $subject ? $subject['department_id'] : null;
                    $subject_stmt->close();

                    $term_sql = "SELECT id FROM academic_calendar WHERE id = ?";
                    $term_stmt = $conn->prepare($term_sql);
                    $term_stmt->bind_param("i", $term_id);
                    $term_stmt->execute();
                    $term_result = $term_stmt->get_result();
                    $term_exists = $term_result->num_rows > 0;
                    $term_stmt->close();

                    if (!$teacher_exists) {
                        $errors[] = "Schedule " . ($index + 1) . ": Selected teacher does not exist.";
                        continue;
                    }
                    if (!$dept_exists) {
                        $errors[] = "Schedule " . ($index + 1) . ": Selected department does not exist.";
                        continue;
                    }
                    if ($class_department_id !== $department_id || $subject_department_id !== $department_id) {
                        $errors[] = "Schedule " . ($index + 1) . ": Class or subject does not belong to the selected department.";
                        continue;
                    }
                    if (!$term_exists) {
                        $errors[] = "Schedule " . ($index + 1) . ": Selected term does not exist.";
                        continue;
                    }

                    // Check for overlapping schedules
                    $overlap_sql = "SELECT id FROM class_schedules 
                                    WHERE teacher_id = ? AND class_id = ? AND term_id = ? 
                                    AND day_of_week = ? 
                                    AND ((start_time < ? AND end_time > ?) 
                                         OR (start_time < ? AND end_time > ?)
                                         OR (start_time >= ? AND end_time <= ?))";
                    $overlap_stmt = $conn->prepare($overlap_sql);
                    $overlap_stmt->bind_param("iiisssssss", $teacher_id, $class_id, $term_id, $day_of_week, 
                                             $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
                    $overlap_stmt->execute();
                    $overlap_result = $overlap_stmt->get_result();
                    if ($overlap_result->num_rows > 0) {
                        $errors[] = "Schedule " . ($index + 1) . ": Conflicts with an existing schedule.";
                        continue;
                    }
                    $overlap_stmt->close();

                    // Insert schedule
                    $insert_sql = "INSERT INTO class_schedules (class_id, teacher_id, subject_id, term_id, day_of_week, start_time, end_time, department_id)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("iiiisssi", $class_id, $teacher_id, $subject_id, $term_id, $day_of_week, $start_time, $end_time, $department_id);
                    $insert_stmt->execute();
                    logActivity($conn, $_SESSION['user_id'], "Added Schedule", 
                               "Teacher ID: $teacher_id, Class ID: $class_id, Subject ID: $subject_id, Term ID: $term_id, Department ID: $department_id");
                    $insert_stmt->close();
                }

                if (!empty($errors)) {
                    $error_msg = implode("<br>", $errors);
                    $conn->rollback();
                } else {
                    $success_msg = "All schedules added successfully.";
                    $conn->commit();
                    header("Location: /admin/class_schedules.php?department_id=$department_filter&view=$view_mode");
                    exit();
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error adding schedules: " . $e->getMessage();
                error_log("Schedules add error: " . $e->getMessage());
            }
        }
    }
}

// Handle edit schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_schedule'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
        error_log("CSRF token validation failed in class_schedules.php");
    } else {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $term_id = (int)($_POST['term_id'] ?? 0);
        $day_of_week = $_POST['day_of_week'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';

        // Validate inputs
        if (!$schedule_id || !$teacher_id || !$department_id || !$class_id || !$subject_id || !$term_id || 
            !in_array($day_of_week, $days_of_week) || !$start_time || !$end_time) {
            $error_msg = "All fields are required for editing schedule.";
        } elseif (strtotime($end_time) <= strtotime($start_time)) {
            $error_msg = "End time must be after start time.";
        } else {
            $conn->begin_transaction();
            try {
                // Validate teacher, department, class, subject, and term
                $teacher_sql = "SELECT id FROM users WHERE id = ? AND user_role = 'teacher'";
                $teacher_stmt = $conn->prepare($teacher_sql);
                $teacher_stmt->bind_param("i", $teacher_id);
                $teacher_stmt->execute();
                $teacher_result = $teacher_stmt->get_result();
                $teacher_exists = $teacher_result->num_rows > 0;
                $teacher_stmt->close();

                $dept_sql = "SELECT id FROM departments WHERE id = ?";
                $dept_stmt = $conn->prepare($dept_sql);
                $dept_stmt->bind_param("i", $department_id);
                $dept_stmt->execute();
                $dept_result = $dept_stmt->get_result();
                $dept_exists = $dept_result->num_rows > 0;
                $dept_stmt->close();

                $class_sql = "SELECT department_id FROM classes WHERE id = ?";
                $class_stmt = $conn->prepare($class_sql);
                $class_stmt->bind_param("i", $class_id);
                $class_stmt->execute();
                $class_result = $class_stmt->get_result();
                $class = $class_result->fetch_assoc();
                $class_department_id = $class ? $class['department_id'] : null;
                $class_stmt->close();

                $subject_sql = "SELECT department_id FROM subjects WHERE id = ?";
                $subject_stmt = $conn->prepare($subject_sql);
                $subject_stmt->bind_param("i", $subject_id);
                $subject_stmt->execute();
                $subject_result = $subject_stmt->get_result();
                $subject = $subject_result->fetch_assoc();
                $subject_department_id = $subject ? $subject['department_id'] : null;
                $subject_stmt->close();

                $term_sql = "SELECT id FROM academic_calendar WHERE id = ?";
                $term_stmt = $conn->prepare($term_sql);
                $term_stmt->bind_param("i", $term_id);
                $term_stmt->execute();
                $term_result = $term_stmt->get_result();
                $term_exists = $term_result->num_rows > 0;
                $term_stmt->close();

                if (!$teacher_exists) {
                    $error_msg = "Selected teacher does not exist.";
                } elseif (!$dept_exists) {
                    $error_msg = "Selected department does not exist.";
                } elseif ($class_department_id !== $department_id || $subject_department_id !== $department_id) {
                    $error_msg = "Class or subject does not belong to the selected department.";
                } elseif (!$term_exists) {
                    $error_msg = "Selected term does not exist.";
                } else {
                    // Check for overlapping schedules (excluding the current schedule)
                    $overlap_sql = "SELECT id FROM class_schedules 
                                    WHERE teacher_id = ? AND class_id = ? AND term_id = ? 
                                    AND day_of_week = ? AND id != ? 
                                    AND ((start_time < ? AND end_time > ?) 
                                         OR (start_time < ? AND end_time > ?)
                                         OR (start_time >= ? AND end_time <= ?))";
                    $overlap_stmt = $conn->prepare($overlap_sql);
                    $overlap_stmt->bind_param("iiisissssss", $teacher_id, $class_id, $term_id, $day_of_week, $schedule_id, 
                                             $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
                    $overlap_stmt->execute();
                    $overlap_result = $overlap_stmt->get_result();
                    if ($overlap_result->num_rows > 0) {
                        $error_msg = "Updated schedule conflicts with an existing schedule.";
                    } else {
                        // Update schedule
                        $update_sql = "UPDATE class_schedules 
                                       SET class_id = ?, teacher_id = ?, subject_id = ?, term_id = ?, 
                                           day_of_week = ?, start_time = ?, end_time = ?, department_id = ?
                                       WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("iiiisssii", $class_id, $teacher_id, $subject_id, $term_id, 
                                                $day_of_week, $start_time, $end_time, $department_id, $schedule_id);
                        $update_stmt->execute();
                        logActivity($conn, $_SESSION['user_id'], "Edited Schedule", 
                                   "Schedule ID: $schedule_id, Teacher ID: $teacher_id, Class ID: $class_id, Subject ID: $subject_id, Term ID: $term_id, Department ID: $department_id");
                        $update_stmt->close();
                        $success_msg = "Schedule updated successfully.";
                        $conn->commit();
                        header("Location: /admin/class_schedules.php?department_id=$department_filter&view=$view_mode");
                        exit();
                    }
                    $overlap_stmt->close();
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error updating schedule: " . $e->getMessage();
                error_log("Schedule update error: " . $e->getMessage());
            }
        }
    }
}

// Handle delete schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
        error_log("CSRF token validation failed in class_schedules.php");
    } else {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        if ($schedule_id <= 0) {
            $error_msg = "Invalid schedule ID.";
        } else {
            $conn->begin_transaction();
            try {
                $delete_sql = "DELETE FROM class_schedules WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $schedule_id);
                $delete_stmt->execute();
                if ($delete_stmt->affected_rows > 0) {
                    logActivity($conn, $_SESSION['user_id'], "Deleted Schedule", "Schedule ID: $schedule_id");
                    $success_msg = "Schedule deleted successfully.";
                    $conn->commit();
                    header("Location: /admin/class_schedules.php?department_id=$department_filter&view=$view_mode");
                    exit();
                } else {
                    $error_msg = "Schedule not found.";
                    $conn->rollback();
                }
                $delete_stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error deleting schedule: " . $e->getMessage();
                error_log("Schedule delete error: " . $e->getMessage());
            }
        }
    }
}

// Fetch departments (for filter and form)
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Fetch schedules
$schedules_sql = "SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time, 
                        c.class_name, u.name AS teacher_name, s.subject_name, 
                        cs.term_id, d.name AS department_name, cs.class_id, 
                        cs.subject_id, cs.department_id, cs.teacher_id
                 FROM class_schedules cs
                 JOIN classes c ON cs.class_id = c.id
                 JOIN users u ON cs.teacher_id = u.id
                 JOIN subjects s ON cs.subject_id = s.id
                 LEFT JOIN departments d ON cs.department_id = d.id";
if ($department_filter > 0) {
    $schedules_sql .= " WHERE cs.department_id = ?";
}
$schedules_sql .= " ORDER BY cs.day_of_week, cs.start_time";
if ($department_filter > 0) {
    $schedules_stmt = $conn->prepare($schedules_sql);
    $schedules_stmt->bind_param("i", $department_filter);
} else {
    $schedules_stmt = $conn->prepare($schedules_sql);
}
$schedules_stmt->execute();
$schedules_result = $schedules_stmt->get_result();
$schedules = $schedules_result->fetch_all(MYSQLI_ASSOC);
$schedules_stmt->close();

// Calculate max periods for timetable grid
$max_periods = 0;
$schedules_by_day = [];
foreach ($schedules as $schedule) {
    $day = $schedule['day_of_week'];
    if (!isset($schedules_by_day[$day])) {
        $schedules_by_day[$day] = [];
    }
    $schedules_by_day[$day][] = $schedule;
    $max_periods = max($max_periods, count($schedules_by_day[$day]));
}
foreach ($schedules_by_day as $day => &$day_schedules) {
    usort($day_schedules, function($a, $b) {
        return strcmp($a['start_time'], $b['start_time']);
    });
}

// Create term lookup array for display
$term_lookup = [];
foreach ($terms as $term) {
    $term_lookup[$term['id']] = $term['term_name'];
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_schedules.php';
?>

<div class="content" id="content">
    <main class="container my-5">
        <section class="card mb-4 animate__animated animate__fadeIn">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Manage Class Schedules</h3>
                <div class="view-toggle">
                    <a href="?department_id=<?php echo $department_filter; ?>&view=list" class="btn btn-sm <?php echo $view_mode === 'list' ? 'btn-primary' : 'btn-outline-primary'; ?> me-2" data-bs-toggle="tooltip" title="List view"><i class="fas fa-list"></i></a>
                    <a href="?department_id=<?php echo $department_filter; ?>&view=timetable" class="btn btn-sm <?php echo $view_mode === 'timetable' ? 'btn-primary' : 'btn-outline-primary'; ?>" data-bs-toggle="tooltip" title="Timetable view"><i class="fas fa-table"></i></a>
                </div>
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
                <?php if (empty($terms)): ?>
                    <div class="alert alert-warning alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>No terms found in the academic calendar.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (empty($teachers)): ?>
                    <div class="alert alert-warning alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>No teachers found. Please add teachers first.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Department Filter -->
                <form class="mb-4 animate__animated animate__fadeInUp" method="GET" id="filterForm">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
                    <div class="row g-3 align-items-center">
                        <div class="col-auto">
                            <label for="department_filter" class="form-label">Filter by Department</label>
                        </div>
                        <div class="col-auto">
                            <select class="form-select custom-select" id="department_filter" name="department_id" data-bs-toggle="tooltip" title="Filter schedules by department">
                                <option value="0" <?php echo $department_filter == 0 ? 'selected' : ''; ?>>All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" 
                                            <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary" data-bs-toggle="tooltip" title="Apply filter">
                                <i class="fas fa-filter me-2"></i>Filter
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Add Schedules Form -->
                <h5 class="mt-4">Add New Schedules</h5>
                <form method="POST" id="schedulesForm" class="animate__animated animate__fadeInUp mb-4" onsubmit="return validateSchedulesForm()" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div id="scheduleForms">
                        <div class="schedule-form" data-index="1">
                            <div class="schedule-form-header">
                                <h6>Schedule 1</h6>
                                <i class="fas fa-times remove-schedule" title="Remove Schedule" style="display: none;"></i>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="teacher_id_1" class="form-label">Teacher <span class="text-danger">*</span></label>
                                    <select class="form-select custom-select" id="teacher_id_1" name="schedules[1][teacher_id]" required data-bs-toggle="tooltip" title="Select the teacher">
                                        <option value="">Select Teacher</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>">
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a teacher.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="department_id_1" class="form-label">Department <span class="text-danger">*</span></label>
                                    <select class="form-select custom-select department-select" id="department_id_1" name="schedules[1][department_id]" required data-bs-toggle="tooltip" title="Select the department">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>">
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a department.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="class_id_1" class="form-label">Class <span class="text-danger">*</span></label>
                                    <select class="form-select custom-select class-select" id="class_id_1" name="schedules[1][class_id]" required data-bs-toggle="tooltip" title="Select the class">
                                        <option value="">Select Class</option>
                                    </select>
                                    <span class="loading-spinner class-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                    <div class="invalid-feedback">Please select a class.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="subject_id_1" class="form-label">Subject <span class="text-danger">*</span></label>
                                    <select class="form-select custom-select subject-select" id="subject_id_1" name="schedules[1][subject_id]" required data-bs-toggle="tooltip" title="Select the subject">
                                        <option value="">Select Subject</option>
                                    </select>
                                    <span class="loading-spinner subject-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                    <div class="invalid-feedback">Please select a subject.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="term_id_1" class="form-label">Term <span class="text-danger">*</span></label>
                                    <select class="form-select custom-select" id="term_id_1" name="schedules[1][term_id]" required data-bs-toggle="tooltip" title="Select the term">
                                        <option value="">Select Term</option>
                                        <?php foreach ($terms as $term): ?>
                                            <option value="<?php echo $term['id']; ?>">
                                                <?php echo htmlspecialchars($term['term_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a term.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="day_of_week_1" class="form-label">Day of Week <span class="text-danger">*</span></label>
                                    <select class="form-select custom-select" id="day_of_week_1" name="schedules[1][day_of_week]" required data-bs-toggle="tooltip" title="Select the day">
                                        <option value="">Select Day</option>
                                        <?php foreach ($days_of_week as $day): ?>
                                            <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a day.</div>
                                </div>
                                <div class="col-md-2">
                                    <label for="start_time_1" class="form-label">Start Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="start_time_1" name="schedules[1][start_time]" required data-bs-toggle="tooltip" title="Select start time">
                                    <div class="invalid-feedback">Please select a start time.</div>
                                </div>
                                <div class="col-md-2">
                                    <label for="end_time_1" class="form-label">End Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="end_time_1" name="schedules[1][end_time]" required data-bs-toggle="tooltip" title="Select end time">
                                    <div class="invalid-feedback">Please select an end time.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="button" id="addAnotherSchedule" class="btn btn-success me-2" data-bs-toggle="tooltip" title="Add another schedule form">
                            <i class="fas fa-plus me-2"></i>Add Another Schedule
                        </button>
                        <button type="submit" name="submit_schedules" class="btn btn-primary" data-bs-toggle="tooltip" title="Save all schedules and view">
                            <i class="fas fa-check me-2"></i>Submit Schedules
                        </button>
                    </div>
                </form>

                <!-- Edit Schedule Modal -->
                <div class="modal fade" id="editScheduleModal" tabindex="-1" aria-labelledby="editScheduleModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editScheduleModalLabel">Edit Schedule</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" id="editScheduleForm" onsubmit="return validateEditScheduleForm()" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="schedule_id" id="edit_schedule_id">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label for="edit_teacher_id" class="form-label">Teacher <span class="text-danger">*</span></label>
                                            <select class="form-select custom-select" id="edit_teacher_id" name="teacher_id" required data-bs-toggle="tooltip" title="Select the teacher">
                                                <option value="">Select Teacher</option>
                                                <?php foreach ($teachers as $teacher): ?>
                                                    <option value="<?php echo $teacher['id']; ?>">
                                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a teacher.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="edit_department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                            <select class="form-select custom-select department-select" id="edit_department_id" name="department_id" required data-bs-toggle="tooltip" title="Select the department">
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>">
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a department.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="edit_class_id" class="form-label">Class <span class="text-danger">*</span></label>
                                            <select class="form-select custom-select class-select" id="edit_class_id" name="class_id" required data-bs-toggle="tooltip" title="Select the class">
                                                <option value="">Select Class</option>
                                            </select>
                                            <span class="loading-spinner class-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                            <div class="invalid-feedback">Please select a class.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="edit_subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                                            <select class="form-select custom-select subject-select" id="edit_subject_id" name="subject_id" required data-bs-toggle="tooltip" title="Select the subject">
                                                <option value="">Select Subject</option>
                                            </select>
                                            <span class="loading-spinner subject-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                            <div class="invalid-feedback">Please select a subject.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="edit_term_id" class="form-label">Term <span class="text-danger">*</span></label>
                                            <select class="form-select custom-select" id="edit_term_id" name="term_id" required data-bs-toggle="tooltip" title="Select the term">
                                                <option value="">Select Term</option>
                                                <?php foreach ($terms as $term): ?>
                                                    <option value="<?php echo $term['id']; ?>">
                                                        <?php echo htmlspecialchars($term['term_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a term.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="edit_day_of_week" class="form-label">Day of Week <span class="text-danger">*</span></label>
                                            <select class="form-select custom-select" id="edit_day_of_week" name="day_of_week" required data-bs-toggle="tooltip" title="Select the day">
                                                <option value="">Select Day</option>
                                                <?php foreach ($days_of_week as $day): ?>
                                                    <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a day.</div>
                                        </div>
                                        <div class="col-md-2">
                                            <label for="edit_start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                                            <input type="time" class="form-control" id="edit_start_time" name="start_time" required data-bs-toggle="tooltip" title="Select start time">
                                            <div class="invalid-feedback">Please select a start time.</div>
                                        </div>
                                        <div class="col-md-2">
                                            <label for="edit_end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                                            <input type="time" class="form-control" id="edit_end_time" name="end_time" required data-bs-toggle="tooltip" title="Select end time">
                                            <div class="invalid-feedback">Please select an end time.</div>
                                        </div>
                                    </div>
                                    <div class="mt-4">
                                        <button type="submit" name="edit_schedule" class="btn btn-primary" data-bs-toggle="tooltip" title="Save changes">
                                            <i class="fas fa-check me-2"></i>Save Changes
                                        </button>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-bs-toggle="tooltip" title="Cancel">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Schedules Display -->
                <h5 class="mt-4">Class Schedules</h5>
                <?php if ($view_mode === 'list'): ?>
                    <div class="table-responsive">
                        <table class="table table-hover animate__animated animate__fadeInUp">
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Term</th>
                                    <th>Day</th>
                                    <th>Time</th>
                                    <th>Department</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($schedules)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No schedules found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($schedule['teacher_name']); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($term_lookup[$schedule['term_id']] ?? 'Unknown Term'); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['day_of_week']); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['start_time'] . ' - ' . $schedule['end_time']); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['department_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <button class="btn btn-primary btn-sm edit-schedule" 
                                                        data-schedule-id="<?php echo $schedule['id']; ?>" 
                                                        data-teacher-id="<?php echo $schedule['teacher_id']; ?>"
                                                        data-department-id="<?php echo $schedule['department_id']; ?>" 
                                                        data-class-id="<?php echo $schedule['class_id']; ?>" 
                                                        data-subject-id="<?php echo $schedule['subject_id']; ?>" 
                                                        data-term-id="<?php echo $schedule['term_id']; ?>" 
                                                        data-day="<?php echo $schedule['day_of_week']; ?>" 
                                                        data-start-time="<?php echo $schedule['start_time']; ?>" 
                                                        data-end-time="<?php echo $schedule['end_time']; ?>" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editScheduleModal" 
                                                        title="Edit Schedule">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                                    <button type="submit" name="delete_schedule" class="btn btn-danger btn-sm" title="Delete Schedule">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="timetable animate__animated animate__fadeInUp">
                        <div class="time-slot header"></div>
                        <?php for ($period = 1; $period <= $max_periods; $period++): ?>
                            <div class="time-slot header">Period <?php echo $period; ?></div>
                        <?php endfor; ?>
                        <?php foreach ($days_of_week as $day): ?>
                            <div class="time-slot period"><?php echo $day; ?></div>
                            <?php for ($period = 1; $period <= $max_periods; $period++): ?>
                                <div class="time-slot" data-day="<?php echo $day; ?>" data-period="<?php echo $period; ?>">
                                    <?php
                                    if (isset($schedules_by_day[$day]) && isset($schedules_by_day[$day][$period - 1])) {
                                        $schedule = $schedules_by_day[$day][$period - 1];
                                        ?>
                                        <div class="schedule-item">
                                            <strong><?php echo htmlspecialchars($schedule['teacher_name']); ?></strong><br>
                                            <?php echo htmlspecialchars($schedule['class_name']); ?><br>
                                            <?php echo htmlspecialchars($schedule['subject_name']); ?><br>
                                            <?php echo htmlspecialchars($schedule['start_time'] . ' - ' . $schedule['end_time']); ?>
                                            <div class="action-buttons">
                                                <button class="btn btn-primary btn-sm edit-schedule" 
                                                        data-schedule-id="<?php echo $schedule['id']; ?>" 
                                                        data-teacher-id="<?php echo $schedule['teacher_id']; ?>"
                                                        data-department-id="<?php echo $schedule['department_id']; ?>" 
                                                        data-class-id="<?php echo $schedule['class_id']; ?>" 
                                                        data-subject-id="<?php echo $schedule['subject_id']; ?>" 
                                                        data-term-id="<?php echo $schedule['term_id']; ?>" 
                                                        data-day="<?php echo $schedule['day_of_week']; ?>" 
                                                        data-start-time="<?php echo $schedule['start_time']; ?>" 
                                                        data-end-time="<?php echo $schedule['end_time']; ?>" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editScheduleModal" 
                                                        title="Edit Schedule">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                                    <button type="submit" name="delete_schedule" class="btn btn-danger btn-sm" title="Delete Schedule">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        <?php
                                    }
                                    ?>
                                </div>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Initialize tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            });
        }, 5000);

        // Trigger department change for first schedule form
        const firstDepartmentSelect = $('#department_id_1');
        if (firstDepartmentSelect.val()) {
            firstDepartmentSelect.trigger('change');
        }

        // Initialize modal
        const editModal = document.getElementById('editScheduleModal');
        editModal.addEventListener('show.bs.modal', () => {
            console.log('Edit modal is opening');
        });
        editModal.addEventListener('shown.bs.modal', () => {
            console.log('Edit modal is fully shown');
        });
        editModal.addEventListener('hide.bs.modal', () => {
            console.log('Edit modal is closing');
            $('#editScheduleForm').removeClass('was-validated');
        });
    });

    function validateSchedulesForm() {
        const forms = document.querySelectorAll('.schedule-form');
        let isValid = true;
        forms.forEach((form, index) => {
            const formElement = form.querySelectorAll('select, input');
            const formIndex = form.getAttribute('data-index');
            formElement.forEach(input => {
                if (!input.checkValidity()) {
                    isValid = false;
                    input.closest('.schedule-form').classList.add('was-validated');
                }
            });
            const startTime = document.getElementById(`start_time_${formIndex}`).value;
            const endTime = document.getElementById(`end_time_${formIndex}`).value;
            if (startTime && endTime && new Date(`1970-01-01T${endTime}`) <= new Date(`1970-01-01T${startTime}`)) {
                isValid = false;
                alert(`Schedule ${formIndex}: End time must be after start time.`);
                document.getElementById(`end_time_${formIndex}`).focus();
            }
        });
        return isValid && confirm('Are you sure you want to submit all schedules?');
    }

    function validateEditScheduleForm() {
        const form = document.getElementById('editScheduleForm');
        let isValid = true;
        form.querySelectorAll('select, input').forEach(input => {
            if (!input.checkValidity()) {
                isValid = false;
                form.classList.add('was-validated');
            }
        });
        const startTime = document.getElementById('edit_start_time').value;
        const endTime = document.getElementById('edit_end_time').value;
        if (startTime && endTime && new Date(`1970-01-01T${endTime}`) <= new Date(`1970-01-01T${startTime}`)) {
            isValid = false;
            alert('End time must be after start time.');
            document.getElementById('edit_end_time').focus();
        }
        return isValid && confirm('Are you sure you want to save changes to this schedule?');
    }

    $(document).ready(function() {
        let scheduleIndex = 1;

        // Cache for AJAX results
        const ajaxCache = {};

        // Add another schedule form
        $('#addAnotherSchedule').click(function() {
            scheduleIndex++;
            const newForm = `
                <div class="schedule-form animate__animated animate__fadeIn" data-index="${scheduleIndex}">
                    <div class="schedule-form-header">
                        <h6>Schedule ${scheduleIndex}</h6>
                        <i class="fas fa-times remove-schedule" title="Remove Schedule"></i>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="teacher_id_${scheduleIndex}" class="form-label">Teacher <span class="text-danger">*</span></label>
                            <select class="form-select custom-select" id="teacher_id_${scheduleIndex}" name="schedules[${scheduleIndex}][teacher_id]" required data-bs-toggle="tooltip" title="Select the teacher">
                                <option value="">Select Teacher</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>">
                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a teacher.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="department_id_${scheduleIndex}" class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select custom-select department-select" id="department_id_${scheduleIndex}" name="schedules[${scheduleIndex}][department_id]" required data-bs-toggle="tooltip" title="Select the department">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>">
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a department.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="class_id_${scheduleIndex}" class="form-label">Class <span class="text-danger">*</span></label>
                            <select class="form-select custom-select class-select" id="class_id_${scheduleIndex}" name="schedules[${scheduleIndex}][class_id]" required data-bs-toggle="tooltip" title="Select the class">
                                <option value="">Select Class</option>
                            </select>
                            <span class="loading-spinner class-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                            <div class="invalid-feedback">Please select a class.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="subject_id_${scheduleIndex}" class="form-label">Subject <span class="text-danger">*</span></label>
                            <select class="form-select custom-select subject-select" id="subject_id_${scheduleIndex}" name="schedules[${scheduleIndex}][subject_id]" required data-bs-toggle="tooltip" title="Select the subject">
                                <option value="">Select Subject</option>
                            </select>
                            <span class="loading-spinner subject-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                            <div class="invalid-feedback">Please select a subject.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="term_id_${scheduleIndex}" class="form-label">Term <span class="text-danger">*</span></label>
                            <select class="form-select custom-select" id="term_id_${scheduleIndex}" name="schedules[${scheduleIndex}][term_id]" required data-bs-toggle="tooltip" title="Select the term">
                                <option value="">Select Term</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>">
                                        <?php echo htmlspecialchars($term['term_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a term.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="day_of_week_${scheduleIndex}" class="form-label">Day of Week <span class="text-danger">*</span></label>
                            <select class="form-select custom-select" id="day_of_week_${scheduleIndex}" name="schedules[${scheduleIndex}][day_of_week]" required data-bs-toggle="tooltip" title="Select the day">
                                <option value="">Select Day</option>
                                <?php foreach ($days_of_week as $day): ?>
                                    <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a day.</div>
                        </div>
                        <div class="col-md-2">
                            <label for="start_time_${scheduleIndex}" class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="start_time_${scheduleIndex}" name="schedules[${scheduleIndex}][start_time]" required data-bs-toggle="tooltip" title="Select start time">
                            <div class="invalid-feedback">Please select a start time.</div>
                        </div>
                        <div class="col-md-2">
                            <label for="end_time_${scheduleIndex}" class="form-label">End Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="end_time_${save as `/admin/class_schedules.php` and test at `http://yourdomain.com/admin/class_schedules.php`. Ensure:

- **Permissions**: `chmod 644 class_schedules.php` and `chown www-data:www-data class_schedules.php`.
- **Templates**: `/templates/admin_schedules.php` includes the role check:
  ```php
  session_start();
  if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
      error_log("Redirect to login: user_id=" . ($_SESSION['user_id'] ?? 'unset') . 
                ", user_role=" . ($_SESSION['user_role'] ?? 'unset') . 
                ", requested_url=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
      header("Location: /admin/login.php");
      exit();
  }
  if (!isset($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  ```
- **Error Logs**: Check `/var/log/apache2/error.log` or equivalent if errors occur.
- **Dependencies**: Confirm Bootstrap 5.3.3, Font Awesome 6.0.0, jQuery 3.6.0, and Animate.css 4.1.1 CDNs are accessible.

If you encounter issues (e.g., 500 errors, missing teachers, or AJAX failures), share:
1. Error log details.
2. Output or behavior observed.
3. Confirmation of file paths and template content.

This code should provide a seamless admin interface for managing class schedules, mirroring the teacher page’s functionality with admin-specific adjustments. Let me know how it works or if further tweaks are needed!