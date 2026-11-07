<?php
ob_start();
session_start();

ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to add_user.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
    $_SESSION['error_msg'] = "Access denied. You must be an admin to access this page.";
    header("Location: /login.php");
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
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

if (!isset($conn) || !$conn) {
    $_SESSION['error_msg'] = "Database connection failed. Please try again later.";
    header("Location: /login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];

// CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
if (!$stmt) {
    $_SESSION['error_msg'] = "System error: Unable to generate CSRF token.";
    header("Location: /admin/user_management.php");
    exit();
}
$stmt->bind_param("is", $admin_id, $csrf_token);
$stmt->execute();
$stmt->close();

define('UPLOAD_DIR', $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/');
define('UPLOAD_URL', '/Uploads/profiles/');

// Init vars
$error_msg = $success_msg = "";
$name = $email = $role = $status = $phone_number = $national_id = $admission_date = $department = $gender = $phone = $address = "";
$department_id = $advisor_id = $class_id = $academic_year = null;
$parent_name = $parent_email = $parent_phone = $parent_national_id = $parent_password = "";
$force_password_change = 1;
$previous_school = $date_of_birth = $nationality = $allergy = $special_child = $parent_occupation = "";
$parent_office_location = $office_address = $residential_address = $emergency_contact_name = $emergency_contact_phone = "";
$link_existing_parent = 0;
$selected_parent_ids = [];
// Parent-role student linking vars
$parent_link_students = 0;
$selected_student_ids = [];
$parent_occupation_direct = $parent_office_location_direct = $parent_office_address_direct = "";
$parent_residential_address_direct = $parent_emergency_name_direct = $parent_emergency_phone_direct = "";

// Fetch departments
$departments = [];
$result = $conn->query("SELECT id, name FROM departments ORDER BY name");
if ($result) while ($row = $result->fetch_assoc()) $departments[] = $row;

// Fetch teachers
$teachers = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $teachers[] = $row;
$stmt->close();

// Fetch classes
$classes = [];
$stmt = $conn->prepare("SELECT id, class_name FROM classes ORDER BY class_name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $classes[] = $row;
$stmt->close();

// Fetch academic years
$academic_years = [];
$result = $conn->query("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
if ($result) while ($row = $result->fetch_assoc()) $academic_years[] = $row['year'];

// ─── Fetch existing parents ────────────────────────────────────────────────
$existing_parents = [];
$stmt = $conn->prepare("
    SELECT 
        u.id AS user_id,
        u.name,
        u.email,
        u.phone,
        u.address AS user_address,
        p.id AS parent_record_id,
        p.parent_name,
        p.occupation,
        p.office_location,
        p.office_address,
        p.residential_address,
        p.phone_number AS parent_phone_detail,
        p.emergency_contact_name,
        p.emergency_contact_phone,
        p.academic_year AS parent_academic_year,
        COUNT(psm.student_id) AS children_count,
        GROUP_CONCAT(su.name ORDER BY su.name SEPARATOR '||') AS children_names
    FROM users u
    LEFT JOIN parents p ON p.user_id = u.id
    LEFT JOIN parent_student_mapping psm ON psm.parent_id = u.id
    LEFT JOIN users su ON su.id = psm.student_id
    WHERE u.role = 'parent'
    GROUP BY u.id
    ORDER BY u.name
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $existing_parents[] = $row;
}
$stmt->close();

$parents_json = json_encode(array_map(function($p) {
    return [
        'user_id'                 => (int)$p['user_id'],
        'name'                    => $p['name'] ?? '',
        'email'                   => $p['email'] ?? '',
        'phone'                   => $p['phone'] ?? $p['parent_phone_detail'] ?? '',
        'occupation'              => $p['occupation'] ?? '',
        'office_location'         => $p['office_location'] ?? '',
        'office_address'          => $p['office_address'] ?? '',
        'residential_address'     => $p['residential_address'] ?? $p['user_address'] ?? '',
        'emergency_contact_name'  => $p['emergency_contact_name'] ?? '',
        'emergency_contact_phone' => $p['emergency_contact_phone'] ?? '',
        'children_count'          => (int)$p['children_count'],
        'children_names'          => $p['children_names'] ? explode('||', $p['children_names']) : [],
    ];
}, $existing_parents));

// ─── Fetch existing students for parent-role linking ──────────────────────
$existing_students = [];
$stmt = $conn->prepare("
    SELECT 
        u.id AS user_id,
        u.name,
        u.email,
        u.status,
        u.gender,
        s.student_name,
        s.academic_year,
        s.residential_address,
        c.class_name,
        GROUP_CONCAT(pu.name ORDER BY pu.name SEPARATOR '||') AS parent_names
    FROM users u
    LEFT JOIN students s ON s.user_id = u.id
    LEFT JOIN classes c ON c.id = u.class_id
    LEFT JOIN parent_student_mapping psm ON psm.student_id = u.id
    LEFT JOIN users pu ON pu.id = psm.parent_id
    WHERE u.role = 'student' AND u.status != 'graduated'
    GROUP BY u.id
    ORDER BY u.name
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $existing_students[] = $row;
}
$stmt->close();

$students_json = json_encode(array_map(function($s) {
    return [
        'user_id'      => (int)$s['user_id'],
        'name'         => $s['student_name'] ?: $s['name'],
        'email'        => $s['email'] ?? '',
        'class_name'   => $s['class_name'] ?? '',
        'academic_year'=> $s['academic_year'] ?? '',
        'parent_names' => $s['parent_names'] ? explode('||', $s['parent_names']) : [],
    ];
}, $existing_students));

// ─── FORM SUBMISSION ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $stmt = $conn->prepare("SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $admin_id, $posted_csrf);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $_SESSION['error_msg'] = "Invalid or expired CSRF token.";
        header("Location: /admin/user_management.php");
        exit();
    }
    $stmt->close();

    // Collect inputs
    $name               = trim($_POST['name'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $password           = trim($_POST['password'] ?? '');
    $role               = trim($_POST['role'] ?? '');
    $status             = trim($_POST['status'] ?? '');
    $phone_number       = ($role === 'student') ? trim($_POST['phone_number'] ?? '') : '';
    $parent_national_id = ($role === 'student') ? trim($_POST['parent_national_id'] ?? '') : '';
    $admission_date     = trim($_POST['admission_date'] ?? '');
    $department         = trim($_POST['department'] ?? '');
    $gender             = trim($_POST['gender'] ?? '');
    $phone              = ($role !== 'student') ? trim($_POST['phone'] ?? '') : '';
    $address            = trim($_POST['address'] ?? '');
    $department_id      = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $force_password_change = isset($_POST['force_password_change']) ? 1 : 0;
    $advisor_id         = !empty($_POST['advisor_id']) ? (int)$_POST['advisor_id'] : null;
    $class_id           = ($role === 'student' && !empty($_POST['class_id'])) ? (int)$_POST['class_id'] : null;
    $academic_year      = ($role === 'student' && !empty($_POST['academic_year'])) ? trim($_POST['academic_year']) : null;

    // Student-specific fields
    $previous_school          = ($role === 'student') ? trim($_POST['previous_school'] ?? '') : '';
    $date_of_birth            = ($role === 'student') ? trim($_POST['date_of_birth'] ?? '') : '';
    $nationality              = ($role === 'student') ? trim($_POST['nationality'] ?? '') : '';
    $allergy                  = ($role === 'student') ? trim($_POST['allergy'] ?? '') : '';
    $special_child            = ($role === 'student') ? trim($_POST['special_child'] ?? 'no') : 'no';
    $parent_occupation        = ($role === 'student') ? trim($_POST['parent_occupation'] ?? '') : '';
    $parent_office_location   = ($role === 'student') ? trim($_POST['parent_office_location'] ?? '') : '';
    $office_address           = ($role === 'student') ? trim($_POST['office_address'] ?? '') : '';
    $residential_address      = ($role === 'student') ? trim($_POST['residential_address'] ?? '') : '';
    $emergency_contact_name   = ($role === 'student') ? trim($_POST['emergency_contact_name'] ?? '') : '';
    $emergency_contact_phone  = ($role === 'student') ? trim($_POST['emergency_contact_phone'] ?? '') : '';

    // Student parent linking
    // FIX: previously this only checked isset(), but the form always POSTs
    // "link_existing_parent" as a hidden field (its VALUE toggles between
    // "1" and "0" via JS when the admin switches modes). isset() is true
    // either way, so $link_existing_parent was always forced to 1 no matter
    // which mode was actually selected in the UI. We now check the value.
    $link_existing_parent = (isset($_POST['link_existing_parent']) && $_POST['link_existing_parent'] === '1') ? 1 : 0;
    $selected_parent_ids  = isset($_POST['selected_parents']) ? $_POST['selected_parents'] : [];
    $parent_name          = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_name'] ?? '') : '';
    $parent_email         = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_email'] ?? '') : '';
    $parent_phone         = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_phone'] ?? '') : '';
    $parent_password      = ($role === 'student' && !$link_existing_parent) ? trim($_POST['parent_password'] ?? '') : '';
    $new_parent_occupation       = ($role === 'student' && !$link_existing_parent) ? trim($_POST['new_parent_occupation'] ?? '') : '';
    $new_parent_office_location  = ($role === 'student' && !$link_existing_parent) ? trim($_POST['new_parent_office_location'] ?? '') : '';
    $new_parent_office_address   = ($role === 'student' && !$link_existing_parent) ? trim($_POST['new_parent_office_address'] ?? '') : '';
    $new_parent_residential      = ($role === 'student' && !$link_existing_parent) ? trim($_POST['new_parent_residential'] ?? '') : '';
    $new_emergency_name          = ($role === 'student' && !$link_existing_parent) ? trim($_POST['new_emergency_contact_name'] ?? '') : '';
    $new_emergency_phone         = ($role === 'student' && !$link_existing_parent) ? trim($_POST['new_emergency_contact_phone'] ?? '') : '';

    // ─── NEW: Parent-role direct fields & student linking ─────────────────
    if ($role === 'parent') {
        $parent_occupation_direct       = trim($_POST['parent_occupation_direct'] ?? '');
        $parent_office_location_direct  = trim($_POST['parent_office_location_direct'] ?? '');
        $parent_office_address_direct   = trim($_POST['parent_office_address_direct'] ?? '');
        $parent_residential_address_direct = trim($_POST['parent_residential_address_direct'] ?? '');
        $parent_emergency_name_direct   = trim($_POST['parent_emergency_name_direct'] ?? '');
        $parent_emergency_phone_direct  = trim($_POST['parent_emergency_phone_direct'] ?? '');
        $selected_student_ids = isset($_POST['selected_students']) ? array_map('intval', $_POST['selected_students']) : [];
    }

    // Profile picture
    $profile_picture = '/assets/images/default_profile.png';
    if ($role === 'student' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        if (is_writable(UPLOAD_DIR)) {
            $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
            $file_tmp = $_FILES['profile_picture']['tmp_name'];
            if (in_array($ext, $allowed_exts) && $_FILES['profile_picture']['size'] <= 5*1024*1024 && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $image_info = @getimagesize($file_tmp);
                if ($image_info !== false && in_array($image_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
                    $new_filename = "user_new_" . time() . "." . $ext;
                    if (move_uploaded_file($file_tmp, UPLOAD_DIR . $new_filename)) {
                        $profile_picture = UPLOAD_URL . $new_filename;
                    }
                }
            }
        }
    }

    // Validation
    $errors = [];
    if (empty($name)) $errors[] = "Name is required.";
    if (strlen($name) > 100) $errors[] = "Name must be 100 characters or less.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if (empty($password) || strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    if (empty($role) || !in_array($role, ['admin','teacher','student','parent'])) $errors[] = "Valid role is required.";
    if (empty($status) || !in_array($status, ['active','inactive','suspended','graduated'])) $errors[] = "Valid status is required.";
    if (empty($gender) || !in_array($gender, ['male','female','other'])) $errors[] = "Gender is required.";
    if (empty($address)) $errors[] = "Address is required.";
    if ($role !== 'student' && empty($phone)) $errors[] = "Phone is required for non-students.";
    if (!empty($phone) && !preg_match('/^\+?\d{10,15}$/', $phone)) $errors[] = "Invalid phone format.";
    if (!empty($phone_number) && !preg_match('/^\+?\d{10,15}$/', $phone_number)) $errors[] = "Invalid phone number format.";
    if (!empty($admission_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $admission_date)) $errors[] = "Invalid admission date.";
    if (!empty($date_of_birth) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) $errors[] = "Invalid date of birth.";
    if (!empty($emergency_contact_phone) && !preg_match('/^\+?\d{10,15}$/', $emergency_contact_phone)) $errors[] = "Invalid emergency contact phone.";

    if ($role === 'student') {
        if (empty($class_id) || empty($academic_year)) $errors[] = "Class and academic year are required for students.";
        if (!empty($academic_year) && !in_array($academic_year, $academic_years)) $errors[] = "Invalid academic year selected.";
        if ($link_existing_parent) {
            if (empty($selected_parent_ids)) $errors[] = "Please select at least one parent to link.";
        } else {
            if (empty($parent_name)) $errors[] = "Parent name is required.";
            if (empty($parent_email) || !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid parent email is required.";
            if (empty($parent_phone) || !preg_match('/^\+?\d{10,15}$/', $parent_phone)) $errors[] = "Valid parent phone is required.";
            if (empty($parent_password) || strlen($parent_password) < 8) $errors[] = "Parent password must be at least 8 characters.";
        }
    }

    if (!empty($errors)) {
        $error_msg = implode("<br>", $errors);
    } else {
        $conn->begin_transaction();
        try {
            // Duplicate email check
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) throw new Exception("Email already exists.");
            $stmt->close();

            // Insert user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $national_id_val = '';
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, phone_number, national_id, admission_date, department, gender, phone, address, profile_picture, department_id, force_password_change, advisor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssssssssssssiiii", $name, $email, $hashed_password, $role, $status, $phone_number, $national_id_val, $admission_date, $department, $gender, $phone, $address, $profile_picture, $department_id, $force_password_change, $advisor_id);
            $stmt->execute();
            $user_id = $conn->insert_id;
            $stmt->close();

            // ─── STUDENT INSERT ────────────────────────────────────────────
            if ($role === 'student') {
                $ay_id = null;
                $ay_stmt = $conn->prepare("SELECT id FROM academic_calendar WHERE year = ? LIMIT 1");
                $ay_stmt->bind_param("s", $academic_year);
                $ay_stmt->execute();
                $ay_res = $ay_stmt->get_result()->fetch_assoc();
                if ($ay_res) $ay_id = (int)$ay_res['id'];
                $ay_stmt->close();

                $student_stmt = $conn->prepare("INSERT INTO students (user_id, student_name, previous_school, date_of_birth, nationality, allergy, special_child, parent_occupation, parent_office_location, office_address, residential_address, emergency_contact_name, emergency_contact_phone, academic_year, academic_year_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $student_stmt->bind_param("isssssssssssssi", $user_id, $name, $previous_school, $date_of_birth, $nationality, $allergy, $special_child, $parent_occupation, $parent_office_location, $office_address, $residential_address, $emergency_contact_name, $emergency_contact_phone, $academic_year, $ay_id);
                $student_stmt->execute();
                $student_stmt->close();
            }

            // ─── PARENT HANDLING (when creating a student) ────────────────
            $parent_ids_to_link = [];

            if ($role === 'student') {
                if ($link_existing_parent) {
                    $parent_ids_to_link = $selected_parent_ids;
                } else {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->bind_param("s", $parent_email);
                    $stmt->execute();
                    $parent_user_id = null;
                    $p_row = $stmt->get_result()->fetch_assoc();
                    if ($p_row) $parent_user_id = (int)$p_row['id'];
                    $stmt->close();

                    if (!$parent_user_id) {
                        $hashed_parent_pw = password_hash($parent_password, PASSWORD_DEFAULT);
                        $p_stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, gender, phone, address, national_id, created_at) VALUES (?, ?, ?, 'parent', 'active', 'other', ?, ?, ?, NOW())");
                        $p_stmt->bind_param("ssssss", $parent_name, $parent_email, $hashed_parent_pw, $parent_phone, $address, $parent_national_id);
                        $p_stmt->execute();
                        $parent_user_id = $conn->insert_id;
                        $p_stmt->close();
                    }

                    $chk = $conn->prepare("SELECT id FROM parents WHERE user_id = ? LIMIT 1");
                    $chk->bind_param("i", $parent_user_id);
                    $chk->execute();
                    $existing_p_record = $chk->get_result()->fetch_assoc();
                    $chk->close();

                    if (!$existing_p_record) {
                        $pd_stmt = $conn->prepare("INSERT INTO parents (user_id, parent_name, occupation, office_location, office_address, residential_address, phone_number, email, emergency_contact_name, emergency_contact_phone, academic_year, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $pd_stmt->bind_param("issssssssss", $parent_user_id, $parent_name, $new_parent_occupation, $new_parent_office_location, $new_parent_office_address, $new_parent_residential, $parent_phone, $parent_email, $new_emergency_name, $new_emergency_phone, $academic_year);
                        $pd_stmt->execute();
                        $pd_stmt->close();
                    }

                    $parent_ids_to_link[] = $parent_user_id;
                }

                foreach ($parent_ids_to_link as $pid) {
                    $pid = (int)$pid;
                    $chk2 = $conn->prepare("SELECT id FROM parent_student_mapping WHERE parent_id = ? AND student_id = ?");
                    $chk2->bind_param("ii", $pid, $user_id);
                    $chk2->execute();
                    if ($chk2->get_result()->num_rows === 0) {
                        $lnk = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
                        $lnk->bind_param("ii", $pid, $user_id);
                        $lnk->execute();
                        $lnk->close();
                    }
                    $chk2->close();
                }
            }

            // Class assignment
            if ($role === 'student' && $class_id && $academic_year) {
                $assign_stmt = $conn->prepare("INSERT INTO student_classes (user_id, class_id, academic_year, created_at) VALUES (?, ?, ?, NOW())");
                $assign_stmt->bind_param("iis", $user_id, $class_id, $academic_year);
                $assign_stmt->execute();
                $assign_stmt->close();
            }

            // ─── NEW: PARENT ROLE — insert parents record + link students ──
            if ($role === 'parent') {
                // Check if parents record already exists (shouldn't for new user, but safe)
                $chk_p = $conn->prepare("SELECT id FROM parents WHERE user_id = ? LIMIT 1");
                $chk_p->bind_param("i", $user_id);
                $chk_p->execute();
                $existing_p = $chk_p->get_result()->fetch_assoc();
                $chk_p->close();

                if (!$existing_p) {
                    // Get academic year for linking (use first available or current)
                    $parent_ay = !empty($academic_years) ? $academic_years[0] : null;
                    $pd_stmt2 = $conn->prepare("INSERT INTO parents (user_id, parent_name, occupation, office_location, office_address, residential_address, phone_number, email, emergency_contact_name, emergency_contact_phone, academic_year, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $pd_stmt2->bind_param("issssssssss", $user_id, $name, $parent_occupation_direct, $parent_office_location_direct, $parent_office_address_direct, $parent_residential_address_direct, $phone, $email, $parent_emergency_name_direct, $parent_emergency_phone_direct, $parent_ay);
                    $pd_stmt2->execute();
                    $pd_stmt2->close();
                }

                // Link parent → selected students
                $linked_count = 0;
                foreach ($selected_student_ids as $sid) {
                    $sid = (int)$sid;
                    if ($sid <= 0) continue;
                    // Verify student exists
                    $vs = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'student'");
                    $vs->bind_param("i", $sid);
                    $vs->execute();
                    $vs_row = $vs->get_result()->fetch_assoc();
                    $vs->close();
                    if (!$vs_row) continue;

                    $chk3 = $conn->prepare("SELECT id FROM parent_student_mapping WHERE parent_id = ? AND student_id = ?");
                    $chk3->bind_param("ii", $user_id, $sid);
                    $chk3->execute();
                    if ($chk3->get_result()->num_rows === 0) {
                        $lnk2 = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
                        $lnk2->bind_param("ii", $user_id, $sid);
                        $lnk2->execute();
                        $lnk2->close();
                        $linked_count++;
                    }
                    $chk3->close();
                }
            }

            $extra_info = "";
            if ($role === 'student') {
                $extra_info = ", linked to " . count($parent_ids_to_link) . " parent(s)";
            } elseif ($role === 'parent') {
                $extra_info = ", linked to " . count($selected_student_ids) . " student(s)";
            }

            logActivity($conn, $admin_id, "Added User", "Admin added user ID: $user_id, role: $role" . $extra_info);
            $conn->commit();
            $_SESSION['success_msg'] = "User added successfully.";
            header("Location: /admin/user_management.php?success=" . urlencode("User added successfully."));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error adding user: " . $e->getMessage());
            $error_msg = "Error adding user: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
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
        body { font-family: 'Inter', sans-serif; }
        .form-label {
            font-size: .875rem; font-weight: 500; color: #1f2937;
            position: absolute; top: 0; left: 1rem;
            transform: translateY(-50%); background: white;
            padding: 0 .25rem; transition: all .2s ease; pointer-events: none;
        }
        .form-input, .form-select, .form-textarea {
            width: 100%; padding: .75rem; border: 1px solid #d1d5db;
            border-radius: .5rem; font-size: .875rem; transition: all .2s ease;
            background: white;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249,115,22,.1);
        }
        .form-group { position: relative; margin-bottom: 1.5rem; }
        .form-input:not(:placeholder-shown) + .form-label,
        .form-input:focus + .form-label,
        .form-textarea:not(:placeholder-shown) + .form-label,
        .form-textarea:focus + .form-label {
            transform: translateY(-1.75rem); font-size: .75rem; color: var(--primary);
        }
        .section-card {
            background: white; border-radius: .75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 1.5rem; margin-bottom: 1rem;
        }
        details summary {
            cursor: pointer; font-weight: 600; color: var(--primary);
            padding: .5rem; display: flex; align-items: center; gap: .5rem;
            list-style: none; user-select: none;
        }
        details summary::-webkit-details-marker { display: none; }
        details summary::before {
            content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            font-size: .75rem; transition: transform .2s; color: var(--primary);
        }
        details[open] summary::before { transform: rotate(-180deg); }
        .profile-img { width: 80px; height: 80px; object-fit: cover; border-radius: 50%; border: 2px solid #e5e7eb; }
        /* Parent card */
        .parent-card {
            border: 2px solid #e5e7eb; border-radius: .75rem; padding: .875rem;
            cursor: pointer; transition: all .2s; position: relative;
        }
        .parent-card:hover { border-color: var(--primary); background: #fff7ed; }
        .parent-card.selected { border-color: var(--primary); background: #fff7ed; box-shadow: 0 0 0 3px rgba(249,115,22,.15); }
        .parent-card input[type=checkbox] { position: absolute; top: .75rem; right: .75rem; width: 1.25rem; height: 1.25rem; accent-color: var(--primary); cursor: pointer; }
        /* Student card */
        .student-card {
            border: 2px solid #e5e7eb; border-radius: .75rem; padding: .875rem;
            cursor: pointer; transition: all .2s; position: relative;
        }
        .student-card:hover { border-color: #3b82f6; background: #eff6ff; }
        .student-card.selected { border-color: #3b82f6; background: #eff6ff; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
        .student-card input[type=checkbox] { position: absolute; top: .75rem; right: .75rem; width: 1.25rem; height: 1.25rem; accent-color: #3b82f6; cursor: pointer; }
        /* Autofill flash */
        @keyframes autofill-flash {
            0% { background: #fef3c7; border-color: #f59e0b; }
            70% { background: #fef3c7; border-color: #f59e0b; }
            100% { background: white; border-color: #d1d5db; }
        }
        .autofill-animate { animation: autofill-flash 1.4s ease forwards; }
        /* Smart suggestion badge */
        .ai-badge {
            display: inline-flex; align-items: center; gap: .25rem;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white; font-size: .65rem; font-weight: 700;
            padding: .125rem .45rem; border-radius: 999px; letter-spacing: .05em;
            vertical-align: middle; margin-left: .35rem;
        }
        /* Parent search */
        #parent-search-input, #student-search-input {
            width: 100%; padding: .6rem .75rem .6rem 2.5rem;
            border: 1px solid #d1d5db; border-radius: .5rem; font-size: .875rem;
            transition: border-color .2s;
        }
        #parent-search-input:focus, #student-search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(249,115,22,.1); }
        #student-search-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
        .parent-grid, .student-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .75rem; max-height: 380px; overflow-y: auto; padding: .25rem; }
        /* Autofill summary */
        #autofill-summary {
            background: linear-gradient(135deg, #fff7ed, #ffedd5);
            border: 1.5px solid #fdba74; border-radius: .75rem; padding: 1rem;
            font-size: .825rem; display: none;
        }
        #autofill-summary .summary-row { display: flex; gap: .5rem; align-items: flex-start; margin-bottom: .35rem; color: #374151; }
        #autofill-summary .summary-row i { color: var(--primary); margin-top: .1rem; flex-shrink: 0; }
        /* Selected students counter */
        .selected-badge {
            display: inline-flex; align-items: center; gap: .3rem;
            background: #3b82f6; color: white; font-size: .7rem; font-weight: 700;
            padding: .15rem .5rem; border-radius: 999px;
        }
        /* Loading spinner */
        .loading-spinner { display: none; border: 2px solid #f3f3f3; border-top: 2px solid var(--primary); border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; margin-left: .5rem; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .tooltip { position: relative; }
        .tooltip .tooltip-text { visibility: hidden; width: 180px; background: #374151; color: white; text-align: center; border-radius: .375rem; padding: .5rem; position: absolute; z-index: 10; bottom: 125%; left: 50%; transform: translateX(-50%); opacity: 0; transition: opacity .3s; font-size: .75rem; }
        .tooltip:hover .tooltip-text { visibility: visible; opacity: 1; }
        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .animate-slide-in { animation: slideIn .3s ease-in-out; }
        @media(max-width:640px) {
            .parent-grid, .student-grid { grid-template-columns: 1fr; }
            .section-card { padding: 1rem; }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">

<!-- Header -->
<header class="bg-gradient-to-r from-orange-500 to-orange-700 text-white p-4 shadow-lg sticky top-0 z-10">
    <div class="container mx-auto flex flex-col sm:flex-row justify-between items-center gap-4">
        <h1 class="text-2xl font-bold flex items-center gap-2">
            <i class="fas fa-user-plus"></i>Add New User
        </h1>
        <nav>
            <a href="/admin/user_management.php" class="text-sm hover:text-orange-200 transition">
                <i class="fas fa-arrow-left mr-1"></i>Back to User Management
            </a>
        </nav>
    </div>
</header>

<main class="container mx-auto p-4 sm:p-6 flex-grow">
    <div class="section-card">
        <h2 class="text-xl font-semibold mb-4 text-gray-800">Add New User</h2>

        <?php if (!empty($error_msg)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded animate-slide-in" role="alert">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_msg; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_msg)): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded animate-slide-in" role="alert">
                <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="addUserForm" class="grid gap-4 sm:grid-cols-2" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <!-- ── User Details ─────────────────────────────────────────── -->
            <details open class="section-card col-span-2">
                <summary><i class="fas fa-user mr-1"></i>User Details</summary>
                <div class="grid gap-4 sm:grid-cols-2 p-4 pt-6">

                    <div class="form-group">
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" " required>
                        <label for="name" class="form-label">Full Name <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group">
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" " required>
                        <label for="email" class="form-label">Email <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group tooltip">
                        <input type="password" id="password" name="password" class="form-input" placeholder=" " required minlength="8">
                        <label for="password" class="form-label">Password <span class="text-red-500">*</span></label>
                        <span class="tooltip-text">At least 8 characters</span>
                    </div>
                    <div class="form-group">
                        <select id="role" name="role" class="form-select" required>
                            <option value="" disabled selected>Select Role</option>
                            <option value="admin"   <?php echo $role==='admin'   ?'selected':''; ?>>Admin</option>
                            <option value="teacher" <?php echo $role==='teacher' ?'selected':''; ?>>Teacher</option>
                            <option value="student" <?php echo $role==='student' ?'selected':''; ?>>Student</option>
                            <option value="parent"  <?php echo $role==='parent'  ?'selected':''; ?>>Parent</option>
                        </select>
                        <label for="role" class="form-label">Role <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group">
                        <select id="status" name="status" class="form-select" required>
                            <option value="" disabled selected>Select Status</option>
                            <option value="active"    <?php echo $status==='active'    ?'selected':''; ?>>Active</option>
                            <option value="inactive"  <?php echo $status==='inactive'  ?'selected':''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $status==='suspended' ?'selected':''; ?>>Suspended</option>
                            <option value="graduated" <?php echo $status==='graduated' ?'selected':''; ?>>Graduated</option>
                        </select>
                        <label for="status" class="form-label">Status <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group" id="phone_number_div" style="display:none">
                        <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone_number,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="phone_number" class="form-label">Phone Number (Optional)</label>
                    </div>
                    <div class="form-group" id="phone_div">
                        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="phone" class="form-label">Phone <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group" id="profile_picture_div" style="display:none">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Profile Picture <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif" class="form-input">
                        <img id="profile_preview" src="/assets/images/default_profile.png" alt="Preview" class="profile-img mt-2 hidden">
                    </div>
                    <div class="form-group">
                        <input type="date" id="admission_date" name="admission_date" value="<?php echo htmlspecialchars($admission_date,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="admission_date" class="form-label">Admission Date</label>
                    </div>
                    <div class="form-group">
                        <select id="gender" name="gender" class="form-select" required>
                            <option value="" disabled selected>Select Gender</option>
                            <option value="male"   <?php echo $gender==='male'   ?'selected':''; ?>>Male</option>
                            <option value="female" <?php echo $gender==='female' ?'selected':''; ?>>Female</option>
                            <option value="other"  <?php echo $gender==='other'  ?'selected':''; ?>>Other</option>
                        </select>
                        <label for="gender" class="form-label">Gender <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group" id="advisor_id_div" style="display:none">
                        <select id="advisor_id" name="advisor_id" class="form-select">
                            <option value="" disabled selected>Select Advisor (Optional)</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $advisor_id==$t['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($t['name'],ENT_QUOTES,'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="advisor_id" class="form-label">Advisor</label>
                    </div>
                    <div class="form-group" id="class_id_div" style="display:none">
                        <select id="class_id" name="class_id" class="form-select">
                            <option value="" disabled selected>Select Class</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo $class_id==$c['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($c['class_name'],ENT_QUOTES,'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="class_id" class="form-label">Class <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group" id="academic_year_div" style="display:none">
                        <select id="academic_year" name="academic_year" class="form-select">
                            <option value="" disabled selected>Select Academic Year</option>
                            <?php foreach ($academic_years as $yr): ?>
                                <option value="<?php echo htmlspecialchars($yr,ENT_QUOTES,'UTF-8'); ?>" <?php echo $academic_year===$yr?'selected':''; ?>>
                                    <?php echo htmlspecialchars($yr,ENT_QUOTES,'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="academic_year" class="form-label">Academic Year <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group" id="department_id_div" style="display:none">
                        <select id="department_id" name="department_id" class="form-select">
                            <option value="" disabled selected>Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo (int)$dept['id']; ?>" <?php echo $department_id==$dept['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($dept['name'],ENT_QUOTES,'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="department_id" class="form-label">Department</label>
                    </div>

                </div>
            </details>

            <!-- ── Student Information ──────────────────────────────────── -->
            <details id="student_details_div" class="section-card col-span-2" style="display:none">
                <summary><i class="fas fa-graduation-cap mr-1"></i>Student Information</summary>
                <div class="grid gap-4 sm:grid-cols-2 p-4 pt-6">
                    <div class="form-group">
                        <input type="text" id="previous_school" name="previous_school" value="<?php echo htmlspecialchars($previous_school,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="previous_school" class="form-label">Previous School</label>
                    </div>
                    <div class="form-group">
                        <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($date_of_birth,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="date_of_birth" class="form-label">Date of Birth</label>
                    </div>
                    <div class="form-group">
                        <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($nationality,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="nationality" class="form-label">Nationality</label>
                    </div>
                    <div class="form-group">
                        <select id="special_child" name="special_child" class="form-select">
                            <option value="no"  <?php echo $special_child==='no' ?'selected':''; ?>>No</option>
                            <option value="yes" <?php echo $special_child==='yes'?'selected':''; ?>>Yes</option>
                        </select>
                        <label for="special_child" class="form-label">Special Needs</label>
                    </div>
                    <div class="form-group col-span-2">
                        <textarea id="allergy" name="allergy" class="form-textarea" rows="2" placeholder=" "><?php echo htmlspecialchars($allergy,ENT_QUOTES,'UTF-8'); ?></textarea>
                        <label for="allergy" class="form-label">Allergies / Medical Conditions</label>
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider mb-2">
                            Parent-linked fields
                            <span class="ai-badge"><i class="fas fa-magic" style="font-size:.6rem"></i> Auto-fill</span>
                        </p>
                    </div>
                    <div class="form-group">
                        <input type="text" id="parent_occupation" name="parent_occupation" value="<?php echo htmlspecialchars($parent_occupation,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="parent_occupation" class="form-label">Parent Occupation</label>
                    </div>
                    <div class="form-group">
                        <input type="text" id="parent_office_location" name="parent_office_location" value="<?php echo htmlspecialchars($parent_office_location,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="parent_office_location" class="form-label">Parent Office Location</label>
                    </div>
                    <div class="form-group col-span-2">
                        <textarea id="office_address" name="office_address" class="form-textarea" rows="2" placeholder=" "><?php echo htmlspecialchars($office_address,ENT_QUOTES,'UTF-8'); ?></textarea>
                        <label for="office_address" class="form-label">Office Address</label>
                    </div>
                    <div class="form-group col-span-2">
                        <textarea id="residential_address" name="residential_address" class="form-textarea" rows="2" placeholder=" "><?php echo htmlspecialchars($residential_address,ENT_QUOTES,'UTF-8'); ?></textarea>
                        <label for="residential_address" class="form-label">Residential Address</label>
                    </div>
                    <div class="form-group">
                        <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($emergency_contact_name,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                    </div>
                    <div class="form-group">
                        <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo htmlspecialchars($emergency_contact_phone,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                        <label for="emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                    </div>
                </div>
            </details>

            <!-- ── Parent / Guardian (for student role) ─────────────────── -->
            <details id="parent_details_div" class="section-card col-span-2" style="display:none" open>
                <summary><i class="fas fa-user-friends mr-1"></i>Parent / Guardian</summary>
                <div class="p-4">
                    <div class="flex gap-3 mb-5 bg-gray-50 p-1 rounded-lg w-fit">
                        <button type="button" id="btn-link-existing"
                            class="px-4 py-2 rounded-md text-sm font-semibold transition-all bg-orange-500 text-white shadow"
                            onclick="switchParentMode('link')">
                            <i class="fas fa-link mr-1"></i>Link Existing Parent
                        </button>
                        <button type="button" id="btn-create-new"
                            class="px-4 py-2 rounded-md text-sm font-semibold transition-all text-gray-600 hover:bg-white"
                            onclick="switchParentMode('new')">
                            <i class="fas fa-plus mr-1"></i>Create New Parent
                        </button>
                    </div>
                    <input type="hidden" name="link_existing_parent" id="link_existing_parent_val" value="1">

                    <!-- Link existing -->
                    <div id="existing_parent_section">
                        <div class="relative mb-3">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" id="parent-search-input" placeholder="Search by name, email or phone…">
                        </div>
                        <?php if (empty($existing_parents)): ?>
                            <p class="text-gray-500 text-sm p-4 text-center bg-gray-50 rounded-lg">No parent accounts found in the system yet.</p>
                        <?php else: ?>
                            <div class="parent-grid" id="parent-grid">
                                <?php foreach ($existing_parents as $p): ?>
                                    <?php
                                        $uid        = (int)$p['user_id'];
                                        $pname      = htmlspecialchars($p['parent_name'] ?: $p['name'], ENT_QUOTES, 'UTF-8');
                                        $pemail     = htmlspecialchars($p['email'] ?? '', ENT_QUOTES, 'UTF-8');
                                        $pphone     = htmlspecialchars($p['parent_phone_detail'] ?: $p['phone'] ?? '', ENT_QUOTES, 'UTF-8');
                                        $poccup     = htmlspecialchars($p['occupation'] ?? '', ENT_QUOTES, 'UTF-8');
                                        $paddress   = htmlspecialchars($p['residential_address'] ?? $p['user_address'] ?? '', ENT_QUOTES, 'UTF-8');
                                        $children   = (int)$p['children_count'];
                                    ?>
                                    <label class="parent-card" data-search="<?php echo strtolower($p['name'].' '.$p['email'].' '.($p['parent_phone_detail']?:$p['phone'])); ?>" onclick="onParentCardClick(<?php echo $uid; ?>, this)">
                                        <input type="checkbox" name="selected_parents[]" value="<?php echo $uid; ?>" class="parent-checkbox" onchange="onParentCheckChange(<?php echo $uid; ?>, this)">
                                        <div class="pr-8">
                                            <div class="font-semibold text-gray-800 text-sm"><?php echo $pname; ?></div>
                                            <?php if ($pemail): ?>
                                                <div class="text-xs text-gray-500 mt-0.5"><i class="fas fa-envelope w-3.5 text-orange-400"></i> <?php echo $pemail; ?></div>
                                            <?php endif; ?>
                                            <?php if ($pphone): ?>
                                                <div class="text-xs text-gray-500"><i class="fas fa-phone w-3.5 text-orange-400"></i> <?php echo $pphone; ?></div>
                                            <?php endif; ?>
                                            <?php if ($poccup): ?>
                                                <div class="text-xs text-gray-400"><i class="fas fa-briefcase w-3.5 text-orange-300"></i> <?php echo $poccup; ?></div>
                                            <?php endif; ?>
                                            <?php if ($paddress): ?>
                                                <div class="text-xs text-gray-400 truncate"><i class="fas fa-map-marker-alt w-3.5 text-orange-300"></i> <?php echo $paddress; ?></div>
                                            <?php endif; ?>
                                            <div class="mt-1.5 flex items-center gap-1.5 flex-wrap">
                                                <?php if ($children > 0): ?>
                                                    <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-600 text-xs px-2 py-0.5 rounded-full font-medium">
                                                        <i class="fas fa-child text-xs"></i> <?php echo $children; ?> child<?php echo $children>1?'ren':''; ?>
                                                    </span>
                                                    <?php if ($p['children_names']): ?>
                                                        <?php $kids = explode('||', $p['children_names']); foreach (array_slice($kids,0,2) as $kid): ?>
                                                            <span class="inline-block bg-gray-100 text-gray-500 text-xs px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($kid,ENT_QUOTES,'UTF-8'); ?></span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($kids) > 2): ?>
                                                            <span class="text-xs text-gray-400">+<?php echo count($kids)-2; ?> more</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 bg-green-50 text-green-600 text-xs px-2 py-0.5 rounded-full font-medium">
                                                        <i class="fas fa-user-plus text-xs"></i> No children yet
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">
                                <i class="fas fa-info-circle text-orange-400"></i>
                                Select one or more parents — student fields will auto-fill from the first selected parent.
                            </p>
                        <?php endif; ?>

                        <div id="autofill-summary" class="mt-4">
                            <div class="flex items-center gap-2 font-semibold text-orange-700 mb-2">
                                <i class="fas fa-magic"></i> Auto-filled from parent profile
                            </div>
                            <div id="autofill-rows"></div>
                            <p class="text-xs text-gray-400 mt-2">You can still edit any of these fields above.</p>
                        </div>
                    </div>

                    <!-- Create new parent -->
                    <div id="new_parent_section" style="display:none">
                        <p class="text-xs text-orange-600 bg-orange-50 border border-orange-200 rounded px-3 py-2 mb-4 flex items-start gap-2">
                            <i class="fas fa-info-circle mt-0.5"></i>
                            Fill in the parent details below. The system will automatically populate matching student fields.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="form-group">
                                <input type="text" id="parent_name" name="parent_name" value="<?php echo htmlspecialchars($parent_name,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                                <label for="parent_name" class="form-label">Parent Name <span class="text-red-500">*</span></label>
                            </div>
                            <div class="form-group">
                                <input type="email" id="parent_email" name="parent_email" value="<?php echo htmlspecialchars($parent_email,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                                <label for="parent_email" class="form-label">Parent Email <span class="text-red-500">*</span></label>
                            </div>
                            <div class="form-group">
                                <input type="text" id="parent_phone" name="parent_phone" value="<?php echo htmlspecialchars($parent_phone,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" " oninput="syncNewParentToStudent()">
                                <label for="parent_phone" class="form-label">Parent Phone <span class="text-red-500">*</span></label>
                            </div>
                            <div class="form-group">
                                <input type="text" id="parent_national_id" name="parent_national_id" value="<?php echo htmlspecialchars($parent_national_id,ENT_QUOTES,'UTF-8'); ?>" class="form-input" placeholder=" ">
                                <label for="parent_national_id" class="form-label">National ID (Optional)</label>
                            </div>
                            <div class="form-group tooltip">
                                <input type="password" id="parent_password" name="parent_password" class="form-input" placeholder=" " minlength="8">
                                <label for="parent_password" class="form-label">Parent Password <span class="text-red-500">*</span></label>
                                <span class="tooltip-text">At least 8 characters</span>
                            </div>
                            <div class="form-group">
                                <input type="text" id="new_parent_occupation" name="new_parent_occupation" class="form-input" placeholder=" " oninput="syncNewParentToStudent()">
                                <label for="new_parent_occupation" class="form-label">Occupation</label>
                            </div>
                            <div class="form-group">
                                <input type="text" id="new_parent_office_location" name="new_parent_office_location" class="form-input" placeholder=" " oninput="syncNewParentToStudent()">
                                <label for="new_parent_office_location" class="form-label">Office Location</label>
                            </div>
                            <div class="form-group col-span-2">
                                <textarea id="new_parent_office_address" name="new_parent_office_address" class="form-textarea" rows="2" placeholder=" " oninput="syncNewParentToStudent()"></textarea>
                                <label for="new_parent_office_address" class="form-label">Office Address</label>
                            </div>
                            <div class="form-group col-span-2">
                                <textarea id="new_parent_residential" name="new_parent_residential" class="form-textarea" rows="2" placeholder=" " oninput="syncNewParentToStudent()"></textarea>
                                <label for="new_parent_residential" class="form-label">Residential Address</label>
                            </div>
                            <div class="form-group">
                                <input type="text" id="new_emergency_contact_name" name="new_emergency_contact_name" class="form-input" placeholder=" " oninput="syncNewParentToStudent()">
                                <label for="new_emergency_contact_name" class="form-label">Emergency Contact Name</label>
                            </div>
                            <div class="form-group">
                                <input type="text" id="new_emergency_contact_phone" name="new_emergency_contact_phone" class="form-input" placeholder=" " oninput="syncNewParentToStudent()">
                                <label for="new_emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <!-- ══════════════════════════════════════════════════════════════
                 NEW: Parent Profile Info + Link to Students (role = parent)
            ═══════════════════════════════════════════════════════════════ -->
            <details id="parent_role_section" class="section-card col-span-2" style="display:none" open>
                <summary><i class="fas fa-id-card mr-1"></i>Parent Profile &amp; Linked Children</summary>
                <div class="p-4">

                    <!-- Parent profile fields -->
                    <p class="text-xs text-gray-500 font-semibold uppercase tracking-wider mb-3">
                        <i class="fas fa-briefcase text-orange-400 mr-1"></i>Parent Details
                        <span class="font-normal normal-case text-gray-400 ml-1">(optional — saved to parent profile)</span>
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2 mb-6">
                        <div class="form-group">
                            <input type="text" id="parent_occupation_direct" name="parent_occupation_direct" class="form-input" placeholder=" ">
                            <label for="parent_occupation_direct" class="form-label">Occupation</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="parent_office_location_direct" name="parent_office_location_direct" class="form-input" placeholder=" ">
                            <label for="parent_office_location_direct" class="form-label">Office Location</label>
                        </div>
                        <div class="form-group col-span-2">
                            <textarea id="parent_office_address_direct" name="parent_office_address_direct" class="form-textarea" rows="2" placeholder=" "></textarea>
                            <label for="parent_office_address_direct" class="form-label">Office Address</label>
                        </div>
                        <div class="form-group col-span-2">
                            <textarea id="parent_residential_address_direct" name="parent_residential_address_direct" class="form-textarea" rows="2" placeholder=" "></textarea>
                            <label for="parent_residential_address_direct" class="form-label">Residential Address</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="parent_emergency_name_direct" name="parent_emergency_name_direct" class="form-input" placeholder=" ">
                            <label for="parent_emergency_name_direct" class="form-label">Emergency Contact Name</label>
                        </div>
                        <div class="form-group">
                            <input type="text" id="parent_emergency_phone_direct" name="parent_emergency_phone_direct" class="form-input" placeholder=" ">
                            <label for="parent_emergency_phone_direct" class="form-label">Emergency Contact Phone</label>
                        </div>
                    </div>

                    <!-- Link to existing students -->
                    <div class="border-t border-gray-100 pt-5">
                        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                            <p class="text-xs text-gray-500 font-semibold uppercase tracking-wider">
                                <i class="fas fa-child text-blue-400 mr-1"></i>Link to Existing Students
                                <span class="font-normal normal-case text-gray-400 ml-1">(optional)</span>
                            </p>
                            <span id="student-selected-count" class="selected-badge hidden">
                                <i class="fas fa-check-circle text-xs"></i>
                                <span id="student-count-num">0</span> selected
                            </span>
                        </div>

                        <p class="text-xs text-blue-600 bg-blue-50 border border-blue-200 rounded px-3 py-2 mb-4 flex items-start gap-2">
                            <i class="fas fa-info-circle mt-0.5"></i>
                            Select one or more students to link this parent to. You can also do this later from the student's profile.
                        </p>

                        <?php if (empty($existing_students)): ?>
                            <p class="text-gray-500 text-sm p-4 text-center bg-gray-50 rounded-lg">No active students found in the system yet.</p>
                        <?php else: ?>
                            <!-- Search -->
                            <div class="relative mb-3">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="text" id="student-search-input" placeholder="Search by name, class or academic year…">
                            </div>
                            <div class="student-grid" id="student-grid">
                                <?php foreach ($existing_students as $s): ?>
                                    <?php
                                        $sid      = (int)$s['user_id'];
                                        $sname    = htmlspecialchars($s['student_name'] ?: $s['name'], ENT_QUOTES, 'UTF-8');
                                        $sclass   = htmlspecialchars($s['class_name'] ?? '', ENT_QUOTES, 'UTF-8');
                                        $say      = htmlspecialchars($s['academic_year'] ?? '', ENT_QUOTES, 'UTF-8');
                                        $semail   = htmlspecialchars($s['email'] ?? '', ENT_QUOTES, 'UTF-8');
                                        $sparents = $s['parent_names'] ? explode('||', $s['parent_names']) : [];
                                        $searchStr = strtolower(($s['student_name'] ?: $s['name']).' '.($s['class_name'] ?? '').' '.($s['academic_year'] ?? ''));
                                    ?>
                                    <label class="student-card" 
                                           data-search="<?php echo htmlspecialchars($searchStr, ENT_QUOTES, 'UTF-8'); ?>"
                                           onclick="onStudentCardClick(<?php echo $sid; ?>, this)">
                                        <input type="checkbox" name="selected_students[]" value="<?php echo $sid; ?>" class="student-checkbox" onchange="onStudentCheckChange(this)">
                                        <div class="pr-8">
                                            <div class="font-semibold text-gray-800 text-sm"><?php echo $sname; ?></div>
                                            <?php if ($sclass): ?>
                                                <div class="text-xs text-gray-500 mt-0.5">
                                                    <i class="fas fa-chalkboard-teacher w-3.5 text-blue-400"></i> <?php echo $sclass; ?>
                                                    <?php if ($say): ?><span class="text-gray-400"> · <?php echo $say; ?></span><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($semail): ?>
                                                <div class="text-xs text-gray-500"><i class="fas fa-envelope w-3.5 text-blue-400"></i> <?php echo $semail; ?></div>
                                            <?php endif; ?>
                                            <div class="mt-1.5 flex items-center gap-1.5 flex-wrap">
                                                <?php if (!empty($sparents)): ?>
                                                    <span class="inline-flex items-center gap-1 bg-orange-50 text-orange-600 text-xs px-2 py-0.5 rounded-full font-medium">
                                                        <i class="fas fa-users text-xs"></i> <?php echo count($sparents); ?> parent<?php echo count($sparents)>1?'s':''; ?>
                                                    </span>
                                                    <?php foreach (array_slice($sparents,0,2) as $pn): ?>
                                                        <span class="inline-block bg-gray-100 text-gray-500 text-xs px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($pn,ENT_QUOTES,'UTF-8'); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if (count($sparents) > 2): ?>
                                                        <span class="text-xs text-gray-400">+<?php echo count($sparents)-2; ?> more</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 bg-gray-50 text-gray-500 text-xs px-2 py-0.5 rounded-full">
                                                        <i class="fas fa-user-slash text-xs"></i> No parent linked
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">
                                <i class="fas fa-info-circle text-blue-400"></i>
                                You can select multiple students. The parent will be linked to all selected students.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </details>

            <!-- ── Additional Settings ─────────────────────────────────── -->
            <details open class="section-card col-span-2">
                <summary><i class="fas fa-cog mr-1"></i>Additional Settings</summary>
                <div class="grid gap-4 sm:grid-cols-2 p-4 pt-6">
                    <div class="form-group col-span-2">
                        <textarea id="address" name="address" class="form-textarea" rows="3" placeholder=" " required><?php echo htmlspecialchars($address,ENT_QUOTES,'UTF-8'); ?></textarea>
                        <label for="address" class="form-label">Address <span class="text-red-500">*</span></label>
                    </div>
                    <div class="form-group">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="force_password_change" <?php echo $force_password_change?'checked':''; ?> class="rounded text-orange-500 focus:ring-orange-500 w-4 h-4">
                            <span class="text-sm text-gray-700">Force password change on next login</span>
                        </label>
                    </div>
                </div>
            </details>

            <!-- Submit -->
            <div class="col-span-2 flex justify-end gap-3 pt-2">
                <a href="/admin/user_management.php" class="bg-gray-600 text-white px-5 py-2.5 rounded-lg hover:bg-gray-700 transition flex items-center gap-2">
                    <i class="fas fa-times"></i>Cancel
                </a>
                <button type="submit" class="bg-gradient-to-r from-orange-500 to-orange-700 text-white px-5 py-2.5 rounded-lg hover:from-orange-600 hover:to-orange-800 transition flex items-center gap-2">
                    <i class="fas fa-user-plus"></i>Add User
                    <span class="loading-spinner" id="loadingSpinner"></span>
                </button>
            </div>
        </form>
    </div>
</main>

<footer class="bg-gray-800 text-white p-4 mt-auto">
    <div class="container mx-auto text-center">
        <p class="text-sm">© <?php echo date('Y'); ?> QPMS. All rights reserved.</p>
        <p class="text-sm mt-1">Developed by Kadevelops</p>
    </div>
</footer>

<?php mysqli_close($conn); ob_end_flush(); ?>

<script>
// ─── Data from PHP ────────────────────────────────────────────────────────
const PARENTS_DATA  = <?php echo $parents_json; ?>;
const STUDENTS_DATA = <?php echo $students_json; ?>;

// ─── Role toggle ─────────────────────────────────────────────────────────
function toggleFields() {
    const role = document.getElementById('role').value;
    const show = (id, vis) => {
        const el = document.getElementById(id);
        if (el) el.style.display = vis ? (el.tagName === 'DETAILS' ? 'block' : 'block') : 'none';
    };

    show('phone_number_div',    role === 'student');
    show('phone_div',           role !== 'student');
    show('profile_picture_div', role === 'student');
    show('department_id_div',   role === 'teacher');
    show('advisor_id_div',      role === 'student');
    show('class_id_div',        role === 'student');
    show('academic_year_div',   role === 'student');
    show('student_details_div', role === 'student');
    show('parent_details_div',  role === 'student');

    // NEW: show parent profile + student linking section when role = parent
    show('parent_role_section', role === 'parent');

    const phoneInput = document.getElementById('phone');
    if (phoneInput) phoneInput.required = (role !== 'student');

    updateParentFieldRequirements();
}

// ─── Switch between link/create parent modes ──────────────────────────────
function switchParentMode(mode) {
    const isLink = mode === 'link';
    document.getElementById('link_existing_parent_val').value = isLink ? '1' : '0';
    document.getElementById('existing_parent_section').style.display = isLink ? 'block' : 'none';
    document.getElementById('new_parent_section').style.display      = isLink ? 'none'  : 'block';

    const btnLink = document.getElementById('btn-link-existing');
    const btnNew  = document.getElementById('btn-create-new');
    btnLink.className = isLink
        ? 'px-4 py-2 rounded-md text-sm font-semibold transition-all bg-orange-500 text-white shadow'
        : 'px-4 py-2 rounded-md text-sm font-semibold transition-all text-gray-600 hover:bg-white';
    btnNew.className  = !isLink
        ? 'px-4 py-2 rounded-md text-sm font-semibold transition-all bg-orange-500 text-white shadow'
        : 'px-4 py-2 rounded-md text-sm font-semibold transition-all text-gray-600 hover:bg-white';

    updateParentFieldRequirements();
}

function updateParentFieldRequirements() {
    const role   = document.getElementById('role').value;
    const isLink = document.getElementById('link_existing_parent_val').value === '1';
    const fields = ['parent_name','parent_email','parent_phone','parent_password'];
    if (role === 'student') {
        fields.forEach(f => {
            const el = document.getElementById(f);
            if (el) el.required = !isLink;
        });
    }
}

// ─── Parent card click / checkbox ────────────────────────────────────────
function onParentCardClick(userId, card) {
    const cb = card.querySelector('.parent-checkbox');
    if (!cb) return;
    cb.checked = !cb.checked;
    onParentCheckChange(userId, cb);
}

function onParentCheckChange(userId, cb) {
    const card = cb.closest('.parent-card');
    card.classList.toggle('selected', cb.checked);

    const selected = document.querySelectorAll('.parent-checkbox:checked');
    if (selected.length > 0) {
        const firstId  = parseInt(selected[0].value);
        const parentDt = PARENTS_DATA.find(p => p.user_id === firstId);
        if (parentDt) autoFillFromParent(parentDt);
    } else {
        document.getElementById('autofill-summary').style.display = 'none';
    }
}

// ─── Auto-fill student fields from parent data ────────────────────────────
function autoFillFromParent(p) {
    const fields = [
        { id: 'parent_occupation',       val: p.occupation,              label: 'Parent Occupation' },
        { id: 'parent_office_location',  val: p.office_location,         label: 'Office Location'   },
        { id: 'office_address',          val: p.office_address,          label: 'Office Address'    },
        { id: 'residential_address',     val: p.residential_address,     label: 'Residential Address' },
        { id: 'emergency_contact_name',  val: p.emergency_contact_name,  label: 'Emergency Contact Name' },
        { id: 'emergency_contact_phone', val: p.emergency_contact_phone, label: 'Emergency Contact Phone' },
        { id: 'address',                 val: p.residential_address,     label: 'Address (main)' },
    ];

    const filled = [];
    fields.forEach(f => {
        if (!f.val) return;
        const el = document.getElementById(f.id);
        if (!el) return;
        if (el.value && el.value.trim()) return;
        el.value = f.val;
        el.classList.remove('autofill-animate');
        void el.offsetWidth;
        el.classList.add('autofill-animate');
        filled.push({ label: f.label, val: f.val });
    });

    if (filled.length > 0) {
        const rows = filled.map(f =>
            `<div class="summary-row"><i class="fas fa-check-circle"></i><span><b>${escHtml(f.label)}:</b> ${escHtml(f.val.substring(0,60))}${f.val.length>60?'…':''}</span></div>`
        ).join('');
        document.getElementById('autofill-rows').innerHTML = rows;
        document.getElementById('autofill-summary').style.display = 'block';

        const sd = document.getElementById('student_details_div');
        if (sd && !sd.open) sd.open = true;
    }
}

// ─── Sync new parent fields → student fields live ────────────────────────
function syncNewParentToStudent() {
    const map = [
        ['new_parent_occupation',    'parent_occupation'],
        ['new_parent_office_location','parent_office_location'],
        ['new_parent_office_address','office_address'],
        ['new_parent_residential',   'residential_address'],
        ['new_emergency_contact_name', 'emergency_contact_name'],
        ['new_emergency_contact_phone','emergency_contact_phone'],
        ['new_parent_residential',   'address'],
    ];
    map.forEach(([src, dst]) => {
        const srcEl = document.getElementById(src);
        const dstEl = document.getElementById(dst);
        if (srcEl && dstEl && srcEl.value.trim()) {
            dstEl.value = srcEl.value;
        }
    });
}

// ─── NEW: Student card click / checkbox ───────────────────────────────────
function onStudentCardClick(studentId, card) {
    const cb = card.querySelector('.student-checkbox');
    if (!cb) return;
    cb.checked = !cb.checked;
    onStudentCheckChange(cb);
}

function onStudentCheckChange(cb) {
    const card = cb.closest('.student-card');
    card.classList.toggle('selected', cb.checked);
    updateStudentCounter();
}

function updateStudentCounter() {
    const checked = document.querySelectorAll('.student-checkbox:checked');
    const badge   = document.getElementById('student-selected-count');
    const num     = document.getElementById('student-count-num');
    if (checked.length > 0) {
        num.textContent = checked.length;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

// ─── Parent/Student search filter ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Parent search
    const parentSearch = document.getElementById('parent-search-input');
    if (parentSearch) {
        parentSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.parent-card').forEach(card => {
                const data = card.getAttribute('data-search') || '';
                card.style.display = !q || data.includes(q) ? '' : 'none';
            });
        });
    }

    // Student search
    const studentSearch = document.getElementById('student-search-input');
    if (studentSearch) {
        studentSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.student-card').forEach(card => {
                const data = card.getAttribute('data-search') || '';
                card.style.display = !q || data.includes(q) ? '' : 'none';
            });
        });
    }

    toggleFields();
    updateParentFieldRequirements();
    document.getElementById('role').addEventListener('change', toggleFields);
});

// ─── Profile picture preview ─────────────────────────────────────────────
document.getElementById('profile_picture')?.addEventListener('change', function() {
    const preview = document.getElementById('profile_preview');
    const file = this.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; preview.classList.remove('hidden'); };
        reader.readAsDataURL(file);
    }
});

// ─── Form submit with validation ─────────────────────────────────────────
document.getElementById('addUserForm').addEventListener('submit', function(e) {
    const role    = document.getElementById('role').value;
    const email   = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const isLink  = document.getElementById('link_existing_parent_val').value === '1';
    const emailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const phoneRx = /^\+?\d{10,15}$/;

    const errors = [];
    if (!emailRx.test(email))   errors.push("Invalid email format.");
    if (password.length < 8)    errors.push("Password must be at least 8 characters.");

    if (role === 'student' && !isLink) {
        const pEmail = document.getElementById('parent_email')?.value;
        const pPhone = document.getElementById('parent_phone')?.value;
        const pPw    = document.getElementById('parent_password')?.value;
        if (pEmail && !emailRx.test(pEmail)) errors.push("Invalid parent email format.");
        if (pPhone && !phoneRx.test(pPhone)) errors.push("Invalid parent phone format.");
        if (pPw && pPw.length < 8)           errors.push("Parent password must be at least 8 characters.");
    }
    if (role === 'student' && isLink) {
        const checked = document.querySelectorAll('.parent-checkbox:checked');
        if (checked.length === 0) errors.push("Please select at least one parent to link.");
    }

    if (errors.length > 0) {
        e.preventDefault();
        showFormErrors(errors);
        return;
    }

    document.querySelector('button[type=submit]').disabled = true;
    document.getElementById('loadingSpinner').style.display = 'inline-block';
});

function showFormErrors(errors) {
    const mainCard = document.querySelector('.section-card');
    let existing = mainCard.querySelector('.form-error-block');
    if (existing) existing.remove();
    const div = document.createElement('div');
    div.className = 'form-error-block bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded animate-slide-in';
    div.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + errors.join('<br>');
    mainCard.insertBefore(div, mainCard.querySelector('form'));
    setTimeout(() => div.remove(), 6000);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>
</body>
</html>