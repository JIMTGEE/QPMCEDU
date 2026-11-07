<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to admin_admissions.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
    $_SESSION['error_msg'] = "Access denied. You must be an admin to access this page.";
    header("Location: /login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Include log activity function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
} else {
    function logActivity($conn, $user_id, $action, $description) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("issss", $user_id, $action, $description, $ip_address, $user_agent);
            $stmt->execute();
            $stmt->close();
        }
    }
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
if ($stmt) {
    $stmt->bind_param("is", $admin_id, $csrf_token);
    $stmt->execute();
    $stmt->close();
}

// Get current page URL for form actions
$current_page = $_SERVER['PHP_SELF'];
$query_string = $_SERVER['QUERY_STRING'] ?? '';

// Handle AJAX requests BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    ob_clean(); // Clear any previous output
    
    // Verify CSRF token
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $csrf_stmt = $conn->prepare("SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $csrf_stmt->bind_param("is", $admin_id, $posted_csrf);
    $csrf_stmt->execute();
    $csrf_stmt->store_result();
    if ($csrf_stmt->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token. Please refresh the page and try again.']);
        exit();
    }
    $csrf_stmt->close();
    
    $action = $_POST['action'] ?? '';
    $application_id = (int)($_POST['application_id'] ?? 0);
    
    if ($application_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid application ID.']);
        exit();
    }
    
    // Fetch application details
    $app_stmt = $conn->prepare("SELECT a.*, c.class_name, d.name as department_name 
                                FROM admissions a 
                                LEFT JOIN classes c ON a.class_id = c.id 
                                LEFT JOIN departments d ON a.department_id = d.id 
                                WHERE a.id = ?");
    $app_stmt->bind_param("i", $application_id);
    $app_stmt->execute();
    $application = $app_stmt->get_result()->fetch_assoc();
    $app_stmt->close();
    
    if (!$application) {
        echo json_encode(['success' => false, 'message' => 'Application not found.']);
        exit();
    }
    
    if ($action === 'update_status') {
        $new_status = $_POST['status'] ?? '';
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        
        if (!in_array($new_status, ['pending', 'accepted', 'rejected', 'cancelled'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid status.']);
            exit();
        }
        
        $update_stmt = $conn->prepare("UPDATE admissions SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("ssi", $new_status, $admin_notes, $application_id);
        
        if ($update_stmt->execute()) {
            if (function_exists('logActivity')) {
                logActivity($conn, $admin_id, "Update Application Status", "Updated application #$application_id status to $new_status");
            }
            echo json_encode(['success' => true, 'message' => 'Status updated successfully.', 'new_status' => $new_status]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $update_stmt->error]);
        }
        $update_stmt->close();
        exit();
    }
    
    elseif ($action === 'accept_application') {
        // Check if application is already accepted
        if ($application['status'] === 'accepted') {
            echo json_encode(['success' => false, 'message' => 'This application has already been accepted.']);
            exit();
        }
        
        // Check if email already exists in users table
        $check_user_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check_user_stmt->bind_param("s", $application['email']);
        $check_user_stmt->execute();
        $user_exists = $check_user_stmt->get_result()->num_rows > 0;
        $check_user_stmt->close();
        
        if ($user_exists) {
            echo json_encode(['success' => false, 'message' => 'A user with this email already exists. Please check the application or use a different email.']);
            exit();
        }
        
        // Check if class exists
        if (empty($application['class_id'])) {
            echo json_encode(['success' => false, 'message' => 'No class assigned to this application. Please assign a class first.']);
            exit();
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Generate password (random 12 characters for better security)
            $generated_password = bin2hex(random_bytes(6)); // 12 character hex string
            $hashed_password = password_hash($generated_password, PASSWORD_DEFAULT);
            
            // Check if parent already exists in users table
            $parent_user_id = null;
            $parent_exists = false;
            
            // Look for existing parent by email or phone
            if (!empty($application['father_email']) || !empty($application['father_phone'])) {
                $check_parent_stmt = $conn->prepare("SELECT id FROM users WHERE (email = ? OR phone = ?) AND role = 'parent'");
                $check_parent_stmt->bind_param("ss", $application['father_email'], $application['father_phone']);
                $check_parent_stmt->execute();
                $parent_result = $check_parent_stmt->get_result();
                
                if ($parent_result->num_rows > 0) {
                    $parent_exists = true;
                    $parent_row = $parent_result->fetch_assoc();
                    $parent_user_id = $parent_row['id'];
                }
                $check_parent_stmt->close();
            }
            
            // Also check mother's email/phone
            if (!$parent_exists && (!empty($application['mother_email']) || !empty($application['mother_phone']))) {
                $check_mother_stmt = $conn->prepare("SELECT id FROM users WHERE (email = ? OR phone = ?) AND role = 'parent'");
                $check_mother_stmt->bind_param("ss", $application['mother_email'], $application['mother_phone']);
                $check_mother_stmt->execute();
                $parent_result = $check_mother_stmt->get_result();
                
                if ($parent_result->num_rows > 0) {
                    $parent_exists = true;
                    $parent_row = $parent_result->fetch_assoc();
                    $parent_user_id = $parent_row['id'];
                }
                $check_mother_stmt->close();
            }
            
            // Insert student user
            $student_stmt = $conn->prepare("
                INSERT INTO users (name, email, password, role, status, phone, gender, address, created_at) 
                VALUES (?, ?, ?, 'student', 'active', ?, ?, ?, NOW())
            ");
            
            // Use father's national ID for student if available, otherwise null
            $student_phone = !empty($application['student_phone']) ? $application['student_phone'] : '';
            $student_gender = !empty($application['gender']) ? $application['gender'] : 'male';
            $student_address = !empty($application['address']) ? $application['address'] : '';
            
            $student_stmt->bind_param("sssssss", 
                $application['full_name'],
                $application['email'],
                $hashed_password,
                $student_phone,
                $student_gender,
                $student_address
            );
            $student_stmt->execute();
            $student_user_id = $conn->insert_id;
            $student_stmt->close();
            
            // Insert student details into students table if it exists
            $students_table_exists = $conn->query("SHOW TABLES LIKE 'students'")->num_rows > 0;
            if ($students_table_exists) {
                $student_details_stmt = $conn->prepare("
                    INSERT INTO students (user_id, student_name, previous_school, date_of_birth, nationality, 
                    allergy, special_child, emergency_contact_name, emergency_contact_phone, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $student_details_stmt->bind_param("issssssss",
                    $student_user_id,
                    $application['full_name'],
                    $application['previous_school'],
                    $application['date_of_birth'],
                    $application['nationality'],
                    $application['allergy'],
                    $application['special_child'],
                    $application['emergency_contact_name'],
                    $application['emergency_contact_phone']
                );
                $student_details_stmt->execute();
                $student_details_stmt->close();
            }
            
            // Create or get parent user
            $parent_email = '';
            if (!$parent_exists) {
                $parent_name = !empty($application['father_name']) ? $application['father_name'] : 
                              (!empty($application['mother_name']) ? $application['mother_name'] : 
                              (!empty($application['guardian_name']) ? $application['guardian_name'] : 'Parent of ' . $application['full_name']));
                
                $parent_email = !empty($application['father_email']) ? $application['father_email'] :
                               (!empty($application['mother_email']) ? $application['mother_email'] :
                               (!empty($application['guardian_email']) ? $application['guardian_email'] : 'parent_' . $application_id . '@placeholder.com'));
                
                $parent_phone = !empty($application['father_phone']) ? $application['father_phone'] :
                               (!empty($application['mother_phone']) ? $application['mother_phone'] :
                               (!empty($application['guardian_phone']) ? $application['guardian_phone'] : ''));
                
                $parent_address = !empty($application['residential_address']) ? $application['residential_address'] :
                                 (!empty($application['address']) ? $application['address'] : '');
                
                $parent_id_number = !empty($application['father_national_id']) ? $application['father_national_id'] :
                                   (!empty($application['mother_national_id']) ? $application['mother_national_id'] : '');
                
                $parent_stmt = $conn->prepare("
                    INSERT INTO users (name, email, password, role, status, phone, address, national_id, created_at) 
                    VALUES (?, ?, ?, 'parent', 'active', ?, ?, ?, NOW())
                ");
                $parent_stmt->bind_param("sssssss", 
                    $parent_name,
                    $parent_email,
                    $hashed_password,
                    $parent_phone,
                    $parent_address,
                    $parent_id_number
                );
                $parent_stmt->execute();
                $parent_user_id = $conn->insert_id;
                $parent_stmt->close();
                
                // Insert parent details if parents table exists
                $parents_table_exists = $conn->query("SHOW TABLES LIKE 'parents'")->num_rows > 0;
                if ($parents_table_exists) {
                    $parent_details_stmt = $conn->prepare("
                        INSERT INTO parents (user_id, parent_name, father_name, mother_name, father_occupation, 
                        mother_occupation, father_office_location, mother_office_location, 
                        phone_number, email, emergency_contact_name, emergency_contact_phone, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $parent_details_stmt->bind_param("isssssssssss",
                        $parent_user_id,
                        $parent_name,
                        $application['father_name'],
                        $application['mother_name'],
                        $application['father_occupation'],
                        $application['mother_occupation'],
                        $application['father_office_location'],
                        $application['mother_office_location'],
                        $parent_phone,
                        $parent_email,
                        $application['emergency_contact_name'],
                        $application['emergency_contact_phone']
                    );
                    $parent_details_stmt->execute();
                    $parent_details_stmt->close();
                }
            } else {
                // Get parent email for existing parent
                $get_parent_email = $conn->prepare("SELECT email FROM users WHERE id = ?");
                $get_parent_email->bind_param("i", $parent_user_id);
                $get_parent_email->execute();
                $result = $get_parent_email->get_result();
                if ($row = $result->fetch_assoc()) {
                    $parent_email = $row['email'];
                }
                $get_parent_email->close();
            }
            
            // Link student to parent if mapping table exists
            $parent_student_mapping_exists = $conn->query("SHOW TABLES LIKE 'parent_student_mapping'")->num_rows > 0;
            if ($parent_student_mapping_exists && $parent_user_id) {
                $link_stmt = $conn->prepare("
                    INSERT INTO parent_student_mapping (parent_id, student_id, created_at) 
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE created_at = created_at
                ");
                $link_stmt->bind_param("ii", $parent_user_id, $student_user_id);
                $link_stmt->execute();
                $link_stmt->close();
            }
            
            // Assign student to class if student_classes table exists
            $student_classes_exists = $conn->query("SHOW TABLES LIKE 'student_classes'")->num_rows > 0;
            if ($student_classes_exists && $application['class_id']) {
                $class_assign_stmt = $conn->prepare("
                    INSERT INTO student_classes (user_id, class_id, academic_year, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $class_assign_stmt->bind_param("iis", $student_user_id, $application['class_id'], $application['academic_year']);
                $class_assign_stmt->execute();
                $class_assign_stmt->close();
            }
            
            // Update application status to accepted
            $update_stmt = $conn->prepare("UPDATE admissions SET status = 'accepted', accepted_by = ?, accepted_at = NOW(), updated_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("ii", $admin_id, $application_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $conn->commit();
            
            if (function_exists('logActivity')) {
                logActivity($conn, $admin_id, "Accept Application", "Accepted application #$application_id - Student: {$application['full_name']}");
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "Application accepted successfully! Student and parent accounts have been created.",
                'credentials' => [
                    'student_email' => $application['email'],
                    'parent_email' => $parent_email,
                    'password' => $generated_password,
                    'student_name' => $application['full_name'],
                    'parent_name' => $parent_name ?? 'Existing Parent'
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error accepting application: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error accepting application. Please try again or contact support.']);
        }
        exit();
    }
    
    elseif ($action === 'get_application_details') {
        echo json_encode(['success' => true, 'application' => $application]);
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit();
}

// Handle bulk status update (non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && !empty($_POST['bulk_action'])) {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $csrf_stmt = $conn->prepare("SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $csrf_stmt->bind_param("is", $admin_id, $posted_csrf);
    $csrf_stmt->execute();
    $csrf_stmt->store_result();
    if ($csrf_stmt->num_rows === 0) {
        $_SESSION['error_msg'] = "Invalid or expired CSRF token.";
        header("Location: " . $current_page);
        exit();
    }
    $csrf_stmt->close();
    
    $bulk_action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (empty($selected_ids)) {
        $_SESSION['error_msg'] = "No applications selected.";
    } elseif (!in_array($bulk_action, ['pending', 'accepted', 'rejected', 'cancelled'])) {
        $_SESSION['error_msg'] = "Invalid action selected.";
    } else {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $update_stmt = $conn->prepare("UPDATE admissions SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)");
        $params = array_merge([$bulk_action], array_map('intval', $selected_ids));
        $types = 's' . str_repeat('i', count($selected_ids));
        $update_stmt->bind_param($types, ...$params);
        
        if ($update_stmt->execute()) {
            $_SESSION['success_msg'] = count($selected_ids) . " application(s) updated to '" . ucfirst($bulk_action) . "'.";
            if (function_exists('logActivity')) {
                logActivity($conn, $admin_id, "Bulk Update Applications", "Updated " . count($selected_ids) . " applications to status: $bulk_action");
            }
        } else {
            $_SESSION['error_msg'] = "Failed to update applications: " . $update_stmt->error;
        }
        $update_stmt->close();
    }
    header("Location: " . $current_page);
    exit();
}

// Pagination settings
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter settings
$status_filter = $_GET['status'] ?? 'pending';
$search_filter = trim($_GET['search'] ?? '');

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_filter)) {
    $where_conditions[] = "(a.full_name LIKE ? OR a.email LIKE ? OR a.father_name LIKE ? OR a.father_email LIKE ? OR a.mother_name LIKE ? OR a.mother_email LIKE ? OR a.student_phone LIKE ? OR a.father_phone LIKE ? OR a.mother_phone LIKE ? OR a.guardian_name LIKE ?)";
    $search_param = "%$search_filter%";
    for ($i = 0; $i < 10; $i++) {
        $params[] = $search_param;
        $types .= "s";
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM admissions a $where_clause";
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total_result = $count_stmt->get_result();
    $total_rows = $total_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_rows = 0;
}
$total_pages = ceil($total_rows / $limit);

// Fetch admissions with pagination
$sql = "
    SELECT 
        a.*,
        c.class_name,
        d.name as department_name
    FROM admissions a
    LEFT JOIN classes c ON a.class_id = c.id
    LEFT JOIN departments d ON a.department_id = d.id
    $where_clause
    ORDER BY a.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $all_params = array_merge($params, [$limit, $offset]);
    $all_types = $types . "ii";
    $stmt->bind_param($all_types, ...$all_params);
    $stmt->execute();
    $admissions_result = $stmt->get_result();
    $stmt->close();
} else {
    $admissions_result = false;
}

// Fetch classes for dropdown
$classes = [];
$class_result = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name");
if ($class_result) {
    while ($row = $class_result->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Fetch departments for dropdown
$departments = [];
$dept_result = $conn->query("SELECT id, name FROM departments ORDER BY name");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Include admin header
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin admissions management for QueensPa School">
    <title>Admissions Management - QueensPa School</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.3s ease;
        }
        .status-pending { background-color: #fef3c7; color: #d97706; }
        .status-accepted { background-color: #d1fae5; color: #059669; }
        .status-rejected { background-color: #fee2e2; color: #dc2626; }
        .status-cancelled { background-color: #f3f4f6; color: #6b7280; }
        
        .action-btn {
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .action-btn:active {
            transform: translateY(0);
        }
        .action-btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }
        .action-btn i {
            transition: transform 0.3s ease;
        }
        .action-btn:hover i {
            transform: scale(1.2);
        }
        
        .modal-overlay {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .modal-overlay.hidden {
            opacity: 0;
            visibility: hidden;
        }
        .modal-content {
            transition: transform 0.3s ease;
        }
        .modal-overlay.hidden .modal-content {
            transform: scale(0.9);
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }
        .toast.show {
            transform: translateX(0);
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto px-4 py-6">
    <!-- Header -->
    <div class="bg-gradient-to-r from-orange-500 to-orange-700 rounded-2xl shadow-lg p-6 mb-6">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="flex items-center mb-4 md:mb-0">
                <i class="fas fa-graduation-cap text-white text-3xl mr-3"></i>
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-white">Admissions Management</h1>
                    <p class="text-orange-100 text-sm mt-1">Review and manage student applications</p>
                </div>
            </div>
            <div class="flex space-x-3">
                <a href="dashboard1.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg transition flex items-center">
                    <i class="fas fa-chart-line mr-2"></i> Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <?php
    $stats_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM admissions";
    $stats_result = $conn->query($stats_sql);
    $stats = $stats_result ? $stats_result->fetch_assoc() : ['total' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0];
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-md p-4 border-l-4 border-orange-500">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-gray-500 text-sm">Total Applications</p>
                    <p class="text-2xl font-bold"><?php echo number_format($stats['total']); ?></p>
                </div>
                <i class="fas fa-file-alt text-orange-400 text-3xl"></i>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-md p-4 border-l-4 border-yellow-500">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-gray-500 text-sm">Pending Review</p>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['pending']); ?></p>
                </div>
                <i class="fas fa-clock text-yellow-400 text-3xl"></i>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-md p-4 border-l-4 border-green-500">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-gray-500 text-sm">Accepted</p>
                    <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['accepted']); ?></p>
                </div>
                <i class="fas fa-check-circle text-green-400 text-3xl"></i>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-md p-4 border-l-4 border-red-500">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-gray-500 text-sm">Rejected</p>
                    <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['rejected']); ?></p>
                </div>
                <i class="fas fa-times-circle text-red-400 text-3xl"></i>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded">
            <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
            <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-md p-4 mb-6">
        <form method="GET" action="<?php echo htmlspecialchars($current_page); ?>" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[150px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Status Filter</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 focus:border-orange-500">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Applications</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="accepted" <?php echo $status_filter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_filter); ?>" placeholder="Name, email, phone, parent..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 focus:border-orange-500">
            </div>
            <div>
                <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg flex items-center transition">
                    <i class="fas fa-search mr-2"></i> Filter
                </button>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($current_page); ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center transition">
                    <i class="fas fa-sync-alt mr-2"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Bulk Actions Form -->
    <form method="POST" action="<?php echo htmlspecialchars($current_page); ?>" id="bulkForm" class="mb-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <div class="bg-white rounded-xl shadow-md p-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-600">Selected:</span>
                <span id="selectedCount" class="bg-orange-100 text-orange-700 px-2 py-1 rounded-md text-sm font-semibold">0</span>
                <span class="text-sm text-gray-600">applications</span>
            </div>
            <div class="flex gap-3">
                <select id="bulkActionSelect" name="bulk_action" class="px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="">Bulk Action</option>
                    <option value="pending">Mark as Pending</option>
                    <option value="accepted">Mark as Accepted</option>
                    <option value="rejected">Mark as Rejected</option>
                    <option value="cancelled">Mark as Cancelled</option>
                </select>
                <button type="submit" id="bulkActionBtn" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg flex items-center transition disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <i class="fas fa-check-double mr-2"></i> Apply to Selected
                </button>
            </div>
        </div>
        
        <!-- Applications Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden mt-4">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="w-10 px-4 py-3">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parents/Guardian</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Applied Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if ($admissions_result && $admissions_result->num_rows > 0): ?>
                            <?php while ($app = $admissions_result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50" id="row-<?php echo $app['id']; ?>">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $app['id']; ?>" class="row-checkbox rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($app['full_name']); ?></div>
                                        <div class="text-xs text-gray-500">DOB: <?php echo htmlspecialchars($app['date_of_birth'] ?? 'N/A'); ?></div>
                                        <div class="text-xs text-gray-500">Gender: <?php echo ucfirst(htmlspecialchars($app['gender'] ?? 'N/A')); ?></div>
                                        <?php if (!empty($app['previous_school'])): ?>
                                            <div class="text-xs text-gray-500">Prev: <?php echo htmlspecialchars($app['previous_school']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm"><i class="fas fa-envelope text-gray-400 w-4"></i> <?php echo htmlspecialchars($app['email']); ?></div>
                                        <div class="text-sm"><i class="fas fa-phone text-gray-400 w-4"></i> <?php echo htmlspecialchars($app['student_phone']); ?></div>
                                        <?php if (!empty($app['address'])): ?>
                                            <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars(substr($app['address'], 0, 30)); ?>...</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if (!empty($app['father_name'])): ?>
                                            <div class="font-medium text-gray-900">F: <?php echo htmlspecialchars($app['father_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($app['mother_name'])): ?>
                                            <div class="font-medium text-gray-900">M: <?php echo htmlspecialchars($app['mother_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($app['guardian_name'])): ?>
                                            <div class="font-medium text-gray-900">G: <?php echo htmlspecialchars($app['guardian_name']); ?></div>
                                        <?php endif; ?>
                                        <div class="text-xs text-gray-500">
                                            <?php if (!empty($app['father_phone'])): ?>
                                                <i class="fas fa-phone text-gray-400 w-4"></i> <?php echo htmlspecialchars($app['father_phone']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium"><?php echo htmlspecialchars($app['class_name'] ?? 'N/A'); ?></span>
                                        <div class="text-xs text-gray-500">Year: <?php echo htmlspecialchars($app['academic_year']); ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm"><?php echo date('M d, Y', strtotime($app['created_at'])); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo date('h:i A', strtotime($app['created_at'])); ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="status-badge status-<?php echo $app['status']; ?>" id="status-badge-<?php echo $app['id']; ?>">
                                            <i class="fas fa-<?php 
                                                echo $app['status'] === 'pending' ? 'clock' : 
                                                    ($app['status'] === 'accepted' ? 'check-circle' : 
                                                    ($app['status'] === 'rejected' ? 'times-circle' : 'ban')); 
                                            ?>"></i>
                                            <?php echo ucfirst($app['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex space-x-1">
                                            <a href="view_admissions.php?id=<?php echo $app['id']; ?>" class="action-btn text-blue-600 hover:text-blue-800 hover:bg-blue-50 p-2 rounded-lg" title="View Details" data-tooltip="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button onclick="updateStatus(<?php echo $app['id']; ?>, '<?php echo $app['status']; ?>')" class="action-btn text-gray-600 hover:text-gray-800 hover:bg-gray-100 p-2 rounded-lg" title="Update Status" data-tooltip="Update Status">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($app['status'] !== 'accepted'): ?>
                                                <button onclick="acceptApplication(<?php echo $app['id']; ?>)" class="action-btn text-green-600 hover:text-green-800 hover:bg-green-50 p-2 rounded-lg" title="Accept Application" data-tooltip="Accept Application">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="inline-block p-2 text-green-400" title="Already Accepted">
                                                    <i class="fas fa-check-circle"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-4xl mb-2 block"></i>
                                    No applications found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-4 py-3 border-t border-gray-200 flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> applications
                    </div>
                    <div class="flex space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_filter); ?>&page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-50 transition">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="px-3 py-1 bg-orange-500 text-white rounded"><?php echo $i; ?></span>
                            <?php elseif (abs($i - $page) <= 2 || $i == 1 || $i == $total_pages): ?>
                                <a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_filter); ?>&page=<?php echo $i; ?>" class="px-3 py-1 border rounded hover:bg-gray-50 transition"><?php echo $i; ?></a>
                            <?php elseif ($i == 2 || $i == $total_pages - 1): ?>
                                <span class="px-3 py-1">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_filter); ?>&page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-50 transition">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast bg-white shadow-lg rounded-lg px-4 py-3 flex items-center space-x-2">
    <i id="toastIcon" class="text-lg"></i>
    <span id="toastMessage" class="text-sm font-medium"></span>
</div>

<!-- Update Status Modal -->
<div id="statusModal" class="fixed inset-0 bg-black bg-opacity-50 modal-overlay hidden flex items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-md">
        <div class="border-b px-6 py-4 flex justify-between items-center">
            <h2 class="text-xl font-bold">Update Application Status</h2>
            <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600 p-1 rounded-full hover:bg-gray-100 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form id="statusForm" class="p-6">
            <input type="hidden" id="status_app_id" name="application_id">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="update_status">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">New Status</label>
                <select id="status_select" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 focus:border-orange-500">
                    <option value="pending">Pending</option>
                    <option value="accepted">Accepted</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Admin Notes (Internal)</label>
                <textarea id="status_notes" name="admin_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-orange-500 focus:border-orange-500" placeholder="Add internal notes about this status change..."></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeStatusModal()" class="px-4 py-2 border rounded-lg text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" id="statusSubmitBtn" class="px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition flex items-center">
                    <i class="fas fa-save mr-2"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Accept Application Modal -->
<div id="acceptModal" class="fixed inset-0 bg-black bg-opacity-50 modal-overlay hidden flex items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="border-b px-6 py-4 flex justify-between items-center">
            <h2 class="text-xl font-bold">Accept Application</h2>
            <button onclick="closeAcceptModal()" class="text-gray-400 hover:text-gray-600 p-1 rounded-full hover:bg-gray-100 transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6" id="acceptModalContent">
            <p class="text-gray-700 mb-4">Loading application details...</p>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmModal" class="fixed inset-0 bg-black bg-opacity-50 modal-overlay hidden flex items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-md">
        <div class="border-b px-6 py-4">
            <h2 class="text-xl font-bold" id="confirmModalTitle">Confirm Action</h2>
        </div>
        <div class="p-6">
            <p class="text-gray-700 mb-4" id="confirmModalMessage">Are you sure you want to proceed?</p>
            <div class="flex justify-end gap-3">
                <button onclick="closeConfirmModal()" class="px-4 py-2 border rounded-lg text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                <button id="confirmModalBtn" class="px-4 py-2 rounded-lg text-white transition flex items-center">
                    <i class="fas fa-check mr-2"></i> Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const csrfToken = '<?php echo $csrf_token; ?>';
    const currentPage = '<?php echo htmlspecialchars($current_page); ?>';
    let currentAppId = null;
    let confirmCallback = null;
    
    // Toast notification
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        const toastIcon = document.getElementById('toastIcon');
        const toastMessage = document.getElementById('toastMessage');
        
        if (type === 'success') {
            toastIcon.className = 'fas fa-check-circle text-green-500';
        } else {
            toastIcon.className = 'fas fa-exclamation-circle text-red-500';
        }
        
        toastMessage.textContent = message;
        toast.classList.add('show');
        
        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }
    
    // Select All functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const selectedCountSpan = document.getElementById('selectedCount');
    const bulkActionSelect = document.getElementById('bulkActionSelect');
    const bulkActionBtn = document.getElementById('bulkActionBtn');
    
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        const count = checked.length;
        if (selectedCountSpan) selectedCountSpan.textContent = count;
        if (bulkActionBtn) bulkActionBtn.disabled = count === 0;
    }
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
            updateSelectedCount();
        });
    }
    
    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            updateSelectedCount();
            // Update select all checkbox state
            const allChecked = document.querySelectorAll('.row-checkbox:checked').length === rowCheckboxes.length;
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = !allChecked && document.querySelectorAll('.row-checkbox:checked').length > 0;
        });
    });
    
    // Bulk action confirmation
    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', function() {
            updateSelectedCount();
        });
    }
    
    document.getElementById('bulkForm').addEventListener('submit', function(e) {
        const selected = document.querySelectorAll('.row-checkbox:checked');
        if (selected.length === 0) {
            e.preventDefault();
            showToast('Please select at least one application', 'error');
        } else if (!bulkActionSelect.value) {
            e.preventDefault();
            showToast('Please select a bulk action', 'error');
        }
    });
    
    updateSelectedCount();
    
    // Confirmation Modal
    function showConfirmation(title, message, callback, confirmClass = 'bg-orange-500 hover:bg-orange-600') {
        document.getElementById('confirmModalTitle').textContent = title;
        document.getElementById('confirmModalMessage').textContent = message;
        const btn = document.getElementById('confirmModalBtn');
        btn.className = `px-4 py-2 rounded-lg text-white transition flex items-center ${confirmClass}`;
        confirmCallback = callback;
        document.getElementById('confirmModal').classList.remove('hidden');
    }
    
    function closeConfirmModal() {
        document.getElementById('confirmModal').classList.add('hidden');
        confirmCallback = null;
    }
    
    document.getElementById('confirmModalBtn').addEventListener('click', function() {
        if (confirmCallback) {
            confirmCallback();
        }
        closeConfirmModal();
    });
    
    // Update Status Modal
    function updateStatus(id, currentStatus) {
        currentAppId = id;
        document.getElementById('status_app_id').value = id;
        document.getElementById('status_select').value = currentStatus;
        document.getElementById('status_notes').value = '';
        document.getElementById('statusModal').classList.remove('hidden');
        document.getElementById('status_select').focus();
    }
    
    function closeStatusModal() {
        document.getElementById('statusModal').classList.add('hidden');
        currentAppId = null;
    }
    
    // Status Form Submission
    document.getElementById('statusForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const newStatus = document.getElementById('status_select').value;
        const appId = document.getElementById('status_app_id').value;
        const currentStatusBadge = document.getElementById('status-badge-' + appId).textContent.trim().toLowerCase();
        
        if (newStatus === currentStatusBadge) {
            showToast('Status is already set to ' + newStatus, 'error');
            return;
        }
        
        const submitBtn = document.getElementById('statusSubmitBtn');
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Updating...';
        submitBtn.disabled = true;
        
        const formData = new FormData(this);
        
        fetch(currentPage, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                closeStatusModal();
                
                // Update status badge without page reload
                const badge = document.getElementById('status-badge-' + appId);
                if (badge) {
                    badge.className = 'status-badge status-' + data.new_status;
                    const icon = badge.querySelector('i');
                    if (icon) {
                        const iconMap = {
                            'pending': 'clock',
                            'accepted': 'check-circle',
                            'rejected': 'times-circle',
                            'cancelled': 'ban'
                        };
                        icon.className = 'fas fa-' + (iconMap[data.new_status] || 'question-circle');
                    }
                    badge.innerHTML = badge.innerHTML.replace(/<i[^>]*><\/i>\s*[A-Za-z]+/, '<i class="fas fa-' + 
                        (data.new_status === 'pending' ? 'clock' : 
                         data.new_status === 'accepted' ? 'check-circle' : 
                         data.new_status === 'rejected' ? 'times-circle' : 'ban') + 
                        '"></i> ' + data.new_status.charAt(0).toUpperCase() + data.new_status.slice(1));
                }
                
                // Update row styling if needed
                const row = document.getElementById('row-' + appId);
                if (row && data.new_status === 'accepted') {
                    const actionsCell = row.querySelector('td:last-child .flex');
                    if (actionsCell) {
                        // Remove accept button
                        const acceptBtn = actionsCell.querySelector('[onclick*="acceptApplication"]');
                        if (acceptBtn) {
                            acceptBtn.outerHTML = '<span class="inline-block p-2 text-green-400" title="Already Accepted"><i class="fas fa-check-circle"></i></span>';
                        }
                    }
                }
                
                showToast(data.message || 'Status updated successfully!', 'success');
            } else {
                showToast(data.message || 'Failed to update status', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred. Please try again.', 'error');
        })
        .finally(() => {
            submitBtn.innerHTML = originalHTML;
            submitBtn.disabled = false;
        });
    });
    
    // Accept Application Modal
    function acceptApplication(id) {
        currentAppId = id;
        const modal = document.getElementById('acceptModal');
        const content = document.getElementById('acceptModalContent');
        
        // Show loading state
        content.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-spinner fa-spin text-2xl text-orange-500 mb-2"></i>
                <p class="text-gray-600">Loading application details...</p>
            </div>
        `;
        modal.classList.remove('hidden');
        
        // Fetch application details
        fetch(currentPage, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'get_application_details',
                application_id: id,
                csrf_token: csrfToken
            })
        })
        .then(response => {
            if (!response.ok) throw new Error('Network error');
            return response.json();
        })
        .then(data => {
            if (data.success && data.application) {
                const app = data.application;
                content.innerHTML = `
                    <div class="bg-gray-50 p-4 rounded-lg mb-4">
                        <h3 class="font-semibold text-lg text-gray-900">${escapeHtml(app.full_name)}</h3>
                        <div class="text-sm text-gray-600 mt-1 space-y-1">
                            <p><i class="fas fa-envelope w-4"></i> ${escapeHtml(app.email)}</p>
                            <p><i class="fas fa-phone w-4"></i> ${escapeHtml(app.student_phone || 'N/A')}</p>
                            <p><i class="fas fa-school w-4"></i> Class: ${escapeHtml(app.class_name || 'N/A')} | Year: ${escapeHtml(app.academic_year)}</p>
                            <p><i class="fas fa-building w-4"></i> Department: ${escapeHtml(app.department_name || 'N/A')}</p>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <p class="text-gray-700 font-medium mb-2">This action will:</p>
                        <ul class="list-disc list-inside text-gray-600 space-y-1.5 text-sm">
                            <li>Create a student user account with login credentials</li>
                            <li>Create or link a parent user account</li>
                            <li>Assign the student to <strong>${escapeHtml(app.class_name || 'the selected class')}</strong></li>
                            <li>Generate a secure random password</li>
                            <li>Mark this application as "Accepted"</li>
                        </ul>
                    </div>
                    
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4">
                        <p class="text-yellow-700 text-sm">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Make sure to save the generated credentials after acceptance.
                        </p>
                    </div>
                    
                    <div class="flex justify-end gap-3">
                        <button onclick="closeAcceptModal()" class="px-4 py-2 border rounded-lg text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                        <button id="confirmAcceptBtn" class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg transition flex items-center">
                            <i class="fas fa-user-check mr-2"></i> Accept Application
                        </button>
                    </div>
                `;
                
                // Add event listener to confirm button
                document.getElementById('confirmAcceptBtn').addEventListener('click', function() {
                    confirmAcceptApplication(id);
                });
            } else {
                content.innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-circle text-red-500 text-3xl mb-2"></i>
                        <p class="text-red-600">Error loading application details.</p>
                        <button onclick="closeAcceptModal()" class="mt-3 px-4 py-2 border rounded-lg text-gray-700 hover:bg-gray-50">Close</button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = `
                <div class="text-center py-4">
                    <i class="fas fa-exclamation-circle text-red-500 text-3xl mb-2"></i>
                    <p class="text-red-600">Network error. Please try again.</p>
                    <button onclick="closeAcceptModal()" class="mt-3 px-4 py-2 border rounded-lg text-gray-700 hover:bg-gray-50">Close</button>
                </div>
            `;
        });
    }
    
    function confirmAcceptApplication(id) {
        const btn = document.getElementById('confirmAcceptBtn');
        if (!btn) return;
        
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
        btn.disabled = true;
        
        fetch(currentPage, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'accept_application',
                application_id: id,
                csrf_token: csrfToken
            })
        })
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                closeAcceptModal();
                
                // Show credentials in a nice alert
                let message = data.message;
                if (data.credentials) {
                    message += '\n\n------ CREDENTIALS ------\n' +
                              'Student: ' + data.credentials.student_email + '\n' +
                              'Parent: ' + data.credentials.parent_email + '\n' +
                              'Password: ' + data.credentials.password + '\n' +
                              'Student Name: ' + data.credentials.student_name + '\n' +
                              'Parent Name: ' + data.credentials.parent_name + '\n' +
                              '-----------------------\n' +
                              'Please save these credentials securely.';
                }
                
                // Show credentials in a modal or alert
                if (data.credentials) {
                    const credsHTML = `
                        <div class="space-y-2">
                            <p class="font-semibold text-green-700"><i class="fas fa-check-circle mr-1"></i> Application accepted successfully!</p>
                            <div class="bg-gray-100 p-3 rounded text-sm font-mono space-y-1">
                                <p><strong>Student Email:</strong> ${escapeHtml(data.credentials.student_email)}</p>
                                <p><strong>Parent Email:</strong> ${escapeHtml(data.credentials.parent_email)}</p>
                                <p><strong>Password:</strong> <span class="text-orange-600 font-bold">${escapeHtml(data.credentials.password)}</span></p>
                                <p><strong>Student:</strong> ${escapeHtml(data.credentials.student_name)}</p>
                                <p><strong>Parent:</strong> ${escapeHtml(data.credentials.parent_name)}</p>
                            </div>
                            <p class="text-xs text-red-600"><i class="fas fa-exclamation-triangle mr-1"></i> Please save these credentials now. This password will not be shown again.</p>
                        </div>
                    `;
                    
                    showConfirmation('Application Accepted - Credentials', credsHTML, () => {
                        location.reload();
                    }, 'bg-green-500 hover:bg-green-600');
                } else {
                    alert(message);
                    location.reload();
                }
            } else {
                showToast(data.message || 'Error accepting application', 'error');
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred. Please try again.', 'error');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        });
    }
    
    function closeAcceptModal() {
        document.getElementById('acceptModal').classList.add('hidden');
        currentAppId = null;
    }
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeStatusModal();
            closeAcceptModal();
            closeConfirmModal();
        }
    });
    
    // Close modals when clicking overlay
    ['statusModal', 'acceptModal', 'confirmModal'].forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (modalId === 'statusModal') closeStatusModal();
                    else if (modalId === 'acceptModal') closeAcceptModal();
                    else if (modalId === 'confirmModal') closeConfirmModal();
                }
            });
        }
    });
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>

<?php
mysqli_close($conn);
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
}
ob_end_flush();
?>