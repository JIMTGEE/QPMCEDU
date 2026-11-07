<?php
session_start();

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set time zone
date_default_timezone_set('Africa/Accra');

// Initialize variables
$user_id = $_SESSION['user_id'];
$success_msg = $error_msg = "";
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$current_day = date('l');
$current_time = date('H:i:s');

// Handle add schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    $class_id = (int)$_POST['class_id'];
    $subject_id = (int)$_POST['subject_id'];
    $term_id = (int)$_POST['term_id'];
    $day_of_week = $_POST['day_of_week'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];

    // Validate inputs
    if (!in_array($day_of_week, $days_of_week) || !strtotime($start_time) || !strtotime($end_time)) {
        $error_msg = "Invalid input data.";
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
            $error_msg = "Schedule overlaps with an existing one.";
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
                           "Class ID: $class_id, Day: $day_of_week, Term ID: $term_id");
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

// Handle edit schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_schedule'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    $class_id = (int)$_POST['class_id'];
    $subject_id = (int)$_POST['subject_id'];
    $term_id = (int)$_POST['term_id'];
    $day_of_week = $_POST['day_of_week'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];

    // Validate inputs
    if (!in_array($day_of_week, $days_of_week) || !strtotime($start_time) || !strtotime($end_time)) {
        $error_msg = "Invalid input data.";
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
            $error_msg = "Schedule overlaps with an existing one.";
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
                               "Schedule ID: $schedule_id, Class ID: $class_id");
                    $success_msg = "Schedule updated successfully.";
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error updating schedule: " . $e->getMessage();
                error_log("Edit schedule error: " . $e->getMessage());
            }
            $update_stmt->close();
        }
        $overlap_stmt->close();
    }
}

// Handle delete schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    $schedule_id = (int)$_POST['schedule_id'];
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
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error deleting schedule: " . $e->getMessage();
        error_log("Delete schedule error: " . $e->getMessage());
    }
    $delete_stmt->close();
}

// Fetch all classes
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// Fetch subjects
$subjects_sql = "SELECT id, subject_name FROM subjects WHERE teacher_id = ? OR teacher_id IS NULL ORDER BY subject_name";
$subjects_stmt = $conn->prepare($subjects_sql);
$subjects_stmt->bind_param("i", $user_id);
$subjects_stmt->execute();
$subjects_result = $subjects_stmt->get_result();
$subjects = $subjects_result->fetch_all(MYSQLI_ASSOC);
$subjects_stmt->close();

// Fetch terms
$terms_sql = "SELECT id, term_name FROM academic_calendar WHERE year = '2024/2025' ORDER BY term_name";
$terms_stmt = $conn->prepare($terms_sql);
$terms_stmt->execute();
$terms_result = $terms_stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
$terms_stmt->close();

// Fetch schedules
$schedules_sql = "SELECT cs.*, c.class_name, s.subject_name, ac.term_name
                  FROM class_schedules cs
                  JOIN classes c ON cs.class_id = c.id
                  LEFT JOIN subjects s ON cs.subject_id = s.id
                  JOIN academic_calendar ac ON cs.term_id = ac.id
                  WHERE cs.teacher_id = ?
                  ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), 
                           cs.start_time";
$schedules_stmt = $conn->prepare($schedules_sql);
$schedules_stmt->bind_param("i", $user_id);
$schedules_stmt->execute();
$schedules_result = $schedules_stmt->get_result();
$schedules = $schedules_result->fetch_all(MYSQLI_ASSOC);
$schedules_stmt->close();

// Organize schedules by day
$schedule_by_day = [];
foreach ($days_of_week as $day) {
    $schedule_by_day[$day] = [];
}
foreach ($schedules as $schedule) {
    $schedule_by_day[$schedule['day_of_week']][] = $schedule;
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher class schedules for QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, class schedules, teacher timetable, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Teacher Schedules">
    <meta property="og:description" content="Teacher interface for managing class schedules in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/class_schedules.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Teacher Schedules">
    <meta name="twitter:description" content="Teacher interface for managing class schedules in QueensPa School.">
    <title>Class Schedules - QueensPa School Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-success, .btn-danger {
            border-radius: 8px;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
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
        }
        .timetable th, .timetable td {
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: left;
            vertical-align: middle;
        }
        .timetable th {
            background-color: var(--primary-color);
            color: white;
        }
        .current-class {
            background-color: #d4edda;
            font-weight: bold;
        }
        .modal-content {
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📅 My Class Schedules</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    
                    <!-- Add Schedule Form -->
                    <h5>Add New Schedule</h5>
                    <form method="POST" class="mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="class_id" class="form-label">Class</label>
                                <select class="form-select" id="class_id" name="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="subject_id" class="form-label">Subject</label>
                                <select class="form-select" id="subject_id" name="subject_id" required>
                                    <option value="">Select Subject</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>">
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="term_id" class="form-label">Term</label>
                                <select class="form-select" id="term_id" name="term_id" required>
                                    <option value="">Select Term</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>">
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="day_of_week" class="form-label">Day of Week</label>
                                <select class="form-select" id="day_of_week" name="day_of_week" required>
                                    <option value="">Select Day</option>
                                    <?php foreach ($days_of_week as $day): ?>
                                        <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="start_time" name="start_time" required>
                            </div>
                            <div class="col-md-4">
                                <label for="end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="end_time" name="end_time" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" name="add_schedule" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Add Schedule
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Timetable -->
                    <h5>Weekly Timetable</h5>
                    <div class="timetable">
                        <table class="table table-bordered">
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
                                            $is_current = $day === $current_day &&
                                                          $schedule['start_time'] <= $current_time &&
                                                          $schedule['end_time'] >= $current_time;
                                            ?>
                                            <tr class="<?php echo $is_current ? 'current-class' : ''; ?>">
                                                <td><?php echo $day; ?></td>
                                                <td>
                                                    <?php echo substr($schedule['start_time'], 0, 5) . ' - ' . 
                                                               substr($schedule['end_time'], 0, 5); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($schedule['class_name']); ?></td>
                                                <td><?php echo htmlspecialchars($schedule['subject_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($schedule['term_name']); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editModal<?php echo $schedule['id']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline" 
                                                          onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                                        <input type="hidden" name="schedule_id" 
                                                               value="<?php echo $schedule['id']; ?>">
                                                        <button type="submit" name="delete_schedule" 
                                                                class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal<?php echo $schedule['id']; ?>" 
                                                 tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="editModalLabel">Edit Schedule</h5>
                                                            <button type="button" class="btn-close" 
                                                                    data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="schedule_id" 
                                                                       value="<?php echo $schedule['id']; ?>">
                                                                <div class="mb-3">
                                                                    <label for="class_id_<?php echo $schedule['id']; ?>" 
                                                                           class="form-label">Class</label>
                                                                    <select class="form-select" 
                                                                            id="class_id_<?php echo $schedule['id']; ?>" 
                                                                            name="class_id" required>
                                                                        <?php foreach ($classes as $class): ?>
                                                                            <option value="<?php echo $class['id']; ?>" 
                                                                                    <?php echo $class['id'] == $schedule['class_id'] ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($class['class_name']); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="subject_id_<?php echo $schedule['id']; ?>" 
                                                                           class="form-label">Subject</label>
                                                                    <select class="form-select" 
                                                                            id="subject_id_<?php echo $schedule['id']; ?>" 
                                                                            name="subject_id" required>
                                                                        <?php foreach ($subjects as $subject): ?>
                                                                            <option value="<?php echo $subject['id']; ?>" 
                                                                                    <?php echo $subject['id'] == $schedule['subject_id'] ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="term_id_<?php echo $schedule['id']; ?>" 
                                                                           class="form-label">Term</label>
                                                                    <select class="form-select" 
                                                                            id="term_id_<?php echo $schedule['id']; ?>" 
                                                                            name="term_id" required>
                                                                        <?php foreach ($terms as $term): ?>
                                                                            <option value="<?php echo $term['id']; ?>" 
                                                                                    <?php echo $term['id'] == $schedule['term_id'] ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($term['term_name']); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="day_of_week_<?php echo $schedule['id']; ?>" 
                                                                           class="form-label">Day of Week</label>
                                                                    <select class="form-select" 
                                                                            id="day_of_week_<?php echo $schedule['id']; ?>" 
                                                                            name="day_of_week" required>
                                                                        <?php foreach ($days_of_week as $day): ?>
                                                                            <option value="<?php echo $day; ?>" 
                                                                                    <?php echo $day == $schedule['day_of_week'] ? 'selected' : ''; ?>>
                                                                                <?php echo $day; ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="start_time_<?php echo $schedule['id']; ?>" 
                                                                           class="form-label">Start Time</label>
                                                                    <input type="time" class="form-control" 
                                                                           id="start_time_<?php echo $schedule['id']; ?>" 
                                                                           name="start_time" 
                                                                           value="<?php echo $schedule['start_time']; ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="end_time_<?php echo $schedule['id']; ?>" 
                                                                           class="form-label">End Time</label>
                                                                    <input type="time" class="form-control" 
                                                                           id="end_time_<?php echo $schedule['id']; ?>" 
                                                                           name="end_time" 
                                                                           value="<?php echo $schedule['end_time']; ?>" required>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" 
                                                                        data-bs-dismiss="modal">Close</button>
                                                                <button type="submit" name="edit_schedule" 
                                                                        class="btn btn-primary">Save Changes</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td><?php echo $day; ?></td>
                                            <td colspan="5">No schedules</td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>