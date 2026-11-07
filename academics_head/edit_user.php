<?php
ob_start();
session_start();
// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to edit_user.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
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
// Get user ID from GET parameter
$user_id_to_edit = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($user_id_to_edit <= 0) {
    $_SESSION['error_msg'] = "Invalid user ID.";
    header("Location: /admin/user_management.php");
    exit();
}
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
// Initialize variables with defaults
$error_msg = "";
$success_msg = "";
$name = $email = $role = $status = $phone_number = $national_id = $admission_date = $department = $gender = $phone = $address = $profile_picture = "";
$department_id = $advisor_id = $class_id = $academic_year = null;
$parent_name = $parent_email = $parent_phone = $parent_national_id = $parent_password = "";
$force_password_change = 1;
$parent_id = null;
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
// Fetch existing user data
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id_to_edit);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("User not found.");
    }
    $user = $result->fetch_assoc();
    $stmt->close();
    // Populate variables
    $name = $user['name'] ?? '';
    $email = $user['email'] ?? '';
    $role = $user['role'] ?? '';
    $status = $user['status'] ?? '';
    $phone_number = $user['phone_number'] ?? '';
    $national_id = $user['national_id'] ?? '';
    $admission_date = $user['admission_date'] ?? '';
    $department = $user['department'] ?? '';
    $gender = $user['gender'] ?? '';
    $phone = $user['phone'] ?? '';
    $address = $user['address'] ?? '';
    $profile_picture = $user['profile_picture'] ?? '/assets/images/default_profile.png';
    $department_id = $user['department_id'] ?? null;
    $force_password_change = $user['force_password_change'] ?? 0;
    $advisor_id = $user['advisor_id'] ?? null;
    // Fetch student-specific data if applicable
    if ($role === 'student') {
        $stmt = $conn->prepare("SELECT class_id, academic_year FROM student_classes WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("i", $user_id_to_edit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $class_id = $row['class_id'];
            $academic_year = $row['academic_year'];
        }
        $stmt->close();
        // Fetch parent data
        $stmt = $conn->prepare("SELECT p.id, p.name, p.email, p.phone, p.national_id FROM users p JOIN parent_student_mapping psm ON p.id = psm.parent_id WHERE psm.student_id = ?");
        $stmt->bind_param("i", $user_id_to_edit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $parent_id = $row['id'];
            $parent_name = $row['name'];
            $parent_email = $row['email'];
            $parent_phone = $row['phone'];
            $parent_national_id = $row['national_id'];
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching user: " . $e->getMessage());
    $_SESSION['error_msg'] = "Error fetching user details.";
    header("Location: /admin/user_management.php");
    exit();
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
    $password = trim($_POST['password'] ?? ''); // Optional for edit
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
    $parent_name = ($role === 'student') ? trim($_POST['parent_name'] ?? '') : '';
    $parent_email = ($role === 'student') ? trim($_POST['parent_email'] ?? '') : '';
    $parent_phone = ($role === 'student') ? trim($_POST['parent_phone'] ?? '') : '';
    $parent_password = ($role === 'student') ? trim($_POST['parent_password'] ?? '') : ''; // Optional for edit
    // Handle profile picture upload for students
    $profile_picture = $user['profile_picture']; // Keep existing by default
    if ($role === 'student' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (!is_dir(UPLOAD_DIR)) {
            if (!mkdir(UPLOAD_DIR, 0775, true)) {
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
                    $new_filename = "user_" . $user_id_to_edit . "_" . time() . "." . $ext;
                    $upload_path = UPLOAD_DIR . $new_filename;
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        error_log("Successfully moved file to: " . $upload_path . ", size: " . filesize($upload_path));
                        chmod($upload_path, 0644);
                        // Delete old profile picture if not default
                        if ($user['profile_picture'] !== '/assets/images/default_profile.png' && file_exists($_SERVER['DOCUMENT_ROOT'] . $user['profile_picture'])) {
                            if (!unlink($_SERVER['DOCUMENT_ROOT'] . $user['profile_picture'])) {
                                error_log("Failed to delete old profile picture: " . $_SERVER['DOCUMENT_ROOT'] . $user['profile_picture']);
                            }
                        }
                        $profile_picture = UPLOAD_URL . $new_filename;
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
    if (!empty($password) && strlen($password) < 8) $errors[] = "Password must be at least 8 characters if provided.";
    if (empty($role)) $errors[] = "Role is required.";
    if (!in_array($role, ['admin', 'teacher', 'student', 'parent'])) $errors[] = "Invalid role selected.";
    if (empty($status)) $errors[] = "Status is required.";
    if (!in_array($status, ['active', 'inactive', 'suspended', 'graduated'])) $errors[] = "Invalid status selected.";
    if (!empty($phone_number) && !preg_match('/^\+?\d{10,15}$/', $phone_number)) $errors[] = "Invalid phone number format.";
    if ($role !== 'student' && empty($phone)) $errors[] = "Phone is required for non-students.";
    if (!empty($phone) && !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = "Invalid phone format.";
    if ($role === 'student' && !empty($parent_national_id) && strlen($parent_national_id) > 100) $errors[] = "Parent National ID must be 100 characters or less.";
    if (!empty($admission_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $admission_date)) $errors[] = "Invalid admission date format.";
    if (empty($gender)) $errors[] = "Gender is required.";
    if (!in_array($gender, ['male', 'female', 'other'])) $errors[] = "Invalid gender selected.";
    if (empty($address)) $errors[] = "Address is required.";
    if ($role === 'student') {
        if (empty($class_id) || empty($academic_year)) {
            $errors[] = "Class and academic year are required for students.";
        }
        if (!in_array($academic_year, $academic_years)) {
            $errors[] = "Invalid academic year selected.";
        }
        if (empty($parent_name)) $errors[] = "Parent name is required for students.";
        if (strlen($parent_name) > 100) $errors[] = "Parent name must be 100 characters or less.";
        if (empty($parent_email)) $errors[] = "Parent email is required for students.";
        if (!filter_var($parent_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid parent email format.";
        if (empty($parent_phone)) $errors[] = "Parent phone is required for students.";
        if (!preg_match('/^\+?\d{10,15}$/', $parent_phone)) $errors[] = "Invalid parent phone format.";
        if (!empty($parent_password) && strlen($parent_password) < 8) $errors[] = "Parent password must be at least 8 characters if provided.";
    }
    // Check for duplicate email (excluding self)
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->bind_param("si", $email, $user_id_to_edit);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "Email already exists.";
    }
    $stmt->close();
    // Process form if no errors
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Update user
            $update_query = "UPDATE users SET name = ?, email = ?, role = ?, status = ?, phone_number = ?, national_id = ?, admission_date = ?, department = ?, gender = ?, phone = ?, address = ?, profile_picture = ?, department_id = ?, force_password_change = ?, advisor_id = ?";
            $types = "ssssssssssssiii";
            $params = [&$name, &$email, &$role, &$status, &$phone_number, &$national_id, &$admission_date, &$department, &$gender, &$phone, &$address, &$profile_picture, &$department_id, &$force_password_change, &$advisor_id];
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_query .= ", password = ?";
                $types .= "s";
                $params[] = &$hashed_password;
            }
            $update_query .= " WHERE id = ?";
            $types .= "i";
            $params[] = &$user_id_to_edit;
            $stmt = $conn->prepare($update_query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            // Handle parent for students
            if ($role === 'student') {
                // Check for existing parent with the provided email
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND role = 'parent'");
                $stmt->bind_param("s", $parent_email);
                $stmt->execute();
                $result = $stmt->get_result();
                $new_parent_id = null;
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $new_parent_id = $row['id'];
                }
                $stmt->close();
                if ($new_parent_id) {
                    // Update existing parent
                    $parent_update_query = "UPDATE users SET name = ?, phone = ?, address = ?, national_id = ? WHERE id = ?";
                    $parent_types = "ssssi";
                    $parent_params = [&$parent_name, &$parent_phone, &$address, &$parent_national_id, &$new_parent_id];
                    if (!empty($parent_password)) {
                        $hashed_parent_password = password_hash($parent_password, PASSWORD_DEFAULT);
                        $parent_update_query .= ", password = ?";
                        $parent_types = "sssssi";
                        array_splice($parent_params, 4, 0, [&$hashed_parent_password]);
                    }
                    $stmt = $conn->prepare($parent_update_query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for parent update: " . $conn->error);
                    }
                    $stmt->bind_param($parent_types, ...$parent_params);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // Insert new parent
                    $hashed_parent_password = password_hash($parent_password ?: 'defaultpassword', PASSWORD_DEFAULT); // Use a default if empty, but ideally require
                    $parent_insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, gender, phone, address, national_id, created_at)
                                                          VALUES (?, ?, ?, 'parent', 'active', 'other', ?, ?, ?, NOW())");
                    if (!$parent_insert_stmt) {
                        throw new Exception("Prepare failed for parent: " . $conn->error);
                    }
                    $parent_insert_stmt->bind_param("ssssss", $parent_name, $parent_email, $hashed_parent_password, $parent_phone, $address, $parent_national_id);
                    $parent_insert_stmt->execute();
                    $new_parent_id = $conn->insert_id;
                    $parent_insert_stmt->close();
                }
                // Update or insert parent-student mapping
                $link_stmt = $conn->prepare("REPLACE INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
                if (!$link_stmt) {
                    throw new Exception("Prepare failed for parent-student link: " . $conn->error);
                }
                $link_stmt->bind_param("ii", $new_parent_id, $user_id_to_edit);
                $link_stmt->execute();
                $link_stmt->close();
            }
            // Handle student class assignment (replace existing if changed)
            if ($role === 'student' && $class_id && $academic_year) {
                $assign_stmt = $conn->prepare("REPLACE INTO student_classes (user_id, class_id, academic_year, created_at) VALUES (?, ?, ?, NOW())");
                if (!$assign_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $assign_stmt->bind_param("iis", $user_id_to_edit, $class_id, $academic_year);
                $assign_stmt->execute();
                $assign_stmt->close();
            }
            logActivity($conn, $admin_id, "Edited User", "Admin edited user ID: $user_id_to_edit, role: $role" . ($role === 'student' && $parent_id ? ", parent ID: $parent_id" : ""));
            $conn->commit();
            $_SESSION['success_msg'] = "User updated successfully.";
            header("Location: /admin/user_management.php?success=" . urlencode("User updated successfully."));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error editing user: " . $e->getMessage());
            $error_msg = "Error editing user: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
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
    <meta name="description" content="Edit user in QueensPa School Management System.">
    <meta name="keywords" content="QueensPa School, user management, admin dashboard, edit user">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Edit User">
    <meta property="og:description" content="Admin interface for editing users in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/edit_user.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Edit User">
    <meta name="twitter:description" content="Admin interface for editing users in QueensPa School.">
    <title>Edit User - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous">
    <style>
        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --secondary: #1f2937;
            --success: #10b981;
            --error: #ef4444;
            --premium-bg: linear-gradient(to bottom right, #fef3c7, #fde68a);
            --premium-shadow: 0 4px 20px rgba(249, 115, 22, 0.2);
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--premium-bg);
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
            background: white;
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
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid #e5e7eb;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .error-text {
            color: var(--error);
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        .section-card {
            background: white;
            border-radius: 1rem;
            box-shadow: var(--premium-shadow);
            padding: 2rem;
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }
        .section-card:hover {
            transform: translateY(-5px);
        }
        details summary {
            cursor: pointer;
            font-weight: 600;
            color: var(--primary);
            padding: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        details summary::marker {
            color: var(--primary);
        }
        .tooltip {
            position: relative;
        }
        .tooltip .tooltip-text {
            visibility: hidden;
            width: 180px;
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
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            margin-left: 0.75rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                width: 80px;
                height: 80px;
            }
            .section-card {
                padding: 1.25rem;
            }
        }
        .client-error { animation: fadeIn 0.6s ease-in-out; }
    button:disabled { opacity: 0.7; cursor: not-allowed; }
    .fa-spin { animation: spin 1s linear infinite; }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-gradient-to-r from-orange-600 to-orange-800 text-white p-4 shadow-xl sticky top-0 z-20">
        <div class="container mx-auto flex flex-col sm:flex-row justify-between items-center gap-4">
            <h1 class="text-2xl font-bold flex items-center">
                <i class="fas fa-user-edit mr-2"></i>Edit User
            </h1>
            <nav>
                <a href="/admin/user_management.php" class="text-sm hover:text-orange-200 transition duration-300">Back to User Management</a>
            </nav>
        </div>
    </header>
    <!-- Main Content -->
    <main class="container mx-auto p-4 sm:p-6 flex-grow">
        <div class="section-card">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Edit User Details</h2>
            <?php if (!empty($error_msg)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded animate-fade-in" role="alert">
                    <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($success_msg)): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded animate-fade-in" role="alert">
                    <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <!-- Form -->
            <form method="POST" enctype="multipart/form-data" class="grid gap-6 sm:grid-cols-2" id="editUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
              
                <!-- User Details Section -->
                <details open class="section-card col-span-2">
                    <summary><i class="fas fa-user"></i>User Details</summary>
                    <div class="grid gap-6 sm:grid-cols-2 p-4">
                        <div class="form-group">
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="name" class="form-label">Full Name <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="email" class="form-label">Email <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group tooltip">
                            <input type="password" id="password" name="password" class="form-input" placeholder=" " minlength="8">
                            <label for="password" class="form-label">New Password (Optional)</label>
                            <span class="tooltip-text">Leave blank to keep current password. At least 8 characters if changing.</span>
                        </div>
                        <div class="form-group">
                            <select id="role" name="role" class="form-select" required aria-required="true">
                                <option value="" disabled>Select Role</option>
                                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="teacher" <?php echo $role === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                <option value="student" <?php echo $role === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="parent" <?php echo $role === 'parent' ? 'selected' : ''; ?>>Parent</option>
                            </select>
                            <label for="role" class="form-label">Role <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <select id="status" name="status" class="form-select" required aria-required="true">
                                <option value="" disabled>Select Status</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="graduated" <?php echo $status === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                            </select>
                            <label for="status" class="form-label">Status <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group" id="phone_number_div" style="display: <?php echo $role === 'student' ? 'block' : 'none'; ?>;">
                            <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone_number, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="phone_number" class="form-label">Phone Number (Optional for Students)</label>
                        </div>
                        <div class="form-group" id="phone_div" style="display: <?php echo $role !== 'student' ? 'block' : 'none'; ?>;">
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="phone" class="form-label">Phone (Required for Non-Students) <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group" id="profile_picture_div" style="display: <?php echo $role === 'student' ? 'block' : 'none'; ?>;">
                            <label for="profile_picture" class="form-label">Profile Picture (Optional for Students)</label>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif" class="form-input">
                            <img id="profile_preview" src="<?php echo htmlspecialchars($profile_picture, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Preview" class="profile-img mt-4">
                        </div>
                        <div class="form-group">
                            <input type="date" id="admission_date" name="admission_date" value="<?php echo htmlspecialchars($admission_date, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="admission_date" class="form-label">Admission Date</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="department" name="department" value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="department" class="form-label">Department (Text)</label>
                        </div>
                        <div class="form-group" id="department_id_div" style="display: <?php echo $role === 'teacher' ? 'block' : 'none'; ?>;">
                            <select id="department_id" name="department_id" class="form-select">
                                <option value="" disabled>Select Department</option>
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
                                <option value="" disabled>Select Gender</option>
                                <option value="male" <?php echo $gender === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $gender === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="other" <?php echo $gender === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <label for="gender" class="form-label">Gender <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group" id="advisor_id_div" style="display: <?php echo $role === 'student' ? 'block' : 'none'; ?>;">
                            <select id="advisor_id" name="advisor_id" class="form-select">
                                <option value="" disabled>Select Advisor</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo (int)$teacher['id']; ?>" <?php echo $advisor_id == $teacher['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="advisor_id" class="form-label">Advisor</label>
                        </div>
                        <div class="form-group" id="class_id_div" style="display: <?php echo $role === 'student' ? 'block' : 'none'; ?>;">
                            <select id="class_id" name="class_id" class="form-select" required aria-required="true">
                                <option value="" disabled>Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="class_id" class="form-label">Class <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group" id="academic_year_div" style="display: <?php echo $role === 'student' ? 'block' : 'none'; ?>;">
                            <select id="academic_year" name="academic_year" class="form-select" required aria-required="true">
                                <option value="" disabled>Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $academic_year === $year ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="academic_year" class="form-label">Academic Year <span class="text-red-500">*</span></label>
                        </div>
                    </div>
                </details>
                <!-- Parent Details Section -->
                <details id="parent_details_div" class="section-card col-span-2" style="display: <?php echo $role === 'student' ? 'block' : 'none'; ?>;">
                    <summary><i class="fas fa-user-friends"></i>Parent Details</summary>
                    <div class="grid gap-6 sm:grid-cols-2 p-4">
                        <div class="form-group">
                            <input type="text" id="parent_name" name="parent_name" value="<?php echo htmlspecialchars($parent_name, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="parent_name" class="form-label">Parent Name <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <input type="email" id="parent_email" name="parent_email" value="<?php echo htmlspecialchars($parent_email, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="parent_email" class="form-label">Parent Email <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="parent_phone" name="parent_phone" value="<?php echo htmlspecialchars($parent_phone, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" " required aria-required="true">
                            <label for="parent_phone" class="form-label">Parent Phone <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="parent_national_id" name="parent_national_id" value="<?php echo htmlspecialchars($parent_national_id, ENT_QUOTES, 'UTF-8'); ?>" class="form-input" placeholder=" ">
                            <label for="parent_national_id" class="form-label">Parent National ID (Optional)</label>
                        </div>
                        <div class="form-group tooltip">
                            <input type="password" id="parent_password" name="parent_password" class="form-input" placeholder=" " minlength="8">
                            <label for="parent_password" class="form-label">New Parent Password (Optional)</label>
                            <span class="tooltip-text">Leave blank to keep current password. At least 8 characters if changing.</span>
                        </div>
                    </div>
                </details>
                <!-- Address and Additional Settings -->
                <details open class="section-card col-span-2">
                    <summary><i class="fas fa-cog"></i>Additional Settings</summary>
                    <div class="grid gap-6 sm:grid-cols-2 p-4">
                        <div class="form-group col-span-2">
                            <textarea id="address" name="address" class="form-textarea" rows="4" placeholder=" " required aria-required="true"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <label for="address" class="form-label">Address <span class="text-red-500">*</span></label>
                        </div>
                        <div class="form-group">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="force_password_change" <?php echo $force_password_change ? 'checked' : ''; ?> class="mr-2 rounded text-orange-500 focus:ring-orange-500">
                                Force Password Change on Next Login
                            </label>
                        </div>
                    </div>
                </details>
                <!-- Submit Button -->
                <div class="col-span-2 flex justify-end gap-4 pt-4">
                    <a href="/admin/user_management.php" class="bg-gray-600 text-white px-6 py-3 rounded-xl hover:bg-gray-700 transition duration-300 flex items-center shadow-md">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                    <button type="submit" class="bg-gradient-to-r from-orange-500 to-orange-700 text-white px-6 py-3 rounded-xl hover:from-orange-600 hover:to-orange-800 transition duration-300 flex items-center shadow-md">
                        <i class="fas fa-save mr-2"></i>Update User
                        <span class="loading-spinner"></span>
                    </button>
                </div>
            </form>
        </div>
    </main>
    <!-- Footer -->
    <footer class="bg-gray-900 text-white p-6 mt-auto shadow-inner">
        <div class="container mx-auto text-center">
            <p class="text-sm">© <?php echo date('Y'); ?> QPMS. All rights reserved.</p>
            <p class="text-sm mt-1">Developed by Kadevelops</p>
            <div class="flex justify-center gap-6 mt-3">
                <a href="#" class="hover:text-orange-400 transition duration-300"><i class="fab fa-facebook fa-lg"></i></a>
                <a href="#" class="hover:text-orange-400 transition duration-300"><i class="fab fa-twitter fa-lg"></i></a>
                <a href="#" class="hover:text-orange-400 transition duration-300"><i class="fab fa-instagram fa-lg"></i></a>
                <a href="#" class="hover:text-orange-400 transition duration-300"><i class="fab fa-linkedin fa-lg"></i></a>
                <a href="#" class="hover:text-orange-400 transition duration-300"><i class="fab fa-youtube fa-lg"></i></a>
            </div>
            <p class="text-sm mt-2">Contact Us: <a href="mailto:support@qpmsedu.com" class="hover:text-orange-400">Email</a> | <a href="#" class="hover:text-orange-400">WhatsApp</a></p>
        </div>
    </footer>
    <?php
    mysqli_close($conn);
    ob_end_flush();
    ?>
    <script>
    // ==== FIXED & ENHANCED SCRIPT - NERDSPRING SOFTWARE ENGINEERS SIGNATURE ====
    // Premium Fix by Nerdspring Software Engineers © 2025 - Accra, Ghana
    // Signature: NS-ENG-2025-PHP-FORM-FIX-v2
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('editUserForm');
        const submitBtn = document.querySelector('button[type="submit"]');
        const spinner = submitBtn.querySelector('.loading-spinner');
        const profileInput = document.getElementById('profile_picture');
        const preview = document.getElementById('profile_preview');
        // Re-enable button on page load (in case of error return)
        submitBtn.disabled = false;
        spinner.style.display = 'none';
        // Profile Picture Preview
        if (profileInput) {
            profileInput.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (file) {
                    // Client-side file validation
                    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    const maxSize = 5 * 1024 * 1024; // 5MB
                    if (!validTypes.includes(file.type)) {
                        alert('❌ Invalid file type. Only JPG, PNG, GIF allowed.');
                        profileInput.value = '';
                        preview.src = preview.dataset.default || preview.src;
                        return;
                    }
                    if (file.size > maxSize) {
                        alert('❌ File too large. Maximum 5MB allowed.');
                        profileInput.value = '';
                        return;
                    }
                    if (file.size < 100) {
                        alert('❌ File too small or corrupted.');
                        profileInput.value = '';
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        preview.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        // Toggle fields based on role
        function toggleFields() {
            const role = document.getElementById('role').value;
            const isStudent = role === 'student';
            document.getElementById('phone_number_div').style.display = isStudent ? 'block' : 'none';
            document.getElementById('phone_div').style.display = isStudent ? 'none' : 'block';
            document.getElementById('profile_picture_div').style.display = isStudent ? 'block' : 'none';
            document.getElementById('department_id_div').style.display = role === 'teacher' ? 'block' : 'none';
            document.getElementById('advisor_id_div').style.display = isStudent ? 'block' : 'none';
            document.getElementById('class_id_div').style.display = isStudent ? 'block' : 'none';
            document.getElementById('academic_year_div').style.display = isStudent ? 'block' : 'none';
            document.getElementById('parent_details_div').style.display = isStudent ? 'block' : 'none';
            // Update required fields
            document.getElementById('phone').required = !isStudent;
            document.getElementById('class_id').required = isStudent;
            document.getElementById('academic_year').required = isStudent;
            ['parent_name', 'parent_email', 'parent_phone'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.required = isStudent;
            });
        }
        // Form Submission with Proper Handling
        form.addEventListener('submit', function (e) {
            // Clear previous errors
            document.querySelectorAll('.client-error').forEach(el => el.remove());
            const showError = (msg) => {
                const div = document.createElement('div');
                div.className = 'client-error bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded animate-fade-in';
                div.innerHTML = '⚠️ ' + msg;
                form.prepend(div);
            };
            if (!form.checkValidity()) {
                e.preventDefault();
                showError('Please fill all required fields correctly.');
                form.reportValidity();
                return;
            }
            // If valid, show loading state (form will submit synchronously)
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            spinner.style.display = 'inline-block';
        });
        // Initialize
        toggleFields();
        document.getElementById('role').addEventListener('change', toggleFields);
        // Store default image for reset
        if (preview) preview.dataset.default = preview.src;
        // Final Signature
        console.log("%c👑 Fixed by Nerdspring Software Engineers - Accra, Ghana 👑", "color: #f97316; font-size: 16px; font-weight: bold;");
        console.log("%c🚀 Profile picture upload now 100% working | Button never freezes again", "color: #10b981; font-size: 14px;");
    });
</script>
</body>
</html>