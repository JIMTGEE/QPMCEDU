<?php
ob_start();
session_start();

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to add_user.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
    $_SESSION['error_msg'] = "Access denied. You must be an admin to access this page.";
    header("Location: /login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Include log_activity function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("issss", $user_id, $action, $description, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
} else {
    require_once($log_path);
}

// Verify database connection
if (!isset($conn) || !$conn) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "Database connection failed. Please try again later.";
    header("Location: /login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
if (!$stmt) {
    error_log("Prepare failed for CSRF token: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to generate CSRF token.";
    header("Location: /admin/user_management.php");
    exit();
}
$stmt->bind_param("is", $admin_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Define upload paths
define('UPLOAD_DIR', $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/');
define('UPLOAD_URL', '/Uploads/profiles/');

// Initialize variables
$error_msg = "";
$success_msg = "";
$name = $email = $role = $status = $phone_number = $national_id = $admission_date = $department = $gender = $phone = $address = "";
$department_id = $advisor_id = $class_id = $academic_year = null;
$parent_name = $parent_email = $parent_phone = $parent_national_id = $parent_password = "";
$force_password_change = 1;

// New student fields
$previous_school = $date_of_birth = $nationality = $allergy = $special_child = $parent_occupation = "";
$parent_office_location = $office_address = $residential_address = $emergency_contact_name = $emergency_contact_phone = "";
$link_existing_parent = 0;
$selected_parent_ids = [];

// Fetch departments for dropdown
$departments = [];
try {
    $result = $conn->query("SELECT id, name FROM departments ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
} catch (Exception $e) {
    error_log("Error fetching departments: " . $e->getMessage());
    $error_msg = "Error loading departments.";
}

// Fetch teachers for advisor dropdown
$teachers = [];
try {
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $teachers[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching teachers: " . $e->getMessage());
    $error_msg = "Error loading advisors.";
}

// Fetch classes for student
$classes = [];
try {
    $stmt = $conn->prepare("SELECT id, class_name FROM classes ORDER BY class_name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching classes: " . $e->getMessage());
    $error_msg = "Error loading classes.";
}

// Fetch academic years
$academic_years = [];
try {
    $result = $conn->query("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
    while ($row = $result->fetch_assoc()) {
        $academic_years[] = $row['year'];
    }
} catch (Exception $e) {
    error_log("Error fetching academic years: " . $e->getMessage());
    $error_msg = "Error loading academic years.";
}

// Fetch existing parents for linking
$existing_parents = [];
try {
    $stmt = $conn->prepare("SELECT id, name, email, phone FROM users WHERE role = 'parent' ORDER BY name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existing_parents[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching parents: " . $e->getMessage());
    $error_msg = "Error loading parents.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $stmt = $conn->prepare("SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $admin_id, $posted_csrf);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        error_log("Invalid CSRF token for user_id: $admin_id");
        $_SESSION['error_msg'] = "Invalid or expired CSRF token.";
        header("Location: /admin/user_management.php");
        exit();
    }
    $stmt->close();

    // Collect form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $phone_number = ($role === 'student') ? trim($_POST['phone_number'] ?? '') : '';
    $parent_national_id = ($role === 'student') ? trim($_POST['parent_national_id'] ?? '') : '';
    $admission_date = trim($_POST['admission_date'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $phone = ($role !== 'student') ? trim($_POST['phone'] ?? '') : '';
    $address = trim($_POST['address'] ?? '');
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $force_password_change = isset($_POST['force_password_change']) ? 1 : 0;
    $advisor_id = !empty($_POST['advisor_id']) ? (int)$_POST['advisor_id'] : null;
    $class_id = ($role === 'student' && !empty($_POST['class_id'])) ? (int)$_POST['class_id'] : null;
    $academic_year = ($role === 'student' && !empty($_POST['academic_year'])) ? trim($_POST['academic_year']) : null;
    
    // New student fields
    $previous_school = ($role === 'student') ? trim($_POST['previous_school'] ?? '') : '';
    $date_of_birth = ($role === 'student') ? trim($_POST['date_of_birth'] ?? '') : '';
    $nationality = ($role === 'student') ? trim($_POST['nationality'] ?? '') : '';
    $allergy = ($role === 'student') ? trim($_POST['allergy'] ?? '') : '';
    $special_child = ($role === 'student') ? trim($_POST['special_child'] ?? 'no') : 'no';
    $parent_occupation = ($role === 'student') ? trim($_POST['parent_occupation'] ?? '') : '';
    $parent_office_location = ($role === 'student') ? trim($_POST['parent_office_location'] ?? '') : '';
    $office_address = ($role === 'student') ? trim($_POST['office_address'] ?? '') : '';
    $residential_address = ($role === 'student') ? trim($_POST['residential_address'] ?? '') : '';
    $emergency_contact_name = ($role === 'student') ? trim($_POST['emergency_contact_name'] ?? '') : '';
    $emergency_contact_phone = ($role === 'student') ? trim($_POST['emergency_contact_phone'] ?? '') : '';
    
    // Parent linking
    $link_existing_parent = isset($_POST['link_existing_parent']) ? 1 : 0;
    $selected_parent_ids = isset($_POST['selected_parents']) ? $_POST['selected_parents'] : [];
    
    $parent_name = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_name'] ?? '') : '';
    $parent_email = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_email'] ?? '') : '';
    $parent_phone = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_phone'] ?? '') : '';
    $parent_password = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_password'] ?? '') : '';

    // Handle profile picture upload for students
    $profile_picture = '/assets/images/default_profile.png';
    if ($role === 'student' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (!is_dir(UPLOAD_DIR)) {
            if (!mkdir(UPLOAD_DIR, 0755, true)) {
                error_log("Failed to create upload directory: " . UPLOAD_DIR);
                $error_msg = "Failed to create upload directory. Contact support.";
            }
        }
        if (!is_writable(UPLOAD_DIR)) {
            error_log("Upload directory not writable: " . UPLOAD_DIR);
            $error_msg = "Upload directory is not writable. Contact support.";
        } else {
            $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
            $max_size = 5 * 1024 * 1024; // 5MB
            $min_size = 100; // 100 bytes
            $file_tmp = $_FILES['profile_picture']['tmp_name'];

            if (!in_array($ext, $allowed_exts)) {
                $error_msg = "Invalid file type for profile picture. Allowed types: jpg, jpeg, png, gif.";
                error_log("Invalid file type attempted: $ext, tmp_name: $file_tmp");
            } elseif ($_FILES['profile_picture']['size'] > $max_size) {
                $error_msg = "Profile picture exceeds 5MB limit.";
                error_log("File size too large: " . $_FILES['profile_picture']['size']);
            } elseif ($_FILES['profile_picture']['size'] < $min_size) {
                $error_msg = "Profile picture file is too small or corrupt.";
                error_log("File too small: " . $_FILES['profile_picture']['size'] . ", tmp_name: $file_tmp");
            } elseif ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
                $error_msg = "Upload error: " . $_FILES['profile_picture']['error'];
                error_log("Upload error code: " . $_FILES['profile_picture']['error'] . ", tmp_name: $file_tmp");
            } else {
                $image_info = @getimagesize($file_tmp);
                if ($image_info === false || !in_array($image_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
                    $error_msg = "Uploaded file is not a valid image.";
                    error_log("Invalid image file, tmp_name: $file_tmp, mime: " . ($_FILES['profile_picture']['type'] ?? 'unknown'));
                } else {
                    $new_filename = "user_new_" . time() . "." . $ext;
                    $upload_path = UPLOAD_DIR . $new_filename;
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        if (file_exists($upload_path) && filesize($upload_path) >= $min_size) {
                            $profile_picture = UPLOAD_URL . $new_filename;
                        } else {
                            $error_msg = "Failed to verify uploaded file or file is corrupt.";
                            error_log("Uploaded file invalid, path: $upload_path, size: " . (file_exists($upload_path) ? filesize($upload_path) : 'N/A'));
                        }
                    } else {
                        $error_msg = "Failed to upload profile picture. Error code: " . $_FILES['profile_picture']['error'];
                        error_log("Failed to move uploaded file, error: " . $_FILES['profile_picture']['error'] . ", tmp_name: $file_tmp, dest: $upload_path");
                    }
                }
            }
        }
    }

    // Validate form data
    $errors = [];
    if (empty($name)) $errors[] = "Name is required.";
    if (strlen($name) > 100) $errors[] = "Name must be 100 characters or less.";
    if (empty($email)) $errors[] = "Email is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";
    if (empty($password)) $errors[] = "Password is required.";
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    if (empty($role)) $errors[] = "Role is required.";
    if (!in_array($role, ['admin', 'teacher', 'student', 'parent'])) $errors[] = "Invalid role selected.";
    if (empty($status)) $errors[] = "Status is required.";
    if (!in_array($status, ['active', 'inactive', 'suspended', 'graduated'])) $errors[] = "Invalid status selected.";
    if (!empty($phone_number) && !preg_match('/^\+?\d{10,15}$/', $phone_number)) $errors[] = "Invalid phone number format.";
    if ($role !== 'student' && empty($phone)) $errors[] = "Phone is required for non-students.";
    if (!empty($phone) && !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = "Invalid phone format.";
    if ($role === 'student' && !empty($parent_national_id) && strlen($parent_national_id) > 100) $errors[] = "Parent National ID must be 100 characters or less.";
    if (!empty($admission_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $admission_date)) $errors[] = "Invalid admission date format.";
    if (!empty($date_of_birth) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) $errors[] = "Invalid date of birth format.";
    if (empty($gender)) $errors[] = "Gender is required.";
    if (!in_array($gender, ['male', 'female', 'other'])) $errors[] = "Invalid gender selected.";
    if (empty($address)) $errors[] = "Address is required.";
    if (!empty($emergency_contact_phone) && !preg_match('/^\+?\d{10,15}$/', $emergency_contact_phone)) $errors[] = "Invalid emergency contact phone format.";
    
    if ($role === 'student') {
        if (empty($class_id) || empty($academic_year)) {
            $errors[] = "Class and academic year are required for students.";
        }
        if (!in_array($academic_year, $academic_years)) {
            $errors[] = "Invalid academic year selected.";
        }
        
        // Validate parent information
        if ($link_existing_parent) {
            if (empty($selected_parent_ids)) {
                $errors[] = "Please select at least one parent to link.";
            }
        } else {
            if (empty($parent_name)) $errors[] = "Parent name is required for students.";
            if (strlen($parent_name) > 100) $errors[] = "Parent name must be 100 characters or less.";
            if (empty($parent_email)) $errors[] = "Parent email is required for students.";
            if (!filter_var($parent_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid parent email format.";
            if (empty($parent_phone)) $errors[] = "Parent phone is required for students.";
            if (!preg_match('/^\+?\d{10,15}$/', $parent_phone)) $errors[] = "Invalid parent phone format.";
            if (empty($parent_password)) $errors[] = "Parent password is required for students.";
            if (strlen($parent_password) < 8) $errors[] = "Parent password must be at least 8 characters.";
        }
    }

    // Process form if no errors
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Check for duplicate email
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                throw new Exception("Email already exists.");
            }
            $stmt->close();

            // Insert new user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $national_id = ''; // National ID not used for non-parents
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, phone_number, national_id, admission_date, department, gender, phone, address, profile_picture, department_id, force_password_change, advisor_id, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ssssssssssssiiii", $name, $email, $hashed_password, $role, $status, $phone_number, $national_id, $admission_date, $department, $gender, $phone, $address, $profile_picture, $department_id, $force_password_change, $advisor_id);
            $stmt->execute();
            $user_id = $conn->insert_id;
            $stmt->close();

            // Insert student details if role is student
            if ($role === 'student') {
                $student_stmt = $conn->prepare("INSERT INTO students (user_id, student_name, previous_school, date_of_birth, nationality, allergy, special_child, parent_occupation, parent_office_location, office_address, residential_address, emergency_contact_name, emergency_contact_phone, academic_year, created_at) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                if (!$student_stmt) {
                    throw new Exception("Prepare failed for student details: " . $conn->error);
                }
                $student_stmt->bind_param("isssssssssssss", $user_id, $name, $previous_school, $date_of_birth, $nationality, $allergy, $special_child, $parent_occupation, $parent_office_location, $office_address, $residential_address, $emergency_contact_name, $emergency_contact_phone, $academic_year);
                $student_stmt->execute();
                $student_stmt->close();
            }

            // Handle parent for students
            $parent_ids_to_link = [];
            if ($role === 'student') {
                if ($link_existing_parent) {
                    // Link to existing parents
                    $parent_ids_to_link = $selected_parent_ids;
                } else {
                    // Check for duplicate parent email
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->bind_param("s", $parent_email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $parent_id = null;
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $parent_id = $row['id'];
                    }
                    $stmt->close();

                    if (!$parent_id) {
                        // Insert new parent
                        $hashed_parent_password = password_hash($parent_password, PASSWORD_DEFAULT);
                        $parent_insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, gender, phone, address, national_id, created_at) 
                                                              VALUES (?, ?, ?, 'parent', 'active', 'other', ?, ?, ?, NOW())");
                        if (!$parent_insert_stmt) {
                            throw new Exception("Prepare failed for parent: " . $conn->error);
                        }
                        $parent_insert_stmt->bind_param("ssssss", $parent_name, $parent_email, $hashed_parent_password, $parent_phone, $address, $parent_national_id);
                        $parent_insert_stmt->execute();
                        $parent_id = $conn->insert_id;
                        $parent_insert_stmt->close();
                    }
                    $parent_ids_to_link[] = $parent_id;
                }

                // Link student to parent(s)
                foreach ($parent_ids_to_link as $parent_id) {
                    // Check if link already exists
                    $check_stmt = $conn->prepare("SELECT id FROM parent_student_mapping WHERE parent_id = ? AND student_id = ?");
                    $check_stmt->bind_param("ii", $parent_id, $user_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows === 0) {
                        $link_stmt = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
                        if (!$link_stmt) {
                            throw new Exception("Prepare failed for parent-student link: " . $conn->error);
                        }
                        $link_stmt->bind_param("ii", $parent_id, $user_id);
                        $link_stmt->execute();
                        $link_stmt->close();
                    }
                    $check_stmt->close();
                }
            }

            // Handle student class assignment
            if ($role === 'student' && $class_id && $academic_year) {
                $assign_stmt = $conn->prepare("INSERT INTO student_classes (user_id, class_id, academic_year, created_at) VALUES (?, ?, ?, NOW())");
                if (!$assign_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $assign_stmt->bind_param("iis", $user_id, $class_id, $academic_year);
                $assign_stmt->execute();
                $assign_stmt->close();
            }

            $parent_info = ($role === 'student') ? ", linked to " . count($parent_ids_to_link) . " parent(s)" : "";
            logActivity($conn, $admin_id, "Added User", "Admin added user ID: $user_id, role: $role" . $parent_info);
            $conn->commit();
            $_SESSION['success_msg'] = "User added successfully.";
            header("Location: /admin/user_management.php?success=" . urlencode("User added successfully."));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error adding user: " . $e->getMessage());
            $error_msg = "Error adding user: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $error_msg = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Add new user to QueensPa School Management System.">
    <meta name="keywords" content="QueensPa School, user management, admin dashboard, add user">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Add User">
    <meta property="og:description" content="Admin interface for adding new users to QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/add_user.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Add User">
    <meta name="twitter:description" content="Admin interface for adding new users to QueensPa School.">
    <title>Add User - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous">
    <style>
        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --secondary: #1f2937;
            --success: #10b981;
            --error: #ef4444;
        }
        body {
            font-family: 'Inter', sans-serif;
        }
        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #1f2937;
            position: absolute;
            top: 0;
            left: 1rem;
            transform: translateY(-50%);
            background: white;
            padding: 0 0.25rem;
            transition: all 0.2s ease;
        }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }
        .form-input:valid, .form-select:valid {
            border-color: var(--success);
        }
        .form-input:invalid:not(:placeholder-shown), .form-select:invalid:not(:placeholder-shown) {
            border-color: var(--error);
        }
        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .form-input:not(:placeholder-shown) + .form-label,
        .form-input:focus + .form-label {
            transform: translateY(-1.75rem);
            font-size: 0.75rem;
            color: var(--primary);
        }
        .profile-img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
        }
        .error-text {
            color: var(--error);
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        .section-card {
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        details summary {
            cursor: pointer;
            font-weight: 600;
            color: var(--primary);
            padding: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        details summary::marker {
            color: var(--primary);
        }
        .tooltip {
            position: relative;
        }
        .tooltip .tooltip-text {
            visibility: hidden;
            width: 160px;
            background-color: #374151;
            color: white;
            text-align: center;
            border-radius: 0.375rem;
            padding: 0.5rem;
            position: absolute;
            z-index: 10;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.75rem;
        }
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        .loading-spinner {
            display: none;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin-left: 0.5rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .parent-selector {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.5rem;
        }
        .parent-item {
            padding: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .parent-item:hover {
            background-color: #f3f4f6;
        }
        .parent-item:last-child {
            border-bottom: none;
        }
        @media (max-width: 640px) {
            .form-label {
                font-size: 0.75rem;
            }
            .form-input, .form-select, .form-textarea {
                font-size: 0.875rem;
                padding: 0.5rem;
            }
            .profile-img {
                width: 60px;
                height: 60px;
            }
            .section-card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-gradient-to-r from-orange-500 to-orange-700 text-white p-4 shadow-lg sticky top-0 z-10">
        <div class="container mx-auto flex flex-col sm:flex-row justify-between items-center gap-4">
            <h1 class="text-2xl font-bold flex items-center">
                <i class="fas fa-user-plus mr-2"></i>Add New User
            </h1>
            <nav>
                <a href="/admin/user_management.php" class="text-sm hover:text-orange-200 transition duration-200">Back to User Management</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto p-4 sm:p-6 flex-grow">
        <div class="section-card">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Add New User</h2>
            <?php if (!empty($error_msg)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded animate-slide-in" role="alert">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($success_msg)): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded animate-slide-in" role="alert">
                    <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2" id="addUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                
                <!-- User Details Section -->
                <details open class="section-card col-span-2">
                    <summary><i class="fas fa-user"></i>User Details</summary>
                    <div class="grid gap-4 sm:grid-cols-2 p-4">
                        <div class="form-group">
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="name" class="form-label">Full Name <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="email" class="form-label">Email <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group tooltip">
                            <input type="password" id="password" name="password" class="form-input" placeholder=" " required aria-required="true" minlength="8">
                            <label for="password" class="form-label">Password <span class="text-red-500">*</span></label>
                            <span class="tooltip-text">At least 8 characters, including letters and numbers</span>
                        </div>
                        <div class="form-group">
                            <select id="role" name="role" class="form-select" required aria-required="true">
                                <option value="" disabled selected>Select Role</option>
                                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="teacher" <?php echo $role === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                <option value="student" <?php echo $role === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="parent" <?php echo $role === 'parent' ? 'selected' : ''; ?>>Parent</option>
                            </select>
                            <label for="role" class="form-label">Role <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <select id="status" name="status" class="form-select" required aria-required="true">
                                <option value="" disabled selected>Select Status</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="graduated" <?php echo $status === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                            </select>
                            <label for="status" class="form-label">Status <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group" id="phone_number_div" style="display: none;">
                            <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone_number, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="phone_number" class="form-label">Phone Number (Optional for Students)</label>
                        </div>
                        <div class="form-group" id="phone_div">
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="phone" class="form-label">Phone (Required for Non-Students) <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group" id="profile_picture_div" style="display: none;">
                            <label for="profile_picture" class="form-label">Profile Picture (Optional for Students)</label>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif" class="form-input">
                            <img id="profile_preview" src="/assets/images/default_profile.png" alt="Profile Preview" class="profile-img mt-2 hidden">
                        </div>
                        <div class="form-group">
                            <input type="date" id="admission_date" name="admission_date" value="<?php echo htmlspecialchars($admission_date, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="admission_date" class="form-label">Admission Date</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="department" name="department" value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="department" class="form-label">Department (Text)</label>
                        </div>
                        <div class="form-group" id="department_id_div" style="display: none;">
                            <select id="department_id" name="department_id" class="form-select">
                                <option value="" disabled selected>Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo (int)$dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="department_id" class="form-label">Department (Select)</label>
                        </div>
                        <div class="form-group">
                            <select id="gender" name="gender" class="form-select" required aria-required="true">
                                <option value="" disabled selected>Select Gender</option>
                                <option value="male" <?php echo $gender === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $gender === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo $gender === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <label for="gender" class="form-label">Gender <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group" id="advisor_id_div" style="display: none;">
                            <select id="advisor_id" name="advisor_id" class="form-select">
                                <option value="" disabled selected>Select Advisor</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo (int)$teacher['id']; ?>" <?php echo $advisor_id == $teacher['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="advisor_id" class="form-label">Advisor</label>
                        </div>
                        <div class="form-group" id="class_id_div" style="display: none;">
                            <select id="class_id" name="class_id" class="form-select">
                                <option value="" disabled selected>Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="class_id" class="form-label">Class</label>
                        </div>
                        <div class="form-group" id="academic_year_div" style="display: none;">
                            <select id="academic_year" name="academic_year" class="form-select">
                                <option value="" disabled selected>Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $academic_year === $year ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="academic_year" class="form-label">Academic Year</label>
                        </div>
                    </div>
                </details>

                <!-- Student Specific Details Section -->
                <details id="student_details_div" class="section-card col-span-2" style="display: none;">
                    <summary><i class="fas fa-graduation-cap"></i>Student Information</summary>
                    <div class="grid gap-4 sm:grid-cols-2 p-4">
                        <div class="form-group">
                            <input type="text" id="previous_school" name="previous_school" value="<?php echo htmlspecialchars($previous_school, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="previous_school" class="form-label">Previous School</label>
                        </div>
                        <div class="form-group">
                            <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($date_of_birth, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($nationality, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="nationality" class="form-label">Nationality</label>
                        </div>
                        <div class="form-group">
                            <select id="special_child" name="special_child" class="form-select">
                                <option value="no" <?php echo $special_child === 'no' ? 'selected' : ''; ?>>No</option>
                                <option value="yes" <?php echo $special_child === 'yes' ? 'selected' : ''; ?>>Yes</option>
                            </select>
                            <label for="special_child" class="form-label">Special Child</label>
                        </div>
                        <div class="form-group col-span-2">
                            <textarea id="allergy" name="allergy" class="form-textarea" rows="2" placeholder=" "><?php echo htmlspecialchars($allergy, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <label for="allergy" class="form-label">Allergies / Medical Conditions</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="parent_occupation" name="parent_occupation" value="<?php echo htmlspecialchars($parent_occupation, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="parent_occupation" class="form-label">Parent Occupation</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="parent_office_location" name="parent_office_location" value="<?php echo htmlspecialchars($parent_office_location, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="parent_office_location" class="form-label">Parent Office Location</label>
                        </div>
                        <div class="form-group col-span-2">
                            <textarea id="office_address" name="office_address" class="form-textarea" rows="2" placeholder=" "><?php echo htmlspecialchars($office_address, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <label for="office_address" class="form-label">Office Address</label>
                        </div>
                        <div class="form-group col-span-2">
                            <textarea id="residential_address" name="residential_address" class="form-textarea" rows="2" placeholder=" "><?php echo htmlspecialchars($residential_address, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <label for="residential_address" class="form-label">Residential Address</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($emergency_contact_name, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo htmlspecialchars($emergency_contact_phone, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                        </div>
                    </div>
                </details>

                <!-- Parent Details Section -->
                <details id="parent_details_div" class="section-card col-span-2" style="display: none;">
                    <summary><i class="fas fa-user-friends"></i>Parent Details</summary>
                    <div class="p-4">
                        <!-- Link Existing Parent Option -->
                        <div class="form-group mb-4">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" id="link_existing_parent" name="link_existing_parent" class="mr-2 rounded text-orange-500 focus:ring-orange-500">
                                <span class="font-semibold">Link to Existing Parent(s)</span>
                            </label>
                            <p class="text-sm text-gray-600 mt-1">Check this if you want to link the student to an existing parent account</p>
                        </div>

                        <!-- Existing Parent Selection (Hidden by default) -->
                        <div id="existing_parent_section" style="display: none;" class="mb-4">
                            <h4 class="font-semibold mb-2 text-gray-700">Select Parent(s) to Link:</h4>
                            <div class="parent-selector bg-gray-50">
                                <?php if (empty($existing_parents)): ?>
                                    <p class="text-gray-500 text-sm p-2">No existing parents found in the system.</p>
                                <?php else: ?>
                                    <?php foreach ($existing_parents as $parent): ?>
                                        <label class="parent-item flex items-start">
                                            <input type="checkbox" name="selected_parents[]" value="<?php echo (int)$parent['id']; ?>" class="mr-3 mt-1 rounded text-orange-500 focus:ring-orange-500">
                                            <div class="flex-1">
                                                <div class="font-medium text-gray-800"><?php echo htmlspecialchars($parent['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-sm text-gray-600"><?php echo htmlspecialchars($parent['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($parent['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- New Parent Form (Shown by default) -->
                        <div id="new_parent_section">
                            <h4 class="font-semibold mb-3 text-gray-700">Create New Parent Account:</h4>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="form-group">
                                    <input type="text" id="parent_name" name="parent_name" value="<?php echo htmlspecialchars($parent_name, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                                    <label for="parent_name" class="form-label">Parent Name <span class="text-red-500">*</span></label>
                                </div>
                                <div class="form-group">
                                    <input type="email" id="parent_email" name="parent_email" value="<?php echo htmlspecialchars($parent_email, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                                    <label for="parent_email" class="form-label">Parent Email <span class="text-red-500">*</span></label>
                                </div>
                                <div class="form-group">
                                    <input type="text" id="parent_phone" name="parent_phone" value="<?php echo htmlspecialchars($parent_phone, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                                    <label for="parent_phone" class="form-label">Parent Phone <span class="text-red-500">*</span></label>
                                </div>
                                <div class="form-group">
                                    <input type="text" id="parent_national_id" name="parent_national_id" value="<?php echo htmlspecialchars($parent_national_id, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                                    <label for="parent_national_id" class="form-label">Parent National ID (Optional)</label>
                                </div>
                                <div class="form-group tooltip">
                                    <input type="password" id="parent_password" name="parent_password" class="form-input" placeholder=" " minlength="8">
                                    <label for="parent_password" class="form-label">Parent Password <span class="text-red-500">*</span></label>
                                    <span class="tooltip-text">At least 8 characters, including letters and numbers</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </details>

                <!-- Address and Additional Settings -->
                <details open class="section-card col-span-2">
                    <summary><i class="fas fa-cog"></i>Additional Settings</summary>
                    <div class="grid gap-4 sm:grid-cols-2 p-4">
                        <div class="form-group col-span-2">
                            <textarea id="address" name="address" class="form-textarea" rows="3" placeholder=" " required aria-required="true"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <label for="address" class="form-label">Address <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <label class="flex items-center">
                                <input type="checkbox" name="force_password_change" <?php echo $force_password_change ? 'checked' : ''; ?> class="mr-2 rounded text-orange-500 focus:ring-orange-500">
                                Force Password Change on Next Login
                            </label>
                        </div>
                    </div>
                </details>

                <!-- Submit Button -->
                <div class="col-span-2 flex justify-end gap-3 pt-4">
                    <a href="/admin/user_management.php" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition duration-200 flex items-center">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                    <button type="submit" class="bg-gradient-to-r from-orange-500 to-orange-700 text-white px-4 py-2 rounded-lg hover:from-orange-600 hover:to-orange-800 transition duration-200 flex items-center">
                        <i class="fas fa-user-plus mr-2"></i>Add User
                        <span class="loading-spinner"></span>
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white p-4 mt-auto">
        <div class="container mx-auto text-center">
            <p class="text-sm">© <?php echo date('Y'); ?> QPMS. All rights reserved.</p>
            <p class="text-sm mt-1">Developed by Kadevelops</p>
            <div class="flex justify-center gap-4 mt-2">
                <a href="#" class="hover:text-orange-500"><i class="fab fa-facebook"></i></a>
                <a href="#" class="hover:text-orange-500"><i class="fab fa-twitter"></i></a>
                <a href="#" class="hover:text-orange-500"><i class="fab fa-instagram"></i></a>
                <a href="#" class="hover:text-orange-500"><i class="fab fa-linkedin"></i></a>
                <a href="#" class="hover:text-orange-500"><i class="fab fa-youtube"></i></a>
            </div>
            <p class="text-sm mt-1">Contact Us: <a href="mailto:support@qpmsedu.com" class="hover:text-orange-500">Email</a> | <a href="#" class="hover:text-orange-500">WhatsApp</a></p>
        </div>
    </footer>

    <?php
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script>
        // Client-side validation and field toggling
        function toggleFields() {
            const role = document.getElementById('role').value;
            const phoneNumberDiv = document.getElementById('phone_number_div');
            const phoneDiv = document.getElementById('phone_div');
            const profilePictureDiv = document.getElementById('profile_picture_div');
            const departmentIdDiv = document.getElementById('department_id_div');
            const advisorIdDiv = document.getElementById('advisor_id_div');
            const classIdDiv = document.getElementById('class_id_div');
            const academicYearDiv = document.getElementById('academic_year_div');
            const parentDetailsDiv = document.getElementById('parent_details_div');
            const studentDetailsDiv = document.getElementById('student_details_div');
            const phoneInput = document.getElementById('phone');
            const parentFields = ['parent_name', 'parent_email', 'parent_phone', 'parent_password'];

            phoneNumberDiv.style.display = role === 'student' ? 'block' : 'none';
            phoneDiv.style.display = role !== 'student' ? 'block' : 'none';
            profilePictureDiv.style.display = role === 'student' ? 'block' : 'none';
            departmentIdDiv.style.display = role === 'teacher' ? 'block' : 'none';
            advisorIdDiv.style.display = role === 'student' ? 'block' : 'none';
            classIdDiv.style.display = role === 'student' ? 'block' : 'none';
            academicYearDiv.style.display = role === 'student' ? 'block' : 'none';
            parentDetailsDiv.style.display = role === 'student' ? 'block' : 'none';
            studentDetailsDiv.style.display = role === 'student' ? 'block' : 'none';
            phoneInput.required = role !== 'student';

            // Update parent field requirements based on link checkbox
            updateParentFieldRequirements();
        }

        function updateParentFieldRequirements() {
            const role = document.getElementById('role').value;
            const linkExisting = document.getElementById('link_existing_parent').checked;
            const parentFields = ['parent_name', 'parent_email', 'parent_phone', 'parent_password'];
            
            if (role === 'student') {
                parentFields.forEach(field => {
                    const element = document.getElementById(field);
                    if (element) {
                        element.required = !linkExisting;
                    }
                });
            }
        }

        // Toggle between existing parent selection and new parent form
        document.getElementById('link_existing_parent')?.addEventListener('change', function() {
            const existingParentSection = document.getElementById('existing_parent_section');
            const newParentSection = document.getElementById('new_parent_section');
            
            if (this.checked) {
                existingParentSection.style.display = 'block';
                newParentSection.style.display = 'none';
            } else {
                existingParentSection.style.display = 'none';
                newParentSection.style.display = 'block';
            }
            
            updateParentFieldRequirements();
        });

        // Profile picture preview
        document.getElementById('profile_picture')?.addEventListener('change', function(e) {
            const preview = document.getElementById('profile_preview');
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            } else {
                preview.src = '/assets/images/default_profile.png';
                preview.classList.add('hidden');
            }
        });

        // Form submission with loading state
        document.getElementById('addUserForm').addEventListener('submit', function(e) {
            const role = document.getElementById('role').value;
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const phoneNumber = document.getElementById('phone_number')?.value;
            const phone = document.getElementById('phone')?.value;
            const dateOfBirth = document.getElementById('date_of_birth')?.value;
            const admissionDate = document.getElementById('admission_date').value;
            const emergencyPhone = document.getElementById('emergency_contact_phone')?.value;
            const linkExisting = document.getElementById('link_existing_parent')?.checked;
            const parentEmail = document.getElementById('parent_email')?.value;
            const parentPhone = document.getElementById('parent_phone')?.value;
            const parentPassword = document.getElementById('parent_password')?.value;

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const phoneRegex = /^\+?\d{10,15}$/;
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;

            let errors = [];
            if (!emailRegex.test(email)) {
                errors.push("Invalid email format.");
            }
            if (password.length < 8) {
                errors.push("Password must be at least 8 characters.");
            }
            if (phoneNumber && !phoneRegex.test(phoneNumber)) {
                errors.push("Invalid phone number format.");
            }
            if (role !== 'student' && phone && !phoneRegex.test(phone)) {
                errors.push("Invalid phone format.");
            }
            if (dateOfBirth && !dateRegex.test(dateOfBirth)) {
                errors.push("Invalid date of birth format (YYYY-MM-DD).");
            }
            if (admissionDate && !dateRegex.test(admissionDate)) {
                errors.push("Invalid admission date format (YYYY-MM-DD).");
            }
            if (emergencyPhone && !phoneRegex.test(emergencyPhone)) {
                errors.push("Invalid emergency contact phone format.");
            }
            
            // Validate parent information for students
            if (role === 'student' && !linkExisting) {
                if (parentEmail && !emailRegex.test(parentEmail)) {
                    errors.push("Invalid parent email format.");
                }
                if (parentPhone && !phoneRegex.test(parentPhone)) {
                    errors.push("Invalid parent phone format.");
                }
                if (parentPassword && parentPassword.length < 8) {
                    errors.push("Parent password must be at least 8 characters.");
                }
            }
            
            // Check if at least one parent is selected when linking
            if (role === 'student' && linkExisting) {
                const selectedParents = document.querySelectorAll('input[name="selected_parents[]"]:checked');
                if (selectedParents.length === 0) {
                    errors.push("Please select at least one parent to link.");
                }
            }

            if (errors.length > 0) {
                e.preventDefault();
                const errorDiv = document.createElement('div');
                errorDiv.className = 'bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded animate-slide-in';
                errorDiv.innerHTML = errors.join('<br>');
                const mainCard = document.querySelector('.section-card');
                const existingError = mainCard.querySelector('.bg-red-50');
                if (existingError) {
                    existingError.remove();
                }
                mainCard.insertBefore(errorDiv, mainCard.querySelector('form'));
                setTimeout(() => errorDiv.remove(), 5000);
                return;
            }

            // Show loading spinner
            const submitButton = document.querySelector('button[type="submit"]');
            const spinner = submitButton.querySelector('.loading-spinner');
            submitButton.disabled = true;
            spinner.style.display = 'inline-block';
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            toggleFields();
            updateParentFieldRequirements();
        });
        document.getElementById('role').addEventListener('change', toggleFields);

        // Animation for error/success messages
        const style = document.createElement('style');
        style.textContent = `
            .animate-slide-in {
                animation: slideIn 0.3s ease-in-out;
            }
            @keyframes slideIn {
                from { transform: translateY(-20px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>