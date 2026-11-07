<?php
session_start();

// Restrict to admins only and validate session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Invalid session: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    header("Location: ../login.php");
    exit();
}

// Include database connection
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    die("System error: Unable to connect to database. Please contact the administrator.");
}

// Verify database connection
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include logging function
$log_function_available = false;
try {
    if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
        $log_function_available = true;
    }
} catch (Exception $e) {
    error_log("Failed to include log_activity.php: " . $e->getMessage());
}

// Validate user_id exists in users table
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin' AND status = 'active'";
if ($user_check_stmt = $conn->prepare($user_check_sql)) {
    $user_check_stmt->bind_param("i", $_SESSION['user_id']);
    $user_check_stmt->execute();
    $user_check_result = $user_check_stmt->get_result();
    if ($user_check_result->num_rows === 0) {
        error_log("Invalid or inactive admin user_id: " . $_SESSION['user_id']);
        header("Location: ../login.php?error=invalid_user");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    die("System error: Unable to validate user. Please contact the administrator.");
}

// Initialize messages
$success_msg = "";
$error_msg = "";

// Ensure archived_students table exists
try {
    $check_table_sql = "CREATE TABLE IF NOT EXISTS archived_students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student_id (student_id),
        INDEX idx_archived_at (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($check_table_sql)) {
        error_log("Failed to ensure archived_students table exists: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Error checking/creating archived_students table: " . $e->getMessage());
}

// Ensure users table status column can accept 'archived'
try {
    $check_column_sql = "SHOW COLUMNS FROM users LIKE 'status'";
    $column_result = $conn->query($check_column_sql);
    if ($column_result) {
        $column_info = $column_result->fetch_assoc();
        
        if ($column_info && strpos($column_info['Type'], 'enum') !== false) {
            // Convert ENUM to VARCHAR to allow 'archived' status
            $alter_sql = "ALTER TABLE users MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'";
            $conn->query($alter_sql);
            error_log("Modified users.status column from ENUM to VARCHAR to support 'archived' status");
        }
    }
} catch (Exception $e) {
    error_log("Error checking/modifying users.status column: " . $e->getMessage());
}

// Handle archive action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_student_id'])) {
    $student_id = intval($_POST['archive_student_id']);
    $archive_reason = trim($_POST['archive_reason'] ?? '');
    
    error_log("=== ARCHIVE OPERATION STARTED ===");
    error_log("Student ID: $student_id, Reason: $archive_reason");
    
    // Verify the student exists and is unassigned
    $verify_sql = "SELECT u.id, u.name, u.email 
                   FROM users u 
                   LEFT JOIN student_classes sc ON u.id = sc.user_id
                   WHERE u.id = ? AND u.role = 'student' AND u.status = 'active' AND sc.id IS NULL";
    
    if ($verify_stmt = $conn->prepare($verify_sql)) {
        $verify_stmt->bind_param("i", $student_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $student_data = $verify_result->fetch_assoc();
            error_log("Student found: " . json_encode($student_data));
            
            // Begin transaction
            $conn->begin_transaction();
            error_log("Transaction started");
            
            try {
                // Check if student already exists in archives
                $check_existing_sql = "SELECT id FROM archived_students WHERE student_id = ?";
                $check_existing_stmt = $conn->prepare($check_existing_sql);
                if (!$check_existing_stmt) {
                    throw new Exception("Prepare failed for check_existing: " . $conn->error);
                }
                
                $check_existing_stmt->bind_param("i", $student_id);
                $check_existing_stmt->execute();
                $check_existing_result = $check_existing_stmt->get_result();
                
                if ($check_existing_result->num_rows > 0) {
                    // Student already archived, just update the timestamp
                    error_log("Student $student_id already exists in archives, updating timestamp");
                    $archive_insert_sql = "UPDATE archived_students SET name = ?, archived_at = NOW() WHERE student_id = ?";
                    $archive_insert_stmt = $conn->prepare($archive_insert_sql);
                    if (!$archive_insert_stmt) {
                        throw new Exception("Prepare failed for update: " . $conn->error);
                    }
                    $archive_insert_stmt->bind_param("si", $student_data['name'], $student_id);
                } else {
                    // New archive entry
                    error_log("Inserting new archive record for student $student_id");
                    $archive_insert_sql = "INSERT INTO archived_students (student_id, name, archived_at) VALUES (?, ?, NOW())";
                    $archive_insert_stmt = $conn->prepare($archive_insert_sql);
                    if (!$archive_insert_stmt) {
                        throw new Exception("Prepare failed for insert: " . $conn->error);
                    }
                    $archive_insert_stmt->bind_param("is", $student_id, $student_data['name']);
                }
                
                if (!$archive_insert_stmt->execute()) {
                    error_log("Archive insert/update failed: " . $archive_insert_stmt->error);
                    throw new Exception("Failed to insert/update archived_students: " . $archive_insert_stmt->error);
                }
                
                $affected_rows = $archive_insert_stmt->affected_rows;
                error_log("Archive operation affected $affected_rows rows");
                $archive_insert_stmt->close();
                $check_existing_stmt->close();
                
                // Verify the archive operation was successful
                $verify_archive_sql = "SELECT COUNT(*) as count, archived_at FROM archived_students WHERE student_id = ?";
                $verify_archive_stmt = $conn->prepare($verify_archive_sql);
                if (!$verify_archive_stmt) {
                    throw new Exception("Prepare failed for verify_archive: " . $conn->error);
                }
                
                $verify_archive_stmt->bind_param("i", $student_id);
                $verify_archive_stmt->execute();
                $verify_archive_result = $verify_archive_stmt->get_result();
                $archive_check = $verify_archive_result->fetch_assoc();
                error_log("Archive verification: Found " . $archive_check['count'] . " records for student $student_id, archived_at: " . ($archive_check['archived_at'] ?? 'NULL'));
                
                if ($archive_check['count'] == 0) {
                    throw new Exception("Archive verification failed: No record found after insert");
                }
                $verify_archive_stmt->close();
                
                // Update user status to archived
                $update_sql = "UPDATE users SET status = 'archived' WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare failed for user update: " . $conn->error);
                }
                
                $update_stmt->bind_param("i", $student_id);
                
                if (!$update_stmt->execute()) {
                    error_log("Failed to update user status: " . $update_stmt->error);
                    throw new Exception("Failed to update user status: " . $update_stmt->error);
                }
                
                $update_affected = $update_stmt->affected_rows;
                error_log("User status update affected $update_affected rows");
                $update_stmt->close();
                
                // Verify user status update
                $verify_user_sql = "SELECT status FROM users WHERE id = ?";
                $verify_user_stmt = $conn->prepare($verify_user_sql);
                if ($verify_user_stmt) {
                    $verify_user_stmt->bind_param("i", $student_id);
                    $verify_user_stmt->execute();
                    $verify_user_result = $verify_user_stmt->get_result();
                    $user_check = $verify_user_result->fetch_assoc();
                    error_log("User status verification: Current status = " . ($user_check['status'] ?? 'NOT FOUND'));
                    $verify_user_stmt->close();
                }
                
                // Log the activity - CORRECTED: pass $conn as first parameter
                if ($log_function_available && function_exists('logActivity')) {
                    try {
                        logActivity(
                            $conn,  // Pass database connection as first parameter
                            $_SESSION['user_id'],
                            'Archive Student',
                            "Archived unassigned student: {$student_data['name']} (ID: $student_id). Reason: $archive_reason"
                        );
                    } catch (Exception $e) {
                        error_log("Logging failed: " . $e->getMessage());
                    }
                } else {
                    // Fallback logging
                    error_log("Admin {$_SESSION['user_id']} archived student: {$student_data['name']} (ID: $student_id). Reason: $archive_reason");
                }
                
                // Commit transaction
                $conn->commit();
                error_log("Transaction committed successfully");
                $success_msg = "Student " . htmlspecialchars($student_data['name']) . " has been archived successfully.";
                
            } catch (Exception $e) {
                $conn->rollback();
                error_log("=== ARCHIVE OPERATION FAILED ===");
                error_log("Error: " . $e->getMessage());
                error_log("Rolling back transaction");
                $error_msg = "Failed to archive student. Error: " . $e->getMessage();
            }
            
            error_log("=== ARCHIVE OPERATION ENDED ===");
            
        } else {
            error_log("Student $student_id not found or already assigned");
            $error_msg = "Student not found or is already assigned to a class.";
        }
        $verify_stmt->close();
    } else {
        error_log("Failed to prepare verification query: " . $conn->error);
        $error_msg = "Database error. Please try again.";
    }
}

// Fetch classes with department info
$classes_sql = "SELECT c.id, c.class_name, d.name as department_name, d.id as department_id 
                FROM classes c 
                LEFT JOIN departments d ON c.department_id = d.id 
                ORDER BY d.name, c.class_name";
$classes_result = $conn->query($classes_sql);

if (!$classes_result) {
    error_log("Error fetching classes: " . $conn->error);
    $classes = [];
} else {
    $classes = $classes_result->fetch_all(MYSQLI_ASSOC);
    $classes_result->free();
}

// Fetch student-class assignments with proper joins
$assignments_sql = "SELECT 
    u.id as user_id,
    u.name as student_name,
    u.email as student_email,
    c.id as class_id,
    c.class_name,
    d.name as department_name,
    d.id as department_id,
    sc.academic_year,
    sc.academic_year_id,
    sc.id as assignment_id
FROM users u 
INNER JOIN student_classes sc ON u.id = sc.user_id
INNER JOIN classes c ON sc.class_id = c.id
LEFT JOIN departments d ON c.department_id = d.id
WHERE u.role = 'student' 
AND u.status = 'active'
ORDER BY d.name, c.class_name, u.name";

$assignments_result = $conn->query($assignments_sql);

$grouped_assignments = [];
$department_stats = [];

if (!$assignments_result) {
    error_log("Error fetching assignments: " . $conn->error);
} else {
    while ($row = $assignments_result->fetch_assoc()) {
        $department = $row['department_name'] ?? 'Unassigned Department';
        $class_name = $row['class_name'] ?? 'Unassigned';
        
        // Group by department then class
        if (!isset($grouped_assignments[$department])) {
            $grouped_assignments[$department] = [];
            $department_stats[$department] = ['total' => 0, 'classes' => []];
        }
        
        if (!isset($grouped_assignments[$department][$class_name])) {
            $grouped_assignments[$department][$class_name] = [];
            $department_stats[$department]['classes'][$class_name] = 0;
        }
        
        $grouped_assignments[$department][$class_name][] = $row;
        $department_stats[$department]['total']++;
        $department_stats[$department]['classes'][$class_name]++;
    }
    $assignments_result->free();
}

// Fetch unassigned students
$unassigned_sql = "SELECT 
    u.id as user_id,
    u.name as student_name,
    u.email as student_email,
    u.created_at as registration_date
FROM users u 
LEFT JOIN student_classes sc ON u.id = sc.user_id
WHERE u.role = 'student' 
AND u.status = 'active'
AND sc.id IS NULL
ORDER BY u.name";

$unassigned_result = $conn->query($unassigned_sql);
$unassigned_students = [];

if ($unassigned_result && $unassigned_result->num_rows > 0) {
    while ($row = $unassigned_result->fetch_assoc()) {
        $unassigned_students[] = $row;
    }
    $unassigned_result->free();
}

// Get total stats
$total_assigned = 0;
foreach ($department_stats as $dept) {
    $total_assigned += $dept['total'];
}
$total_unassigned = count($unassigned_students);
$total_classes = count($classes);
$total_departments = count($grouped_assignments);

// Include header
try {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
} catch (Exception $e) {
    error_log("Failed to include admin_header.php: " . $e->getMessage());
    die("System error: Unable to load page header. Please contact the administrator.");
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="description" content="Manage student-class assignments with an intuitive, modern interface for Queen's Park Montessori School.">
    <meta name="robots" content="noindex, nofollow">
    <title>Student Class Management � Queen's Park Montessori</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#fff7ed', 100: '#ffedd5', 200: '#fed7aa',
                            300: '#fdba74', 400: '#fb923c', 500: '#f97316',
                            600: '#ea580c', 700: '#c2410c', 800: '#9a3412', 900: '#7c2d12',
                        },
                        accent: {
                            400: '#34d399', 500: '#10b981', 600: '#059669',
                        },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.3s ease-in-out',
                        'slide-up': 'slideUp 0.4s ease-out',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideUp: { '0%': { transform: 'translateY(10px)', opacity: '0' }, '100%': { transform: 'translateY(0)', opacity: '1' } },
                    },
                },
            },
        };
    </script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-primary: #f8fafc;
            --bg-card: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
        }
        
        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-card: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --border-color: #334155;
        }
        
        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            transition: all 0.3s ease;
        }
        
        .glass-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .glass-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--bg-card), var(--bg-card));
            border-left: 4px solid #f97316;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            border-left-width: 8px;
        }
        
        .student-row {
            transition: all 0.2s ease;
            border-radius: 8px;
        }
        
        .student-row:hover {
            background-color: rgba(249, 115, 22, 0.08);
            transform: translateX(4px);
        }
        
        .department-section {
            animation: fadeIn 0.5s ease-out;
        }
        
        .scrollbar-thin {
            scrollbar-width: thin;
            scrollbar-color: #f97316 transparent;
        }
        
        .scrollbar-thin::-webkit-scrollbar {
            width: 6px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background-color: #f97316;
            border-radius: 20px;
        }
        
        .modal-overlay {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }
        
        .modal {
            animation: slideUp 0.3s ease-out;
        }
        
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .print-only { display: block !important; }
        }
        
        .print-only { display: none; }
    </style>
</head>
<body class="min-h-screen">
    <!-- Archive Confirmation Modal -->
    <div id="archiveModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="display: none;">
        <div class="modal-overlay absolute inset-0" onclick="closeArchiveModal()"></div>
        <div class="modal glass-card p-6 relative z-10 w-full max-w-md mx-4">
            <div class="text-center mb-6">
                <div class="w-16 h-16 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-archive text-3xl text-red-600"></i>
                </div>
                <h3 class="text-xl font-bold">Archive Student</h3>
                <p class="text-secondary mt-2">Are you sure you want to archive this student? They will be moved to the archives and can be restored later.</p>
            </div>
            
            <form id="archiveForm" method="POST" action="">
                <input type="hidden" name="archive_student_id" id="archiveStudentId">
                <div class="mb-4">
                    <label for="archiveReason" class="block text-sm font-medium mb-2">
                        Reason for Archiving <span class="text-red-500">*</span>
                    </label>
                    <textarea 
                        name="archive_reason" 
                        id="archiveReason" 
                        rows="3"
                        class="w-full px-4 py-2.5 border border-[var(--border-color)] rounded-xl bg-[var(--bg-card)] focus:ring-2 focus:ring-red-500 focus:border-transparent transition-all"
                        placeholder="Please provide a reason for archiving this student..."
                        required
                    ></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="button" 
                            onclick="closeArchiveModal()" 
                            class="flex-1 px-4 py-2.5 border border-[var(--border-color)] rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-all font-medium">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="flex-1 px-4 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-xl transition-all font-medium">
                        <i class="fas fa-archive mr-2"></i>Archive Student
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
        
        <!-- Header Section -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8 no-print">
            <div class="animate-fade-in">
                <h1 class="text-3xl font-bold bg-gradient-to-r from-primary-500 to-primary-700 bg-clip-text text-transparent">
                    Student Class Management
                </h1>
                <p class="text-secondary mt-1 flex items-center gap-2">
                    <i class="fas fa-graduation-cap text-primary-500"></i>
                    Manage student assignments across departments and classes
                </p>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                <a href="/admin/assign_class.php" 
                   class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white px-5 py-2.5 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl no-print">
                    <i class="fas fa-user-plus"></i>
                    Assign Student
                </a>
                <a href="/admin/promote_learners.php" 
                   class="inline-flex items-center gap-2 bg-accent-500 hover:bg-accent-600 text-white px-5 py-2.5 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl no-print">
                    <i class="fas fa-arrow-trend-up"></i>
                    Promote Learners
                </a>
                <a href="/admin/archives.php" 
                   class="inline-flex items-center gap-2 bg-gray-500 hover:bg-gray-600 text-white px-5 py-2.5 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl no-print">
                    <i class="fas fa-archive"></i>
                    Archives
                </a>
                <button onclick="window.print()" 
                        class="inline-flex items-center gap-2 bg-white dark:bg-gray-700 border-2 border-primary-500 text-primary-600 dark:text-primary-400 px-5 py-2.5 rounded-xl font-medium hover:bg-primary-50 dark:hover:bg-gray-600 transition-all no-print">
                    <i class="fas fa-print"></i>
                    Print Report
                </button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8 no-print">
            <div class="glass-card stat-card p-5 animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                        <i class="fas fa-school text-2xl text-primary-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary font-medium">Total Departments</p>
                        <p class="text-2xl font-bold"><?php echo $total_departments; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-5 animate-slide-up" style="animation-delay: 0.2s; border-left-color: #10b981;">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                        <i class="fas fa-users text-2xl text-green-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary font-medium">Total Assigned</p>
                        <p class="text-2xl font-bold"><?php echo $total_assigned; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-5 animate-slide-up" style="animation-delay: 0.3s; border-left-color: #ef4444;">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                        <i class="fas fa-user-slash text-2xl text-red-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary font-medium">Unassigned</p>
                        <p class="text-2xl font-bold"><?php echo $total_unassigned; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-5 animate-slide-up" style="animation-delay: 0.4s; border-left-color: #8b5cf6;">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                        <i class="fas fa-layer-group text-2xl text-purple-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary font-medium">Total Classes</p>
                        <p class="text-2xl font-bold"><?php echo $total_classes; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($success_msg)): ?>
            <div class="glass-card border-l-4 border-green-500 bg-green-50 dark:bg-green-900/20 p-4 mb-6 animate-fade-in" id="successAlert">
                <div class="flex items-center gap-3">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    <p class="text-green-800 dark:text-green-200"><?php echo $success_msg; ?></p>
                    <button onclick="document.getElementById('successAlert').remove()" class="ml-auto text-green-600 hover:text-green-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_msg)): ?>
            <div class="glass-card border-l-4 border-red-500 bg-red-50 dark:bg-red-900/20 p-4 mb-6 animate-fade-in" id="errorAlert">
                <div class="flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                    <p class="text-red-800 dark:text-red-200"><?php echo $error_msg; ?></p>
                    <button onclick="document.getElementById('errorAlert').remove()" class="ml-auto text-red-600 hover:text-red-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Search and Filter Bar -->
        <div class="glass-card p-4 mb-6 no-print">
            <div class="flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-3 top-3.5 text-secondary"></i>
                    <input type="text" 
                           id="searchInput" 
                           placeholder="Search students, classes, or departments..." 
                           class="w-full pl-10 pr-4 py-2.5 border border-[var(--border-color)] rounded-xl bg-[var(--bg-card)] focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all">
                </div>
                <button onclick="toggleAllDepartments()" 
                        class="px-4 py-2.5 border border-[var(--border-color)] rounded-xl hover:bg-primary-50 dark:hover:bg-gray-700 transition-all font-medium">
                    <i class="fas fa-expand-alt mr-2"></i>Expand All
                </button>
                <button onclick="exportToCSV()" 
                        class="px-4 py-2.5 bg-green-500 text-white rounded-xl hover:bg-green-600 transition-all font-medium">
                    <i class="fas fa-download mr-2"></i>Export CSV
                </button>
            </div>
        </div>

        <!-- Main Content: Departments and Classes -->
        <?php if (empty($grouped_assignments) && empty($unassigned_students)): ?>
            <div class="glass-card p-12 text-center animate-fade-in">
                <i class="fas fa-inbox text-6xl text-secondary mb-4"></i>
                <h3 class="text-xl font-semibold mb-2">No Assignments Found</h3>
                <p class="text-secondary mb-6">Start by assigning students to their respective classes.</p>
                <a href="/admin/assign_class.php" 
                   class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white px-6 py-3 rounded-xl font-medium transition-all">
                    <i class="fas fa-plus"></i>
                    Assign First Student
                </a>
            </div>
        <?php else: ?>
            <!-- Department Accordion -->
            <?php foreach ($grouped_assignments as $department => $classes): ?>
                <div class="department-section mb-6" data-department="<?php echo htmlspecialchars($department); ?>">
                    <div class="glass-card p-5 mb-4 cursor-pointer department-header" 
                         onclick="toggleDepartment(this)" 
                         data-expanded="true">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-xl bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                                    <i class="fas fa-building text-2xl text-primary-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold"><?php echo htmlspecialchars($department); ?></h3>
                                    <p class="text-sm text-secondary">
                                        <?php echo count($classes); ?> classes � 
                                        <?php echo $department_stats[$department]['total']; ?> students
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 px-3 py-1 rounded-full text-sm font-medium">
                                    <?php echo $department_stats[$department]['total']; ?> Students
                                </span>
                                <i class="fas fa-chevron-down text-xl transition-transform duration-300 department-chevron"></i>
                            </div>
                        </div>
                    </div>

                    <div class="department-content grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 ml-4">
                        <?php foreach ($classes as $class_name => $students): ?>
                            <div class="glass-card overflow-hidden animate-slide-up" 
                                 data-class="<?php echo htmlspecialchars($class_name); ?>"
                                 data-department="<?php echo htmlspecialchars($department); ?>">
                                
                                <div class="bg-gradient-to-r from-primary-500 to-primary-600 text-white p-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h4 class="font-bold text-lg"><?php echo htmlspecialchars($class_name); ?></h4>
                                            <p class="text-sm opacity-90">
                                                <i class="fas fa-users mr-1"></i>
                                                <?php echo count($students); ?> student<?php echo count($students) !== 1 ? 's' : ''; ?>
                                            </p>
                                        </div>
                                        <div class="bg-white/20 backdrop-blur-sm rounded-lg px-3 py-2">
                                            <span class="text-2xl font-bold"><?php echo count($students); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-3 max-h-[300px] overflow-y-auto scrollbar-thin">
                                    <?php foreach ($students as $student): ?>
                                        <div class="student-row flex items-center justify-between px-3 py-2.5 mb-1" 
                                             data-student="<?php echo htmlspecialchars($student['student_name']); ?>">
                                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                                <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900/50 flex items-center justify-center flex-shrink-0">
                                                    <span class="text-sm font-bold text-primary-600">
                                                        <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="font-medium text-sm truncate"><?php echo htmlspecialchars($student['student_name']); ?></p>
                                                    <p class="text-xs text-secondary">
                                                        ID: <?php echo $student['user_id']; ?> � 
                                                        <?php echo htmlspecialchars($student['academic_year'] ?? 'N/A'); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 flex-shrink-0">
                                                <a href="/admin/view_student.php?id=<?php echo $student['user_id']; ?>" 
                                                   class="text-blue-500 hover:text-blue-700 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-gray-700 transition-all"
                                                   title="View profile">
                                                    <i class="fas fa-user"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Unassigned Students Section -->
            <?php if (!empty($unassigned_students)): ?>
                <div class="department-section mb-6" data-department="Unassigned">
                    <div class="glass-card p-5 mb-4 border-l-4 border-red-500 cursor-pointer department-header" 
                         onclick="toggleDepartment(this)" 
                         data-expanded="true">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                                    <i class="fas fa-user-slash text-2xl text-red-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-red-600">Unassigned Students</h3>
                                    <p class="text-sm text-secondary">Students without class assignments</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 px-3 py-1 rounded-full text-sm font-medium">
                                    <?php echo count($unassigned_students); ?> Students
                                </span>
                                <i class="fas fa-chevron-down text-xl transition-transform duration-300 department-chevron"></i>
                            </div>
                        </div>
                    </div>

                    <div class="department-content ml-4">
                        <div class="glass-card overflow-hidden">
                            <div class="p-4 max-h-[400px] overflow-y-auto scrollbar-thin">
                                <?php foreach ($unassigned_students as $student): ?>
                                    <div class="student-row flex items-center justify-between px-3 py-3 mb-1 border border-red-200 dark:border-red-900/30 rounded-lg">
                                        <div class="flex items-center gap-3 flex-1 min-w-0">
                                            <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center flex-shrink-0">
                                                <span class="text-sm font-bold text-red-600">
                                                    <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                                                </span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-medium text-sm truncate"><?php echo htmlspecialchars($student['student_name']); ?></p>
                                                <p class="text-xs text-secondary">
                                                    ID: <?php echo $student['user_id']; ?> � 
                                                    Registered: <?php echo date('M j, Y', strtotime($student['registration_date'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <a href="/admin/assign_class.php?student_id=<?php echo $student['user_id']; ?>" 
                                               class="bg-primary-500 hover:bg-primary-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition-all">
                                                <i class="fas fa-plus mr-1"></i> Assign
                                            </a>
                                            <button onclick="openArchiveModal(<?php echo $student['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>')"
                                                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition-all"
                                                    title="Archive student">
                                                <i class="fas fa-archive mr-1"></i> Archive
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Archive Modal Functions
        function openArchiveModal(studentId, studentName) {
            document.getElementById('archiveStudentId').value = studentId;
            document.getElementById('archiveReason').value = '';
            document.getElementById('archiveModal').style.display = 'flex';
            setTimeout(() => {
                document.getElementById('archiveReason').focus();
            }, 100);
        }
        
        function closeArchiveModal() {
            document.getElementById('archiveModal').style.display = 'none';
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeArchiveModal();
            }
        });

        // Department Accordion Toggle
        function toggleDepartment(header) {
            const content = header.nextElementSibling;
            const chevron = header.querySelector('.department-chevron');
            const isExpanded = header.getAttribute('data-expanded') === 'true';
            
            if (isExpanded) {
                content.style.maxHeight = '0';
                content.style.opacity = '0';
                content.style.overflow = 'hidden';
                chevron.style.transform = 'rotate(-90deg)';
                header.setAttribute('data-expanded', 'false');
            } else {
                content.style.maxHeight = content.scrollHeight + 'px';
                content.style.opacity = '1';
                content.style.overflow = 'visible';
                chevron.style.transform = 'rotate(0deg)';
                header.setAttribute('data-expanded', 'true');
            }
        }

        // Expand/Collapse All
        function toggleAllDepartments() {
            const headers = document.querySelectorAll('.department-header');
            const allExpanded = Array.from(headers).every(h => h.getAttribute('data-expanded') === 'true');
            
            headers.forEach(header => {
                const content = header.nextElementSibling;
                const chevron = header.querySelector('.department-chevron');
                
                if (allExpanded) {
                    content.style.maxHeight = '0';
                    content.style.opacity = '0';
                    content.style.overflow = 'hidden';
                    chevron.style.transform = 'rotate(-90deg)';
                    header.setAttribute('data-expanded', 'false');
                } else {
                    content.style.maxHeight = content.scrollHeight + 'px';
                    content.style.opacity = '1';
                    content.style.overflow = 'visible';
                    chevron.style.transform = 'rotate(0deg)';
                    header.setAttribute('data-expanded', 'true');
                }
            });
        }

        // Search Functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            document.querySelectorAll('.department-section').forEach(section => {
                let hasVisibleContent = false;
                
                section.querySelectorAll('[data-class]').forEach(card => {
                    const className = card.getAttribute('data-class').toLowerCase();
                    const department = card.getAttribute('data-department').toLowerCase();
                    let hasVisibleStudent = false;
                    
                    card.querySelectorAll('[data-student]').forEach(row => {
                        const studentName = row.getAttribute('data-student').toLowerCase();
                        if (studentName.includes(searchTerm) || 
                            className.includes(searchTerm) || 
                            department.includes(searchTerm)) {
                            row.style.display = '';
                            hasVisibleStudent = true;
                        } else {
                            row.style.display = 'none';
                        }
                    });
                    
                    if (hasVisibleStudent || className.includes(searchTerm)) {
                        card.style.display = '';
                        hasVisibleContent = true;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                section.querySelectorAll('.student-row').forEach(row => {
                    const studentName = row.querySelector('.font-medium')?.textContent?.toLowerCase() || '';
                    if (studentName.includes(searchTerm)) {
                        row.style.display = '';
                        hasVisibleContent = true;
                    } else if (!row.closest('[data-class]')) {
                        row.style.display = 'none';
                    }
                });
                
                section.style.display = hasVisibleContent ? '' : 'none';
            });
        });

        // Export to CSV
        function exportToCSV() {
            let csv = 'Department,Class,Student Name,Student ID,Academic Year\n';
            
            <?php foreach ($grouped_assignments as $department => $classes): ?>
                <?php foreach ($classes as $class_name => $students): ?>
                    <?php foreach ($students as $student): ?>
                        csv += '<?php echo addslashes($department); ?>,<?php echo addslashes($class_name); ?>,<?php echo addslashes($student['student_name']); ?>,<?php echo $student['user_id']; ?>,<?php echo addslashes($student['academic_year'] ?? 'N/A'); ?>\n';
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            
            <?php foreach ($unassigned_students as $student): ?>
                csv += 'Unassigned,N/A,<?php echo addslashes($student['student_name']); ?>,<?php echo $student['user_id']; ?>,N/A\n';
            <?php endforeach; ?>
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'student_assignments_<?php echo date('Y-m-d'); ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>

<?php 
try {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
} catch (Exception $e) {
    error_log("Failed to include admin_footer.php: " . $e->getMessage());
}

$conn->close();
?>