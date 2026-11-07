<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Redirect to login if not authenticated or not a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception("Database connection failed: " . ($conn->connect_error ?? 'Connection not established'));
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

// Initialize variables
$teacher_id = (int)$_SESSION['user_id'];
$success_msg = null;
$edit_mode = false;
$edit_term_id = null;

// Validate teacher
$teacher_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
if (!$teacher_stmt) {
    error_log("Failed to prepare teacher query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate teacher.";
    header("Location: /login.php");
    exit();
}
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

// Log page access
function log_action($conn, $user_id, $action, $description, $ip, $user_agent) {
    $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $log_stmt->bind_param("issss", $user_id, $action, $description, $ip, $user_agent);
        $log_stmt->execute();
        $log_stmt->close();
    }
}
log_action($conn, $teacher_id, 'Accessed Submit Grades', 
           "Teacher accessed submit grades page for user_id: $teacher_id", 
           $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

// Validate class_id
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    error_log("Invalid or missing class_id for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Invalid class ID.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$class_id = (int)$_GET['class_id'];

// Fetch class details
$class_sql = "SELECT c.id, c.class_name, c.department_id FROM classes c WHERE c.id = ? AND c.teacher_id = ?";
$class_stmt = $conn->prepare($class_sql);
if (!$class_stmt) {
    error_log("Failed to prepare class query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to load class data.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$class_stmt->bind_param("ii", $class_id, $teacher_id);
$class_stmt->execute();
$class = $class_stmt->get_result()->fetch_assoc();
$class_stmt->close();

if (!$class) {
    error_log("Class not found or unauthorized for class_id: $class_id, user_id: $teacher_id");
    $_SESSION['error_msg'] = "Class not found or you are not authorized.";
    header("Location: /teacher/view_classes.php");
    exit();
}

// Get current academic year and term
$year_sql = "SELECT year, id AS term_id, term_name 
             FROM academic_calendar 
             WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
$year_stmt = $conn->prepare($year_sql);
if (!$year_stmt) {
    error_log("Failed to prepare year query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to load academic year.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$year_stmt->execute();
$year_result = $year_stmt->get_result()->fetch_assoc();
$year_stmt->close();

if (!$year_result) {
    error_log("No active academic year found for class_id: $class_id");
    $_SESSION['error_msg'] = "No active academic year found.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$current_year = $year_result['year'];
$class['term_id'] = $year_result['term_id'];
$class['term_name'] = $year_result['term_name'];

// Fetch exams/terms
$exams_sql = "SELECT id, term_name, year FROM academic_calendar WHERE start_date <= CURDATE() ORDER BY year DESC, start_date DESC";
$exams_stmt = $conn->prepare($exams_sql);
if (!$exams_stmt) {
    error_log("Failed to prepare exams query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to load exam data.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$exams_stmt->execute();
$exams = $exams_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exams_stmt->close();

// Fetch all classes for promotion
$all_classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$all_classes_stmt = $conn->prepare($all_classes_sql);
if (!$all_classes_stmt) {
    error_log("Failed to prepare all classes query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to load classes.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$all_classes_stmt->execute();
$all_classes = $all_classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$all_classes_stmt->close();

// Generate and store CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
if ($csrf_stmt) {
    $csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
    $csrf_stmt->execute();
    $csrf_stmt->close();
    // Clean up expired tokens
    $conn->query("DELETE FROM csrf_tokens WHERE expires_at <= NOW()");
}

// Fetch students
$students_sql = "SELECT u.id, u.name, u.email, u.profile_picture 
                 FROM student_classes sc 
                 JOIN users u ON sc.user_id = u.id 
                 WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
                 ORDER BY u.name";
$students_stmt = $conn->prepare($students_sql);
if (!$students_stmt) {
    error_log("Failed to prepare students query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to load student data.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$students_stmt->bind_param("is", $class_id, $current_year);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

$selected_student_id = isset($_GET['student_id']) && is_numeric($_GET['student_id']) ? (int)$_GET['student_id'] : null;
$student_profile_picture = '/Uploads/profiles/default.jpg';

// Validate selected student
if ($selected_student_id) {
    $student_valid_sql = "SELECT u.id, u.profile_picture 
                          FROM student_classes sc 
                          JOIN users u ON sc.user_id = u.id 
                          WHERE sc.user_id = ? AND sc.class_id = ? AND sc.academic_year = ?";
    $student_valid_stmt = $conn->prepare($student_valid_sql);
    if (!$student_valid_stmt) {
        error_log("Failed to prepare student validation query: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to validate student.";
        header("Location: /teacher/submit_grades.php?class_id=$class_id");
        exit();
    }
    $student_valid_stmt->bind_param("iis", $selected_student_id, $class_id, $current_year);
    $student_valid_stmt->execute();
    $student_valid = $student_valid_stmt->get_result()->fetch_assoc();
    $student_valid_stmt->close();

    if (!$student_valid) {
        error_log("Invalid student_id: $selected_student_id for class_id: $class_id, academic_year: $current_year");
        $_SESSION['error_msg'] = "Invalid student selected.";
        $selected_student_id = null;
    } elseif ($student_valid['profile_picture'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $student_valid['profile_picture'])) {
        $student_profile_picture = htmlspecialchars($student_valid['profile_picture']);
    } else {
        error_log("Invalid profile picture for user_id: $selected_student_id, path: " . ($student_valid['profile_picture'] ?? 'none'));
    }
}

// Fetch subjects
$subjects_sql = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name";
$subjects_stmt = $conn->prepare($subjects_sql);
if (!$subjects_stmt) {
    error_log("Failed to prepare subjects query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to load subjects.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$subjects_stmt->bind_param("i", $class['department_id']);
$subjects_stmt->execute();
$subjects = $subjects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subjects_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['submit_grades', 'update_record', 'edit_record', 'delete_record'])) {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $csrf_check_sql = "SELECT 1 FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $csrf_check_stmt = $conn->prepare($csrf_check_sql);
    if (!$csrf_check_stmt) {
        error_log("Failed to prepare CSRF check query: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to validate session.";
        header("Location: /teacher/submit_grades.php?class_id=$class_id" . ($selected_student_id ? "&student_id=$selected_student_id" : ""));
        exit();
    }
    $csrf_check_stmt->bind_param("is", $teacher_id, $posted_csrf);
    $csrf_check_stmt->execute();
    if (!$csrf_check_stmt->get_result()->fetch_assoc()) {
        error_log("Invalid CSRF token for user_id: $teacher_id");
        $_SESSION['error_msg'] = "Invalid session. Please try again.";
        header("Location: /teacher/submit_grades.php?class_id=$class_id" . ($selected_student_id ? "&student_id=$selected_student_id" : ""));
        exit();
    }
    $csrf_check_stmt->close();

    if ($_POST['action'] === 'submit_grades' && $selected_student_id && $class['term_id']) {
        $exam_id = isset($_POST['exam_id']) && is_numeric($_POST['exam_id']) ? (int)$_POST['exam_id'] : $class['term_id'];
        $conn->begin_transaction();
        $error = false;

        // Submit grades (ONLY mid_term_exam and end_term_exam, out of 100)
        $grades_sql = "INSERT INTO grades (user_id, class_id, subject_id, term_id, mid_term_exam, end_term_exam) 
                       VALUES (?, ?, ?, ?, ?, ?) 
                       ON DUPLICATE KEY UPDATE 
                       mid_term_exam = VALUES(mid_term_exam), end_term_exam = VALUES(end_term_exam), 
                       updated_at = CURRENT_TIMESTAMP";
        $grades_stmt = $conn->prepare($grades_sql);
        if (!$grades_stmt) {
            error_log("Failed to prepare grades query: " . $conn->error);
            $_SESSION['error_msg'] = "System error: Unable to submit grades.";
            $conn->rollback();
            header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
            exit();
        }

        foreach ($subjects as $subject) {
            $subject_id = $subject['id'];
            $mid_term = isset($_POST['mid_term'][$subject_id]) && is_numeric($_POST['mid_term'][$subject_id]) ? (float)$_POST['mid_term'][$subject_id] : null;
            $end_term = isset($_POST['end_term'][$subject_id]) && is_numeric($_POST['end_term'][$subject_id]) ? (float)$_POST['end_term'][$subject_id] : null;

            $grade_types = ['Mid-Term Exam' => $mid_term, 'End-Term Exam' => $end_term];
            foreach ($grade_types as $type => $value) {
                if ($value !== null && ($value < 0 || $value > 100)) {
                    $_SESSION['error_msg'] = "$type grade for {$subject['subject_name']} must be between 0 and 100.";
                    $error = true;
                    break 2;
                }
            }

            $grades_stmt->bind_param("iiiidd", $selected_student_id, $class_id, $subject_id, $exam_id, $mid_term, $end_term);
            if (!$grades_stmt->execute()) {
                error_log("Failed to submit grades for user_id: $selected_student_id, class_id: $class_id, subject_id: $subject_id, error: " . $grades_stmt->error);
                $_SESSION['error_msg'] = "Failed to submit grades for {$subject['subject_name']}.";
                $error = true;
                break;
            }
        }
        $grades_stmt->close();

        // Submit report (unchanged)
        if (!$error) {
            $attendance = isset($_POST['attendance']) && is_numeric($_POST['attendance']) ? (int)$_POST['attendance'] : null;
            $conduct = isset($_POST['conduct']) ? trim($_POST['conduct']) : null;
            $attitude = isset($_POST['attitude']) ? trim($_POST['attitude']) : null;
            $interest = isset($_POST['interest']) ? trim($_POST['interest']) : null;
            $reopening_date = isset($_POST['reopening_date']) && !empty($_POST['reopening_date']) ? $_POST['reopening_date'] : null;
            $teacher_remarks = isset($_POST['teacher_remarks']) ? trim($_POST['teacher_remarks']) : null;
            $headmaster_remarks = isset($_POST['headmaster_remarks']) ? trim($_POST['headmaster_remarks']) : null;
            $promoted_to_class_id = isset($_POST['promoted_to_class_id']) && is_numeric($_POST['promoted_to_class_id']) ? (int)$_POST['promoted_to_class_id'] : null;

            if ($attendance !== null && $attendance < 0) {
                $_SESSION['error_msg'] = "Attendance cannot be negative.";
                $error = true;
            }
            if ($reopening_date && !DateTime::createFromFormat('Y-m-d', $reopening_date)) {
                $_SESSION['error_msg'] = "Invalid reopening date format.";
                $error = true;
            }

            if (!$error) {
                $report_sql = "INSERT INTO student_reports (user_id, class_id, term_id, attendance, conduct, attitude, interest, 
                               reopening_date, teacher_remarks, headmaster_remarks, promoted_to_class_id) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE 
                               attendance = VALUES(attendance), conduct = VALUES(conduct), attitude = VALUES(attitude), 
                               interest = VALUES(interest), reopening_date = VALUES(reopening_date), 
                               teacher_remarks = VALUES(teacher_remarks), headmaster_remarks = VALUES(headmaster_remarks), 
                               promoted_to_class_id = VALUES(promoted_to_class_id), updated_at = CURRENT_TIMESTAMP";
                $report_stmt = $conn->prepare($report_sql);
                if (!$report_stmt) {
                    error_log("Failed to prepare report query: " . $conn->error);
                    $_SESSION['error_msg'] = "System error: Unable to submit report.";
                    $error = true;
                } else {
                    $report_stmt->bind_param("iiisssssssi", $selected_student_id, $class_id, $exam_id, 
                                             $attendance, $conduct, $attitude, $interest, $reopening_date, 
                                             $teacher_remarks, $headmaster_remarks, $promoted_to_class_id);
                    if (!$report_stmt->execute()) {
                        error_log("Failed to submit report for user_id: $selected_student_id, class_id: $class_id, term_id: $exam_id, error: " . $report_stmt->error);
                        $_SESSION['error_msg'] = "Failed to submit student report.";
                        $error = true;
                    }
                    $report_stmt->close();
                }
            }
        }

        if ($error) {
            $conn->rollback();
        } else {
            $conn->commit();
            $success_msg = "Grades and report submitted successfully for {$class['class_name']}.";
            log_action($conn, $teacher_id, 'Submitted Grades and Report', 
                       "Teacher submitted grades and report for student_id: $selected_student_id, class: {$class['class_name']} (ID: $class_id)", 
                       $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        }
        header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
        exit();
    } elseif ($_POST['action'] === 'edit_record' && $selected_student_id) {
        $term_id = isset($_POST['term_id']) && is_numeric($_POST['term_id']) ? (int)$_POST['term_id'] : null;
        if (!$term_id) {
            error_log("Invalid term_id for edit action, user_id: $teacher_id");
            $_SESSION['error_msg'] = "Invalid term selected.";
            header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
            exit();
        }
        $edit_mode = true;
        $edit_term_id = $term_id;

        // Fetch grades for editing
        $edit_grades_sql = "SELECT subject_id, mid_term_exam, end_term_exam
                            FROM grades 
                            WHERE user_id = ? AND class_id = ? AND term_id = ?";
        $edit_grades_stmt = $conn->prepare($edit_grades_sql);
        if ($edit_grades_stmt) {
            $edit_grades_stmt->bind_param("iii", $selected_student_id, $class_id, $edit_term_id);
            $edit_grades_stmt->execute();
            $edit_grades_result = $edit_grades_stmt->get_result();
            $edit_grades = [];
            while ($row = $edit_grades_result->fetch_assoc()) {
                $edit_grades[$row['subject_id']] = $row;
            }
            $edit_grades_stmt->close();
        }

        // Fetch report for editing
        $edit_report_sql = "SELECT attendance, conduct, attitude, interest, reopening_date, 
                            teacher_remarks, headmaster_remarks, promoted_to_class_id 
                            FROM student_reports 
                            WHERE user_id = ? AND class_id = ? AND term_id = ?";
        $edit_report_stmt = $conn->prepare($edit_report_sql);
        if ($edit_report_stmt) {
            $edit_report_stmt->bind_param("iii", $selected_student_id, $class_id, $edit_term_id);
            $edit_report_stmt->execute();
            $edit_report = $edit_report_stmt->get_result()->fetch_assoc();
            $edit_report_stmt->close();
        }
    } elseif ($_POST['action'] === 'update_record' && $selected_student_id) {
        $term_id = isset($_POST['term_id']) && is_numeric($_POST['term_id']) ? (int)$_POST['term_id'] : null;
        if (!$term_id) {
            error_log("Invalid term_id for update action, user_id: $teacher_id");
            $_SESSION['error_msg'] = "Invalid term selected.";
            header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
            exit();
        }

        $conn->begin_transaction();
        $error = false;

        // Update grades
        $update_grades_sql = "INSERT INTO grades (user_id, class_id, subject_id, term_id, mid_term_exam, end_term_exam) 
                              VALUES (?, ?, ?, ?, ?, ?) 
                              ON DUPLICATE KEY UPDATE 
                              mid_term_exam = VALUES(mid_term_exam), end_term_exam = VALUES(end_term_exam), 
                              updated_at = CURRENT_TIMESTAMP";
        $update_grades_stmt = $conn->prepare($update_grades_sql);
        if (!$update_grades_stmt) {
            error_log("Failed to prepare update grades query: " . $conn->error);
            $_SESSION['error_msg'] = "System error: Unable to update grades.";
            $conn->rollback();
            header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
            exit();
        }

        foreach ($subjects as $subject) {
            $subject_id = $subject['id'];
            $mid_term = isset($_POST['mid_term'][$subject_id]) && is_numeric($_POST['mid_term'][$subject_id]) ? (float)$_POST['mid_term'][$subject_id] : null;
            $end_term = isset($_POST['end_term'][$subject_id]) && is_numeric($_POST['end_term'][$subject_id]) ? (float)$_POST['end_term'][$subject_id] : null;

            $grade_types = ['Mid-Term Exam' => $mid_term, 'End-Term Exam' => $end_term];
            foreach ($grade_types as $type => $value) {
                if ($value !== null && ($value < 0 || $value > 100)) {
                    $_SESSION['error_msg'] = "$type grade for {$subject['subject_name']} must be between 0 and 100.";
                    $error = true;
                    break 2;
                }
            }

            $update_grades_stmt->bind_param("iiiidd", $selected_student_id, $class_id, $subject_id, $term_id, $mid_term, $end_term);
            if (!$update_grades_stmt->execute()) {
                error_log("Failed to update grades for user_id: $selected_student_id, class_id: $class_id, subject_id: $subject_id, term_id: $term_id, error: " . $update_grades_stmt->error);
                $_SESSION['error_msg'] = "Failed to update grades for {$subject['subject_name']}.";
                $error = true;
                break;
            }
        }
        $update_grades_stmt->close();

        // Update report (unchanged)
        if (!$error) {
            $attendance = isset($_POST['attendance']) && is_numeric($_POST['attendance']) ? (int)$_POST['attendance'] : null;
            $conduct = isset($_POST['conduct']) ? trim($_POST['conduct']) : null;
            $attitude = isset($_POST['attitude']) ? trim($_POST['attitude']) : null;
            $interest = isset($_POST['interest']) ? trim($_POST['interest']) : null;
            $reopening_date = isset($_POST['reopening_date']) && !empty($_POST['reopening_date']) ? $_POST['reopening_date'] : null;
            $teacher_remarks = isset($_POST['teacher_remarks']) ? trim($_POST['teacher_remarks']) : null;
            $headmaster_remarks = isset($_POST['headmaster_remarks']) ? trim($_POST['headmaster_remarks']) : null;
            $promoted_to_class_id = isset($_POST['promoted_to_class_id']) && is_numeric($_POST['promoted_to_class_id']) ? (int)$_POST['promoted_to_class_id'] : null;

            if ($attendance !== null && $attendance < 0) {
                $_SESSION['error_msg'] = "Attendance cannot be negative.";
                $error = true;
            }
            if ($reopening_date && !DateTime::createFromFormat('Y-m-d', $reopening_date)) {
                $_SESSION['error_msg'] = "Invalid reopening date format.";
                $error = true;
            }

            if (!$error) {
                $update_report_sql = "INSERT INTO student_reports (user_id, class_id, term_id, attendance, conduct, attitude, interest, 
                                      reopening_date, teacher_remarks, headmaster_remarks, promoted_to_class_id) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE 
                                      attendance = VALUES(attendance), conduct = VALUES(conduct), attitude = VALUES(attitude), 
                                      interest = VALUES(interest), reopening_date = VALUES(reopening_date), 
                                      teacher_remarks = VALUES(teacher_remarks), headmaster_remarks = VALUES(headmaster_remarks), 
                                      promoted_to_class_id = VALUES(promoted_to_class_id), updated_at = CURRENT_TIMESTAMP";
                $update_report_stmt = $conn->prepare($update_report_sql);
                if (!$update_report_stmt) {
                    error_log("Failed to prepare update report query: " . $conn->error);
                    $_SESSION['error_msg'] = "System error: Unable to update report.";
                    $error = true;
                } else {
                    $update_report_stmt->bind_param("iiisssssssi", $selected_student_id, $class_id, $term_id, 
                                                    $attendance, $conduct, $attitude, $interest, $reopening_date, 
                                                    $teacher_remarks, $headmaster_remarks, $promoted_to_class_id);
                    if (!$update_report_stmt->execute()) {
                        error_log("Failed to update report for user_id: $selected_student_id, class_id: $class_id, term_id: $term_id, error: " . $update_report_stmt->error);
                        $_SESSION['error_msg'] = "Failed to update student report.";
                        $error = true;
                    }
                    $update_report_stmt->close();
                }
            }
        }

        if ($error) {
            $conn->rollback();
        } else {
            $conn->commit();
            $success_msg = "Historical record updated successfully.";
            log_action($conn, $teacher_id, 'Updated Historical Record', 
                       "Teacher updated grades and report for student_id: $selected_student_id, class_id: $class_id, term_id: $term_id", 
                       $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        }
        header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
        exit();
    } elseif ($_POST['action'] === 'delete_record' && $selected_student_id) {
        $term_id = isset($_POST['term_id']) && is_numeric($_POST['term_id']) ? (int)$_POST['term_id'] : null;
        if (!$term_id) {
            error_log("Invalid term_id for delete action, user_id: $teacher_id");
            $_SESSION['error_msg'] = "Invalid term selected.";
            header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
            exit();
        }

        $conn->begin_transaction();
        $error = false;

        // Delete grades
        $delete_grades_sql = "DELETE FROM grades WHERE user_id = ? AND class_id = ? AND term_id = ?";
        $delete_grades_stmt = $conn->prepare($delete_grades_sql);
        if (!$delete_grades_stmt) {
            error_log("Failed to prepare delete grades query: " . $conn->error);
            $_SESSION['error_msg'] = "System error: Unable to delete grades.";
            $error = true;
        } else {
            $delete_grades_stmt->bind_param("iii", $selected_student_id, $class_id, $term_id);
            if (!$delete_grades_stmt->execute()) {
                error_log("Failed to delete grades for user_id: $selected_student_id, class_id: $class_id, term_id: $term_id, error: " . $delete_grades_stmt->error);
                $_SESSION['error_msg'] = "Failed to delete grades.";
                $error = true;
            }
            $delete_grades_stmt->close();
        }

        // Delete report
        $delete_report_sql = "DELETE FROM student_reports WHERE user_id = ? AND class_id = ? AND term_id = ?";
        $delete_report_stmt = $conn->prepare($delete_report_sql);
        if (!$delete_report_stmt) {
            error_log("Failed to prepare delete report query: " . $conn->error);
            $_SESSION['error_msg'] = "System error: Unable to delete report.";
            $error = true;
        } else {
            $delete_report_stmt->bind_param("iii", $selected_student_id, $class_id, $term_id);
            if (!$delete_report_stmt->execute()) {
                error_log("Failed to delete report for user_id: $selected_student_id, class_id: $class_id, term_id: $term_id, error: " . $delete_report_stmt->error);
                $_SESSION['error_msg'] = "Failed to delete report.";
                $error = true;
            }
            $delete_report_stmt->close();
        }

        if ($error) {
            $conn->rollback();
        } else {
            $conn->commit();
            $success_msg = "Historical record deleted successfully.";
            log_action($conn, $teacher_id, 'Deleted Historical Record', 
                       "Teacher deleted grades and report for student_id: $selected_student_id, class_id: $class_id, term_id: $term_id", 
                       $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        }
        header("Location: /teacher/submit_grades.php?class_id=$class_id&student_id=$selected_student_id");
        exit();
    }
}

// Fetch existing grades
$existing_grades = [];
if ($selected_student_id && $class['term_id'] && !$edit_mode) {
    $existing_grades_sql = "SELECT subject_id, mid_term_exam, end_term_exam
                            FROM grades 
                            WHERE user_id = ? AND class_id = ? AND term_id = ?";
    $existing_grades_stmt = $conn->prepare($existing_grades_sql);
    if (!$existing_grades_stmt) {
        error_log("Failed to prepare existing grades query: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to load existing grades.";
        header("Location: /teacher/submit_grades.php?class_id=$class_id");
        exit();
    }
    $existing_grades_stmt->bind_param("iii", $selected_student_id, $class_id, $class['term_id']);
    $existing_grades_stmt->execute();
    $existing_grades_result = $existing_grades_stmt->get_result();
    while ($row = $existing_grades_result->fetch_assoc()) {
        $existing_grades[$row['subject_id']] = $row;
    }
    $existing_grades_stmt->close();
}

// Fetch existing report
$existing_report = [];
if ($selected_student_id && $class['term_id'] && !$edit_mode) {
    $report_sql = "SELECT attendance, conduct, attitude, interest, reopening_date, 
                   teacher_remarks, headmaster_remarks, promoted_to_class_id 
                   FROM student_reports 
                   WHERE user_id = ? AND class_id = ? AND term_id = ?";
    $report_stmt = $conn->prepare($report_sql);
    if (!$report_stmt) {
        error_log("Failed to prepare existing report query: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to load existing report.";
        header("Location: /teacher/submit_grades.php?class_id=$class_id");
        exit();
    }
    $report_stmt->bind_param("iii", $selected_student_id, $class_id, $class['term_id']);
    $report_stmt->execute();
    $existing_report = $report_stmt->get_result()->fetch_assoc();
    $report_stmt->close();
}

// Fetch historical data
$historical_data = [];
if ($selected_student_id) {
    $history_sql = "SELECT g.subject_id, s.subject_name, g.mid_term_exam, g.end_term_exam,
                    sr.attendance, sr.conduct, sr.attitude, sr.interest, sr.reopening_date,
                    sr.teacher_remarks, sr.headmaster_remarks, sr.promoted_to_class_id, c.class_name AS promoted_class_name,
                    ac.id AS term_id, ac.term_name, ac.year, COALESCE(g.created_at, sr.created_at) AS created_at
                    FROM academic_calendar ac
                    LEFT JOIN grades g ON g.term_id = ac.id AND g.user_id = ? AND g.class_id = ?
                    LEFT JOIN subjects s ON g.subject_id = s.id
                    LEFT JOIN student_reports sr ON sr.term_id = ac.id AND sr.user_id = ? AND sr.class_id = ?
                    LEFT JOIN classes c ON sr.promoted_to_class_id = c.id
                    WHERE ac.start_date <= CURDATE() AND (g.user_id IS NOT NULL OR sr.user_id IS NOT NULL)
                    ORDER BY ac.year DESC, ac.start_date DESC, s.subject_name";
    $history_stmt = $conn->prepare($history_sql);
    if (!$history_stmt) {
        error_log("Failed to prepare historical data query: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to load historical data.";
        header("Location: /teacher/submit_grades.php?class_id=$class_id");
        exit();
    }
    $history_stmt->bind_param("iiii", $selected_student_id, $class_id, $selected_student_id, $class_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();
    while ($row = $history_result->fetch_assoc()) {
        $term_key = $row['term_name'] . ' - ' . $row['year'];
        if (!isset($historical_data[$term_key])) {
            $historical_data[$term_key] = [
                'term_id' => $row['term_id'],
                'term_name' => $row['term_name'],
                'year' => $row['year'],
                'created_at' => $row['created_at'],
                'grades' => [],
                'report' => []
            ];
        }
        if ($row['subject_id']) {
            $historical_data[$term_key]['grades'][] = [
                'subject_name' => $row['subject_name'],
                'mid_term_exam' => $row['mid_term_exam'],
                'end_term_exam' => $row['end_term_exam']
            ];
        }
        if ($row['attendance'] !== null || $row['conduct'] || $row['attitude'] || $row['interest'] || 
            $row['reopening_date'] || $row['teacher_remarks'] || $row['headmaster_remarks'] || $row['promoted_to_class_id']) {
            $historical_data[$term_key]['report'] = [
                'attendance' => $row['attendance'],
                'conduct' => $row['conduct'],
                'attitude' => $row['attitude'],
                'interest' => $row['interest'],
                'reopening_date' => $row['reopening_date'],
                'teacher_remarks' => $row['teacher_remarks'],
                'headmaster_remarks' => $row['headmaster_remarks'],
                'promoted_class_name' => $row['promoted_class_name'],
                'promoted_to_class_id' => $row['promoted_to_class_id']
            ];
        }
    }
    $history_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Submit grades and reports for students in a class at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher, submit grades, student reports, promotion">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Submit Grades & Reports - QueensPa School">
    <meta property="og:description" content="Teacher interface to submit grades and reports for students in a class.">
    <meta property="og-type" content="website">
    <meta property="og-url" content="https://qpmsedu.com/teacher/submit_grades.php">
    <title>Submit Grades & Reports - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWUF2bA" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --hover-color: #e03e00;
            --secondary-color: #009879;
            --secondary-hover: #007a5e;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #fff3e0);
            color: var(--text-color);
            font-family: 'Helvetica', sans-serif;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
            max-width: 900px;
            margin: 0 auto;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 6px;
            color: white;
            transition: background-color 0.3s ease;
            font-size: 0.875rem;
            padding: 8px 16px;
        }
        .btn-primary:hover {
            background-color: var(--hover-color);
            border-color: var(--hover-color);
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            border-radius: 6px;
            color: white;
            transition: background-color 0.3s ease;
            font-size: 0.875rem;
        }
        .btn-secondary:hover {
            background-color: var(--secondary-hover);
            border-color: var(--secondary-hover);
        }
        .btn-danger {
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .form-select, .form-control {
            border-radius: 6px;
            border: 1px solid var(--primary-color);
            color: var(--text-color);
            font-size: 0.875rem;
            padding: 8px;
            box-shadow: 0 0 0 1px rgba(60, 66, 87, 0.16);
            transition: box-shadow 0.2s ease;
        }
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.25);
            outline: none;
        }
        .styled-table {
            border-collapse: collapse;
            width: 100%;
            font-size: 0.875rem;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.15);
            margin: 20px 0;
        }
        .styled-table thead tr {
            background-color: var(--secondary-color);
            color: white;
            text-align: left;
        }
        .styled-table th, .styled-table td {
            padding: 12px 15px;
            vertical-align: middle;
        }
        .styled-table tbody tr {
            border-bottom: 1px solid #ddd;
        }
        .styled-table tbody tr:nth-of-type(even) {
            background-color: #f3f3f3;
        }
        .styled-table tbody tr:hover {
            background-color: #ffe5cc;
        }
        .grade-input {
            width: 80px;
            text-align: center;
        }
        .profile-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        .quick-links .btn {
            min-width: 150px;
            margin: 5px;
            font-size: 0.875rem;
        }
        .alert {
            border-radius: 6px;
            border-color: var(--primary-color);
            background-color: #fff3e0;
            color: var(--text-color);
        }
        fieldset {
            border: 1px solid var(--primary-color);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        legend {
            background-color: var(--primary-color);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 1rem;
        }
        .report-field {
            margin-bottom: 15px;
        }
        .report-field label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        @media (max-width: 767.98px) {
            .quick-links {
                flex-direction: column;
                align-items: stretch;
            }
            .quick-links .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            .styled-table {
                font-size: 0.75rem;
            }
            .grade-input {
                width: 60px;
            }
            .card {
                margin: 10px;
            }
            .report-field label {
                width: 100%;
                display: block;
            }
        }
    </style>
</head>
<body>
    <?php
    try {
        include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    } catch (Exception $e) {
        error_log("Failed to include teacher_header.php: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>
    <div class="content" id="content">
        <main class="container my-4">
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-warning animate__animated animate__fadeIn" id="error_alert"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="alert alert-success animate__animated animate__fadeIn" id="success_alert"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <section class="card mb-4 animate__animated animate__fadeInDown">
                <div class="card-header d-flex align-items-center">
                    <img src="/Uploads/profiles/default.png" alt="Teacher Profile Picture" class="profile-img me-3">
                    <h3 class="card-title mb-0">Submit Grades & Reports: <?php echo htmlspecialchars($class['class_name']); ?></h3>
                    <a href="edit_profile.php?id=<?php echo $teacher_id; ?>" class="btn btn-primary btn-sm ms-auto" data-bs-toggle="tooltip" title="Edit your profile"><i class="fas fa-edit"></i> Edit Profile</a>
                </div>
            </section>
            <section class="card mb-4 animate__animated animate__fadeInDown">
                <div class="card-header">
                    <h3 class="card-title mb-0">Select Student</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($students)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No students enrolled in <?php echo htmlspecialchars($class['class_name']); ?> for academic year <?php echo htmlspecialchars($current_year); ?>.
                        </div>
                    <?php else: ?>
                        <form method="GET" action="" class="row g-3 align-items-center">
                            <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($class_id); ?>">
                            <div class="col-auto">
                                <label for="student_id" class="form-label">Student:</label>
                            </div>
                            <div class="col-auto">
                                <select name="student_id" id="student_id" class="form-select" onchange="this.form.submit()">
                                    <option value="">Select a student</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo htmlspecialchars($student['id']); ?>" <?php echo $selected_student_id === $student['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($student['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <a href="view_classes.php" class="btn btn-primary" data-bs-toggle="tooltip" title="Return to class list"><i class="fas fa-arrow-left"></i> Back to Classes</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <?php if ($selected_student_id && !empty($subjects) && $class['term_id'] && !$edit_mode): ?>
                <section class="card mb-4 animate__animated animate__zoomIn">
                    <div class="card-header">
                        <img src="<?php echo $student_profile_picture; ?>" alt="Student Profile Picture" class="profile-img me-3">
                        <h3 class="card-title mb-0">Enter Grades & Report for <?php echo htmlspecialchars($students[array_search($selected_student_id, array_column($students, 'id'))]['name']); ?> in <?php echo htmlspecialchars($class['class_name']); ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="gradeReportForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="submit_grades">
                            <fieldset>
                                <legend>Grade Entry</legend>
                                <div class="mb-3">
                                    <label for="exam_id" class="form-label">Select Academic Term:</label>
                                    <select name="exam_id" id="exam_id" class="form-select" required>
                                        <option value="">Select a Term</option>
                                        <?php foreach ($exams as $exam): ?>
                                            <option value="<?php echo htmlspecialchars($exam['id']); ?>" <?php echo $class['term_id'] == $exam['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($exam['term_name'] . ' - ' . $exam['year']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="styled-table-container">
                                    <table class="styled-table" id="gradesTable">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th data-bs-toggle="tooltip" title="Max score: 100">Mid-Term (100%)</th>
                                                <th data-bs-toggle="tooltip" title="Max score: 100">End-Term (100%)</th>
                                                <th>Total (100%)</th>
                                                <th>Grade</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($subjects as $subject): ?>
                                                <?php $existing = isset($existing_grades[$subject['id']]) ? $existing_grades[$subject['id']] : []; ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                    <td>
                                                        <input type="number" name="mid_term[<?php echo $subject['id']; ?>]" class="form-control grade-input mid-term" min="0" max="100" step="0.01" value="<?php echo isset($existing['mid_term_exam']) ? htmlspecialchars($existing['mid_term_exam']) : ''; ?>" placeholder="0.00" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" name="end_term[<?php echo $subject['id']; ?>]" class="form-control grade-input end-term" min="0" max="100" step="0.01" value="<?php echo isset($existing['end_term_exam']) ? htmlspecialchars($existing['end_term_exam']) : ''; ?>" placeholder="0.00" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control grade-input total" value="" placeholder="0.00" readonly tabindex="-1">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control grade grade-letter" value="" placeholder="-" readonly tabindex="-1">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control grade grade-remarks" value="" placeholder="-" readonly tabindex="-1">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </fieldset>
                            <fieldset>
                                <legend>Student Report</legend>
                                <div class="report-field">
                                    <label for="academic_period" class="form-label">Academic Period:</label>
                                    <input type="text" id="academic_period" class="form-control" value="<?php echo htmlspecialchars($current_year . ' ' . $class['term_name']); ?>" readonly>
                                </div>
                                <div class="report-field">
                                    <label for="attendance" class="form-label">Attendance (days):</label>
                                    <input type="number" name="attendance" id="attendance" class="form-control" min="0" value="<?php echo isset($existing_report['attendance']) ? htmlspecialchars($existing_report['attendance']) : ''; ?>" placeholder="0">
                                </div>
                                <div class="report-field">
                                    <label for="conduct" class="form-label">Conduct:</label>
                                    <textarea name="conduct" id="conduct" class="form-control" rows="3" placeholder="Enter conduct comment"><?php echo isset($existing_report['conduct']) ? htmlspecialchars($existing_report['conduct']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="attitude" class="form-label">Attitude:</label>
                                    <textarea name="attitude" id="attitude" class="form-control" rows="3" placeholder="Enter attitude comment"><?php echo isset($existing_report['attitude']) ? htmlspecialchars($existing_report['attitude']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="interest" class="form-label">Interest:</label>
                                    <textarea name="interest" id="interest" class="form-control" rows="3" placeholder="Enter interest comment"><?php echo isset($existing_report['interest']) ? htmlspecialchars($existing_report['interest']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="reopening_date" class="form-label">Reopening Date:</label>
                                    <input type="date" name="reopening_date" id="reopening_date" class="form-control" value="<?php echo isset($existing_report['reopening_date']) ? htmlspecialchars($existing_report['reopening_date']) : ''; ?>">
                                </div>
                                <div class="report-field">
                                    <label for="teacher_remarks" class="form-label">Teacher Remarks:</label>
                                    <textarea name="teacher_remarks" id="teacher_remarks" class="form-control" rows="3" placeholder="Enter teacher remarks"><?php echo isset($existing_report['teacher_remarks']) ? htmlspecialchars($existing_report['teacher_remarks']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="headmaster_remarks" class="form-label">Headmaster Remarks:</label>
                                    <textarea name="headmaster_remarks" id="headmaster_remarks" class="form-control" rows="3" placeholder="Enter headmaster remarks"><?php echo isset($existing_report['headmaster_remarks']) ? htmlspecialchars($existing_report['headmaster_remarks']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="promoted_to_class_id" class="form-label">Promoted to:</label>
                                    <select name="promoted_to_class_id" id="promoted_to_class_id" class="form-select">
                                        <option value="">Select a class</option>
                                        <?php foreach ($all_classes as $promo_class): ?>
                                            <option value="<?php echo htmlspecialchars($promo_class['id']); ?>" <?php echo isset($existing_report['promoted_to_class_id']) && $existing_report['promoted_to_class_id'] == $promo_class['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($promo_class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </fieldset>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary" data-bs-toggle="tooltip" title="Save grades and report for this student"><i class="fas fa-save"></i> Submit Grades & Report</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($edit_mode && $selected_student_id && !empty($subjects)): ?>
                <section class="card mb-4 animate__animated animate__zoomIn">
                    <div class="card-header">
                        <img src="<?php echo $student_profile_picture; ?>" alt="Student Profile Picture" class="profile-img me-3">
                        <h3 class="card-title mb-0">Edit Grades & Report for <?php echo htmlspecialchars($students[array_search($selected_student_id, array_column($students, 'id'))]['name']); ?> in <?php echo htmlspecialchars($class['class_name']); ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="editGradeReportForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_record">
                            <input type="hidden" name="term_id" value="<?php echo htmlspecialchars($edit_term_id); ?>">
                            <fieldset>
                                <legend>Edit Grade Entry</legend>
                                <div class="mb-3">
                                    <label for="exam_id" class="form-label">Exam/Term:</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($historical_data[array_search($edit_term_id, array_column($historical_data, 'term_id'))]['term_name'] . ' - ' . $historical_data[array_search($edit_term_id, array_column($historical_data, 'term_id'))]['year']); ?>" readonly>
                                </div>
                                <div class="styled-table-container">
                                    <table class="styled-table" id="editGradesTable">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th data-bs-toggle="tooltip" title="Max score: 100">Mid-Term (100%)</th>
                                                <th data-bs-toggle="tooltip" title="Max score: 100">End-Term (100%)</th>
                                                <th>Total (100%)</th>
                                                <th>Grade</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($subjects as $subject): ?>
                                                <?php $edit = isset($edit_grades[$subject['id']]) ? $edit_grades[$subject['id']] : []; ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                    <td>
                                                        <input type="number" name="mid_term[<?php echo $subject['id']; ?>]" class="form-control grade-input mid-term" min="0" max="100" step="0.01" value="<?php echo isset($edit['mid_term_exam']) ? htmlspecialchars($edit['mid_term_exam']) : ''; ?>" placeholder="0.00" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" name="end_term[<?php echo $subject['id']; ?>]" class="form-control grade-input end-term" min="0" max="100" step="0.01" value="<?php echo isset($edit['end_term_exam']) ? htmlspecialchars($edit['end_term_exam']) : ''; ?>" placeholder="0.00" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control grade-input total" value="" placeholder="0.00" readonly tabindex="-1">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control grade grade-letter" value="" placeholder="-" readonly tabindex="-1">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control grade grade-remarks" value="" placeholder="-" readonly tabindex="-1">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </fieldset>
                            <fieldset>
                                <legend>Edit Student Report</legend>
                                <div class="report-field">
                                    <label for="edit_academic_period" class="form-label">Academic Period:</label>
                                    <input type="text" id="edit_academic_period" class="form-control" value="<?php echo htmlspecialchars($current_year . ' ' . $historical_data[array_search($edit_term_id, array_column($historical_data, 'term_id'))]['term_name']); ?>" readonly>
                                </div>
                                <div class="report-field">
                                    <label for="edit_attendance" class="form-label">Attendance (days):</label>
                                    <input type="number" name="attendance" id="edit_attendance" class="form-control" min="0" value="<?php echo isset($edit_report['attendance']) ? htmlspecialchars($edit_report['attendance']) : ''; ?>" placeholder="0">
                                </div>
                                <div class="report-field">
                                    <label for="edit_conduct" class="form-label">Conduct:</label>
                                    <textarea name="conduct" id="edit_conduct" class="form-control" rows="3" placeholder="Enter conduct comment"><?php echo isset($edit_report['conduct']) ? htmlspecialchars($edit_report['conduct']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="edit_attitude" class="form-label">Attitude:</label>
                                    <textarea name="attitude" id="edit_attitude" class="form-control" rows="3" placeholder="Enter attitude comment"><?php echo isset($edit_report['attitude']) ? htmlspecialchars($edit_report['attitude']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="edit_interest" class="form-label">Interest:</label>
                                    <textarea name="interest" id="edit_interest" class="form-control" rows="3" placeholder="Enter interest comment"><?php echo isset($edit_report['interest']) ? htmlspecialchars($edit_report['interest']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="edit_reopening_date" class="form-label">Reopening Date:</label>
                                    <input type="date" name="reopening_date" id="edit_reopening_date" class="form-control" value="<?php echo isset($edit_report['reopening_date']) ? htmlspecialchars($edit_report['reopening_date']) : ''; ?>">
                                </div>
                                <div class="report-field">
                                    <label for="edit_teacher_remarks" class="form-label">Teacher Remarks:</label>
                                    <textarea name="teacher_remarks" id="edit_teacher_remarks" class="form-control" rows="3" placeholder="Enter teacher remarks"><?php echo isset($edit_report['teacher_remarks']) ? htmlspecialchars($edit_report['teacher_remarks']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="edit_headmaster_remarks" class="form-label">Headmaster Remarks:</label>
                                    <textarea name="headmaster_remarks" id="edit_headmaster_remarks" class="form-control" rows="3" placeholder="Enter headmaster remarks"><?php echo isset($edit_report['headmaster_remarks']) ? htmlspecialchars($edit_report['headmaster_remarks']) : ''; ?></textarea>
                                </div>
                                <div class="report-field">
                                    <label for="edit_promoted_to_class_id" class="form-label">Promoted to:</label>
                                    <select name="promoted_to_class_id" id="edit_promoted_to_class_id" class="form-select">
                                        <option value="">Select a class</option>
                                        <?php foreach ($all_classes as $promo_class): ?>
                                            <option value="<?php echo htmlspecialchars($promo_class['id']); ?>" <?php echo isset($edit_report['promoted_to_class_id']) && $edit_report['promoted_to_class_id'] == $promo_class['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($promo_class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </fieldset>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary" data-bs-toggle="tooltip" title="Update grades and report for this student"><i class="fas fa-save"></i> Update</button>
                                <a href="/teacher/submit_grades.php?class_id=<?php echo $class_id; ?>&student_id=<?php echo $selected_student_id; ?>" class="btn btn-secondary" data-bs-toggle="tooltip" title="Cancel editing"><i class="fas fa-times"></i> Cancel</a>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($selected_student_id && !empty($historical_data)): ?>
                <section class="card mb-4 animate__animated animate__fadeInUp">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Historical Grades & Reports</h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($historical_data as $term_key => $data): ?>
                            <div class="mb-4">
                                <h4><?php echo htmlspecialchars($term_key); ?> (Created: <?php echo htmlspecialchars(date('F j, Y', strtotime($data['created_at']))); ?>)</h4>
                                <?php if (!empty($data['grades'])): ?>
                                    <div class="styled-table-container">
                                        <table class="styled-table">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <th>Mid-Term</th>
                                                    <th>End-Term</th>
                                                    <th>Total</th>
                                                    <th>Grade</th>
                                                    <th>Remarks</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($data['grades'] as $grade): ?>
                                                    <?php
                                                        $total = null;
                                                        if ($grade['mid_term_exam'] !== null && $grade['end_term_exam'] !== null) {
                                                            $total = ($grade['mid_term_exam'] + $grade['end_term_exam']) / 2;
                                                        }
                                                        if ($total !== null) {
                                                            if ($total >= 70) {
                                                                $grade_letter = 'A'; $remarks = 'Excellent';
                                                            } elseif ($total >= 60) {
                                                                $grade_letter = 'B'; $remarks = 'Very Good';
                                                            } elseif ($total >= 50) {
                                                                $grade_letter = 'C'; $remarks = 'Good';
                                                            } elseif ($total >= 45) {
                                                                $grade_letter = 'D'; $remarks = 'Fair';
                                                            } elseif ($total >= 40) {
                                                                $grade_letter = 'E'; $remarks = 'Pass';
                                                            } else {
                                                                $grade_letter = 'F'; $remarks = 'Fail';
                                                            }
                                                        } else {
                                                            $grade_letter = $remarks = '-';
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                                        <td><?php echo $grade['mid_term_exam'] !== null ? htmlspecialchars(number_format($grade['mid_term_exam'], 2)) : '-'; ?></td>
                                                        <td><?php echo $grade['end_term_exam'] !== null ? htmlspecialchars(number_format($grade['end_term_exam'], 2)) : '-'; ?></td>
                                                        <td><?php echo $total !== null ? htmlspecialchars(number_format($total, 2)) : '-'; ?></td>
                                                        <td><?php echo $grade_letter; ?></td>
                                                        <td><?php echo $remarks; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($data['report'])): ?>
                                    <div class="report-field">
                                        <label>Attendance:</label>
                                        <span><?php echo $data['report']['attendance'] !== null ? htmlspecialchars($data['report']['attendance']) : '-'; ?> days</span>
                                    </div>
                                    <div class="report-field">
                                        <label>Conduct:</label>
                                        <span><?php echo $data['report']['conduct'] ? htmlspecialchars($data['report']['conduct']) : '-'; ?></span>
                                    </div>
                                    <div class="report-field">
                                        <label>Attitude:</label>
                                        <span><?php echo $data['report']['attitude'] ? htmlspecialchars($data['report']['attitude']) : '-'; ?></span>
                                    </div>
                                    <div class="report-field">
                                        <label>Interest:</label>
                                        <span><?php echo $data['report']['interest'] ? htmlspecialchars($data['report']['interest']) : '-'; ?></span>
                                    </div>
                                    <div class="report-field">
                                        <label>Reopening Date:</label>
                                        <span><?php echo $data['report']['reopening_date'] ? htmlspecialchars($data['report']['reopening_date']) : '-'; ?></span>
                                    </div>
                                    <div class="report-field">
                                        <label>Teacher Remarks:</label>
                                        <span><?php echo $data['report']['teacher_remarks'] ? htmlspecialchars($data['report']['teacher_remarks']) : '-'; ?></span>
                                    </div>
                                    <div class="report-field">
                                        <label>Headmaster Remarks:</label>
                                        <span><?php echo $data['report']['headmaster_remarks'] ? htmlspecialchars($data['report']['headmaster_remarks']) : '-'; ?></span>
                                    </div>
                                    <div class="report-field">
                                        <label>Promoted to:</label>
                                        <span><?php echo $data['report']['promoted_class_name'] ? htmlspecialchars($data['report']['promoted_class_name']) : '-'; ?></span>
                                    </div>
                                <?php endif; ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="edit_record">
                                    <input type="hidden" name="term_id" value="<?php echo htmlspecialchars($data['term_id']); ?>">
                                    <button type="submit" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" title="Edit this record"><i class="fas fa-edit"></i> Edit</button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="delete_record">
                                    <input type="hidden" name="term_id" value="<?php echo htmlspecialchars($data['term_id']); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" data-bs-toggle="tooltip" title="Delete this record"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6Rzsynlock9gu9j2V5Ldk3XwO8vC8O8vC8O8vC8O8vC8O8vC8O8vC8O8vC8O8v" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltips.forEach(tooltip => new bootstrap.Tooltip(tooltip));
            setTimeout(() => {
                const alerts = document.querySelectorAll('#error_alert, #success_alert');
                alerts.forEach(alert => alert.classList.add('animate__fadeOut'));
            }, 5000);

            function calcGrade(total) {
                if (total >= 70) return ['A', 'Excellent'];
                if (total >= 60) return ['B', 'Very Good'];
                if (total >= 50) return ['C', 'Good'];
                if (total >= 45) return ['D', 'Fair'];
                if (total >= 40) return ['E', 'Pass'];
                return ['F', 'Fail'];
            }

            function updateGradeRows(tableId = 'gradesTable') {
                document.querySelectorAll(`#${tableId} tbody tr`).forEach(row => {
                    const mid = parseFloat(row.querySelector('.mid-term').value) || 0;
                    const end = parseFloat(row.querySelector('.end-term').value) || 0;
                    const total = +((mid + end) / 2).toFixed(2); // average, out of 100
                    row.querySelector('.total').value = total;
                    const [grade, remarks] = calcGrade(total);
                    row.querySelector('.grade-letter').value = grade;
                    row.querySelector('.grade-remarks').value = remarks;
                });
            }

            document.querySelectorAll('.mid-term, .end-term').forEach(input => {
                input.addEventListener('input', () => updateGradeRows('gradesTable'));
            });
            updateGradeRows('gradesTable');

            // Also for edit mode
            document.querySelectorAll('#editGradesTable .mid-term, #editGradesTable .end-term').forEach(input => {
                input.addEventListener('input', () => updateGradeRows('editGradesTable'));
            });
            updateGradeRows('editGradesTable');
        });
    </script>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>