<?php
/**
 * /api/submit_assignment.php
 * Handles assignment submissions from students
 * Database: qpmceduc_db
 */
ob_start();
session_start();

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__, 2) . '/error.log');
ini_set('display_errors', 0);

// Set JSON response headers
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in as a student to submit assignments.']);
    exit();
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit();
}

// Validate action
$action = $_POST['action'] ?? '';
if ($action !== 'submit_assignment') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
    exit();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'System configuration error.']);
    exit();
}
require_once($db_path);

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: 'Unknown error'));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed. Please try again later.']);
    exit();
}

// Get student ID
$student_id = (int)$_SESSION['user_id'];

// Get and validate input
$assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
$submission_text = trim($_POST['submission_text'] ?? '');
$late_submission_reason = trim($_POST['late_submission_reason'] ?? '');

if (!$assignment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assignment ID.']);
    exit();
}

// Validate the assignment exists and is published
$validate_sql = "SELECT a.id, a.title, a.class_id, a.subject_id, a.group_id, a.max_points,
                        a.is_group_assignment, a.due_date, a.status,
                        sc.user_id as class_student,
                        gm.user_id as group_member
                 FROM assignments a
                 LEFT JOIN student_classes sc ON a.class_id = sc.class_id AND sc.user_id = ?
                 LEFT JOIN group_members gm ON a.group_id = gm.group_id AND gm.user_id = ?
                 WHERE a.id = ? AND a.status = 'published'";
$validate_stmt = $conn->prepare($validate_sql);
if (!$validate_stmt) {
    error_log("Validate prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit();
}
$validate_stmt->bind_param("iii", $student_id, $student_id, $assignment_id);
$validate_stmt->execute();
$assignment = $validate_stmt->get_result()->fetch_assoc();
$validate_stmt->close();

if (!$assignment) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assignment not found or not available for submission.']);
    exit();
}

// Check if student has access (either via class or group)
if (!$assignment['class_student'] && !$assignment['group_member']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have access to this assignment.']);
    exit();
}

// Check if already submitted
$check_sql = "SELECT id, status, is_graded FROM assignment_submissions 
              WHERE assignment_id = ? AND user_id = ?";
$check_stmt = $conn->prepare($check_sql);
if (!$check_stmt) {
    error_log("Check prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit();
}
$check_stmt->bind_param("ii", $assignment_id, $student_id);
$check_stmt->execute();
$existing = $check_stmt->get_result()->fetch_assoc();
$check_stmt->close();

if ($existing) {
    if ($existing['is_graded'] == 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This assignment has already been graded and cannot be resubmitted.']);
        exit();
    }
    $is_resubmission = true;
} else {
    $is_resubmission = false;
}

// Handle file uploads
$uploaded_files = [];
$upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/submissions/' . $student_id . '/';
$max_file_size = 10 * 1024 * 1024; // 10MB per file
$allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'pptx', 'xlsx'];

if (isset($_FILES['submission_files']) && is_array($_FILES['submission_files']['name'])) {
    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            error_log("Failed to create upload directory: $upload_dir");
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to create upload directory.']);
            exit();
        }
    }
    
    $file_count = count($_FILES['submission_files']['name']);
    
    for ($i = 0; $i < $file_count; $i++) {
        // Skip empty file inputs
        if ($_FILES['submission_files']['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        
        // Check for upload errors
        if ($_FILES['submission_files']['error'][$i] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            ];
            $error_msg = $error_messages[$_FILES['submission_files']['error'][$i]] ?? 'Unknown upload error.';
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "File upload error: " . $error_msg]);
            exit();
        }
        
        $file_name = $_FILES['submission_files']['name'][$i];
        $file_size = $_FILES['submission_files']['size'][$i];
        $file_tmp = $_FILES['submission_files']['tmp_name'][$i];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Validate file extension
        if (!in_array($file_ext, $allowed_types)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "File type '{$file_ext}' is not allowed. Accepted types: " . implode(', ', $allowed_types)]);
            exit();
        }
        
        // Validate file size
        if ($file_size > $max_file_size) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "File '{$file_name}' exceeds the 10MB size limit."]);
            exit();
        }
        
        // Generate unique filename
        $unique_name = time() . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_name);
        $file_path = $upload_dir . $unique_name;
        
        // Move file
        if (!move_uploaded_file($file_tmp, $file_path)) {
            error_log("Failed to move uploaded file: $file_tmp to $file_path");
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => "Failed to save file '{$file_name}'. Please try again."]);
            exit();
        }
        
        // Store relative path for database
        $uploaded_files[] = '/uploads/submissions/' . $student_id . '/' . $unique_name;
    }
}

// Check if we have at least something to submit
if (empty($submission_text) && empty($uploaded_files)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please provide either text answers or upload files for your submission.']);
    exit();
}

// Determine submission status
$due_date = $assignment['due_date'];
$is_late = false;
if ($due_date && strtotime($due_date) < time()) {
    $is_late = true;
}
$submission_status = $is_late ? 'late' : 'submitted';

// Begin transaction
$conn->begin_transaction();

try {
    $file_paths_string = !empty($uploaded_files) ? implode(',', $uploaded_files) : null;
    $current_time = date('Y-m-d H:i:s');
    $group_id = $assignment['group_id'] ?? null;
    
    // Also store the answers in the `answers` field as JSON
    $answers_data = [];
    if (!empty($submission_text)) {
        // Try to parse questions from the assignment
        $questions = json_decode($assignment['questions'] ?? '[]', true);
        if (!empty($questions) && is_array($questions)) {
            foreach ($questions as $index => $question) {
                $answers_data[] = [
                    'question_text' => $question['question_text'] ?? '',
                    'type' => $question['type'] ?? 'text',
                    'answer' => $submission_text // Store the full submission text for each question
                ];
            }
        }
    }
    $answers_json = !empty($answers_data) ? json_encode($answers_data) : null;
    
    if ($is_resubmission) {
        // Update existing submission
        $update_sql = "UPDATE assignment_submissions 
                       SET answers = ?,
                           submission_text = ?,
                           file_paths = ?,
                           submission_date = ?,
                           late_submission_reason = ?,
                           status = ?,
                           is_graded = 0,
                           score = NULL,
                           feedback = NULL,
                           graded_by = NULL,
                           graded_at = NULL,
                           updated_at = NOW()
                       WHERE assignment_id = ? AND user_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed (update): " . $conn->error);
        }
        $update_stmt->bind_param("ssssssii", 
            $answers_json,
            $submission_text, 
            $file_paths_string, 
            $current_time,
            $late_submission_reason,
            $submission_status,
            $assignment_id,
            $student_id
        );
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new submission
        $insert_sql = "INSERT INTO assignment_submissions 
                       (assignment_id, user_id, group_id, answers, submission_text, file_paths, 
                        submission_date, late_submission_reason, status, is_graded, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            throw new Exception("Prepare failed (insert): " . $conn->error);
        }
        $insert_stmt->bind_param("iiissssss", 
            $assignment_id,
            $student_id,
            $group_id,
            $answers_json,
            $submission_text,
            $file_paths_string,
            $current_time,
            $late_submission_reason,
            $submission_status
        );
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Log the activity
    if (function_exists('logActivity')) {
        $action = $is_resubmission ? "Resubmitted" : "Submitted";
        $desc = "Assignment #$assignment_id: " . htmlspecialchars($assignment['title']);
        logActivity($conn, $student_id, $action, $desc);
    }
    
    // Commit transaction
    $conn->commit();
    
    // Success response
    $message = $is_late ? 
        'Assignment submitted successfully (marked as late). Your teacher will be notified.' : 
        'Assignment submitted successfully! You can now use AI Grading for instant feedback.';
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'is_late' => $is_late,
        'is_resubmission' => $is_resubmission,
        'files_count' => count($uploaded_files),
        'submission_date' => $current_time
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    // Clean up uploaded files
    foreach ($uploaded_files as $file) {
        $full_path = $_SERVER['DOCUMENT_ROOT'] . $file;
        if (file_exists($full_path)) {
            unlink($full_path);
        }
    }
    
    error_log("Submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save submission. Please try again. Error: ' . $e->getMessage()]);
}

$conn->close();
ob_end_flush();