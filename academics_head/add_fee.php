<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/helpers.php');

// Check database connection
if (!$conn || $conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . ($conn->connect_error ?? 'Connection is null')]));
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch required data from database
$data = [];

// Fetch academic years from academic_calendar table
$academic_years_result = $conn->query("SELECT DISTINCT year as year_label FROM academic_calendar ORDER BY year DESC");
if ($academic_years_result) {
    while ($row = $academic_years_result->fetch_assoc()) {
        $data['academic_years'][$row['year_label']] = $row;
    }
    $academic_years_result->free();
}

// Fetch terms from academic_calendar table
$terms_result = $conn->query("SELECT DISTINCT term_name FROM academic_calendar ORDER BY start_date");
if ($terms_result) {
    while ($row = $terms_result->fetch_assoc()) {
        $data['terms'][] = $row['term_name'];
    }
    $terms_result->free();
}

// Fetch departments
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if ($departments_result) {
    while ($row = $departments_result->fetch_assoc()) {
        $data['departments'][$row['id']] = $row['name'];
    }
    $departments_result->free();
}

// Fetch classes with department info
$classes_result = $conn->query("SELECT c.id, c.class_name, c.department_id, d.name as department_name 
                               FROM classes c 
                               LEFT JOIN departments d ON c.department_id = d.id 
                               WHERE c.department_id IS NOT NULL 
                               ORDER BY c.class_name ASC");
if ($classes_result) {
    while ($row = $classes_result->fetch_assoc()) {
        $data['classes'][$row['id']] = $row;
    }
    $classes_result->free();
}

// Fetch existing fee types for suggestions
$fee_types_result = $conn->query("SELECT DISTINCT fee_type FROM fees ORDER BY fee_type");
$existing_fee_types = [];
if ($fee_types_result) {
    while ($row = $fee_types_result->fetch_assoc()) {
        $existing_fee_types[] = $row['fee_type'];
    }
    $fee_types_result->free();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false];
    
    switch ($_POST['action']) {
        case 'get_students_by_class':
            $class_id = (int)($_POST['class_id'] ?? 0);
            $academic_year = trim($_POST['academic_year'] ?? '');
            
            $students = [];
            
            if ($class_id > 0 && !empty($academic_year)) {
                $query = "SELECT DISTINCT u.id, u.name, u.phone, u.email, c.class_name, c.id AS class_id
                          FROM users u 
                          JOIN student_classes sc ON u.id = sc.user_id 
                          JOIN classes c ON sc.class_id = c.id 
                          WHERE u.role = 'student' 
                          AND u.status = 'active'
                          AND sc.class_id = ?
                          AND sc.academic_year = ?
                          ORDER BY u.name ASC";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("is", $class_id, $academic_year);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $students[] = $row;
                }
                $stmt->close();
            }
            
            $response = ['success' => true, 'students' => $students];
            break;
            
        case 'filter_students':
            $academic_year = trim($_POST['academic_year'] ?? '');
            $department_id = (int)($_POST['department_id'] ?? 0);
            $class_id = (int)($_POST['class_id'] ?? 0);
            $search = trim($_POST['search'] ?? '');
            
            $filtered_students = [];
            $query = "SELECT DISTINCT u.id, u.name, u.phone, u.email, c.class_name, c.id AS class_id, c.department_id, sc.academic_year 
                      FROM users u 
                      JOIN student_classes sc ON u.id = sc.user_id 
                      JOIN classes c ON sc.class_id = c.id 
                      WHERE u.role = 'student' AND u.status = 'active'";
            
            $params = [];
            $types = "";
            
            if (!empty($academic_year)) {
                $query .= " AND sc.academic_year = ?";
                $types .= "s";
                $params[] = $academic_year;
            }
            
            if ($department_id > 0) {
                $query .= " AND c.department_id = ?";
                $types .= "i";
                $params[] = $department_id;
            }
            
            if ($class_id > 0) {
                $query .= " AND c.id = ?";
                $types .= "i";
                $params[] = $class_id;
            }
            
            if ($search) {
                $query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
                $types .= "ss";
                $search_param = "%$search%";
                $params[] = $search_param;
                $params[] = $search_param;
            }
            
            $query .= " ORDER BY c.class_name, u.name LIMIT 200";
            
            if ($stmt = $conn->prepare($query)) {
                if ($params) {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $filtered_students[] = $row;
                }
                $stmt->close();
                $response = ['success' => true, 'students' => $filtered_students];
            } else {
                $response['error'] = "Failed to prepare query: " . $conn->error;
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// Handle form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_fee'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token. Please try again.";
    } else {
        // Collect and validate form data
        $target_type = trim($_POST['target_type'] ?? '');
        $academic_year = trim($_POST['academic_year'] ?? '');
        $term_name = trim($_POST['term_name'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $fee_type = trim($_POST['fee_type'] ?? '');
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.00;
        $due_date = trim($_POST['due_date'] ?? '');
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        
        // Additional fields based on target type
        $class_id = null;
        $user_id = null;
        $bulk_students = [];
        
        if ($target_type === 'Class') {
            $class_id = (int)($_POST['class_id'] ?? 0);
        } elseif ($target_type === 'Learner') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $user_id = (int)($_POST['user_id'] ?? 0);
        } elseif ($target_type === 'School') {
            // School target - no specific class or student selection
            $class_id = null;
            $user_id = null;
        }
        
        // For bulk operations
        $bulk_mode = ($target_type === 'Bulk Learners');
        if ($bulk_mode) {
            $student_ids = $_POST['bulk_student_ids'] ?? [];
            $student_amounts = $_POST['student_amounts'] ?? [];
            $student_classes = $_POST['student_classes'] ?? [];
            
            foreach ($student_ids as $index => $student_id_str) {
                $student_id = (int)$student_id_str;
                $student_class_id = isset($student_classes[$index]) ? (int)$student_classes[$index] : 0;
                $student_amount = isset($student_amounts[$index]) ? (float)$student_amounts[$index] : $amount;
                
                if ($student_id > 0 && $student_class_id > 0 && $student_amount > 0) {
                    $bulk_students[] = [
                        'user_id' => $student_id,
                        'class_id' => $student_class_id,
                        'amount' => $student_amount
                    ];
                }
            }
        }
        
        // Validation
        $errors = [];
        
        if (empty($target_type) || !in_array($target_type, ['Class', 'Learner', 'School', 'Bulk Learners'])) {
            $errors[] = "Please select a valid target type.";
        }
        
        if (empty($academic_year)) {
            $errors[] = "Please select an academic year.";
        }
        
        if (empty($term_name)) {
            $errors[] = "Please select a term.";
        }
        
        if ($department_id <= 0 || !isset($data['departments'][$department_id])) {
            $errors[] = "Please select a valid department.";
        }
        
        if (empty($fee_type) || strlen($fee_type) > 50) {
            $errors[] = "Fee type is required and must be 50 characters or less.";
        }
        
        if ($amount <= 0) {
            $errors[] = "Amount must be greater than 0.";
        }
        
        if (empty($due_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $due_date)) {
            $errors[] = "Please select a valid due date.";
        }
        
        if (!$bulk_mode) {
            if ($target_type === 'Class' && ($class_id <= 0 || !isset($data['classes'][$class_id]))) {
                $errors[] = "Please select a valid class.";
            }
            
            if ($target_type === 'Learner' && ($user_id <= 0 || $class_id <= 0)) {
                $errors[] = "Please select a valid student and class.";
            }
        } else {
            if (empty($bulk_students)) {
                $errors[] = "Please select at least one student for bulk operation.";
            }
        }
        
        // Validate department enum
        $department_name = $data['departments'][$department_id] ?? '';
        
        if (empty($errors)) {
            $conn->begin_transaction();
            
            try {
                if ($bulk_mode) {
                    // Process bulk students
                    $processed_count = 0;
                    $failed_count = 0;
                    
                    foreach ($bulk_students as $student_data) {
                        $student_user_id = $student_data['user_id'];
                        $student_class_id = $student_data['class_id'];
                        $student_amount = $student_data['amount'];
                        
                        // Check for duplicate fee
                        $dup_query = "SELECT id FROM fees WHERE fee_type = ? AND academic_year = ? AND target_type = 'Learner' AND user_id = ? AND class_id = ?";
                        if ($dup_stmt = $conn->prepare($dup_query)) {
                            $dup_stmt->bind_param("ssii", $fee_type, $academic_year, $student_user_id, $student_class_id);
                            $dup_stmt->execute();
                            $dup_result = $dup_stmt->get_result();
                            
                            if ($dup_result->num_rows === 0) {
                                // Insert fee
                                $insert_query = "INSERT INTO fees (class_id, user_id, department, fee_type, amount, due_date, academic_year, target_type, is_recurring, created_at, updated_at) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Learner', ?, NOW(), NOW())";
                                
                                if ($stmt = $conn->prepare($insert_query)) {
                                    $stmt->bind_param("iissdssi", 
                                        $student_class_id,
                                        $student_user_id,
                                        $department_name,
                                        $fee_type,
                                        $student_amount,
                                        $due_date,
                                        $academic_year,
                                        $is_recurring
                                    );
                                    
                                    if ($stmt->execute()) {
                                        $fee_id = $stmt->insert_id;
                                        
                                        // Insert debt
                                        $debt_query = "INSERT INTO debts (user_id, fee_id, amount_due, academic_year, class_id, created_at, updated_at) 
                                                       VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                                        
                                        if ($debt_stmt = $conn->prepare($debt_query)) {
                                            $debt_stmt->bind_param("iidsi", 
                                                $student_user_id,
                                                $fee_id,
                                                $student_amount,
                                                $academic_year,
                                                $student_class_id
                                            );
                                            $debt_stmt->execute();
                                            $debt_stmt->close();
                                        }
                                        
                                        $processed_count++;
                                    }
                                    $stmt->close();
                                }
                            } else {
                                $failed_count++;
                            }
                            $dup_stmt->close();
                        }
                    }
                    
                    $conn->commit();
                    $success_msg = "Fees added successfully for {$processed_count} students.";
                    if ($failed_count > 0) {
                        $success_msg .= " {$failed_count} students were skipped (duplicate fees).";
                    }
                    
                } else {
                    // Process single fee
                    if ($target_type === 'School') {
                        $class_id = null;
                        $user_id = null;
                    }
                    
                    // Check for duplicate fee
                    $dup_query = "SELECT id FROM fees WHERE fee_type = ? AND academic_year = ? AND target_type = ?";
                    $dup_params = [$fee_type, $academic_year, $target_type];
                    $dup_types = "sss";
                    
                    if ($target_type === 'Class' && $class_id) {
                        $dup_query .= " AND class_id = ? AND user_id IS NULL";
                        $dup_types .= "i";
                        $dup_params[] = $class_id;
                    } elseif ($target_type === 'Learner' && $user_id && $class_id) {
                        $dup_query .= " AND user_id = ? AND class_id = ?";
                        $dup_types .= "ii";
                        $dup_params[] = $user_id;
                        $dup_params[] = $class_id;
                    } elseif ($target_type === 'School') {
                        $dup_query .= " AND class_id IS NULL AND user_id IS NULL";
                    }
                    
                    $dup_stmt = $conn->prepare($dup_query);
                    if ($dup_params) {
                        $dup_stmt->bind_param($dup_types, ...$dup_params);
                    }
                    $dup_stmt->execute();
                    $dup_result = $dup_stmt->get_result();
                    
                    if ($dup_result->num_rows > 0) {
                        throw new Exception("A similar fee already exists for the selected criteria.");
                    }
                    $dup_stmt->close();
                    
                    // Insert fee
                    $insert_query = "INSERT INTO fees (class_id, user_id, department, fee_type, amount, due_date, academic_year, target_type, is_recurring, created_at, updated_at) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    
                    if ($stmt = $conn->prepare($insert_query)) {
                        // Handle null values properly
                        $null_class_id = $class_id ?: null;
                        $null_user_id = $user_id ?: null;
                        
                        $stmt->bind_param("iissdsssi", 
                            $null_class_id,
                            $null_user_id,
                            $department_name,
                            $fee_type,
                            $amount,
                            $due_date,
                            $academic_year,
                            $target_type,
                            $is_recurring
                        );
                        
                        if ($stmt->execute()) {
                            $fee_id = $stmt->insert_id;
                            
                            // Determine which students get the debt
                            $debt_students = [];
                            
                            if ($target_type === 'Class') {
                                $query = "SELECT sc.user_id, sc.class_id 
                                          FROM student_classes sc 
                                          JOIN users u ON sc.user_id = u.id 
                                          WHERE sc.class_id = ? AND sc.academic_year = ? 
                                          AND u.role = 'student' AND u.status = 'active'";
                                if ($student_stmt = $conn->prepare($query)) {
                                    $student_stmt->bind_param("is", $class_id, $academic_year);
                                    $student_stmt->execute();
                                    $student_result = $student_stmt->get_result();
                                    while ($row = $student_result->fetch_assoc()) {
                                        $debt_students[] = $row;
                                    }
                                    $student_stmt->close();
                                }
                            } elseif ($target_type === 'Learner') {
                                $query = "SELECT sc.user_id, sc.class_id 
                                          FROM student_classes sc 
                                          JOIN users u ON sc.user_id = u.id 
                                          WHERE sc.user_id = ? AND sc.class_id = ? AND sc.academic_year = ? 
                                          AND u.role = 'student' AND u.status = 'active'";
                                if ($student_stmt = $conn->prepare($query)) {
                                    $student_stmt->bind_param("iis", $user_id, $class_id, $academic_year);
                                    $student_stmt->execute();
                                    $student_result = $student_stmt->get_result();
                                    if ($row = $student_result->fetch_assoc()) {
                                        $debt_students[] = $row;
                                    }
                                    $student_stmt->close();
                                }
                            } elseif ($target_type === 'School') {
                                $query = "SELECT sc.user_id, sc.class_id 
                                          FROM student_classes sc 
                                          JOIN users u ON sc.user_id = u.id 
                                          JOIN classes c ON sc.class_id = c.id 
                                          WHERE sc.academic_year = ? AND c.department_id = ?
                                          AND u.role = 'student' AND u.status = 'active'";
                                if ($student_stmt = $conn->prepare($query)) {
                                    $student_stmt->bind_param("si", $academic_year, $department_id);
                                    $student_stmt->execute();
                                    $student_result = $student_stmt->get_result();
                                    while ($row = $student_result->fetch_assoc()) {
                                        $debt_students[] = $row;
                                    }
                                    $student_stmt->close();
                                }
                            }
                            
                            // Insert debts
                            if (!empty($debt_students)) {
                                $debt_query = "INSERT INTO debts (user_id, fee_id, amount_due, academic_year, class_id, created_at, updated_at) 
                                               VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                                
                                if ($debt_stmt = $conn->prepare($debt_query)) {
                                    foreach ($debt_students as $student) {
                                        $debt_stmt->bind_param("iidsi", 
                                            $student['user_id'],
                                            $fee_id,
                                            $amount,
                                            $academic_year,
                                            $student['class_id']
                                        );
                                        $debt_stmt->execute();
                                    }
                                    $debt_stmt->close();
                                }
                            }
                            
                            $conn->commit();
                            $debt_count = count($debt_students);
                            $success_msg = "Fee added successfully.";
                            if ($debt_count > 0) {
                                $success_msg .= " {$debt_count} debt(s) created.";
                            }
                        } else {
                            throw new Exception("Failed to insert fee: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        throw new Exception("Failed to prepare fee query: " . $conn->error);
                    }
                }
                
                // Regenerate CSRF token after successful submission
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Failed to add fee: " . $e->getMessage();
            }
        } else {
            $error_msg = implode("<br>", $errors);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Fee/Debt - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #ffd700;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f9fafb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            border: none;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            border: none;
            padding: 1.5rem;
        }
        
        .card-header h3 {
            margin-bottom: 0.25rem;
            font-weight: 700;
        }
        
        .card-header .opacity-75 {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid rgba(255, 98, 0, 0.1);
        }
        
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .required-field::after {
            content: " *";
            color: var(--danger-color);
        }
        
        .form-control, .form-select {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 98, 0, 0.25);
        }
        
        .btn {
            border-radius: 8px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
            background: transparent;
        }
        
        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
            transform: translateY(-2px);
        }
        
        .target-type-info {
            background: rgba(255, 98, 0, 0.05);
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 98, 0, 0.1);
        }
        
        .target-type-info .row > div {
            padding: 0.75rem;
        }
        
        .target-type-info i {
            color: var(--primary-color);
            font-size: 1.25rem;
        }
        
        .bulk-students-table {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        
        .table th {
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            padding: 1rem;
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table .student-row.selected {
            background-color: rgba(255, 98, 0, 0.05);
        }
        
        .student-amount-input {
            width: 100px;
        }
        
        .amount-display {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            background: rgba(255, 98, 0, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            display: inline-block;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 1rem 1.5rem;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
        }
        
        .progress-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3rem;
            position: relative;
        }
        
        .progress-indicator::before {
            content: '';
            position: absolute;
            top: 25px;
            left: 0;
            right: 0;
            height: 3px;
            background: #e9ecef;
            z-index: 1;
        }
        
        .step {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        
        .step-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            border: 3px solid #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-weight: 700;
            font-size: 1.25rem;
            transition: all 0.3s;
        }
        
        .step.active .step-circle {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }
        
        .step.completed .step-circle {
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
            border-color: var(--success-color);
            color: white;
        }
        
        .step-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.95rem;
        }
        
        .step.active .step-label {
            color: var(--primary-color);
        }
        
        .step.completed .step-label {
            color: var(--success-color);
        }
        
        .loading-spinner {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        .validation-summary {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #f5c6cb;
            color: #721c24;
            display: none;
        }
        
        .validation-summary.show {
            display: block;
        }
        
        .validation-summary h5 {
            color: #721c24;
        }
        
        .validation-summary ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }
        
        .sms-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid #dee2e6;
        }
        
        .invalid-feedback {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.875rem;
            color: var(--danger-color);
        }
        
        .is-invalid {
            border-color: var(--danger-color);
        }
        
        .is-invalid:focus {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 1.5rem;
            }
            
            .filter-section {
                padding: 1.5rem;
            }
            
            .progress-indicator {
                flex-wrap: wrap;
            }
            
            .step {
                flex: 0 0 50%;
                margin-bottom: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .step {
                flex: 0 0 100%;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .d-flex.justify-content-between {
                flex-direction: column;
            }
            
            .d-flex.justify-content-between > div {
                width: 100%;
                text-align: center;
            }
            
            .d-flex.justify-content-between .btn {
                margin-right: 0 !important;
                margin-left: 0 !important;
            }
        }
    </style>
</head>
<body>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (file_exists($header_path)) {
        include $header_path;
    } else {
        echo '<nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="/">Queen\'s Paradise Montessori School</a></div></nav>';
    }
    ?>
    
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted" id="loadingMessage">Processing your request...</p>
        </div>
    </div>
    
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Add New Fee/Debt</h3>
                        <p class="mb-0 opacity-75">Add fees for individual learners, classes, or multiple learners at once</p>
                    </div>
                    
                    <div class="card-body">
                        <!-- Progress Indicator -->
                        <div class="progress-indicator">
                            <div class="step active" id="step1">
                                <div class="step-circle">1</div>
                                <div class="step-label">Basic Information</div>
                            </div>
                            <div class="step" id="step2">
                                <div class="step-circle">2</div>
                                <div class="step-label">Target Selection</div>
                            </div>
                            <div class="step" id="step3">
                                <div class="step-circle">3</div>
                                <div class="step-label">Fee Details</div>
                            </div>
                            <div class="step" id="step4">
                                <div class="step-circle">4</div>
                                <div class="step-label">Review & Submit</div>
                            </div>
                        </div>
                        
                        <?php if ($success_msg): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo $success_msg; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error_msg): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php echo $error_msg; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($data['academic_years'])): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No academic years found. Please add academic years first in the Academic Calendar.
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($data['departments'])): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No departments found. Please add departments first.
                            </div>
                        <?php endif; ?>
                        
                        <!-- Validation Summary -->
                        <div class="validation-summary" id="validationSummary">
                            <h5 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following errors:</h5>
                            <ul id="validationErrors"></ul>
                        </div>
                        
                        <form method="POST" class="needs-validation" novalidate id="fee-form" onsubmit="return validateForm()">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="submit_fee" value="1">
                            
                            <!-- Step 1: Basic Information -->
                            <div class="filter-section" id="step1-section">
                                <h5 class="section-title"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="academic_year" class="form-label required-field">Academic Year</label>
                                        <select name="academic_year" id="academic_year" class="form-select" required onchange="updateProgress()">
                                            <option value="">Select Academic Year</option>
                                            <?php foreach ($data['academic_years'] as $year_label => $year): ?>
                                                <option value="<?php echo htmlspecialchars($year_label); ?>" <?php echo isset($_POST['academic_year']) && $_POST['academic_year'] == $year_label ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($year_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select an academic year.</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="term_name" class="form-label required-field">Term</label>
                                        <select name="term_name" id="term_name" class="form-select" required onchange="updateProgress()">
                                            <option value="">Select Term</option>
                                            <?php foreach ($data['terms'] as $term): ?>
                                                <option value="<?php echo htmlspecialchars($term); ?>" <?php echo isset($_POST['term_name']) && $_POST['term_name'] == $term ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($term); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a term.</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="department_id" class="form-label required-field">Department</label>
                                        <select name="department_id" id="department_id" class="form-select" required onchange="updateProgress()">
                                            <option value="">Select Department</option>
                                            <?php foreach ($data['departments'] as $id => $name): ?>
                                                <option value="<?php echo $id; ?>" <?php echo isset($_POST['department_id']) && $_POST['department_id'] == $id ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a department.</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="fee_type" class="form-label required-field">Fee Type</label>
                                        <input type="text" id="fee_type" name="fee_type" class="form-control" 
                                               value="<?php echo isset($_POST['fee_type']) ? htmlspecialchars($_POST['fee_type']) : ''; ?>" 
                                               required maxlength="50" list="fee_suggestions" oninput="updateProgress()">
                                        <datalist id="fee_suggestions">
                                            <?php foreach ($existing_fee_types as $type): ?>
                                                <option value="<?php echo htmlspecialchars($type); ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                        <small class="text-muted d-block mt-1">
                                            <i class="fas fa-lightbulb me-1"></i>
                                            Examples: Tuition, Books, Uniform, PTA, Sports, Excursion
                                        </small>
                                        <div class="invalid-feedback">Please enter a fee type.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Step 2: Target Selection -->
                            <div class="filter-section" id="step2-section" style="display: none;">
                                <h5 class="section-title"><i class="fas fa-bullseye me-2"></i>Who Should Pay This Fee?</h5>
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-3">
                                        <div class="target-type-card" onclick="selectTargetType('Class')" data-target="Class">
                                            <div class="text-center p-3 border rounded" style="cursor: pointer; transition: all 0.3s;" id="targetClass">
                                                <div style="font-size: 2.5rem; margin-bottom: 1rem; color: var(--primary-color);">
                                                    <i class="fas fa-users"></i>
                                                </div>
                                                <div style="font-weight: 600; color: var(--dark); margin-bottom: 0.5rem;">Entire Class</div>
                                                <div style="color: #6c757d; font-size: 0.9rem;">All students in a specific class</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="target-type-card" onclick="selectTargetType('Learner')" data-target="Learner">
                                            <div class="text-center p-3 border rounded" style="cursor: pointer; transition: all 0.3s;" id="targetLearner">
                                                <div style="font-size: 2.5rem; margin-bottom: 1rem; color: var(--primary-color);">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                                <div style="font-weight: 600; color: var(--dark); margin-bottom: 0.5rem;">Single Student</div>
                                                <div style="color: #6c757d; font-size: 0.9rem;">One specific student</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="target-type-card" onclick="selectTargetType('Bulk Learners')" data-target="Bulk Learners">
                                            <div class="text-center p-3 border rounded" style="cursor: pointer; transition: all 0.3s;" id="targetBulk">
                                                <div style="font-size: 2.5rem; margin-bottom: 1rem; color: var(--primary-color);">
                                                    <i class="fas fa-user-friends"></i>
                                                </div>
                                                <div style="font-weight: 600; color: var(--dark); margin-bottom: 0.5rem;">Multiple Students</div>
                                                <div style="color: #6c757d; font-size: 0.9rem;">Select specific students</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="target-type-card" onclick="selectTargetType('School')" data-target="School">
                                            <div class="text-center p-3 border rounded" style="cursor: pointer; transition: all 0.3s;" id="targetSchool">
                                                <div style="font-size: 2.5rem; margin-bottom: 1rem; color: var(--primary-color);">
                                                    <i class="fas fa-school"></i>
                                                </div>
                                                <div style="font-weight: 600; color: var(--dark); margin-bottom: 0.5rem;">Entire Department</div>
                                                <div style="color: #6c757d; font-size: 0.9rem;">All students in department</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <input type="hidden" name="target_type" id="target_type" value="">
                                
                                <!-- Class & Student Selection (for Class and Learner) -->
                                <div id="classStudentSection" style="display: none;">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="class_id" class="form-label required-field">Class</label>
                                            <select name="class_id" id="class_id" class="form-select" onchange="loadStudentsForClass(); updateProgress()">
                                                <option value="">Select Class</option>
                                                <?php foreach ($data['classes'] as $id => $class): ?>
                                                    <option value="<?php echo $id; ?>" data-dept="<?php echo $class['department_id']; ?>"
                                                        <?php echo isset($_POST['class_id']) && $_POST['class_id'] == $id ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                                        (<?php echo htmlspecialchars($class['department_name']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a class.</div>
                                        </div>
                                        
                                        <div class="col-md-6" id="studentSelection" style="display: none;">
                                            <label for="user_id" class="form-label required-field">Student</label>
                                            <select name="user_id" id="user_id" class="form-select" onchange="updateProgress()">
                                                <option value="">Select Student</option>
                                            </select>
                                            <div class="invalid-feedback">Please select a student.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Bulk Student Selection -->
                                <div id="bulkStudentsSection" style="display: none;">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Bulk Mode:</strong> Select specific students to assign this fee. Each student will receive an individual fee entry.
                                    </div>
                                    
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label for="bulkClassFilter" class="form-label">Filter by Class</label>
                                            <select id="bulkClassFilter" class="form-select" onchange="loadBulkStudents()">
                                                <option value="">All Classes</option>
                                                <?php foreach ($data['classes'] as $id => $class): ?>
                                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label for="bulkSearch" class="form-label">Search Students</label>
                                            <div class="input-group">
                                                <input type="text" id="bulkSearch" class="form-control" 
                                                       placeholder="Search by student name or email..." onkeyup="debounceLoadBulkStudents()">
                                                <button type="button" class="btn btn-outline-primary" onclick="loadBulkStudents()">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="selectAllStudents" onchange="toggleSelectAll()">
                                            <label class="form-check-label" for="selectAllStudents">Select All Visible</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="amountType" id="sameAmount" value="same" checked onchange="toggleAmountType()">
                                            <label class="form-check-label" for="sameAmount">Same Amount for All</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="amountType" id="individualAmount" value="individual" onchange="toggleAmountType()">
                                            <label class="form-check-label" for="individualAmount">Individual Amounts</label>
                                        </div>
                                    </div>
                                    
                                    <div class="bulk-students-table">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th class="text-center" width="50">
                                                        <input type="checkbox" id="selectAllHeader" onchange="toggleSelectAll()">
                                                    </th>
                                                    <th>Student Name</th>
                                                    <th>Class</th>
                                                    <th>Contact</th>
                                                    <th>Amount (GH₵)</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bulkStudentsBody">
                                                <tr>
                                                    <td colspan="5" class="text-center py-5 text-muted">
                                                        <i class="fas fa-users fa-3x mb-3"></i><br>
                                                        Select filters and search to load students
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="mt-3 d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-primary" id="selectedCount">0 selected</span>
                                            <span class="ms-2 text-muted" id="totalStudents">0 total</span>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadBulkStudents()">
                                                <i class="fas fa-sync-alt me-1"></i> Refresh
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary ms-2" onclick="selectAllVisible()">
                                                <i class="fas fa-check-double me-1"></i> Select All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- School Target Info -->
                                <div id="schoolTargetInfo" style="display: none;">
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle me-2"></i>
                                        <strong>Department-wide Fee</strong><br>
                                        This fee will be applied to <span id="departmentStudentsCount">all active students</span> in the selected department for the current academic year.
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Step 3: Fee Details -->
                            <div class="filter-section" id="step3-section" style="display: none;">
                                <h5 class="section-title"><i class="fas fa-money-bill-wave me-2"></i>Fee Details</h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="amount" class="form-label required-field">Amount (GH₵)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">GH₵</span>
                                            <input type="number" id="amount" name="amount" class="form-control" 
                                                   step="0.01" min="0.01" value="<?php echo isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : ''; ?>" 
                                                   required oninput="updateAmountDisplay(); updateBulkAmounts(); updateProgress()">
                                        </div>
                                        <div class="mt-2">
                                            <span class="amount-display" id="amountDisplay">GH₵ 0.00</span>
                                        </div>
                                        <div class="invalid-feedback">Please enter a valid amount greater than 0.</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="due_date" class="form-label required-field">Due Date</label>
                                        <input type="date" id="due_date" name="due_date" class="form-control" 
                                               value="<?php echo isset($_POST['due_date']) ? htmlspecialchars($_POST['due_date']) : date('Y-m-d', strtotime('+30 days')); ?>" 
                                               required min="<?php echo date('Y-m-d'); ?>" onchange="updateDaysRemaining(); updateProgress()">
                                        <small class="text-muted d-block mt-1">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            Days remaining: <span id="daysRemaining">30</span>
                                        </small>
                                        <div class="invalid-feedback">Please select a due date.</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="row mt-4">
                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="is_recurring" name="is_recurring" <?php echo isset($_POST['is_recurring']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="is_recurring">
                                                        <strong>Recurring Fee</strong><br>
                                                        <small class="text-muted">This fee will repeat automatically each term</small>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="row mt-4">
                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="sendSms" name="send_sms" onchange="toggleSmsOptions()">
                                                    <label class="form-check-label" for="sendSms">
                                                        <strong>Send SMS Notification</strong><br>
                                                        <small class="text-muted">Notify parents via SMS (requires SMS credits)</small>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 mt-3" id="smsSection" style="display: none;">
                                        <label for="sms_message" class="form-label">Custom SMS Message</label>
                                        <textarea id="sms_message" name="sms_message" class="form-control" rows="3" 
                                                  placeholder="Default: Dear Parent, a new fee of GH₵[amount] has been added for [student_name]. Due: [due_date]. Please make payment before the due date." 
                                                  oninput="updateSmsPreview()"></textarea>
                                        <small class="text-muted">
                                            Available variables: [student_name], [amount], [fee_type], [due_date], [term], [academic_year]
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Step 4: Review & Submit -->
                            <div class="filter-section" id="step4-section" style="display: none;">
                                <h5 class="section-title"><i class="fas fa-check-circle me-2"></i>Review & Submit</h5>
                                
                                <!-- Summary Card -->
                                <div class="card border-primary mb-4">
                                    <div class="card-header bg-primary text-white">
                                        <h6 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Fee Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <dl class="row mb-0">
                                                    <dt class="col-sm-5">Target Type:</dt>
                                                    <dd class="col-sm-7" id="summaryTarget">-</dd>
                                                    
                                                    <dt class="col-sm-5">Academic Year:</dt>
                                                    <dd class="col-sm-7" id="summaryYear">-</dd>
                                                    
                                                    <dt class="col-sm-5">Department:</dt>
                                                    <dd class="col-sm-7" id="summaryDepartment">-</dd>
                                                    
                                                    <dt class="col-sm-5">Term:</dt>
                                                    <dd class="col-sm-7" id="summaryTerm">-</dd>
                                                </dl>
                                            </div>
                                            <div class="col-md-6">
                                                <dl class="row mb-0">
                                                    <dt class="col-sm-5">Fee Type:</dt>
                                                    <dd class="col-sm-7" id="summaryFeeType">-</dd>
                                                    
                                                    <dt class="col-sm-5">Amount:</dt>
                                                    <dd class="col-sm-7" id="summaryAmount">-</dd>
                                                    
                                                    <dt class="col-sm-5">Due Date:</dt>
                                                    <dd class="col-sm-7" id="summaryDueDate">-</dd>
                                                    
                                                    <dt class="col-sm-5">Students Affected:</dt>
                                                    <dd class="col-sm-7" id="summaryStudents">-</dd>
                                                </dl>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SMS Notification -->
                                <div class="mb-4" id="smsReviewSection" style="display: none;">
                                    <h6><i class="fas fa-sms me-2"></i>SMS Notification</h6>
                                    <div class="sms-preview">
                                        <p id="smsPreviewText" class="mb-1"></p>
                                        <small class="text-muted">Characters: <span id="smsChars">0</span> (SMS limit: 160 characters)</small>
                                    </div>
                                </div>
                                
                                <!-- Final Check -->
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Important:</strong> Please review all information carefully. Once submitted, fees will be created and cannot be easily modified.
                                </div>
                            </div>
                            
                            <!-- Navigation Buttons -->
                            <div class="d-flex justify-content-between mt-4">
                                <div>
                                    <button type="button" class="btn btn-secondary btn-lg" id="prevBtn" style="display: none;" onclick="prevStep()">
                                        <i class="fas fa-arrow-left me-2"></i>Previous
                                    </button>
                                </div>
                                <div>
                                    <a href="/admin/fees_payments.php" class="btn btn-outline-secondary btn-lg me-2">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                    <button type="button" class="btn btn-primary btn-lg" id="nextBtn" onclick="nextStep()">
                                        Next Step <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn" style="display: none;">
                                        <i class="fas fa-save me-2"></i>Add Fee
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        echo '<footer class="bg-dark text-white py-3 mt-4"><div class="container text-center"><p>&copy; ' . date('Y') . ' Queen\'s Paradise Montessori School</p></div></footer>';
    }
    ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    // Global variables
    let currentStep = 1;
    let totalSteps = 4;
    let loadBulkStudentsTimeout = null;
    
    $(document).ready(function() {
        // Initialize Select2
        $('#user_id').select2({
            placeholder: 'Select a student...',
            allowClear: true,
            width: '100%'
        });
        
        $('#bulkClassFilter').select2({
            placeholder: 'All Classes',
            allowClear: true,
            width: '100%'
        });
        
        // Initialize form
        updateProgress();
        updateAmountDisplay();
        updateDaysRemaining();
        
        <?php if (isset($_POST['target_type'])): ?>
            // Restore form state after submission with errors
            selectTargetType('<?php echo $_POST['target_type']; ?>');
            updateProgress();
            <?php if ($_POST['target_type'] === 'Bulk Learners'): ?>
                loadBulkStudents();
            <?php endif; ?>
        <?php endif; ?>
        
        // Update SMS preview
        updateSmsPreview();
    });
    
    // Show loading spinner
    function showLoading(message = 'Processing...') {
        $('#loadingMessage').text(message);
        $('#loadingSpinner').css('display', 'flex');
    }
    
    // Hide loading spinner
    function hideLoading() {
        $('#loadingSpinner').hide();
    }
    
    // Update progress indicator
    function updateProgress() {
        // Update step indicators
        $('.step').removeClass('active completed');
        
        for (let i = 1; i <= totalSteps; i++) {
            const stepElement = $('#step' + i);
            if (i < currentStep) {
                stepElement.addClass('completed');
                stepElement.find('.step-circle').html('<i class="fas fa-check"></i>');
            } else if (i === currentStep) {
                stepElement.addClass('active');
            } else {
                stepElement.find('.step-circle').text(i);
            }
        }
        
        // Show/hide sections
        $('#step1-section, #step2-section, #step3-section, #step4-section').hide();
        $('#step' + currentStep + '-section').show();
        
        // Update navigation buttons
        $('#prevBtn').toggle(currentStep > 1);
        $('#nextBtn').toggle(currentStep < totalSteps);
        $('#submitBtn').toggle(currentStep === totalSteps);
        
        // Update summary on last step
        if (currentStep === totalSteps) {
            updateSummary();
        }
    }
    
    // Next step
    function nextStep() {
        if (validateCurrentStep()) {
            if (currentStep < totalSteps) {
                currentStep++;
                updateProgress();
            }
        }
    }
    
    // Previous step
    function prevStep() {
        if (currentStep > 1) {
            currentStep--;
            updateProgress();
        }
    }
    
    // Validate current step
    function validateCurrentStep() {
        let isValid = true;
        
        switch (currentStep) {
            case 1:
                if (!$('#academic_year').val()) {
                    $('#academic_year').addClass('is-invalid');
                    isValid = false;
                }
                if (!$('#term_name').val()) {
                    $('#term_name').addClass('is-invalid');
                    isValid = false;
                }
                if (!$('#department_id').val()) {
                    $('#department_id').addClass('is-invalid');
                    isValid = false;
                }
                if (!$('#fee_type').val()) {
                    $('#fee_type').addClass('is-invalid');
                    isValid = false;
                }
                break;
                
            case 2:
                const targetType = $('#target_type').val();
                if (!targetType) {
                    alert('Please select a target type');
                    isValid = false;
                } else if (targetType === 'Class') {
                    if (!$('#class_id').val()) {
                        $('#class_id').addClass('is-invalid');
                        isValid = false;
                    }
                } else if (targetType === 'Learner') {
                    if (!$('#class_id').val()) {
                        $('#class_id').addClass('is-invalid');
                        isValid = false;
                    }
                    if (!$('#user_id').val()) {
                        $('#user_id').addClass('is-invalid');
                        isValid = false;
                    }
                } else if (targetType === 'Bulk Learners') {
                    const selectedCount = $('.student-checkbox:checked').length;
                    if (selectedCount === 0) {
                        alert('Please select at least one student');
                        isValid = false;
                    }
                }
                break;
                
            case 3:
                if (!$('#amount').val() || parseFloat($('#amount').val()) <= 0) {
                    $('#amount').addClass('is-invalid');
                    isValid = false;
                }
                if (!$('#due_date').val()) {
                    $('#due_date').addClass('is-invalid');
                    isValid = false;
                }
                break;
        }
        
        // Remove invalid class on valid fields
        if (isValid) {
            $('.is-invalid').removeClass('is-invalid');
        }
        
        return isValid;
    }
    
    // Select target type
    function selectTargetType(targetType) {
        $('#target_type').val(targetType);
        
        // Update UI - add border to selected card
        $('.target-type-card .border').removeClass('border-primary').addClass('border');
        $(`#target${targetType.replace(' ', '')}`).removeClass('border').addClass('border-primary');
        
        // Show/hide sections
        $('#classStudentSection, #bulkStudentsSection, #schoolTargetInfo').hide();
        $('#studentSelection').hide();
        
        if (targetType === 'Class' || targetType === 'Learner') {
            $('#classStudentSection').show();
            if (targetType === 'Learner') {
                $('#studentSelection').show();
                loadStudentsForClass();
            }
        } else if (targetType === 'Bulk Learners') {
            $('#bulkStudentsSection').show();
            loadBulkStudents();
        } else if (targetType === 'School') {
            $('#schoolTargetInfo').show();
            const deptName = $('#department_id option:selected').text();
            $('#departmentStudentsCount').text('all active students in ' + deptName);
        }
        
        // Update progress
        updateProgress();
    }
    
    // Load students for selected class (single learner)
    function loadStudentsForClass() {
        const classId = $('#class_id').val();
        const academicYear = $('#academic_year').val();
        
        if (!classId || !academicYear) {
            $('#user_id').html('<option value="">Select Student</option>');
            return;
        }
        
        showLoading('Loading students...');
        
        $.ajax({
            url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'get_students_by_class',
                class_id: classId,
                academic_year: academicYear
            },
            success: function(response) {
                if (response.success) {
                    const studentSelect = $('#user_id');
                    studentSelect.html('<option value="">Select Student</option>');
                    
                    if (response.students.length === 0) {
                        studentSelect.append('<option value="">No students found</option>');
                    } else {
                        response.students.forEach(student => {
                            const phone = student.phone ? ` (${student.phone})` : '';
                            studentSelect.append(
                                `<option value="${student.id}">${student.name}${phone}</option>`
                            );
                        });
                    }
                    
                    studentSelect.trigger('change');
                    studentSelect.removeClass('is-invalid');
                }
            },
            error: function() {
                $('#user_id').html('<option value="">Error loading students</option>');
            },
            complete: function() {
                hideLoading();
            }
        });
    }
    
    // Load bulk students
    function loadBulkStudents() {
        const academicYear = $('#academic_year').val();
        const deptId = $('#department_id').val();
        const classFilter = $('#bulkClassFilter').val();
        const search = $('#bulkSearch').val();
        
        if (!academicYear || !deptId) {
            showBulkStudentsMessage('Please select academic year and department first');
            return;
        }
        
        showLoading('Loading students...');
        
        $.ajax({
            url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'filter_students',
                academic_year: academicYear,
                department_id: deptId,
                class_id: classFilter,
                search: search
            },
            success: function(response) {
                if (response.success) {
                    renderBulkStudentsTable(response.students);
                } else {
                    showBulkStudentsMessage('Error: ' + (response.error || 'Unknown error'));
                }
            },
            error: function() {
                showBulkStudentsMessage('Failed to load students');
            },
            complete: function() {
                hideLoading();
            }
        });
    }
    
    // Debounced version for search input
    function debounceLoadBulkStudents() {
        clearTimeout(loadBulkStudentsTimeout);
        loadBulkStudentsTimeout = setTimeout(loadBulkStudents, 500);
    }
    
    // Render bulk students table
    function renderBulkStudentsTable(students) {
        const tableBody = $('#bulkStudentsBody');
        
        if (students.length === 0) {
            showBulkStudentsMessage('No students found');
            return;
        }
        
        tableBody.empty();
        
        const amountType = $('input[name="amountType"]:checked').val();
        const defaultAmount = $('#amount').val() || '0';
        
        students.forEach(student => {
            const amountField = amountType === 'same' 
                ? `<span class="text-primary">${defaultAmount}</span>`
                : `<input type="number" class="form-control student-amount-input" value="${defaultAmount}" step="0.01" min="0.01" onchange="validateStudentAmount(this)">`;
            
            const phone = student.phone || student.email || 'N/A';
            
            tableBody.append(`
                <tr class="student-row" data-student-id="${student.id}" data-class-id="${student.class_id}">
                    <td class="text-center">
                        <input type="checkbox" class="student-checkbox form-check-input" onchange="updateSelectedCount()">
                    </td>
                    <td>${student.name}</td>
                    <td>${student.class_name}</td>
                    <td>${phone}</td>
                    <td>${amountField}</td>
                </tr>
            `);
        });
        
        updateSelectedCount();
    }
    
    // Show message in bulk students table
    function showBulkStudentsMessage(message) {
        $('#bulkStudentsBody').html(`
            <tr>
                <td colspan="5" class="text-center py-5 text-muted">
                    <i class="fas fa-users fa-3x mb-3"></i><br>
                    ${message}
                </td>
            </tr>
        `);
        updateSelectedCount();
    }
    
    // Toggle select all students
    function toggleSelectAll() {
        const isChecked = $('#selectAllStudents').prop('checked');
        $('#selectAllHeader').prop('checked', isChecked);
        $('.student-checkbox').prop('checked', isChecked);
        $('.student-row').toggleClass('selected', isChecked);
        updateSelectedCount();
    }
    
    // Select all visible students
    function selectAllVisible() {
        $('.student-checkbox').prop('checked', true);
        $('.student-row').addClass('selected');
        $('#selectAllStudents').prop('checked', true);
        $('#selectAllHeader').prop('checked', true);
        updateSelectedCount();
    }
    
    // Update selected student count
    function updateSelectedCount() {
        const count = $('.student-checkbox:checked').length;
        const total = $('.student-row').length;
        $('#selectedCount').text(count + ' selected');
        $('#totalStudents').text(total + ' total');
    }
    
    // Toggle amount type
    function toggleAmountType() {
        const amountType = $('input[name="amountType"]:checked').val();
        const defaultAmount = $('#amount').val() || '0';
        
        if (amountType === 'same') {
            $('.student-amount-input').replaceWith(`<span class="text-primary">${defaultAmount}</span>`);
        } else {
            $('.student-row').each(function() {
                $(this).find('td').eq(4).html(
                    `<input type="number" class="form-control student-amount-input" value="${defaultAmount}" step="0.01" min="0.01" onchange="validateStudentAmount(this)">`
                );
            });
        }
    }
    
    // Validate individual student amount
    function validateStudentAmount(input) {
        const value = parseFloat(input.value);
        if (isNaN(value) || value <= 0) {
            input.classList.add('is-invalid');
            return false;
        }
        input.classList.remove('is-invalid');
        return true;
    }
    
    // Update bulk amounts when default amount changes
    function updateBulkAmounts() {
        const amountType = $('input[name="amountType"]:checked').val();
        const defaultAmount = $('#amount').val() || '0';
        
        if (amountType === 'same') {
            $('.student-row').each(function() {
                $(this).find('td').eq(4).html(`<span class="text-primary">${defaultAmount}</span>`);
            });
        }
        
        updateAmountDisplay();
    }
    
    // Update amount display
    function updateAmountDisplay() {
        const amount = $('#amount').val() || '0.00';
        $('#amountDisplay').text('GH₵ ' + parseFloat(amount).toFixed(2));
    }
    
    // Update days remaining
    function updateDaysRemaining() {
        const dueDate = $('#due_date').val();
        if (!dueDate) return;
        
        const today = new Date();
        const due = new Date(dueDate);
        const timeDiff = due.getTime() - today.getTime();
        const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
        
        const daysElement = $('#daysRemaining');
        daysElement.text(daysDiff);
        
        // Color code
        daysElement.removeClass('text-success text-warning text-danger');
        if (daysDiff > 30) {
            daysElement.addClass('text-success');
        } else if (daysDiff > 7) {
            daysElement.addClass('text-warning');
        } else if (daysDiff >= 0) {
            daysElement.addClass('text-danger');
        } else {
            daysElement.addClass('text-danger').text('Overdue!');
        }
    }
    
    // Toggle SMS options
    function toggleSmsOptions() {
        $('#smsSection').toggle($('#sendSms').is(':checked'));
        $('#smsReviewSection').toggle($('#sendSms').is(':checked'));
        updateSmsPreview();
    }
    
    // Update SMS preview
    function updateSmsPreview() {
        if (!$('#sendSms').is(':checked')) return;
        
        const amount = $('#amount').val() || '0.00';
        const feeType = $('#fee_type').val() || '[Fee Type]';
        const dueDate = $('#due_date').val() || '[Due Date]';
        const termName = $('#term_name option:selected').text() || '[Term]';
        const academicYear = $('#academic_year option:selected').text() || '[Academic Year]';
        const customMessage = $('#sms_message').val();
        
        let preview = '';
        if (customMessage) {
            preview = customMessage
                .replace(/\[student_name\]/g, '[Student Name]')
                .replace(/\[amount\]/g, amount)
                .replace(/\[fee_type\]/g, feeType)
                .replace(/\[due_date\]/g, dueDate)
                .replace(/\[term\]/g, termName)
                .replace(/\[academic_year\]/g, academicYear);
        } else {
            preview = `Dear Parent, a new fee of GH₵${amount} has been added for [student_name]. Fee: ${feeType}. Due: ${dueDate}. Term: ${termName}. Academic Year: ${academicYear}.`;
        }
        
        $('#smsPreviewText').text(preview);
        $('#smsChars').text(preview.length);
        
        // Highlight if over SMS limit
        if (preview.length > 160) {
            $('#smsChars').addClass('text-danger');
        } else {
            $('#smsChars').removeClass('text-danger');
        }
    }
    
    // Update summary
    function updateSummary() {
        // Target type
        $('#summaryTarget').text($('#target_type').val() || '-');
        
        // Academic year
        const academicYear = $('#academic_year option:selected').text() || '-';
        $('#summaryYear').text(academicYear);
        
        // Department
        const department = $('#department_id option:selected').text() || '-';
        $('#summaryDepartment').text(department);
        
        // Term
        const term = $('#term_name option:selected').text() || '-';
        $('#summaryTerm').text(term);
        
        // Fee type
        $('#summaryFeeType').text($('#fee_type').val() || '-');
        
        // Amount
        const amount = $('#amount').val() || '0.00';
        $('#summaryAmount').text('GH₵ ' + parseFloat(amount).toFixed(2));
        
        // Due date
        const dueDate = $('#due_date').val();
        if (dueDate) {
            const dateObj = new Date(dueDate);
            $('#summaryDueDate').text(dateObj.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}));
        } else {
            $('#summaryDueDate').text('-');
        }
        
        // Students affected
        const targetType = $('#target_type').val();
        let studentsText = '-';
        
        if (targetType === 'Class') {
            studentsText = 'All students in selected class';
        } else if (targetType === 'Learner') {
            studentsText = '1 student';
        } else if (targetType === 'Bulk Learners') {
            const selectedCount = $('.student-checkbox:checked').length;
            studentsText = selectedCount + ' students';
        } else if (targetType === 'School') {
            const deptName = $('#department_id option:selected').text();
            studentsText = 'All students in ' + deptName;
        }
        
        $('#summaryStudents').text(studentsText);
    }
    
    // Form validation and submission
    function validateForm() {
        // Validate all steps
        for (let i = 1; i <= totalSteps; i++) {
            currentStep = i;
            if (!validateCurrentStep()) {
                currentStep = i;
                updateProgress();
                return false;
            }
        }
        
        currentStep = totalSteps;
        
        // Prepare bulk data if needed
        const targetType = $('#target_type').val();
        if (targetType === 'Bulk Learners') {
            const selectedStudents = $('.student-checkbox:checked');
            if (selectedStudents.length === 0) {
                alert('Please select at least one student');
                return false;
            }
            
            // Validate individual amounts if enabled
            if ($('#individualAmount').is(':checked')) {
                let valid = true;
                $('.student-amount-input').each(function() {
                    if (!validateStudentAmount(this)) {
                        valid = false;
                    }
                });
                
                if (!valid) {
                    alert('Please enter valid amounts for all selected students.');
                    return false;
                }
            }
            
            // Get student data
            selectedStudents.each(function() {
                const row = $(this).closest('tr');
                const studentId = row.data('student-id');
                const classId = row.data('class-id');
                let amount = $('#amount').val();
                
                // If individual amounts, get from input
                if ($('#individualAmount').is(':checked')) {
                    const amountInput = row.find('.student-amount-input');
                    if (amountInput.length) {
                        amount = amountInput.val();
                    }
                }
                
                if (studentId && classId && amount > 0) {
                    $('#fee-form').append(
                        `<input type="hidden" name="bulk_student_ids[]" value="${studentId}">`
                    );
                    $('#fee-form').append(
                        `<input type="hidden" name="student_classes[]" value="${classId}">`
                    );
                    $('#fee-form').append(
                        `<input type="hidden" name="student_amounts[]" value="${amount}">`
                    );
                }
            });
            
            // Confirmation
            const selectedCount = selectedStudents.length;
            if (!confirm(`You are about to add fees for ${selectedCount} student(s). Continue?`)) {
                return false;
            }
        }
        
        // Check if due date is in the past
        const dueDate = new Date($('#due_date').val());
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (dueDate < today) {
            if (!confirm('The due date is in the past. Do you want to continue?')) {
                return false;
            }
        }
        
        // Final confirmation
        if (!confirm('Are you sure you want to add this fee?')) {
            return false;
        }
        
        // Show loading spinner
        showLoading('Adding fee(s)...');
        $('#submitBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');
        
        return true;
    }
    
    // Initialize event listeners
    $(function() {
        // Amount display update
        $('#amount').on('input', function() {
            updateAmountDisplay();
            updateBulkAmounts();
        });
        
        // Days remaining update
        $('#due_date').on('change', function() {
            updateDaysRemaining();
        });
        
        // SMS preview updates
        $('#amount, #fee_type, #due_date, #sms_message').on('input', updateSmsPreview);
        $('#academic_year, #term_name').on('change', updateSmsPreview);
        
        // Remove invalid class on input
        $('input, select').on('input change', function() {
            $(this).removeClass('is-invalid');
        });
        
        // Initialize due date to 30 days from now if not set
        if (!$('#due_date').val()) {
            const futureDate = new Date();
            futureDate.setDate(futureDate.getDate() + 30);
            $('#due_date').val(futureDate.toISOString().split('T')[0]);
            updateDaysRemaining();
        }
    });
    </script>
</body>
</html>