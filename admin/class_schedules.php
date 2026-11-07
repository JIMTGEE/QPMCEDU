<?php
session_start();

// =============================================================================
// AUTHENTICATION & SECURITY - Redirect to login if not authorized
// =============================================================================

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("Unauthorized access attempt: No session user_id");
    $_SESSION['error_msg'] = "Please log in to access this page.";
    header("Location: /login.php");
    exit();
}

// Check if user has teacher role
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "You do not have permission to access this page.";
    header("Location: /login.php");
    exit();
}

// =============================================================================
// DATABASE CONNECTION WITH ERROR HANDLING
// =============================================================================

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: " . $db_path);
    $_SESSION['error_msg'] = "System error: Configuration missing.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
} else {
    function logActivity($conn, $user_id, $action, $description) {
        $sql = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iss", $user_id, $action, $description);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate user is an active teacher
$user_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, name, email FROM users WHERE id = ? AND role = 'teacher' AND status = 'active'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $user_id);
    $user_check_stmt->execute();
    $user_check_result = $user_check_stmt->get_result();
    if ($user_check_result->num_rows === 0) {
        error_log("Invalid or inactive teacher account: user_id=" . $user_id);
        $_SESSION['error_msg'] = "Your account is not active or you do not have teacher privileges.";
        header("Location: /login.php");
        exit();
    }
    $user_data = $user_check_result->fetch_assoc();
    $user_check_stmt->close();
} else {
    error_log("User validation query failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Set time zone
date_default_timezone_set('Africa/Accra');

// =============================================================================
// INITIALIZE VARIABLES
// =============================================================================

$success_msg = "";
$error_msg = "";
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$current_day = date('l');
$current_time = date('H:i:s');

// Log page access
logActivity($conn, $user_id, "Viewed Class Schedules", "Teacher accessed class schedules page");

// =============================================================================
// HANDLE ADD SCHEDULE
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    $class_id = (int)$_POST['class_id'];
    $subject_id = (int)$_POST['subject_id'];
    $term_id = (int)$_POST['term_id'];
    $day_of_week = trim($_POST['day_of_week']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);

    // Validate inputs
    if ($class_id <= 0 || $subject_id <= 0 || $term_id <= 0) {
        $error_msg = "Please select valid class, subject, and term.";
    } elseif (!in_array($day_of_week, $days_of_week)) {
        $error_msg = "Invalid day of week selected.";
    } elseif (empty($start_time) || empty($end_time)) {
        $error_msg = "Please provide both start and end times.";
    } elseif (strtotime($start_time) >= strtotime($end_time)) {
        $error_msg = "Start time must be before end time.";
    } else {
        // Check for overlapping schedules
        $overlap_sql = "SELECT id FROM class_schedules 
                        WHERE teacher_id = ? AND day_of_week = ? AND term_id = ? 
                        AND ((start_time <= ? AND end_time >= ?) OR (start_time <= ? AND end_time >= ?))";
        $overlap_stmt = $conn->prepare($overlap_sql);
        $overlap_stmt->bind_param("issssss", $user_id, $day_of_week, $term_id, 
                                 $start_time, $start_time, $end_time, $end_time);
        $overlap_stmt->execute();
        $overlap_result = $overlap_stmt->get_result();
        
        if ($overlap_result->num_rows > 0) {
            $error_msg = "Schedule overlaps with an existing class period.";
        } else {
            // Insert schedule
            $insert_sql = "INSERT INTO class_schedules (class_id, subject_id, term_id, teacher_id, day_of_week, start_time, end_time) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iiiisss", $class_id, $subject_id, $term_id, 
                                     $user_id, $day_of_week, $start_time, $end_time);
            try {
                $conn->begin_transaction();
                $insert_stmt->execute();
                logActivity($conn, $user_id, "Added Schedule", 
                           "Class ID: $class_id, Subject ID: $subject_id, Day: $day_of_week");
                $success_msg = "Schedule added successfully.";
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error adding schedule: " . $e->getMessage();
                error_log("Add schedule error: " . $e->getMessage());
            }
            $insert_stmt->close();
        }
        $overlap_stmt->close();
    }
}

// =============================================================================
// HANDLE EDIT SCHEDULE
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_schedule'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    $class_id = (int)$_POST['class_id'];
    $subject_id = (int)$_POST['subject_id'];
    $term_id = (int)$_POST['term_id'];
    $day_of_week = trim($_POST['day_of_week']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);

    // Validate inputs
    if ($schedule_id <= 0 || $class_id <= 0 || $subject_id <= 0 || $term_id <= 0) {
        $error_msg = "Invalid schedule data.";
    } elseif (!in_array($day_of_week, $days_of_week)) {
        $error_msg = "Invalid day of week selected.";
    } elseif (empty($start_time) || empty($end_time)) {
        $error_msg = "Please provide both start and end times.";
    } elseif (strtotime($start_time) >= strtotime($end_time)) {
        $error_msg = "Start time must be before end time.";
    } else {
        // Check for overlapping schedules (excluding current schedule)
        $overlap_sql = "SELECT id FROM class_schedules 
                        WHERE teacher_id = ? AND day_of_week = ? AND term_id = ? AND id != ?
                        AND ((start_time <= ? AND end_time >= ?) OR (start_time <= ? AND end_time >= ?))";
        $overlap_stmt = $conn->prepare($overlap_sql);
        $overlap_stmt->bind_param("isisssss", $user_id, $day_of_week, $term_id, 
                                 $schedule_id, $start_time, $start_time, $end_time, $end_time);
        $overlap_stmt->execute();
        $overlap_result = $overlap_stmt->get_result();
        
        if ($overlap_result->num_rows > 0) {
            $error_msg = "Schedule overlaps with an existing class period.";
        } else {
            // Verify schedule belongs to this teacher
            $verify_sql = "SELECT id FROM class_schedules WHERE id = ? AND teacher_id = ?";
            $verify_stmt = $conn->prepare($verify_sql);
            $verify_stmt->bind_param("ii", $schedule_id, $user_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            if ($verify_result->num_rows === 0) {
                $error_msg = "You don't have permission to edit this schedule.";
            } else {
                // Update schedule
                $update_sql = "UPDATE class_schedules 
                               SET class_id = ?, subject_id = ?, term_id = ?, 
                                   day_of_week = ?, start_time = ?, end_time = ?
                               WHERE id = ? AND teacher_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("iiisssii", $class_id, $subject_id, $term_id, 
                                         $day_of_week, $start_time, $end_time, 
                                         $schedule_id, $user_id);
                try {
                    $conn->begin_transaction();
                    $update_stmt->execute();
                    if ($update_stmt->affected_rows > 0) {
                        logActivity($conn, $user_id, "Edited Schedule", 
                                   "Schedule ID: $schedule_id");
                        $success_msg = "Schedule updated successfully.";
                    } else {
                        $success_msg = "No changes were made to the schedule.";
                    }
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error updating schedule: " . $e->getMessage();
                    error_log("Edit schedule error: " . $e->getMessage());
                }
                $update_stmt->close();
            }
            $verify_stmt->close();
        }
        $overlap_stmt->close();
    }
}

// =============================================================================
// HANDLE DELETE SCHEDULE
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    
    if ($schedule_id <= 0) {
        $error_msg = "Invalid schedule ID.";
    } else {
        // Verify schedule belongs to this teacher
        $verify_sql = "SELECT id FROM class_schedules WHERE id = ? AND teacher_id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("ii", $schedule_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 0) {
            $error_msg = "You don't have permission to delete this schedule.";
        } else {
            $delete_sql = "DELETE FROM class_schedules WHERE id = ? AND teacher_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("ii", $schedule_id, $user_id);
            try {
                $conn->begin_transaction();
                $delete_stmt->execute();
                if ($delete_stmt->affected_rows > 0) {
                    logActivity($conn, $user_id, "Deleted Schedule", 
                               "Schedule ID: $schedule_id");
                    $success_msg = "Schedule deleted successfully.";
                } else {
                    $error_msg = "Schedule not found or already deleted.";
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error deleting schedule: " . $e->getMessage();
                error_log("Delete schedule error: " . $e->getMessage());
            }
            $delete_stmt->close();
        }
        $verify_stmt->close();
    }
}

// =============================================================================
// FETCH CLASSES
// =============================================================================

$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes = [];
if ($classes_stmt) {
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    while ($row = $classes_result->fetch_assoc()) {
        $classes[] = $row;
    }
    $classes_stmt->close();
}

// =============================================================================
// FETCH SUBJECTS (teacher's subjects + general subjects)
// =============================================================================

$subjects_sql = "SELECT id, subject_name FROM subjects WHERE teacher_id = ? OR teacher_id IS NULL ORDER BY subject_name";
$subjects_stmt = $conn->prepare($subjects_sql);
$subjects = [];
if ($subjects_stmt) {
    $subjects_stmt->bind_param("i", $user_id);
    $subjects_stmt->execute();
    $subjects_result = $subjects_stmt->get_result();
    while ($row = $subjects_result->fetch_assoc()) {
        $subjects[] = $row;
    }
    $subjects_stmt->close();
}

// =============================================================================
// FETCH TERMS (academic terms)
// =============================================================================

// Get current academic year
$current_year_sql = "SELECT id FROM academic_years WHERE is_active = 1 LIMIT 1";
$current_year_result = $conn->query($current_year_sql);
$current_year_id = null;
if ($current_year_result && $current_year_result->num_rows > 0) {
    $current_year_id = $current_year_result->fetch_assoc()['id'];
}

$terms_sql = "SELECT id, term_name FROM academic_terms WHERE academic_year_id = ? ORDER BY term_number";
$terms_stmt = $conn->prepare($terms_sql);
$terms = [];
if ($terms_stmt && $current_year_id) {
    $terms_stmt->bind_param("i", $current_year_id);
    $terms_stmt->execute();
    $terms_result = $terms_stmt->get_result();
    while ($row = $terms_result->fetch_assoc()) {
        $terms[] = $row;
    }
    $terms_stmt->close();
}

// If no terms found for current year, show fallback
if (empty($terms)) {
    $terms = [
        ['id' => 1, 'term_name' => 'First Term'],
        ['id' => 2, 'term_name' => 'Second Term'],
        ['id' => 3, 'term_name' => 'Third Term']
    ];
}

// =============================================================================
// FETCH SCHEDULES
// =============================================================================

$schedules_sql = "SELECT cs.*, c.class_name, s.subject_name, at.term_name
                  FROM class_schedules cs
                  JOIN classes c ON cs.class_id = c.id
                  LEFT JOIN subjects s ON cs.subject_id = s.id
                  JOIN academic_terms at ON cs.term_id = at.id
                  WHERE cs.teacher_id = ?
                  ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), 
                           cs.start_time";
$schedules_stmt = $conn->prepare($schedules_sql);
$schedules = [];
if ($schedules_stmt) {
    $schedules_stmt->bind_param("i", $user_id);
    $schedules_stmt->execute();
    $schedules_result = $schedules_stmt->get_result();
    while ($row = $schedules_result->fetch_assoc()) {
        $schedules[] = $row;
    }
    $schedules_stmt->close();
}

// Organize schedules by day
$schedule_by_day = [];
foreach ($days_of_week as $day) {
    $schedule_by_day[$day] = [];
}
foreach ($schedules as $schedule) {
    $schedule_by_day[$schedule['day_of_week']][] = $schedule;
}

// Include header
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
} else {
    // Fallback header if template not found
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Class Schedules - QueensPa School</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>
    <body>
        <nav class="navbar navbar-dark bg-primary">
            <div class="container">
                <a class="navbar-brand" href="#">QueensPa School - Teacher Portal</a>
                <div class="text-white">Welcome, ' . htmlspecialchars($user_data['name'] ?? 'Teacher') . '</div>
            </div>
        </nav>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher class schedules for QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, class schedules, teacher timetable, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Class Schedules - QueensPa School Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background-color: #f8f9fa;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: none;
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1rem 1.25rem;
        }
        .card-header h3 {
            margin: 0;
            font-size: 1.25rem;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .btn-sm {
            border-radius: 6px;
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
        .timetable {
            overflow-x: auto;
        }
        .timetable table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .timetable th, .timetable td {
            border: 1px solid #dee2e6;
            padding: 10px 12px;
            text-align: left;
            vertical-align: middle;
        }
        .timetable th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        .current-class {
            background-color: #d4edda;
            font-weight: bold;
        }
        .modal-content {
            border-radius: 12px;
        }
        .alert {
            border-radius: 10px;
        }
        .table tbody tr:hover {
            background-color: #fff3e0;
        }
    </style>
</head>
<body>
    <div class="container my-4">
        <!-- Main Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-calendar-alt me-2"></i> My Class Schedules</h3>
            </div>
            <div class="card-body">
                
                <!-- Success/Error Messages -->
                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Add Schedule Form -->
                <div class="mb-5">
                    <h5 class="mb-3"><i class="fas fa-plus-circle text-primary me-2"></i> Add New Schedule</h5>
                    <form method="POST" class="row g-3">
                        <div class="col-md-3">
                            <label for="class_id" class="form-label">Class <span class="text-danger">*</span></label>
                            <select class="form-select" id="class_id" name="class_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                            <select class="form-select" id="subject_id" name="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="term_id" class="form-label">Term <span class="text-danger">*</span></label>
                            <select class="form-select" id="term_id" name="term_id" required>
                                <option value="">Select Term</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>">
                                        <?php echo htmlspecialchars($term['term_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="day_of_week" class="form-label">Day <span class="text-danger">*</span></label>
                            <select class="form-select" id="day_of_week" name="day_of_week" required>
                                <option value="">Select Day</option>
                                <?php foreach ($days_of_week as $day): ?>
                                    <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="start_time" name="start_time" required>
                        </div>
                        <div class="col-md-2">
                            <label for="end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="end_time" name="end_time" required>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" name="add_schedule" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i> Add Schedule
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Weekly Timetable -->
                <h5 class="mb-3"><i class="fas fa-table me-2 text-primary"></i> Weekly Timetable</h5>
                <div class="timetable">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Time</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Term</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($days_of_week as $day): ?>
                                <?php if (!empty($schedule_by_day[$day])): ?>
                                    <?php foreach ($schedule_by_day[$day] as $schedule): ?>
                                        <?php
                                        $is_current = ($day === $current_day &&
                                                      $schedule['start_time'] <= $current_time &&
                                                      $schedule['end_time'] >= $current_time);
                                        ?>
                                        <tr class="<?php echo $is_current ? 'current-class' : ''; ?>">
                                            <td><strong><?php echo $day; ?></strong></td>
                                            <td><?php echo substr($schedule['start_time'], 0, 5) . ' - ' . substr($schedule['end_time'], 0, 5); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['subject_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['term_name']); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-primary me-1" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editModal<?php echo $schedule['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" class="d-inline" 
                                                      onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                                    <button type="submit" name="delete_schedule" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        
                                        <!-- Edit Modal -->
                                        <div class="modal fade" id="editModal<?php echo $schedule['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Schedule</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Class</label>
                                                                <select class="form-select" name="class_id" required>
                                                                    <?php foreach ($classes as $class): ?>
                                                                        <option value="<?php echo $class['id']; ?>" 
                                                                                <?php echo $class['id'] == $schedule['class_id'] ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Subject</label>
                                                                <select class="form-select" name="subject_id" required>
                                                                    <?php foreach ($subjects as $subject): ?>
                                                                        <option value="<?php echo $subject['id']; ?>" 
                                                                                <?php echo $subject['id'] == $schedule['subject_id'] ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Term</label>
                                                                <select class="form-select" name="term_id" required>
                                                                    <?php foreach ($terms as $term): ?>
                                                                        <option value="<?php echo $term['id']; ?>" 
                                                                                <?php echo $term['id'] == $schedule['term_id'] ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Day of Week</label>
                                                                <select class="form-select" name="day_of_week" required>
                                                                    <?php foreach ($days_of_week as $day): ?>
                                                                        <option value="<?php echo $day; ?>" 
                                                                                <?php echo $day == $schedule['day_of_week'] ? 'selected' : ''; ?>>
                                                                            <?php echo $day; ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Start Time</label>
                                                                <input type="time" class="form-control" name="start_time" 
                                                                       value="<?php echo $schedule['start_time']; ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">End Time</label>
                                                                <input type="time" class="form-control" name="end_time" 
                                                                       value="<?php echo $schedule['end_time']; ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="edit_schedule" class="btn btn-primary">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td><strong><?php echo $day; ?></strong></td>
                                        <td colspan="5" class="text-muted text-center">No classes scheduled</td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Legend -->
                <div class="mt-4 pt-2 border-top">
                    <small class="text-muted">
                        <i class="fas fa-square text-success me-1"></i> Green row = Current ongoing class
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        echo '<footer class="text-center text-muted py-3 mt-4 border-top">
                <small>QueensPa School Management System &copy; ' . date('Y') . '</small>
              </footer>';
    }
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php mysqli_close($conn); ?>
</body>
</html>