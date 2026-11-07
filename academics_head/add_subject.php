<?php
ob_start();
session_start();

// Enable error logging (not display) to diagnose 500 errors
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to add_subject.php");
    header("Location: ../login.php");
    exit();
}

// Include database connection with error handling
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    die("Server configuration error. Please contact the administrator.");
}
require_once($db_path);

// Fallback for log_activity function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Verify database connection
if (!isset($conn) || !$conn) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    die("Database connection failed. Please try again later.");
}

$success_msg = $error_msg = "";
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'list'; // Default to list view

// Define departments where teachers are not assigned
$no_teacher_depts = [1, 6]; // Pre-School (1), Lower Primary (6)

// Fetch departments for all modes
try {
    $departments_sql = "SELECT id, name FROM departments ORDER BY name";
    $departments_stmt = $conn->prepare($departments_sql);
    if (!$departments_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $departments_stmt->execute();
    $departments_result = $departments_stmt->get_result();
    $departments_list = $departments_result->fetch_all(MYSQLI_ASSOC);
    $departments_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching departments: " . $e->getMessage());
    $error_msg = "Error loading departments. Please try again.";
    $departments_list = [];
}

// Fetch academic years for filtering
try {
    $years_sql = "SELECT id, year_label FROM academic_years ORDER BY start_date DESC";
    $years_stmt = $conn->prepare($years_sql);
    if (!$years_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $years_stmt->execute();
    $years_result = $years_stmt->get_result();
    $academic_years = $years_result->fetch_all(MYSQLI_ASSOC);
    $years_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching academic years: " . $e->getMessage());
    $academic_years = [];
}

// Handle List Mode
if ($mode === 'list') {
    try {
        $sql = "SELECT d.id AS dept_id, d.name AS dept_name, 
                       s.id AS subject_id, s.subject_name, s.subject_code, s.status, s.created_at,
                       u.name AS teacher_name,
                       COUNT(DISTINCT sc.class_id) AS class_count,
                       COUNT(DISTINCT lp.id) AS lesson_plan_count
                FROM departments d
                LEFT JOIN subjects s ON d.id = s.department_id
                LEFT JOIN users u ON s.teacher_id = u.id
                LEFT JOIN class_subjects sc ON s.id = sc.subject_id
                LEFT JOIN lesson_plans lp ON s.id = lp.subject_id AND lp.status = 'published'
                GROUP BY d.id, d.name, s.id, s.subject_name, s.subject_code, s.status, s.created_at, u.name
                ORDER BY d.name, s.subject_name";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        // Organize subjects by department
        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $dept_id = $row['dept_id'];
            if (!isset($departments[$dept_id])) {
                $departments[$dept_id] = [
                    'name' => $row['dept_name'],
                    'subjects' => [],
                    'has_teachers' => !in_array($dept_id, $no_teacher_depts),
                    'total_subjects' => 0
                ];
            }
            if ($row['subject_id']) {
                $departments[$dept_id]['subjects'][] = [
                    'id' => $row['subject_id'],
                    'name' => $row['subject_name'],
                    'code' => $row['subject_code'],
                    'status' => $row['status'],
                    'teacher' => in_array($dept_id, $no_teacher_depts) ? 'N/A' : ($row['teacher_name'] ?: 'Not Assigned'),
                    'class_count' => $row['class_count'] ?? 0,
                    'lesson_plan_count' => $row['lesson_plan_count'] ?? 0,
                    'created_at' => $row['created_at']
                ];
                $departments[$dept_id]['total_subjects']++;
            }
        }
        $stmt->close();

        // Get total counts for stats
        $stats_sql = "SELECT 
                        COUNT(*) as total_subjects,
                        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_subjects,
                        COUNT(DISTINCT department_id) as departments_with_subjects
                      FROM subjects";
        $stats_stmt = $conn->prepare($stats_sql);
        if ($stats_stmt) {
            $stats_stmt->execute();
            $stats = $stats_stmt->get_result()->fetch_assoc();
            $stats_stmt->close();
        }

        // Log activity
        $admin_id = $_SESSION['user_id'];
        $action = "Viewed Subjects by Department";
        $description = "Admin viewed the list of subjects grouped by department.";
        logActivity($conn, $admin_id, $action, $description);
    } catch (Exception $e) {
        error_log("Error in list mode: " . $e->getMessage());
        $error_msg = "Error loading subjects. Please try again.";
    }
}

// Handle Add Mode
if ($mode === 'add') {
    // Fetch teachers (only for departments that allow teachers)
    try {
        $teachers_sql = "SELECT id, name, email, department_id FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name";
        $teachers_stmt = $conn->prepare($teachers_sql);
        if (!$teachers_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $teachers_stmt->execute();
        $teachers_result = $teachers_stmt->get_result();
        $teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);
        $teachers_stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching teachers: " . $e->getMessage());
        $teachers = [];
    }

    // Check for dept_id in URL or POST data
    $department_id = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['department_id'])) {
        $department_id = (int)$_POST['department_id'];
    } elseif (isset($_GET['dept_id'])) {
        $department_id = (int)$_GET['dept_id'];
    }

    // Validate dept_id if provided
    if ($department_id) {
        try {
            $dept_check_sql = "SELECT id, name FROM departments WHERE id = ?";
            $dept_check_stmt = $conn->prepare($dept_check_sql);
            if (!$dept_check_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $dept_check_stmt->bind_param("i", $department_id);
            $dept_check_stmt->execute();
            $dept_result = $dept_check_stmt->get_result();
            if ($dept_result->num_rows === 0) {
                $department_id = null;
                $error_msg = "Invalid department selected.";
            } else {
                $selected_dept = $dept_result->fetch_assoc();
            }
            $dept_check_stmt->close();
        } catch (Exception $e) {
            error_log("Error validating department: " . $e->getMessage());
            $department_id = null;
            $error_msg = "Error validating department.";
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $subject_name = trim($_POST['subject_name'] ?? '');
        $subject_code = trim($_POST['subject_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $teacher_id = !empty($_POST['teacher_id']) && !in_array($department_id, $no_teacher_depts) ? (int)$_POST['teacher_id'] : null;
        $status = trim($_POST['status'] ?? 'Active');
        $academic_year_id = !empty($_POST['academic_year_id']) ? (int)$_POST['academic_year_id'] : null;

        $errors = [];
        if (empty($subject_name)) $errors[] = "Subject name is required.";
        if (strlen($subject_name) > 100) $errors[] = "Subject name must be 100 characters or less.";
        if (empty($subject_code)) $errors[] = "Subject code is required.";
        if (strlen($subject_code) > 20) $errors[] = "Subject code must be 20 characters or less.";
        if (!preg_match('/^[A-Z0-9-]+$/', $subject_code)) $errors[] = "Subject code must contain only uppercase letters, numbers, or hyphens.";
        if (!in_array($status, ['Active', 'Inactive'])) $errors[] = "Invalid status selected.";
        if ($description && strlen($description) > 1000) $errors[] = "Description must be 1000 characters or less.";

        // Check for duplicate subject code
        try {
            $check_sql = "SELECT id FROM subjects WHERE subject_code = ?";
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $check_stmt->bind_param("s", $subject_code);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors[] = "Subject code already exists.";
            }
            $check_stmt->close();
        } catch (Exception $e) {
            error_log("Error checking duplicate code: " . $e->getMessage());
            $errors[] = "Error validating subject code.";
        }

        // Validate department_id if provided
        if ($department_id) {
            try {
                $dept_check_sql = "SELECT id FROM departments WHERE id = ?";
                $dept_check_stmt = $conn->prepare($dept_check_sql);
                if (!$dept_check_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $dept_check_stmt->bind_param("i", $department_id);
                $dept_check_stmt->execute();
                if ($dept_check_stmt->get_result()->num_rows === 0) {
                    $errors[] = "Invalid department selected.";
                    $department_id = null;
                }
                $dept_check_stmt->close();
            } catch (Exception $e) {
                error_log("Error validating department: " . $e->getMessage());
                $errors[] = "Error validating department.";
            }
        }

        // Validate teacher_id if provided and department allows teachers
        if ($teacher_id && !in_array($department_id, $no_teacher_depts)) {
            try {
                $teacher_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND status = 'active'";
                $teacher_check_stmt = $conn->prepare($teacher_check_sql);
                if (!$teacher_check_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $teacher_check_stmt->bind_param("i", $teacher_id);
                $teacher_check_stmt->execute();
                if ($teacher_check_stmt->get_result()->num_rows === 0) {
                    $errors[] = "Invalid teacher selected.";
                }
                $teacher_check_stmt->close();
            } catch (Exception $e) {
                error_log("Error validating teacher: " . $e->getMessage());
                $errors[] = "Error validating teacher.";
            }
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();
                $insert_sql = "INSERT INTO subjects (subject_name, subject_code, department_id, description, teacher_id, status, created_by, created_at, updated_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                if (!$insert_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $admin_id = $_SESSION['user_id'];
                $insert_stmt->bind_param("ssisssi", $subject_name, $subject_code, $department_id, $description, $teacher_id, $status, $admin_id);
                $insert_stmt->execute();
                $new_subject_id = $conn->insert_id;

                // Log activity
                $action = "Added Subject";
                $description_log = "Subject: $subject_name, Code: $subject_code";
                logActivity($conn, $admin_id, $action, $description_log);

                $conn->commit();
                $_SESSION['success_msg'] = "Subject added successfully!";
                header("Location: ./add_subject.php?mode=list");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Error adding subject: " . $e->getMessage());
                $error_msg = "Error adding subject. Please try again.";
            } finally {
                if (isset($insert_stmt)) {
                    $insert_stmt->close();
                }
            }
        } else {
            $error_msg = implode("<br>", $errors);
        }
    }
}

// Handle View Mode
if ($mode === 'view') {
    $subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

    try {
        $sql = "SELECT s.id, s.subject_name, s.subject_code, s.description, s.status, s.created_at, s.updated_at,
                       d.id AS department_id, d.name AS department_name, 
                       u.id AS teacher_id, u.name AS teacher_name, u.email AS teacher_email,
                       (SELECT COUNT(*) FROM class_subjects WHERE subject_id = s.id) AS class_count,
                       (SELECT COUNT(*) FROM lesson_plans WHERE subject_id = s.id AND status = 'published') AS lesson_plan_count,
                       (SELECT COUNT(*) FROM assignments WHERE subject_id = s.id) AS assignment_count
                FROM subjects s
                LEFT JOIN departments d ON s.department_id = d.id
                LEFT JOIN users u ON s.teacher_id = u.id
                WHERE s.id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subject = $result->fetch_assoc();
        $stmt->close();

        if (!$subject) {
            $error_msg = "Subject not found.";
        } else {
            // Set teacher to N/A for Pre-School and Lower Primary
            if (in_array($subject['department_id'], $no_teacher_depts)) {
                $subject['teacher_name'] = 'N/A';
            }
            
            // Get assigned classes
            $classes_sql = "SELECT c.id, c.class_name, c.class_teacher_id, u.name AS class_teacher_name
                           FROM classes c
                           JOIN class_subjects cs ON c.id = cs.class_id
                           LEFT JOIN users u ON c.class_teacher_id = u.id
                           WHERE cs.subject_id = ?
                           ORDER BY c.class_name";
            $classes_stmt = $conn->prepare($classes_sql);
            if ($classes_stmt) {
                $classes_stmt->bind_param("i", $subject_id);
                $classes_stmt->execute();
                $subject['assigned_classes'] = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $classes_stmt->close();
            }
        }

        // Log activity
        if (!$error_msg) {
            $admin_id = $_SESSION['user_id'];
            $action = "Viewed Subject Details";
            $description = "Admin viewed details for subject: " . ($subject['subject_name'] ?? 'Unknown');
            logActivity($conn, $admin_id, $action, $description);
        }
    } catch (Exception $e) {
        error_log("Error in view mode: " . $e->getMessage());
        $error_msg = "Error loading subject details. Please try again.";
    }
}

// Handle Edit Mode
if ($mode === 'edit') {
    $subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $subject_name = trim($_POST['subject_name'] ?? '');
        $subject_code = trim($_POST['subject_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $teacher_id = !empty($_POST['teacher_id']) && !in_array($department_id, $no_teacher_depts) ? (int)$_POST['teacher_id'] : null;
        $status = trim($_POST['status'] ?? 'Active');

        $errors = [];
        if (empty($subject_name)) $errors[] = "Subject name is required.";
        if (strlen($subject_name) > 100) $errors[] = "Subject name must be 100 characters or less.";
        if (empty($subject_code)) $errors[] = "Subject code is required.";
        if (strlen($subject_code) > 20) $errors[] = "Subject code must be 20 characters or less.";
        if (!preg_match('/^[A-Z0-9-]+$/', $subject_code)) $errors[] = "Subject code must contain only uppercase letters, numbers, or hyphens.";
        if (!in_array($status, ['Active', 'Inactive'])) $errors[] = "Invalid status selected.";
        if ($description && strlen($description) > 1000) $errors[] = "Description must be 1000 characters or less.";

        // Check for duplicate subject code (excluding current subject)
        try {
            $check_sql = "SELECT id FROM subjects WHERE subject_code = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $check_stmt->bind_param("si", $subject_code, $subject_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors[] = "Subject code already exists.";
            }
            $check_stmt->close();
        } catch (Exception $e) {
            error_log("Error checking duplicate code: " . $e->getMessage());
            $errors[] = "Error validating subject code.";
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();
                $update_sql = "UPDATE subjects SET 
                              subject_name = ?, subject_code = ?, department_id = ?, 
                              description = ?, teacher_id = ?, status = ?, updated_by = ?, updated_at = NOW()
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $admin_id = $_SESSION['user_id'];
                $update_stmt->bind_param("ssisssii", $subject_name, $subject_code, $department_id, $description, $teacher_id, $status, $admin_id, $subject_id);
                $update_stmt->execute();

                // Log activity
                $action = "Updated Subject";
                $description_log = "Updated subject: $subject_name, Code: $subject_code";
                logActivity($conn, $admin_id, $action, $description_log);

                $conn->commit();
                $_SESSION['success_msg'] = "Subject updated successfully!";
                header("Location: ./add_subject.php?mode=view&subject_id=$subject_id");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Error updating subject: " . $e->getMessage());
                $error_msg = "Error updating subject. Please try again.";
            } finally {
                if (isset($update_stmt)) {
                    $update_stmt->close();
                }
            }
        } else {
            $error_msg = implode("<br>", $errors);
        }
    }

    // Fetch subject data for editing
    try {
        $sql = "SELECT s.*, d.name AS department_name 
                FROM subjects s
                LEFT JOIN departments d ON s.department_id = d.id
                WHERE s.id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subject = $result->fetch_assoc();
        $stmt->close();

        if (!$subject) {
            $error_msg = "Subject not found.";
        }
    } catch (Exception $e) {
        error_log("Error in edit mode: " . $e->getMessage());
        $error_msg = "Error loading subject for editing.";
    }
}

// Include header
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
if (!file_exists($header_path)) {
    error_log("Header file missing: $header_path");
    echo "<!DOCTYPE html><html><head><title>QueensPa School</title></head><body>";
} else {
    include $header_path;
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage subjects in QueensPa School management system: view, add, and see details.">
    <meta name="keywords" content="QueensPa School, subjects, add subject, view subject, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Subject Management - QueensPa School</title>
    
    <!-- CSS Links -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-dark: #e03d00;
            --primary-light: #ff6b00;
            --secondary-color: #ff8c00;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --danger-color: #ef4444;
            --dark-bg: #1f2937;
            --light-bg: #f9fafb;
            --border-radius: 16px;
            --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            --hover-shadow: 0 20px 30px -10px rgba(255, 69, 0, 0.15);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #fff9f5 0%, #fff5f0 100%);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            color: #374151;
        }
        
        .dashboard-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 24px;
        }
        
        /* Page Header */
        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border-left: 6px solid var(--primary-color);
            animation: slideInDown 0.5s ease;
            background: linear-gradient(to right, white, #fffaf5);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header-content h1 {
            color: var(--primary-color);
            font-weight: 800;
            margin: 0;
            font-size: 2rem;
            letter-spacing: -0.025em;
        }
        
        .header-content .subtitle {
            color: var(--primary-light);
            font-size: 1rem;
            margin: 0.5rem 0 0 0;
            font-weight: 500;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: 1px solid rgba(255, 69, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--hover-shadow);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(to right, var(--primary-color), var(--primary-light));
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, rgba(255, 69, 0, 0.1), rgba(255, 107, 0, 0.1));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .stat-icon i {
            font-size: 24px;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.2;
        }
        
        /* Cards */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            margin-bottom: 1.5rem;
            overflow: hidden;
            background: white;
        }
        
        .card:hover {
            box-shadow: var(--hover-shadow);
        }
        
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--primary-light));
            color: white;
            border-bottom: none;
            padding: 1.25rem 1.75rem;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.025em;
        }
        
        .card-body {
            padding: 1.75rem;
        }
        
        /* Department Cards */
        .department-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        
        .department-card {
            height: 100%;
            border: 1px solid rgba(255, 69, 0, 0.1);
            border-radius: var(--border-radius);
            background: white;
            transition: var(--transition);
        }
        
        .department-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--hover-shadow);
        }
        
        .department-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            padding: 1.25rem;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .department-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
        }
        
        .subject-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.35rem 1rem;
            border-radius: 100px;
            font-size: 0.85rem;
            font-weight: 600;
            backdrop-filter: blur(4px);
        }
        
        .department-body {
            padding: 1.25rem;
        }
        
        /* Tables */
        .table-container {
            max-height: 350px;
            overflow-y: auto;
            border-radius: 12px;
            border: 1px solid rgba(255, 69, 0, 0.1);
            margin-bottom: 1rem;
        }
        
        .table {
            margin: 0;
            font-size: 0.9rem;
        }
        
        .table thead th {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            border: none;
            padding: 0.85rem 1rem;
            font-weight: 600;
            font-size: 0.85rem;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }
        
        .table tbody tr {
            transition: var(--transition);
        }
        
        .table tbody tr:hover {
            background-color: #fff5f0;
        }
        
        .table tbody td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(255, 69, 0, 0.1);
            vertical-align: middle;
        }
        
        /* Badges */
        .badge {
            padding: 0.4rem 0.85rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.025em;
        }
        
        .badge.bg-success {
            background: rgba(16, 185, 129, 0.1) !important;
            color: var(--success-color) !important;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .badge.bg-warning {
            background: rgba(245, 158, 11, 0.1) !important;
            color: var(--warning-color) !important;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .badge.bg-secondary {
            background: rgba(107, 114, 128, 0.1) !important;
            color: #6b7280 !important;
            border: 1px solid rgba(107, 114, 128, 0.2);
        }
        
        .badge.bg-info {
            background: rgba(59, 130, 246, 0.1) !important;
            color: var(--info-color) !important;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        /* Subject Link */
        .subject-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .subject-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        /* Buttons */
        .btn-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            border: none;
            border-radius: 10px;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(255, 69, 0, 0.2);
        }
        
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 69, 0, 0.3);
            color: white;
        }
        
        .btn-custom.btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            box-shadow: none;
        }
        
        .btn-custom.btn-outline:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
        }
        
        .btn-custom.btn-success {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }
        
        .btn-custom.btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        .btn-custom.btn-icon {
            padding: 0.5rem;
            border-radius: 8px;
        }
        
        /* Forms */
        .form-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select, .form-textarea {
            border: 2px solid #f3e8e2;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: var(--transition);
            background-color: white;
        }
        
        .form-control:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(255, 69, 0, 0.1);
            outline: none;
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        /* Alerts */
        .alert-custom {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--card-shadow);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: linear-gradient(to right, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            border-left: 4px solid var(--success-color);
            color: var(--success-color);
        }
        
        .alert-danger {
            background: linear-gradient(to right, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));
            border-left: 4px solid var(--danger-color);
            color: var(--danger-color);
        }
        
        .alert-info {
            background: linear-gradient(to right, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
            border-left: 4px solid var(--info-color);
            color: var(--info-color);
        }
        
        /* Detail View */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .detail-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid rgba(255, 69, 0, 0.1);
        }
        
        .detail-item {
            display: flex;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 69, 0, 0.1);
        }
        
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--primary-color);
            min-width: 140px;
            font-size: 0.9rem;
        }
        
        .detail-value {
            color: #4b5563;
            font-weight: 500;
        }
        
        /* Quick Stats */
        .quick-stats {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin: 1rem 0;
        }
        
        .quick-stat {
            background: rgba(255, 69, 0, 0.05);
            padding: 0.5rem 1rem;
            border-radius: 100px;
            font-size: 0.85rem;
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .quick-stat i {
            margin-right: 0.25rem;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px dashed rgba(255, 69, 0, 0.1);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #f3e8e2;
        }
        
        .empty-state p {
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* Animations */
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .animate__fadeIn {
            animation-duration: 0.5s;
        }
        
        .animate__pulse {
            animation: pulse 2s infinite;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 12px;
            }
            
            .page-header {
                padding: 1.25rem;
            }
            
            .header-content h1 {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .department-grid {
                grid-template-columns: 1fr;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-custom {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f3e8e2;
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary-color), var(--primary-light));
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, var(--primary-dark), var(--primary-color));
        }
    </style>
</head>
<body>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) {
        error_log("Admin header file missing: $header_path");
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin header template at $header_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin header template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $header_path; 
    ?>

    <div class="dashboard-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <h1><i class="fas fa-book-open me-2"></i> Subject Management</h1>
                <p class="subtitle">Add, view, and manage academic subjects across departments</p>
            </div>
            <?php if ($mode === 'list'): ?>
                <a href="./add_subject.php?mode=add" class="btn-custom">
                    <i class="fas fa-plus me-1"></i> Add New Subject
                </a>
            <?php endif; ?>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'list'): ?>
            <!-- Stats Cards -->
            <?php if (isset($stats)): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-label">Total Subjects</div>
                    <div class="stat-value"><?php echo number_format($stats['total_subjects'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">Active Subjects</div>
                    <div class="stat-value"><?php echo number_format($stats['active_subjects'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-label">Departments</div>
                    <div class="stat-value"><?php echo count($departments); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-label">With Teachers</div>
                    <div class="stat-value"><?php 
                        $with_teachers = 0;
                        foreach ($departments as $dept) {
                            foreach ($dept['subjects'] as $subj) {
                                if ($subj['teacher'] !== 'N/A' && $subj['teacher'] !== 'Not Assigned') {
                                    $with_teachers++;
                                }
                            }
                        }
                        echo number_format($with_teachers);
                    ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Subject List View -->
            <div class="card animate__animated animate__fadeIn">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-list me-2"></i> Subjects by Department</h5>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control form-control-sm" id="searchSubjects" placeholder="Search subjects..." style="width: 200px;">
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($departments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <p>No departments or subjects found.</p>
                            <a href="./add_subject.php?mode=add" class="btn-custom">
                                <i class="fas fa-plus me-1"></i> Add Your First Subject
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="department-grid">
                            <?php foreach ($departments as $dept_id => $dept): ?>
                                <div class="department-card" data-department="<?php echo htmlspecialchars($dept['name']); ?>">
                                    <div class="department-header">
                                        <h3><i class="fas fa-building me-2"></i><?php echo htmlspecialchars($dept['name']); ?></h3>
                                        <span class="subject-count">
                                            <i class="fas fa-book me-1"></i>
                                            <?php echo count($dept['subjects']); ?> Subjects
                                        </span>
                                    </div>
                                    <div class="department-body">
                                        <?php if (empty($dept['subjects'])): ?>
                                            <p class="text-muted text-center mb-3">No subjects assigned to this department</p>
                                        <?php else: ?>
                                            <div class="table-container">
                                                <table class="table">
                                                    <thead>
                                                        <tr>
                                                            <th>Subject</th>
                                                            <th>Code</th>
                                                            <?php if ($dept['has_teachers']): ?>
                                                                <th>Teacher</th>
                                                            <?php endif; ?>
                                                            <th>Stats</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($dept['subjects'] as $subject): ?>
                                                            <tr class="subject-row" data-subject-name="<?php echo strtolower($subject['name']); ?>" data-subject-code="<?php echo strtolower($subject['code']); ?>">
                                                                <td>
                                                                    <a href="./add_subject.php?mode=view&subject_id=<?php echo $subject['id']; ?>" class="subject-link">
                                                                        <i class="fas fa-book text-muted"></i>
                                                                        <?php echo htmlspecialchars($subject['name']); ?>
                                                                    </a>
                                                                    <span class="badge <?php echo $subject['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?> ms-1">
                                                                        <?php echo $subject['status'] === 'Active' ? 'Active' : 'Inactive'; ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-info">
                                                                        <?php echo htmlspecialchars($subject['code']); ?>
                                                                    </span>
                                                                </td>
                                                                <?php if ($dept['has_teachers']): ?>
                                                                    <td>
                                                                        <small>
                                                                            <?php if ($subject['teacher'] !== 'Not Assigned'): ?>
                                                                                <i class="fas fa-user-circle me-1"></i>
                                                                                <?php echo htmlspecialchars($subject['teacher']); ?>
                                                                            <?php else: ?>
                                                                                <span class="text-muted">Not Assigned</span>
                                                                            <?php endif; ?>
                                                                        </small>
                                                                    </td>
                                                                <?php endif; ?>
                                                                <td>
                                                                    <div class="quick-stats">
                                                                        <?php if ($subject['class_count'] > 0): ?>
                                                                            <span class="quick-stat" title="Classes">
                                                                                <i class="fas fa-users"></i>
                                                                                <?php echo $subject['class_count']; ?>
                                                                            </span>
                                                                        <?php endif; ?>
                                                                        <?php if ($subject['lesson_plan_count'] > 0): ?>
                                                                            <span class="quick-stat" title="Lesson Plans">
                                                                                <i class="fas fa-file-alt"></i>
                                                                                <?php echo $subject['lesson_plan_count']; ?>
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <div class="d-flex gap-1">
                                                                        <a href="./add_subject.php?mode=view&subject_id=<?php echo $subject['id']; ?>" class="btn-custom btn-sm btn-icon" title="View Details">
                                                                            <i class="fas fa-eye"></i>
                                                                        </a>
                                                                        <a href="./add_subject.php?mode=edit&subject_id=<?php echo $subject['id']; ?>" class="btn-custom btn-sm btn-icon btn-outline" title="Edit Subject">
                                                                            <i class="fas fa-edit"></i>
                                                                        </a>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                        <div class="text-center mt-3">
                                            <a href="./add_subject.php?mode=add&dept_id=<?php echo $dept_id; ?>" class="btn-custom btn-sm btn-outline">
                                                <i class="fas fa-plus me-1"></i> Add Subject to <?php echo htmlspecialchars($dept['name']); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($mode === 'add'): ?>
            <!-- Add Subject Form -->
            <div class="card animate__animated animate__fadeIn">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-plus-circle me-2"></i> Add New Subject</h5>
                        <a href="./add_subject.php?mode=list" class="btn-custom btn-outline btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to List
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($selected_dept)): ?>
                        <div class="alert alert-info alert-custom mb-4">
                            <i class="fas fa-info-circle me-2"></i>
                            Adding subject to department: <strong><?php echo htmlspecialchars($selected_dept['name']); ?></strong>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="needs-validation" novalidate id="addSubjectForm">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subject_name" class="form-label">
                                        <i class="fas fa-tag me-1"></i> Subject Name *
                                    </label>
                                    <input type="text" class="form-control" id="subject_name" name="subject_name" 
                                           value="<?php echo htmlspecialchars($subject_name ?? ''); ?>" 
                                           placeholder="e.g., Mathematics" required maxlength="100">
                                    <div class="invalid-feedback">Please enter a subject name (max 100 characters).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subject_code" class="form-label">
                                        <i class="fas fa-barcode me-1"></i> Subject Code *
                                    </label>
                                    <input type="text" class="form-control" id="subject_code" name="subject_code" 
                                           value="<?php echo htmlspecialchars($subject_code ?? ''); ?>" 
                                           placeholder="e.g., MATH-101" required maxlength="20">
                                    <small class="text-muted">Uppercase letters, numbers, and hyphens only</small>
                                    <div class="invalid-feedback">Please enter a valid subject code (max 20 characters).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="department_id" class="form-label">
                                        <i class="fas fa-building me-1"></i> Department
                                    </label>
                                    <select name="department_id" id="department_id" class="form-select">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments_list as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" 
                                                <?php echo ($department_id === $dept['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on me-1"></i> Status *
                                    </label>
                                    <select name="status" id="status" class="form-select" required>
                                        <option value="Active" <?php echo (isset($status) && $status === 'Active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo (isset($status) && $status === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a status.</div>
                                </div>
                            </div>
                            <div class="col-md-6" id="teacher_id_field">
                                <div class="mb-3">
                                    <label for="teacher_id" class="form-label">
                                        <i class="fas fa-chalkboard-teacher me-1"></i> Teacher
                                    </label>
                                    <select name="teacher_id" id="teacher_id" class="form-select">
                                        <option value="">Select Teacher</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>" 
                                                data-department="<?php echo $teacher['department_id']; ?>"
                                                <?php echo isset($teacher_id) && $teacher_id === $teacher['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="mb-3">
                                    <label for="description" class="form-label">
                                        <i class="fas fa-align-left me-1"></i> Description
                                    </label>
                                    <textarea class="form-control form-textarea" id="description" name="description" 
                                              rows="4" placeholder="Enter subject description (optional)..."
                                              maxlength="1000"><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                                    <small class="text-muted float-end" id="charCount">0/1000 characters</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn-custom btn-success">
                                <i class="fas fa-save me-1"></i> Save Subject
                            </button>
                            <a href="./add_subject.php?mode=list" class="btn-custom btn-outline">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($mode === 'view' && isset($subject)): ?>
            <!-- View Subject Details -->
            <div class="card animate__animated animate__fadeIn">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-eye me-2"></i> Subject Details</h5>
                        <div class="d-flex gap-2">
                            <a href="./add_subject.php?mode=edit&subject_id=<?php echo $subject['id']; ?>" class="btn-custom btn-sm">
                                <i class="fas fa-edit me-1"></i> Edit Subject
                            </a>
                            <a href="./add_subject.php?mode=list" class="btn-custom btn-outline btn-sm">
                                <i class="fas fa-arrow-left me-1"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($error_msg): ?>
                        <div class="alert alert-danger alert-custom animate__animated animate__shakeX">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error_msg); ?>
                        </div>
                    <?php else: ?>
                        <div class="detail-grid">
                            <div class="detail-card">
                                <h6 class="mb-3" style="color: var(--primary-color);">
                                    <i class="fas fa-info-circle me-2"></i>Basic Information
                                </h6>
                                <div class="detail-item">
                                    <span class="detail-label">Subject Name:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Subject Code:</span>
                                    <span class="detail-value">
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($subject['subject_code']); ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Department:</span>
                                    <span class="detail-value">
                                        <?php if ($subject['department_name']): ?>
                                            <span class="badge bg-warning">
                                                <?php echo htmlspecialchars($subject['department_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Not Assigned</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Status:</span>
                                    <span class="detail-value">
                                        <span class="badge <?php echo $subject['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <i class="fas <?php echo $subject['status'] === 'Active' ? 'fa-check-circle' : 'fa-times-circle'; ?> me-1"></i>
                                            <?php echo htmlspecialchars($subject['status']); ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="detail-card">
                                <h6 class="mb-3" style="color: var(--primary-color);">
                                    <i class="fas fa-chart-bar me-2"></i>Statistics
                                </h6>
                                <div class="detail-item">
                                    <span class="detail-label">Classes:</span>
                                    <span class="detail-value">
                                        <span class="badge bg-info">
                                            <i class="fas fa-users me-1"></i>
                                            <?php echo $subject['class_count'] ?? 0; ?> Classes
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Lesson Plans:</span>
                                    <span class="detail-value">
                                        <span class="badge bg-success">
                                            <i class="fas fa-file-alt me-1"></i>
                                            <?php echo $subject['lesson_plan_count'] ?? 0; ?> Plans
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Assignments:</span>
                                    <span class="detail-value">
                                        <span class="badge bg-warning">
                                            <i class="fas fa-tasks me-1"></i>
                                            <?php echo $subject['assignment_count'] ?? 0; ?> Assignments
                                        </span>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Created:</span>
                                    <span class="detail-value">
                                        <i class="fas fa-calendar me-1"></i>
                                        <?php echo date('M d, Y', strtotime($subject['created_at'])); ?>
                                    </span>
                                </div>
                                <?php if ($subject['updated_at']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Last Updated:</span>
                                    <span class="detail-value">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('M d, Y', strtotime($subject['updated_at'])); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="detail-card">
                                <h6 class="mb-3" style="color: var(--primary-color);">
                                    <i class="fas fa-user me-2"></i>Assignment
                                </h6>
                                <div class="detail-item">
                                    <span class="detail-label">Teacher:</span>
                                    <span class="detail-value">
                                        <?php if ($subject['teacher_name'] && $subject['teacher_name'] !== 'N/A'): ?>
                                            <div>
                                                <i class="fas fa-user-circle me-1"></i>
                                                <?php echo htmlspecialchars($subject['teacher_name']); ?>
                                                <?php if ($subject['teacher_email']): ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($subject['teacher_email']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Not Assigned</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if (!empty($subject['description'])): ?>
                            <div class="detail-card" style="grid-column: 1 / -1;">
                                <h6 class="mb-3" style="color: var(--primary-color);">
                                    <i class="fas fa-align-left me-2"></i>Description
                                </h6>
                                <div class="p-3 bg-light rounded">
                                    <?php echo nl2br(htmlspecialchars($subject['description'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($subject['assigned_classes'])): ?>
                            <div class="detail-card" style="grid-column: 1 / -1;">
                                <h6 class="mb-3" style="color: var(--primary-color);">
                                    <i class="fas fa-users me-2"></i>Assigned Classes (<?php echo count($subject['assigned_classes']); ?>)
                                </h6>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Class</th>
                                                <th>Class Teacher</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($subject['assigned_classes'] as $class): ?>
                                            <tr>
                                                <td>
                                                    <i class="fas fa-users me-1"></i>
                                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                                </td>
                                                <td>
                                                    <?php if ($class['class_teacher_name']): ?>
                                                        <?php echo htmlspecialchars($class['class_teacher_name']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not Assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="action-buttons">
                            <a href="./add_subject.php?mode=edit&subject_id=<?php echo $subject['id']; ?>" class="btn-custom">
                                <i class="fas fa-edit me-1"></i> Edit Subject
                            </a>
                            <a href="./add_subject.php?mode=list" class="btn-custom btn-outline">
                                <i class="fas fa-arrow-left me-1"></i> Back to Subjects
                            </a>
                            <a href="./add_subject.php?mode=add" class="btn-custom btn-success">
                                <i class="fas fa-plus me-1"></i> Add Another Subject
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($mode === 'edit' && isset($subject)): ?>
            <!-- Edit Subject Form -->
            <div class="card animate__animated animate__fadeIn">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-edit me-2"></i> Edit Subject: <?php echo htmlspecialchars($subject['subject_name']); ?></h5>
                        <a href="./add_subject.php?mode=view&subject_id=<?php echo $subject['id']; ?>" class="btn-custom btn-outline btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Details
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" class="needs-validation" novalidate id="editSubjectForm">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subject_name" class="form-label">
                                        <i class="fas fa-tag me-1"></i> Subject Name *
                                    </label>
                                    <input type="text" class="form-control" id="subject_name" name="subject_name" 
                                           value="<?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?>" 
                                           required maxlength="100">
                                    <div class="invalid-feedback">Please enter a subject name (max 100 characters).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subject_code" class="form-label">
                                        <i class="fas fa-barcode me-1"></i> Subject Code *
                                    </label>
                                    <input type="text" class="form-control" id="subject_code" name="subject_code" 
                                           value="<?php echo htmlspecialchars($subject['subject_code'] ?? ''); ?>" 
                                           required maxlength="20">
                                    <small class="text-muted">Uppercase letters, numbers, and hyphens only</small>
                                    <div class="invalid-feedback">Please enter a valid subject code (max 20 characters).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="department_id" class="form-label">
                                        <i class="fas fa-building me-1"></i> Department
                                    </label>
                                    <select name="department_id" id="department_id" class="form-select">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments_list as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" 
                                                <?php echo ($subject['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">
                                        <i class="fas fa-toggle-on me-1"></i> Status *
                                    </label>
                                    <select name="status" id="status" class="form-select" required>
                                        <option value="Active" <?php echo ($subject['status'] ?? '') === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo ($subject['status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a status.</div>
                                </div>
                            </div>
                            <div class="col-md-6" id="teacher_id_field">
                                <div class="mb-3">
                                    <label for="teacher_id" class="form-label">
                                        <i class="fas fa-chalkboard-teacher me-1"></i> Teacher
                                    </label>
                                    <select name="teacher_id" id="teacher_id" class="form-select">
                                        <option value="">Select Teacher</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>" 
                                                data-department="<?php echo $teacher['department_id']; ?>"
                                                <?php echo ($subject['teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="mb-3">
                                    <label for="description" class="form-label">
                                        <i class="fas fa-align-left me-1"></i> Description
                                    </label>
                                    <textarea class="form-control form-textarea" id="description" name="description" 
                                              rows="4" maxlength="1000"><?php echo htmlspecialchars($subject['description'] ?? ''); ?></textarea>
                                    <small class="text-muted float-end" id="charCount">0/1000 characters</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <button type="submit" class="btn-custom btn-success">
                                <i class="fas fa-save me-1"></i> Update Subject
                            </button>
                            <a href="./add_subject.php?mode=view&subject_id=<?php echo $subject['id']; ?>" class="btn-custom btn-outline">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Admin footer file missing: $footer_path");
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin footer template at $footer_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin footer template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    $conn->close();
    ?>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Form validation
            const addForm = document.getElementById('addSubjectForm');
            const editForm = document.getElementById('editSubjectForm');
            
            if (addForm) {
                addForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    addForm.classList.add('was-validated');
                    
                    if (addForm.checkValidity()) {
                        addForm.submit();
                    }
                }, false);
            }
            
            if (editForm) {
                editForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    editForm.classList.add('was-validated');
                    
                    if (editForm.checkValidity()) {
                        editForm.submit();
                    }
                }, false);
            }

            // Dynamically show/hide teacher field based on department selection
            const deptSelect = document.getElementById('department_id');
            const teacherField = document.getElementById('teacher_id_field');
            const teacherSelect = document.getElementById('teacher_id');
            const noTeacherDepts = <?php echo json_encode($no_teacher_depts); ?>;
            
            function toggleTeacherField() {
                if (deptSelect && teacherField && teacherSelect) {
                    const selectedDeptId = parseInt(deptSelect.value);
                    
                    if (selectedDeptId && noTeacherDepts.includes(selectedDeptId)) {
                        teacherField.style.opacity = '0.5';
                        teacherField.style.pointerEvents = 'none';
                        teacherSelect.value = '';
                    } else {
                        teacherField.style.opacity = '1';
                        teacherField.style.pointerEvents = 'auto';
                        
                        // Filter teachers by department if needed
                        if (selectedDeptId) {
                            Array.from(teacherSelect.options).forEach(option => {
                                if (option.value === '') return;
                                const teacherDept = option.getAttribute('data-department');
                                if (teacherDept && parseInt(teacherDept) !== selectedDeptId) {
                                    option.style.display = 'none';
                                } else {
                                    option.style.display = '';
                                }
                            });
                        } else {
                            Array.from(teacherSelect.options).forEach(option => {
                                option.style.display = '';
                            });
                        }
                    }
                }
            }
            
            if (deptSelect && teacherField) {
                deptSelect.addEventListener('change', toggleTeacherField);
                toggleTeacherField(); // Initialize on page load
            }

            // Auto-format subject code to uppercase
            const subjectCodeInputs = document.querySelectorAll('#subject_code');
            subjectCodeInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
                });
            });

            // Character counter for description
            const descriptionInputs = document.querySelectorAll('#description');
            descriptionInputs.forEach(input => {
                const counter = input.closest('.mb-3').querySelector('#charCount');
                if (counter) {
                    const updateCount = () => {
                        const count = input.value.length;
                        counter.textContent = count + '/1000 characters';
                        counter.style.color = count > 900 ? 'var(--danger-color)' : 'var(--primary-color)';
                    };
                    
                    input.addEventListener('input', updateCount);
                    updateCount(); // Initialize
                }
            });

            // Search functionality for subjects
            const searchInput = document.getElementById('searchSubjects');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase().trim();
                    
                    document.querySelectorAll('.subject-row').forEach(row => {
                        const subjectName = row.getAttribute('data-subject-name') || '';
                        const subjectCode = row.getAttribute('data-subject-code') || '';
                        
                        if (subjectName.includes(searchTerm) || subjectCode.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                    
                    // Hide empty departments
                    document.querySelectorAll('.department-card').forEach(card => {
                        const visibleRows = card.querySelectorAll('.subject-row:not([style*="display: none"])').length;
                        if (visibleRows === 0) {
                            card.style.display = 'none';
                        } else {
                            card.style.display = '';
                        }
                    });
                });
            }

            // Add animation to cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate__animated', 'animate__fadeIn');
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.department-card, .stat-card, .detail-card').forEach(card => {
                observer.observe(card);
            });
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>