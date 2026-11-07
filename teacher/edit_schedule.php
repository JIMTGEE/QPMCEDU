<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Restrict to teachers
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Redirect to login: user_id=" . ($_SESSION['user_id'] ?? 'unset') .
              ", user_role=" . ($_SESSION['user_role'] ?? 'unset') .
              ", requested_url=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    header("Location: /teacher/login.php");
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
$teacher_id = $_SESSION['user_id'];
$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$success_msg = $error_msg = "";

// Days of the week (Monday to Friday)
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Fetch current academic year from database
$academic_year_query = "SELECT `year` FROM academic_calendar WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY created_at DESC LIMIT 1";
$academic_year_stmt = $conn->prepare($academic_year_query);
$academic_year_stmt->execute();
$academic_year_result = $academic_year_stmt->get_result();

if ($academic_year_result->num_rows > 0) {
    $row = $academic_year_result->fetch_assoc();
    $academic_year = $row['year'];
    logActivity($conn, $teacher_id, "Fetched Academic Year", "Academic Year: $academic_year fetched from DB for teacher ID: $teacher_id");
} else {
    $current_year = date('Y');
    $academic_year = $current_year . '/' . ($current_year + 1);
    $error_msg .= "No current academic year found in database. Using fallback: $academic_year. ";
    logActivity($conn, $teacher_id, "Academic Year Fallback", "No DB match, using $academic_year for teacher ID: $teacher_id");
}
$academic_year_stmt->close();

// Set default term_id
$current_term_id = 2;

// Fetch teacher's department
$teacher_sql = "SELECT department_id FROM users WHERE id = ?";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();
$teacher_department_id = $teacher ? $teacher['department_id'] : null;
$teacher_stmt->close();

if (!$teacher_department_id) {
    $error_msg = "Teacher is not assigned to a department.";
}

// Fetch the schedule to edit
$schedule_data = null;
if ($schedule_id > 0) {
    $schedule_sql = "SELECT cs.*, c.class_name, s.subject_name, d.name AS department_name 
                     FROM class_schedules cs
                     LEFT JOIN classes c ON cs.class_id = c.id
                     LEFT JOIN subjects s ON cs.subject_id = s.id
                     LEFT JOIN departments d ON cs.department_id = d.id
                     WHERE cs.id = ? AND cs.teacher_id = ?";
    $schedule_stmt = $conn->prepare($schedule_sql);
    $schedule_stmt->bind_param("ii", $schedule_id, $teacher_id);
    $schedule_stmt->execute();
    $schedule_result = $schedule_stmt->get_result();
    
    if ($schedule_result->num_rows > 0) {
        $schedule_data = $schedule_result->fetch_assoc();
    } else {
        $error_msg = "Schedule not found or you don't have permission to edit it.";
    }
    $schedule_stmt->close();
} else {
    $error_msg = "No schedule ID provided.";
}

// Function to export the class_schedules table to SQL file (copy from class_schedule.php)
function exportTableToSql($conn, $table = 'class_schedules', $filename = null) {
    if ($filename === null) {
        $filename = $_SERVER['DOCUMENT_ROOT'] . '/class_schedules.sql';
    }
   
    $dir = dirname($filename);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            return false;
        }
    }
   
    if (!is_writable($dir)) {
        error_log("Directory not writable: $dir");
        return false;
    }
   
    $file = fopen($filename, 'w');
    if (!$file) {
        error_log("Failed to open file $filename for writing.");
        return false;
    }
   
    try {
        fwrite($file, "-- phpMyAdmin SQL Dump\n");
        fwrite($file, "-- version 5.2.2\n");
        fwrite($file, "-- https://www.phpmyadmin.net/\n--\n");
        fwrite($file, "-- Host: localhost:3306\n");
        fwrite($file, "-- Generation Time: " . date('M d, Y') . " at " . date('h:i A') . "\n");
        fwrite($file, "-- Server version: 11.4.9-MariaDB-log\n");
        fwrite($file, "-- PHP Version: 8.4.16\n\n");
        fwrite($file, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($file, "START TRANSACTION;\n");
        fwrite($file, "SET time_zone = \"+00:00\";\n\n\n");
        fwrite($file, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
        fwrite($file, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
        fwrite($file, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
        fwrite($file, "/*!40101 SET NAMES utf8mb4 */;\n\n");
        fwrite($file, "--\n-- Database: `qpmseduc_queenspa`\n--\n\n");
        fwrite($file, "-- --------------------------------------------------------\n\n");
        fwrite($file, "--\n-- Table structure for table `$table`\n--\n\n");
       
        $create = $conn->query("SHOW CREATE TABLE $table");
        if (!$create) {
            throw new Exception("Failed to get table structure: " . $conn->error);
        }
        $createRow = $create->fetch_row();
        fwrite($file, $createRow[1] . ";\n\n");
        fwrite($file, "--\n-- Dumping data for table `$table`\n--\n\n");
       
        $result = $conn->query("SELECT * FROM $table ORDER BY id");
        if (!$result) {
            throw new Exception("Failed to fetch table data: " . $conn->error);
        }
       
        if ($result->num_rows > 0) {
            fwrite($file, "INSERT INTO `$table` (`id`, `class_id`, `day_of_week`, `start_time`, `end_time`, `created_at`, `updated_at`, `subject_id`, `term_id`, `department_id`, `teacher_id`, `academic_year`) VALUES\n");
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $values = "(" .
                    intval($row['id']) . ", " .
                    intval($row['class_id']) . ", '" .
                    $conn->real_escape_string($row['day_of_week']) . "', '" .
                    $conn->real_escape_string($row['start_time']) . "', '" .
                    $conn->real_escape_string($row['end_time']) . "', '" .
                    $conn->real_escape_string($row['created_at']) . "', '" .
                    $conn->real_escape_string($row['updated_at']) . "', " .
                    intval($row['subject_id']) . ", " .
                    intval($row['term_id']) . ", " .
                    intval($row['department_id']) . ", " .
                    intval($row['teacher_id']) . ", '" .
                    $conn->real_escape_string($row['academic_year']) . "')";
                $rows[] = $values;
            }
            fwrite($file, implode(",\n", $rows) . ";\n");
        }
       
        fwrite($file, "\n--\n-- Indexes for dumped tables\n--\n\n");
        fwrite($file, "--\n-- Indexes for table `$table`\n--\n");
        fwrite($file, "ALTER TABLE `$table`\n");
        fwrite($file, " ADD PRIMARY KEY (`id`),\n");
        fwrite($file, " ADD KEY `class_id` (`class_id`),\n");
        fwrite($file, " ADD KEY `fk_subject_id` (`subject_id`),\n");
        fwrite($file, " ADD KEY `fk_term_id` (`term_id`),\n");
        fwrite($file, " ADD KEY `fk_teacher_id_schedules` (`teacher_id`),\n");
        fwrite($file, " ADD KEY `fk_class_schedules_department_id` (`department_id`);\n\n");
       
        fwrite($file, "--\n-- AUTO_INCREMENT for dumped tables\n--\n\n");
        fwrite($file, "--\n-- AUTO_INCREMENT for table `$table`\n--\n");
        $auto = $conn->query("SHOW TABLE STATUS LIKE '$table'");
        if (!$auto) {
            throw new Exception("Failed to get auto increment value: " . $conn->error);
        }
        $autoRow = $auto->fetch_assoc();
        $autoInc = intval($autoRow['Auto_increment']);
        fwrite($file, "ALTER TABLE `$table`\n");
        fwrite($file, " MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=" . $autoInc . ";\n");
        fwrite($file, "COMMIT;\n\n");
        fwrite($file, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
        fwrite($file, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
        fwrite($file, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
       
        fclose($file);
        error_log("Successfully exported $table to $filename");
        return true;
       
    } catch (Exception $e) {
        fclose($file);
        error_log("Error exporting table: " . $e->getMessage());
        return false;
    }
}

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

// Handle update schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $academic_year = trim($_POST['academic_year'] ?? '');
    $day_of_week = $_POST['day_of_week'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    
    // Validate inputs
    if (!$schedule_id || !$department_id || !$class_id || !$subject_id || empty($academic_year) ||
        !in_array($day_of_week, $days_of_week) || !$start_time || !$end_time) {
        $error_msg = "All fields are required.";
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $error_msg = "End time must be after start time.";
    } else {
        // Validate that the schedule belongs to the teacher
        $check_sql = "SELECT id FROM class_schedules WHERE id = ? AND teacher_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $schedule_id, $teacher_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            $error_msg = "Schedule not found or you don't have permission to edit it.";
            $check_stmt->close();
        } else {
            $check_stmt->close();
            
            // Validate department, class, and subject relationship
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
            
            if ($class_department_id !== $department_id || $subject_department_id !== $department_id) {
                $error_msg = "Class or subject does not belong to the selected department.";
            } else {
                // Check for overlapping schedules (excluding current schedule)
                $overlap_sql = "SELECT id FROM class_schedules
                                WHERE teacher_id = ? AND class_id = ? AND academic_year = ?
                                AND day_of_week = ? AND id != ?
                                AND ((start_time < ? AND end_time > ?)
                                     OR (start_time < ? AND end_time > ?)
                                     OR (start_time >= ? AND end_time <= ?))";
                $overlap_stmt = $conn->prepare($overlap_sql);
                $overlap_stmt->bind_param("iisssissssss", $teacher_id, $class_id, $academic_year, $day_of_week, $schedule_id,
                                         $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
                $overlap_stmt->execute();
                $overlap_result = $overlap_stmt->get_result();
                
                if ($overlap_result->num_rows > 0) {
                    $error_msg = "This update conflicts with an existing schedule for the same academic year.";
                    $overlap_stmt->close();
                } else {
                    $overlap_stmt->close();
                    
                    // Update schedule
                    $conn->begin_transaction();
                    try {
                        $update_sql = "UPDATE class_schedules 
                                       SET class_id = ?, day_of_week = ?, start_time = ?, end_time = ?, 
                                           subject_id = ?, term_id = ?, department_id = ?, academic_year = ?, updated_at = NOW()
                                       WHERE id = ? AND teacher_id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("isssiiissi", $class_id, $day_of_week, $start_time, $end_time, 
                                                 $subject_id, $current_term_id, $department_id, $academic_year, $schedule_id, $teacher_id);
                        
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to update schedule: " . $update_stmt->error);
                        }
                        
                        if ($update_stmt->affected_rows > 0) {
                            $conn->commit();
                            
                            logActivity($conn, $teacher_id, "Updated Schedule",
                                       "Schedule ID: $schedule_id, Teacher ID: $teacher_id, Class ID: $class_id, Subject ID: $subject_id, Academic Year: $academic_year");
                            
                            // Export to SQL file after successful update
                            $export_filename = $_SERVER['DOCUMENT_ROOT'] . '/class_schedules.sql';
                            if (exportTableToSql($conn, 'class_schedules', $export_filename)) {
                                $success_msg = "Schedule updated successfully. Database export updated.";
                                logActivity($conn, $teacher_id, "Exported Schedules", "Exported class_schedules table to SQL file after update");
                            } else {
                                $success_msg = "Schedule updated successfully.";
                                $error_msg .= " Warning: Failed to update class_schedules.sql file. Please contact administrator.";
                                error_log("Failed to export class_schedules table after updating schedule");
                            }
                            
                            // Refresh schedule data
                            $refresh_sql = "SELECT cs.*, c.class_name, s.subject_name, d.name AS department_name 
                                           FROM class_schedules cs
                                           LEFT JOIN classes c ON cs.class_id = c.id
                                           LEFT JOIN subjects s ON cs.subject_id = s.id
                                           LEFT JOIN departments d ON cs.department_id = d.id
                                           WHERE cs.id = ? AND cs.teacher_id = ?";
                            $refresh_stmt = $conn->prepare($refresh_sql);
                            $refresh_stmt->bind_param("ii", $schedule_id, $teacher_id);
                            $refresh_stmt->execute();
                            $refresh_result = $refresh_stmt->get_result();
                            $schedule_data = $refresh_result->fetch_assoc();
                            $refresh_stmt->close();
                        } else {
                            throw new Exception("No changes were made to the schedule.");
                        }
                        $update_stmt->close();
                        
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error_msg = "Error updating schedule: " . $e->getMessage();
                        error_log("Schedule update error: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// Fetch departments for dropdown
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit class schedule - QueensPa School Teacher Portal">
    <meta name="robots" content="noindex, nofollow">
    <title>Edit Schedule - QueensPa School Teacher Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: var(--text-color);
        }
        .container {
            max-width: 1200px;
        }
        .card {
            border: none;
            border-radius: 16px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            border-radius: 16px 16px 0 0;
            padding: 1.5rem;
        }
        .btn-primary, .btn-outline-primary, .btn-secondary {
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover) !important;
            border-color: var(--primary-hover) !important;
            animation: pulse 0.5s;
        }
        .btn-secondary {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
        }
        .btn-secondary:hover {
            background-color: #5a6268 !important;
            border-color: #5a6268 !important;
        }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #ced4da;
            padding: 0.75rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.2);
            outline: none;
        }
        .alert {
            border-radius: 10px;
            padding: 1rem;
            display: flex;
            align-items: center;
            transition: opacity 0.3s ease;
        }
        .loading-spinner {
            display: none;
            color: var(--primary-color);
            margin-left: 10px;
        }
        .invalid-feedback {
            display: none;
        }
        .was-validated .form-control:invalid ~ .invalid-feedback,
        .was-validated .form-select:invalid ~ .invalid-feedback {
            display: block;
        }
        .schedule-info {
            background: #e7f3ff;
            border: 1px solid #b6d4fe;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 15px;
        }
        @media (max-width: 767.98px) {
            .container {
                padding: 15px;
            }
            .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-5">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Class Schedule</h3>
                    <a href="/teacher/class_schedule.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Schedules
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($schedule_data): ?>
                        <!-- Current Schedule Info -->
                        <div class="schedule-info animate__animated animate__fadeIn">
                            <strong><i class="fas fa-info-circle me-2"></i>Editing Schedule:</strong>
                            <span class="ms-2">
                                <?php echo htmlspecialchars($schedule_data['class_name'] ?? 'N/A'); ?> - 
                                <?php echo htmlspecialchars($schedule_data['subject_name'] ?? 'N/A'); ?> - 
                                <?php echo htmlspecialchars($schedule_data['day_of_week'] ?? 'N/A'); ?> 
                                (<?php echo htmlspecialchars($schedule_data['start_time'] ?? 'N/A'); ?> - 
                                <?php echo htmlspecialchars($schedule_data['end_time'] ?? 'N/A'); ?>)
                            </span>
                        </div>

                        <!-- Edit Schedule Form -->
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?schedule_id=' . $schedule_id); ?>" id="editScheduleForm" class="animate__animated animate__fadeInUp" novalidate>
                            <input type="hidden" name="schedule_id" value="<?php echo $schedule_id; ?>">
                            
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                    <select class="form-select department-select" id="department_id" name="department_id" required data-bs-toggle="tooltip" title="Select the department">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>"
                                                    <?php echo ($dept['id'] == ($schedule_data['department_id'] ?? $teacher_department_id)) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a department.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="class_id" class="form-label">Class <span class="text-danger">*</span></label>
                                    <select class="form-select class-select" id="class_id" name="class_id" required data-bs-toggle="tooltip" title="Select the class">
                                        <option value="">Select Class</option>
                                        <?php if (isset($schedule_data['class_id'])): ?>
                                            <option value="<?php echo $schedule_data['class_id']; ?>" selected>
                                                <?php echo htmlspecialchars($schedule_data['class_name'] ?? ''); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                    <span class="loading-spinner class-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                    <div class="invalid-feedback">Please select a class.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                                    <select class="form-select subject-select" id="subject_id" name="subject_id" required data-bs-toggle="tooltip" title="Select the subject">
                                        <option value="">Select Subject</option>
                                        <?php if (isset($schedule_data['subject_id'])): ?>
                                            <option value="<?php echo $schedule_data['subject_id']; ?>" selected>
                                                <?php echo htmlspecialchars($schedule_data['subject_name'] ?? ''); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                    <span class="loading-spinner subject-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                    <div class="invalid-feedback">Please select a subject.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="academic_year" class="form-label">Academic Year <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="academic_year" name="academic_year"
                                           value="<?php echo htmlspecialchars($schedule_data['academic_year'] ?? $academic_year); ?>" required
                                           data-bs-toggle="tooltip" title="Format: YYYY/YYYY (e.g., 2024/2025)"
                                           pattern="\d{4}/\d{4}" placeholder="YYYY/YYYY">
                                    <div class="invalid-feedback">Please enter a valid academic year (format: YYYY/YYYY).</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="day_of_week" class="form-label">Day of Week <span class="text-danger">*</span></label>
                                    <select class="form-select" id="day_of_week" name="day_of_week" required data-bs-toggle="tooltip" title="Select the day">
                                        <option value="">Select Day</option>
                                        <?php foreach ($days_of_week as $day): ?>
                                            <option value="<?php echo $day; ?>" <?php echo ($day == ($schedule_data['day_of_week'] ?? '')) ? 'selected' : ''; ?>>
                                                <?php echo $day; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a day.</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="start_time" name="start_time"
                                           value="<?php echo htmlspecialchars($schedule_data['start_time'] ?? ''); ?>" required
                                           data-bs-toggle="tooltip" title="Select start time">
                                    <div class="invalid-feedback">Please select a start time.</div>
                                </div>

                                <div class="col-md-3">
                                    <label for="end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="end_time" name="end_time"
                                           value="<?php echo htmlspecialchars($schedule_data['end_time'] ?? ''); ?>" required
                                           data-bs-toggle="tooltip" title="Select end time">
                                    <div class="invalid-feedback">Please select an end time.</div>
                                </div>

                                <div class="col-12">
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <a href="/teacher/class_schedule.php" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </a>
                                        <button type="submit" name="update_schedule" class="btn btn-primary" id="updateScheduleBtn">
                                            <i class="fas fa-save me-2"></i>Update Schedule
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                            <h4>Schedule Not Found</h4>
                            <p class="text-muted">The schedule you're trying to edit doesn't exist or you don't have permission to access it.</p>
                            <a href="/teacher/class_schedule.php" class="btn btn-primary mt-3">
                                <i class="fas fa-arrow-left me-2"></i>Back to Schedules
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; mysqli_close($conn); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" crossorigin="anonymous" defer></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" crossorigin="anonymous"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            // Initialize form validation
            const form = document.getElementById('editScheduleForm');
            if (form) {
                form.addEventListener('submit', validateForm);
            }

            // Load initial classes and subjects if department is pre-selected
            const initialDepartment = $('#department_id').val();
            if (initialDepartment) {
                loadClasses(initialDepartment, $('#class_id').val());
                loadSubjects(initialDepartment, $('#subject_id').val());
            }
        });

        function validateForm(event) {
            event.preventDefault();
            console.log('Validating edit schedule form...');

            const form = document.getElementById('editScheduleForm');
            const formElements = form.querySelectorAll('select, input');
            let isValid = true;
            let errorMessages = [];

            // Check HTML5 validation
            formElements.forEach(input => {
                if (!input.checkValidity()) {
                    isValid = false;
                    form.classList.add('was-validated');
                    errorMessages.push(`${input.closest('.form-label')?.textContent || 'Field'} is required.`);
                    console.log(`Validation failed for ${input.id}: ${input.validationMessage}`);
                }
            });

            // Validate academic year format
            const academicYear = document.getElementById('academic_year').value;
            const academicYearPattern = /^\d{4}\/\d{4}$/;
            if (academicYear && !academicYearPattern.test(academicYear)) {
                isValid = false;
                errorMessages.push('Academic year must be in format YYYY/YYYY (e.g., 2024/2025).');
            }

            // Validate time constraints
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            if (startTime && endTime && new Date(`1970-01-01T${endTime}`) <= new Date(`1970-01-01T${startTime}`)) {
                isValid = false;
                errorMessages.push('End time must be after start time.');
                console.log(`Time validation failed: Start=${startTime}, End=${endTime}`);
                document.getElementById('end_time').focus();
            }

            if (!isValid) {
                alert('Please correct the following errors:\n' + errorMessages.join('\n'));
                $('#updateScheduleBtn').prop('disabled', false);
                console.log('Form submission prevented due to validation errors.');
                return false;
            }

            if (confirm('Are you sure you want to update this schedule?')) {
                console.log('Form validated, submitting...');
                submitEditForm();
            } else {
                console.log('Form submission cancelled by user.');
                $('#updateScheduleBtn').prop('disabled', false);
            }

            return false;
        }

        function submitEditForm() {
            $('#updateScheduleBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Updating...');

            $.ajax({
                url: $('#editScheduleForm').attr('action') || window.location.href,
                type: 'POST',
                data: $('#editScheduleForm').serialize() + '&update_schedule=1',
                success: function(response) {
                    console.log('Form submission successful:', response);
                    // Redirect back to class_schedule.php with success message
                    window.location.href = '/teacher/class_schedule.php?success=2';
                },
                error: function(xhr, status, error) {
                    console.error('Form submission error:', status, error, xhr.responseText);
                    alert('Failed to update schedule: ' + (xhr.responseText || error) + '. Please try again or contact support.');
                    $('#updateScheduleBtn').prop('disabled', false).html('<i class="fas fa-save me-2"></i>Update Schedule');
                }
            });
        }

        $(document).ready(function() {
            // Cache for AJAX results
            const ajaxCache = {};

            // Handle department change
            $('#department_id').change(function(e) {
                e.preventDefault();
                const departmentId = $(this).val();
                const classSelect = $('#class_id');
                const subjectSelect = $('#subject_id');
                const classSpinner = $('.class-spinner');
                const subjectSpinner = $('.subject-spinner');

                classSelect.empty().append('<option value="">Select Class</option>');
                subjectSelect.empty().append('<option value="">Select Subject</option>');

                if (!departmentId) {
                    console.log('No department selected');
                    return;
                }

                console.log(`Fetching data for department ${departmentId}`);

                // Load classes
                loadClasses(departmentId, null);

                // Load subjects
                loadSubjects(departmentId, null);
            });

            function loadClasses(departmentId, selectedClassId) {
                const classSelect = $('#class_id');
                const classSpinner = $('.class-spinner');
                const cacheKey = `classes_${departmentId}`;

                if (ajaxCache[cacheKey]) {
                    populateSelect(classSelect, ajaxCache[cacheKey], 'id', 'class_name', selectedClassId);
                    classSpinner.hide();
                } else {
                    classSpinner.show();
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_classes_by_department',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        timeout: 5000,
                        success: function(response) {
                            classSpinner.hide();
                            console.log('Classes response:', response);
                            if (response.error) {
                                alert(`Error fetching classes: ${response.error}`);
                                classSelect.append('<option value="">No classes available</option>');
                            } else if (response.classes.length === 0) {
                                alert('No classes found for this department.');
                                classSelect.append('<option value="">No classes available</option>');
                            } else {
                                ajaxCache[cacheKey] = response.classes;
                                populateSelect(classSelect, response.classes, 'id', 'class_name', selectedClassId);
                            }
                        },
                        error: function(xhr, status, error) {
                            classSpinner.hide();
                            console.error('Classes AJAX error:', status, error, xhr.responseText);
                            alert(`Failed to fetch classes: ${error}. Please try again or contact support.`);
                            classSelect.append('<option value="">Select Class</option>');
                        }
                    });
                }
            }

            function loadSubjects(departmentId, selectedSubjectId) {
                const subjectSelect = $('#subject_id');
                const subjectSpinner = $('.subject-spinner');
                const cacheKey = `subjects_${departmentId}`;

                if (ajaxCache[cacheKey]) {
                    populateSelect(subjectSelect, ajaxCache[cacheKey], 'id', 'subject_name', selectedSubjectId);
                    subjectSpinner.hide();
                } else {
                    subjectSpinner.show();
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_subjects_by_department',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        timeout: 5000,
                        success: function(response) {
                            subjectSpinner.hide();
                            console.log('Subjects response:', response);
                            if (response.error) {
                                alert(`Error fetching subjects: ${response.error}`);
                                subjectSelect.append('<option value="">No subjects available</option>');
                            } else if (response.subjects.length === 0) {
                                alert('No subjects found for this department.');
                                subjectSelect.append('<option value="">No subjects available</option>');
                            } else {
                                ajaxCache[cacheKey] = response.subjects;
                                populateSelect(subjectSelect, response.subjects, 'id', 'subject_name', selectedSubjectId);
                            }
                        },
                        error: function(xhr, status, error) {
                            subjectSpinner.hide();
                            console.error('Subjects AJAX error:', status, error, xhr.responseText);
                            alert(`Failed to fetch subjects: ${error}. Please try again or contact support.`);
                            subjectSelect.append('<option value="">Select Subject</option>');
                        }
                    });
                }
            }

            // Helper function to populate select options
            function populateSelect(select, items, valueKey, textKey, selectedValue) {
                $.each(items, function(index, item) {
                    select.append(
                        `<option value="${item[valueKey]}" ${item[valueKey] == selectedValue ? 'selected' : ''}>${item[textKey]}</option>`
                    );
                });
            }
        });
    </script>
</body>
</html>