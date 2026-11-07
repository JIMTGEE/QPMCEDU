<?php
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) die("Invalid student ID.");

$error_msg = '';
$success_msg = '';
$student_data = [];

function fetchStudentData($conn, $student_id) {
    $data = [];
    
    $query = $conn->prepare("
        SELECT u.*, d.name AS department_name, a.name AS advisor_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN users a ON u.advisor_id = a.id
        WHERE u.id = ? AND u.role = 'student'
    ");
    if (!$query) return $data;
    $query->bind_param("i", $student_id);
    $query->execute();
    $result = $query->get_result();
    $data['user'] = $result->fetch_assoc();
    $query->close();
    
    $query = $conn->prepare("SELECT * FROM students WHERE user_id = ?");
    if ($query) {
        $query->bind_param("i", $student_id);
        $query->execute();
        $result = $query->get_result();
        $data['details'] = $result->fetch_assoc();
        $query->close();
    }
    
    $query = $conn->prepare("
        SELECT sc.*, c.class_name 
        FROM student_classes sc
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE sc.user_id = ?
        ORDER BY sc.created_at DESC LIMIT 1
    ");
    if (!$query) {
        $query = $conn->prepare("
            SELECT sc.*, c.classname AS class_name
            FROM student_classes sc
            LEFT JOIN classes c ON sc.class_id = c.id
            WHERE sc.user_id = ?
            ORDER BY sc.created_at DESC LIMIT 1
        ");
    }
    if ($query) {
        $query->bind_param("i", $student_id);
        $query->execute();
        $result = $query->get_result();
        $data['current_class'] = $result->fetch_assoc();
        $query->close();
    }
    
    $query = $conn->prepare("
        SELECT u.*, p.occupation, p.office_location, p.office_address, 
               p.residential_address, p.emergency_contact_name, p.emergency_contact_phone,
               p.phone_number as parent_phone
        FROM parent_student_mapping psm
        JOIN users u ON psm.parent_id = u.id
        LEFT JOIN parents p ON u.id = p.user_id
        WHERE psm.student_id = ?
        ORDER BY u.id DESC
    ");
    if ($query) {
        $query->bind_param("i", $student_id);
        $query->execute();
        $result = $query->get_result();
        $data['parents'] = [];
        while ($row = $result->fetch_assoc()) $data['parents'][] = $row;
        $query->close();
    }
    
    return $data;
}

function fetchClasses($conn) {
    $classes = [];
    $query = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name");
    if (!$query) $query = $conn->query("SELECT id, classname AS class_name FROM classes ORDER BY classname");
    if ($query) while ($row = $query->fetch_assoc()) $classes[] = $row;
    return $classes;
}

function fetchDepartments($conn) {
    $departments = [];
    $query = $conn->query("SELECT id, name FROM departments ORDER BY name");
    if ($query) while ($row = $query->fetch_assoc()) $departments[] = $row;
    return $departments;
}

function fetchAdvisors($conn) {
    $advisors = [];
    $query = $conn->query("SELECT id, name FROM users WHERE role IN ('teacher', 'admin') AND status = 'active' ORDER BY name");
    if ($query) while ($row = $query->fetch_assoc()) $advisors[] = $row;
    return $advisors;
}

function fetchAcademicYears($conn) {
    $years = [];
    $table_check = $conn->query("SHOW TABLES LIKE 'academic_calendar'");
    if ($table_check && $table_check->num_rows > 0) {
        $query = $conn->query("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
        if ($query) while ($row = $query->fetch_assoc()) $years[] = ['display' => $row['year']];
    }
    if (empty($years)) {
        $query = $conn->query("SELECT DISTINCT academic_year FROM student_classes ORDER BY academic_year DESC");
        if ($query) while ($row = $query->fetch_assoc()) $years[] = ['display' => $row['academic_year']];
    }
    if (empty($years)) {
        $query = $conn->query("SELECT DISTINCT academic_year FROM students WHERE academic_year IS NOT NULL ORDER BY academic_year DESC");
        if ($query) while ($row = $query->fetch_assoc()) $years[] = ['display' => $row['academic_year']];
    }
    if (empty($years)) $years[] = ['display' => date('Y') . '/' . (date('Y') + 1)];
    return $years;
}

function fetchAllParents($conn, $exclude_ids = []) {
    $parents = [];
    $sql = "SELECT u.id, u.name, u.email, u.phone FROM users u WHERE u.role = 'parent' AND u.status = 'active'";
    if (!empty($exclude_ids)) {
        $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));
        $sql .= " AND u.id NOT IN ($placeholders)";
    }
    $sql .= " ORDER BY u.name";
    $query = $conn->prepare($sql);
    if ($query) {
        if (!empty($exclude_ids)) {
            $types = str_repeat('i', count($exclude_ids));
            $query->bind_param($types, ...$exclude_ids);
        }
        $query->execute();
        $result = $query->get_result();
        while ($row = $result->fetch_assoc()) $parents[] = $row;
        $query->close();
    }
    return $parents;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $conn->begin_transaction();
    
    try {
        $academic_year = $_POST['academic_year'] ?? '2025/2026';
        $academic_year_id = !empty($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : null;
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $phone_number = $_POST['phone_number'] ?? '';
        $address = $_POST['address'] ?? '';
        $national_id = $_POST['national_id'] ?? '';
        $gender = $_POST['gender'] ?? 'male';
        $status = $_POST['status'] ?? 'active';
        $admission_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : null;
        $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
        $advisor_id = !empty($_POST['advisor_id']) ? intval($_POST['advisor_id']) : null;
        
        // ========== 1. UPDATE STUDENT USER ==========
        // SQL placeholders: name, email, phone, phone_number, address, national_id, gender, status, admission_date, department_id, advisor_id, student_id
        // Total: 12 placeholders
        // Types: name(s), email(s), phone(s), phone_number(s), address(s), national_id(s), gender(s), status(s), admission_date(s), department_id(i), advisor_id(i), student_id(i)
        // Type string: "sssssssssiii" (9 s + 3 i = 12)
        
        $sql_update_user = "UPDATE users SET 
            name=?, email=?, phone=?, phone_number=?,
            address=?, national_id=?, gender=?, status=?,
            admission_date=?, department_id=?, advisor_id=?, updated_at=NOW()
            WHERE id=? AND role='student'";
        
        $update_user = $conn->prepare($sql_update_user);
        
        if (!$update_user) throw new Exception("Failed to prepare user update: " . $conn->error);
        
        // 12 parameters total: 9 strings + 3 integers
        $update_user->bind_param(
            "sssssssssiii",
            $name,           // 1. s
            $email,          // 2. s
            $phone,          // 3. s
            $phone_number,   // 4. s
            $address,        // 5. s
            $national_id,    // 6. s
            $gender,         // 7. s
            $status,         // 8. s
            $admission_date, // 9. s
            $department_id,  // 10. i
            $advisor_id,     // 11. i
            $student_id      // 12. i
        );
        
        if (!$update_user->execute()) throw new Exception("Failed to update user: " . $update_user->error);
        $update_user->close();
        
        // ========== 2. UPDATE/INSERT STUDENT DETAILS ==========
        $previous_school = $_POST['previous_school'] ?? '';
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $nationality = $_POST['nationality'] ?? '';
        $allergy = $_POST['allergy'] ?? '';
        $special_child = $_POST['special_child'] ?? 'no';
        $parent_occupation = $_POST['parent_occupation'] ?? '';
        $parent_office_location = $_POST['parent_office_location'] ?? '';
        $office_address = $_POST['office_address'] ?? '';
        $residential_address = $_POST['residential_address'] ?? '';
        $emergency_contact_name = $_POST['emergency_contact_name'] ?? '';
        $emergency_contact_phone = $_POST['emergency_contact_phone'] ?? '';
        
        $check_student = $conn->prepare("SELECT id FROM students WHERE user_id = ?");
        $check_student->bind_param("i", $student_id);
        $check_student->execute();
        $result = $check_student->get_result();
        $student_exists = $result->num_rows > 0;
        $check_student->close();
        
        if ($student_exists) {
            // UPDATE students: 14 "s" + 2 "i" = 16 total
            $update_student = $conn->prepare("
                UPDATE students SET 
                    student_name=?, previous_school=?, date_of_birth=?, 
                    nationality=?, allergy=?, special_child=?,
                    parent_occupation=?, parent_office_location=?, 
                    office_address=?, residential_address=?,
                    emergency_contact_name=?, emergency_contact_phone=?,
                    academic_year=?, academic_year_id=?
                WHERE user_id=?
            ");
            
            if ($update_student) {
                $update_student->bind_param(
                    "sssssssssssssii",
                    $name, $previous_school, $date_of_birth,
                    $nationality, $allergy, $special_child,
                    $parent_occupation, $parent_office_location,
                    $office_address, $residential_address,
                    $emergency_contact_name, $emergency_contact_phone,
                    $academic_year, $academic_year_id, $student_id
                );
                $update_student->execute();
                $update_student->close();
            }
        } else {
            // INSERT students: 1 "i" + 14 "s" = 15 total
            $insert_student = $conn->prepare("
                INSERT INTO students (
                    user_id, student_name, previous_school, date_of_birth,
                    nationality, allergy, special_child, parent_occupation,
                    parent_office_location, office_address, residential_address,
                    emergency_contact_name, emergency_contact_phone,
                    academic_year, academic_year_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($insert_student) {
                $insert_student->bind_param(
                    "isssssssssssssi",
                    $student_id, $name, $previous_school, $date_of_birth,
                    $nationality, $allergy, $special_child,
                    $parent_occupation, $parent_office_location,
                    $office_address, $residential_address,
                    $emergency_contact_name, $emergency_contact_phone,
                    $academic_year, $academic_year_id
                );
                $insert_student->execute();
                $insert_student->close();
            }
        }
        
        // ========== 3. UPDATE CLASS ASSIGNMENT ==========
        if (!empty($_POST['class_id'])) {
            $class_id = intval($_POST['class_id']);
            $check_class = $conn->prepare("SELECT id FROM student_classes WHERE user_id = ? AND academic_year = ?");
            if ($check_class) {
                $check_class->bind_param("is", $student_id, $academic_year);
                $check_class->execute();
                $result = $check_class->get_result();
                if ($result->num_rows > 0) {
                    $update_class = $conn->prepare("UPDATE student_classes SET class_id=?, academic_year_id=? WHERE user_id=? AND academic_year=?");
                    if ($update_class) { 
                        $update_class->bind_param("iiis", $class_id, $academic_year_id, $student_id, $academic_year); 
                        $update_class->execute(); 
                        $update_class->close(); 
                    }
                } else {
                    $insert_class = $conn->prepare("INSERT INTO student_classes (user_id, class_id, academic_year, academic_year_id) VALUES (?, ?, ?, ?)");
                    if ($insert_class) { 
                        $insert_class->bind_param("iisi", $student_id, $class_id, $academic_year, $academic_year_id); 
                        $insert_class->execute(); 
                        $insert_class->close(); 
                    }
                }
                $check_class->close();
            }
        }
        
        // ========== 4. HANDLE NEW PARENT CREATION ==========
        $new_parent_id = null;
        if (!empty($_POST['new_parent_name']) && !empty($_POST['new_parent_email'])) {
            $new_parent_name = trim($_POST['new_parent_name']);
            $new_parent_email = trim($_POST['new_parent_email']);
            $new_parent_phone = trim($_POST['new_parent_phone'] ?? '');
            $new_parent_password = password_hash('parent123', PASSWORD_DEFAULT);
            
            $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_email->bind_param("s", $new_parent_email);
            $check_email->execute();
            $email_result = $check_email->get_result();
            
            if ($email_result->num_rows > 0) {
                $existing = $email_result->fetch_assoc();
                $new_parent_id = $existing['id'];
            } else {
                // INSERT users: 5 placeholders - "sssss" (5 strings)
                $insert_user = $conn->prepare("INSERT INTO users (name, email, password, role, phone, address, gender, status, created_at, updated_at) VALUES (?, ?, ?, 'parent', ?, ?, 'other', 'active', NOW(), NOW())");
                if ($insert_user) {
                    $insert_user->bind_param("sssss", $new_parent_name, $new_parent_email, $new_parent_password, $new_parent_phone, $address);
                    $insert_user->execute();
                    $new_parent_id = $conn->insert_id;
                    $insert_user->close();
                }
            }
            $check_email->close();
            
            if ($new_parent_id) {
                $new_parent_occupation = trim($_POST['new_parent_occupation'] ?? '');
                $new_parent_office_location = trim($_POST['new_parent_office_location'] ?? '');
                $new_parent_office_address = trim($_POST['new_parent_office_address'] ?? '');
                $new_parent_residential = trim($_POST['new_parent_residential_address'] ?? '');
                $new_parent_emergency_name = trim($_POST['new_parent_emergency_contact_name'] ?? '');
                $new_parent_emergency_phone = trim($_POST['new_parent_emergency_contact_phone'] ?? '');
                
                $check_parent = $conn->prepare("SELECT id FROM parents WHERE user_id = ?");
                $check_parent->bind_param("i", $new_parent_id);
                $check_parent->execute();
                $parent_result = $check_parent->get_result();
                
                if ($parent_result->num_rows > 0) {
                    // UPDATE parents: 10 "s" + 1 "i" = 11 total
                    $update_parent = $conn->prepare("UPDATE parents SET parent_name=?, occupation=?, office_location=?, office_address=?, residential_address=?, phone_number=?, email=?, emergency_contact_name=?, emergency_contact_phone=?, academic_year=?, updated_at=NOW() WHERE user_id=?");
                    if ($update_parent) {
                        $update_parent->bind_param("ssssssssssi", 
                            $new_parent_name, $new_parent_occupation, $new_parent_office_location,
                            $new_parent_office_address, $new_parent_residential, $new_parent_phone,
                            $new_parent_email, $new_parent_emergency_name, $new_parent_emergency_phone,
                            $academic_year, $new_parent_id
                        );
                        $update_parent->execute();
                        $update_parent->close();
                    }
                } else {
                    // INSERT parents: 1 "i" + 10 "s" = 11 total
                    $insert_parent = $conn->prepare("INSERT INTO parents (user_id, parent_name, occupation, office_location, office_address, residential_address, phone_number, email, emergency_contact_name, emergency_contact_phone, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($insert_parent) {
                        $insert_parent->bind_param("issssssssss", 
                            $new_parent_id, $new_parent_name, $new_parent_occupation,
                            $new_parent_office_location, $new_parent_office_address,
                            $new_parent_residential, $new_parent_phone, $new_parent_email,
                            $new_parent_emergency_name, $new_parent_emergency_phone,
                            $academic_year
                        );
                        $insert_parent->execute();
                        $insert_parent->close();
                    }
                }
                $check_parent->close();
                
                // Link new parent to student
                $check_mapping = $conn->prepare("SELECT id FROM parent_student_mapping WHERE parent_id = ? AND student_id = ?");
                $check_mapping->bind_param("ii", $new_parent_id, $student_id);
                $check_mapping->execute();
                $mapping_result = $check_mapping->get_result();
                if ($mapping_result->num_rows === 0) {
                    $insert_mapping = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id) VALUES (?, ?)");
                    if ($insert_mapping) { 
                        $insert_mapping->bind_param("ii", $new_parent_id, $student_id); 
                        $insert_mapping->execute(); 
                        $insert_mapping->close(); 
                    }
                }
                $check_mapping->close();
            }
        }
        
        // ========== 5. ADD EXISTING PARENT ==========
        if (!empty($_POST['add_parent_id'])) {
            $add_parent_id = intval($_POST['add_parent_id']);
            $check_mapping = $conn->prepare("SELECT id FROM parent_student_mapping WHERE parent_id = ? AND student_id = ?");
            $check_mapping->bind_param("ii", $add_parent_id, $student_id);
            $check_mapping->execute();
            $mapping_result = $check_mapping->get_result();
            if ($mapping_result->num_rows === 0) {
                $insert_mapping = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id) VALUES (?, ?)");
                if ($insert_mapping) { 
                    $insert_mapping->bind_param("ii", $add_parent_id, $student_id); 
                    $insert_mapping->execute(); 
                    $insert_mapping->close(); 
                }
            }
            $check_mapping->close();
        }
        
        // ========== 6. UPDATE EXISTING PARENTS ==========
        if (isset($_POST['parent_data']) && is_array($_POST['parent_data'])) {
            foreach ($_POST['parent_data'] as $parent_id => $parent_info) {
                $parent_id = intval($parent_id);
                if (!empty($parent_info['name']) && !empty($parent_info['email'])) {
                    $p_name = $parent_info['name'];
                    $p_email = $parent_info['email'];
                    $p_phone = $parent_info['phone'] ?? '';
                    $p_address = $parent_info['address'] ?? '';
                    
                    // UPDATE users: 4 "s" + 1 "i" = 5 total
                    $update_parent_user = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, address=?, updated_at=NOW() WHERE id=? AND role='parent'");
                    if ($update_parent_user) {
                        $update_parent_user->bind_param("ssssi", $p_name, $p_email, $p_phone, $p_address, $parent_id);
                        $update_parent_user->execute();
                        $update_parent_user->close();
                    }
                    
                    $p_occupation = $parent_info['occupation'] ?? '';
                    $p_office_location = $parent_info['office_location'] ?? '';
                    $p_office_address = $parent_info['office_address'] ?? '';
                    $p_residential = $parent_info['residential_address'] ?? $p_address;
                    $p_emergency_name = $parent_info['emergency_contact_name'] ?? '';
                    $p_emergency_phone = $parent_info['emergency_contact_phone'] ?? '';
                    
                    // UPDATE parents: 10 "s" + 1 "i" = 11 total
                    $update_parent = $conn->prepare("UPDATE parents SET parent_name=?, occupation=?, office_location=?, office_address=?, residential_address=?, email=?, phone_number=?, emergency_contact_name=?, emergency_contact_phone=?, academic_year=?, updated_at=NOW() WHERE user_id=?");
                    if ($update_parent) {
                        $update_parent->bind_param("ssssssssssi", 
                            $p_name, $p_occupation, $p_office_location,
                            $p_office_address, $p_residential, $p_email,
                            $p_phone, $p_emergency_name, $p_emergency_phone,
                            $academic_year, $parent_id
                        );
                        $update_parent->execute();
                        $update_parent->close();
                    }
                }
            }
        }
        
        // ========== 7. REMOVE PARENT MAPPING ==========
        if (!empty($_POST['remove_parent_id'])) {
            $remove_parent_id = intval($_POST['remove_parent_id']);
            $delete_mapping = $conn->prepare("DELETE FROM parent_student_mapping WHERE parent_id = ? AND student_id = ?");
            if ($delete_mapping) { 
                $delete_mapping->bind_param("ii", $remove_parent_id, $student_id); 
                $delete_mapping->execute(); 
                $delete_mapping->close(); 
            }
        }
        
        $conn->commit();
        $success_msg = "Student and parent information updated successfully!";
        $student_data = fetchStudentData($conn, $student_id);
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error updating student: " . $e->getMessage();
    }
} else {
    $student_data = fetchStudentData($conn, $student_id);
    if (empty($student_data['user'])) $error_msg = "Student not found.";
}

$classes = fetchClasses($conn);
$departments = fetchDepartments($conn);
$advisors = fetchAdvisors($conn);
$academic_years = fetchAcademicYears($conn);
$existing_parent_ids = array_map(function($p) { return $p['id']; }, $student_data['parents'] ?? []);
$available_parents = fetchAllParents($conn, $existing_parent_ids);

function formatDateValue($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return '';
    return date('Y-m-d', strtotime($date));
}
function getAcademicYearDisplay($year_row) {
    return $year_row['display'] ?? $year_row['year_name'] ?? $year_row['academic_year'] ?? 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student - <?php echo htmlspecialchars($student_data['user']['name'] ?? 'Student'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --orange-primary: #FF6B35; --orange-dark: #E55A2B; --orange-light: #FF8C5A;
            --orange-pale: #FFF1EB; --orange-gradient: linear-gradient(135deg, #FF6B35 0%, #FF8C5A 100%);
            --bg-main: #FFF5F0; --bg-card: #FFFFFF; --text-dark: #1a1a1a;
            --text-medium: #4a4a4a; --text-light: #6b7280; --border-color: #FFE0D0;
            --danger: #dc2626; --success: #059669;
            --shadow-sm: 0 1px 3px rgba(255, 107, 53, 0.1);
            --shadow-md: 0 4px 6px rgba(255, 107, 53, 0.1);
            --shadow-lg: 0 10px 25px rgba(255, 107, 53, 0.15);
            --radius-sm: 8px; --radius-md: 12px; --radius-lg: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-main); padding: 20px; line-height: 1.6; color: var(--text-dark); }
        .container { max-width: 1000px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .page-title { font-size: 24px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: var(--orange-primary); }
        .btn { padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 14px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: var(--transition); font-family: 'Inter', sans-serif; }
        .btn-primary { background: var(--orange-gradient); color: white; box-shadow: var(--shadow-sm); }
        .btn-primary:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .btn-secondary { background: #6b7280; color: white; } .btn-secondary:hover { background: #4b5563; }
        .btn-danger { background: var(--danger); color: white; } .btn-danger:hover { background: #b91c1c; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .alert { padding: 15px 20px; border-radius: var(--radius-sm); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; }
        .alert-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .form-card { background: var(--bg-card); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); overflow: hidden; animation: fadeInUp 0.5s ease-out; }
        .form-card-header { background: var(--orange-gradient); color: white; padding: 20px 30px; display: flex; align-items: center; gap: 12px; }
        .form-card-header i { font-size: 24px; } .form-card-header h2 { font-size: 20px; font-weight: 600; }
        .form-card-body { padding: 30px; }
        .form-section { margin-bottom: 30px; } .form-section:last-child { margin-bottom: 0; }
        .section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--orange-primary); }
        .section-icon { width: 35px; height: 35px; background: var(--orange-pale); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; color: var(--orange-primary); font-size: 16px; }
        .section-title { font-size: 16px; font-weight: 600; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .form-group { display: flex; flex-direction: column; } .form-group.full-width { grid-column: 1 / -1; }
        .form-label { font-size: 13px; font-weight: 600; color: var(--text-medium); margin-bottom: 6px; display: flex; align-items: center; gap: 5px; }
        .form-label .required { color: var(--danger); }
        .form-input, .form-select, .form-textarea { padding: 10px 14px; border: 2px solid #e5e7eb; border-radius: var(--radius-sm); font-size: 14px; font-family: 'Inter', sans-serif; color: var(--text-dark); background: white; transition: var(--transition); width: 100%; }
        .form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: var(--orange-primary); box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1); }
        .form-textarea { resize: vertical; min-height: 80px; }
        .form-select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); background-position: right 10px center; background-repeat: no-repeat; background-size: 20px; padding-right: 40px; }
        .parent-card { background: var(--orange-pale); border-radius: var(--radius-md); padding: 20px; margin-bottom: 15px; border: 1px solid var(--border-color); }
        .parent-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color); }
        .parent-card-title { font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .parent-card-title i { color: var(--orange-primary); }
        .parent-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .add-section { margin-top: 15px; padding: 15px; background: #f9fafb; border-radius: var(--radius-sm); border: 1px dashed #d1d5db; }
        .add-section h4 { font-size: 14px; font-weight: 600; margin-bottom: 10px; }
        .add-section.new-parent { border-color: var(--success); background: #f0fdf4; }
        .add-section.new-parent h4 { color: var(--success); }
        .form-actions { display: flex; gap: 12px; justify-content: flex-end; padding-top: 20px; border-top: 1px solid var(--border-color); }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 768px) { .form-grid, .parent-grid { grid-template-columns: 1fr; } .form-card-body { padding: 20px; } }
        @media (max-width: 480px) { body { padding: 10px; } .form-actions { flex-direction: column; } .btn { width: 100%; justify-content: center; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <div class="page-title"><i class="fas fa-user-edit"></i>Edit Student Profile</div>
            <div><a href="view_student.php?id=<?php echo $student_id; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Profile</a></div>
        </div>
        
        <?php if ($success_msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?php echo $success_msg; ?></div><?php endif; ?>
        <?php if ($error_msg): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo $error_msg; ?></div><?php endif; ?>
        
        <?php if (!empty($student_data['user'])): ?>
        <form method="POST" action="" id="editStudentForm">
            <div class="form-card">
                <div class="form-card-header"><i class="fas fa-user-graduate"></i><h2><?php echo htmlspecialchars($student_data['user']['name']); ?></h2></div>
                <div class="form-card-body">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <div class="section-header"><div class="section-icon"><i class="fas fa-user"></i></div><div class="section-title">Basic Information</div></div>
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label"><span class="required">*</span> Full Name</label><input type="text" name="name" class="form-input" required value="<?php echo htmlspecialchars($student_data['user']['name'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label"><span class="required">*</span> Email</label><input type="email" name="email" class="form-input" required value="<?php echo htmlspecialchars($student_data['user']['email'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-input" value="<?php echo htmlspecialchars($student_data['user']['phone'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Phone (Alt)</label><input type="text" name="phone_number" class="form-input" value="<?php echo htmlspecialchars($student_data['user']['phone_number'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label"><span class="required">*</span> Gender</label><select name="gender" class="form-select" required><option value="male" <?php echo ($student_data['user']['gender']??'')=='male'?'selected':''; ?>>Male</option><option value="female" <?php echo ($student_data['user']['gender']??'')=='female'?'selected':''; ?>>Female</option><option value="other" <?php echo ($student_data['user']['gender']??'')=='other'?'selected':''; ?>>Other</option></select></div>
                            <div class="form-group"><label class="form-label"><span class="required">*</span> Status</label><select name="status" class="form-select" required><option value="active" <?php echo ($student_data['user']['status']??'')=='active'?'selected':''; ?>>Active</option><option value="inactive" <?php echo ($student_data['user']['status']??'')=='inactive'?'selected':''; ?>>Inactive</option><option value="suspended" <?php echo ($student_data['user']['status']??'')=='suspended'?'selected':''; ?>>Suspended</option><option value="graduated" <?php echo ($student_data['user']['status']??'')=='graduated'?'selected':''; ?>>Graduated</option></select></div>
                            <div class="form-group"><label class="form-label">National ID</label><input type="text" name="national_id" class="form-input" value="<?php echo htmlspecialchars($student_data['user']['national_id'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Admission Date</label><input type="date" name="admission_date" class="form-input" value="<?php echo formatDateValue($student_data['user']['admission_date'] ?? ''); ?>"></div>
                            <div class="form-group full-width"><label class="form-label">Address</label><textarea name="address" class="form-textarea" rows="2"><?php echo htmlspecialchars($student_data['user']['address'] ?? ''); ?></textarea></div>
                        </div>
                    </div>
                    
                    <!-- Academic Information -->
                    <div class="form-section">
                        <div class="section-header"><div class="section-icon"><i class="fas fa-graduation-cap"></i></div><div class="section-title">Academic Information</div></div>
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Class</label><select name="class_id" class="form-select"><option value="">Select Class</option><?php foreach ($classes as $class): ?><option value="<?php echo $class['id']; ?>" <?php echo ($student_data['current_class']['class_id']??'')==$class['id']?'selected':''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="form-label">Academic Year</label><div style="display:flex;gap:10px;"><input type="text" name="academic_year" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['academic_year'] ?? $student_data['current_class']['academic_year'] ?? '2025/2026'); ?>" style="flex:1;"><select name="academic_year_select" class="form-select" onchange="document.getElementsByName('academic_year')[0].value=this.value;" style="flex:1;"><option value="">Quick Select</option><?php foreach ($academic_years as $year): ?><option value="<?php echo htmlspecialchars(getAcademicYearDisplay($year)); ?>" <?php $cay=$student_data['details']['academic_year']??$student_data['current_class']['academic_year']??''; echo $cay==getAcademicYearDisplay($year)?'selected':''; ?>><?php echo htmlspecialchars(getAcademicYearDisplay($year)); ?></option><?php endforeach; ?></select></div></div>
                            <div class="form-group"><label class="form-label">Department</label><select name="department_id" class="form-select"><option value="">Select</option><?php foreach ($departments as $dept): ?><option value="<?php echo $dept['id']; ?>" <?php echo ($student_data['user']['department_id']??'')==$dept['id']?'selected':''; ?>><?php echo htmlspecialchars($dept['name']); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="form-label">Advisor</label><select name="advisor_id" class="form-select"><option value="">Select</option><?php foreach ($advisors as $advisor): ?><option value="<?php echo $advisor['id']; ?>" <?php echo ($student_data['user']['advisor_id']??'')==$advisor['id']?'selected':''; ?>><?php echo htmlspecialchars($advisor['name']); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="form-label">Previous School</label><input type="text" name="previous_school" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['previous_school'] ?? ''); ?>"></div>
                        </div>
                    </div>
                    
                    <!-- Personal Details -->
                    <div class="form-section">
                        <div class="section-header"><div class="section-icon"><i class="fas fa-id-card"></i></div><div class="section-title">Personal Details</div></div>
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" class="form-input" value="<?php echo formatDateValue($student_data['details']['date_of_birth'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Nationality</label><input type="text" name="nationality" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['nationality'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Allergies</label><input type="text" name="allergy" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['allergy'] ?? ''); ?>" placeholder="e.g., None"></div>
                            <div class="form-group"><label class="form-label">Special Child</label><select name="special_child" class="form-select"><option value="no" <?php echo ($student_data['details']['special_child']??'')=='no'?'selected':''; ?>>No</option><option value="yes" <?php echo ($student_data['details']['special_child']??'')=='yes'?'selected':''; ?>>Yes</option></select></div>
                            <div class="form-group full-width"><label class="form-label">Residential Address</label><textarea name="residential_address" class="form-textarea" rows="2"><?php echo htmlspecialchars($student_data['details']['residential_address'] ?? ''); ?></textarea></div>
                        </div>
                    </div>
                    
                    <!-- Parent/Guardian Information -->
                    <div class="form-section">
                        <div class="section-header"><div class="section-icon"><i class="fas fa-users"></i></div><div class="section-title">Parent / Guardian Information</div></div>
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Parent Occupation</label><input type="text" name="parent_occupation" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['parent_occupation'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Parent Office Location</label><input type="text" name="parent_office_location" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['parent_office_location'] ?? ''); ?>"></div>
                            <div class="form-group full-width"><label class="form-label">Office Address</label><textarea name="office_address" class="form-textarea" rows="2"><?php echo htmlspecialchars($student_data['details']['office_address'] ?? ''); ?></textarea></div>
                        </div>
                        
                        <!-- Existing Parents -->
                        <?php if (!empty($student_data['parents'])): ?>
                            <?php foreach ($student_data['parents'] as $index => $parent): ?>
                                <div class="parent-card">
                                    <div class="parent-card-header">
                                        <div class="parent-card-title"><i class="fas fa-user-circle"></i>Parent <?php echo $index + 1; ?></div>
                                        <button type="submit" name="remove_parent_id" value="<?php echo $parent['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Remove this parent link?');"><i class="fas fa-unlink"></i> Remove</button>
                                    </div>
                                    <div class="parent-grid">
                                        <div class="form-group"><label class="form-label">Name</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][name]" class="form-input" value="<?php echo htmlspecialchars($parent['name'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Email</label><input type="email" name="parent_data[<?php echo $parent['id']; ?>][email]" class="form-input" value="<?php echo htmlspecialchars($parent['email'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Phone</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][phone]" class="form-input" value="<?php echo htmlspecialchars($parent['phone'] ?? $parent['parent_phone'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Occupation</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][occupation]" class="form-input" value="<?php echo htmlspecialchars($parent['occupation'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Office Location</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][office_location]" class="form-input" value="<?php echo htmlspecialchars($parent['office_location'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Address</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][address]" class="form-input" value="<?php echo htmlspecialchars($parent['address'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Residential</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][residential_address]" class="form-input" value="<?php echo htmlspecialchars($parent['residential_address'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Office Address</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][office_address]" class="form-input" value="<?php echo htmlspecialchars($parent['office_address'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Emergency Contact</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][emergency_contact_name]" class="form-input" value="<?php echo htmlspecialchars($parent['emergency_contact_name'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Emergency Phone</label><input type="text" name="parent_data[<?php echo $parent['id']; ?>][emergency_contact_phone]" class="form-input" value="<?php echo htmlspecialchars($parent['emergency_contact_phone'] ?? ''); ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Add Existing Parent -->
                        <?php if (!empty($available_parents)): ?>
                            <div class="add-section">
                                <h4><i class="fas fa-link" style="color:var(--orange-primary);"></i> Link Existing Parent</h4>
                                <div style="display:flex;gap:10px;align-items:flex-end;">
                                    <div class="form-group" style="flex:1;margin-bottom:0;"><label class="form-label">Select Parent</label><select name="add_parent_id" class="form-select"><option value="">Choose...</option><?php foreach ($available_parents as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name'].' ('.$p['email'].')'); ?></option><?php endforeach; ?></select></div>
                                    <button type="submit" name="update_student" class="btn btn-primary btn-sm"><i class="fas fa-link"></i> Link</button>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Add NEW Parent -->
                        <div class="add-section new-parent">
                            <h4><i class="fas fa-user-plus"></i> Register New Parent</h4>
                            <p style="font-size:12px;color:var(--text-light);margin-bottom:12px;">Create a new parent account and link to this student.</p>
                            <div class="parent-grid">
                                <div class="form-group"><label class="form-label"><span class="required">*</span> Parent Name</label><input type="text" name="new_parent_name" class="form-input" placeholder="Full name"></div>
                                <div class="form-group"><label class="form-label"><span class="required">*</span> Parent Email</label><input type="email" name="new_parent_email" class="form-input" placeholder="parent@example.com"></div>
                                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="new_parent_phone" class="form-input" placeholder="Phone"></div>
                                <div class="form-group"><label class="form-label">Occupation</label><input type="text" name="new_parent_occupation" class="form-input" placeholder="Occupation"></div>
                                <div class="form-group"><label class="form-label">Office Location</label><input type="text" name="new_parent_office_location" class="form-input" placeholder="Work location"></div>
                                <div class="form-group"><label class="form-label">Office Address</label><input type="text" name="new_parent_office_address" class="form-input" placeholder="Office address"></div>
                                <div class="form-group"><label class="form-label">Residential</label><input type="text" name="new_parent_residential_address" class="form-input" placeholder="Home address"></div>
                                <div class="form-group"><label class="form-label">Emergency Contact</label><input type="text" name="new_parent_emergency_contact_name" class="form-input" placeholder="Contact name"></div>
                                <div class="form-group"><label class="form-label">Emergency Phone</label><input type="text" name="new_parent_emergency_contact_phone" class="form-input" placeholder="Contact phone"></div>
                            </div>
                            <p style="font-size:11px;color:var(--text-light);margin-top:8px;"><i class="fas fa-info-circle"></i> Default password: <strong>parent123</strong></p>
                        </div>
                    </div>
                    
                    <!-- Emergency Contact -->
                    <div class="form-section">
                        <div class="section-header"><div class="section-icon"><i class="fas fa-phone-alt"></i></div><div class="section-title">Student Emergency Contact</div></div>
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Contact Name</label><input type="text" name="emergency_contact_name" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['emergency_contact_name'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Contact Phone</label><input type="text" name="emergency_contact_phone" class="form-input" value="<?php echo htmlspecialchars($student_data['details']['emergency_contact_phone'] ?? ''); ?>"></div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <a href="view_student.php?id=<?php echo $student_id; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                        <button type="submit" name="update_student" class="btn btn-primary"><i class="fas fa-save"></i> Save All Changes</button>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <script>
        document.getElementById('editStudentForm')?.addEventListener('submit', function(e) {
            const name = this.querySelector('[name="name"]')?.value.trim();
            const email = this.querySelector('[name="email"]')?.value.trim();
            const npn = this.querySelector('[name="new_parent_name"]')?.value.trim();
            const npe = this.querySelector('[name="new_parent_email"]')?.value.trim();
            if (!name) { e.preventDefault(); alert('Enter student name.'); return; }
            if (!email) { e.preventDefault(); alert('Enter student email.'); return; }
            if (npn && !npe) { e.preventDefault(); alert('Enter new parent email.'); return; }
            if (!npn && npe) { e.preventDefault(); alert('Enter new parent name.'); return; }
            if (!confirm('Save all changes?')) e.preventDefault();
        });
    </script>
</body>
</html>
<?php if (isset($conn)) $conn->close(); ob_end_flush(); ?>