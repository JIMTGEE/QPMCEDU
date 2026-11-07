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

if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include logging function
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
} catch (Exception $e) {
    error_log("Failed to include log_activity.php: " . $e->getMessage());
    die("System error: Unable to load logging functions. Please contact the administrator.");
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

// ============================================
// ENSURE ARCHIVED_STUDENTS TABLE EXISTS
// ============================================
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
    } else {
        error_log("archived_students table verified/created successfully");
    }
} catch (Exception $e) {
    error_log("Error checking/creating archived_students table: " . $e->getMessage());
}

// ============================================
// HANDLE POST ACTIONS
// ============================================
$success_msg = $error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'archive' && isset($_POST['student_id'])) {
            $student_id = intval($_POST['student_id']);
            
            error_log("=== ARCHIVE FROM ARCHIVES.PHP STARTED ===");
            error_log("Student ID: $student_id");
            
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // First, get student information
                $student_info_sql = "SELECT id, name, email FROM users WHERE id = ? AND role = 'student' AND status = 'active'";
                if ($stmt = $conn->prepare($student_info_sql)) {
                    $stmt->bind_param("i", $student_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($student = $result->fetch_assoc()) {
                        error_log("Student found: " . json_encode($student));
                        
                        // Check if already archived
                        $check_existing_sql = "SELECT id FROM archived_students WHERE student_id = ?";
                        $check_stmt = $conn->prepare($check_existing_sql);
                        $check_stmt->bind_param("i", $student_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows > 0) {
                            // Update existing record
                            $archive_sql = "UPDATE archived_students SET name = ?, archived_at = NOW() WHERE student_id = ?";
                            $archive_stmt = $conn->prepare($archive_sql);
                            $archive_stmt->bind_param("si", $student['name'], $student_id);
                        } else {
                            // Insert new record
                            $archive_sql = "INSERT INTO archived_students (student_id, name) VALUES (?, ?)";
                            $archive_stmt = $conn->prepare($archive_sql);
                            $archive_stmt->bind_param("is", $student_id, $student['name']);
                        }
                        
                        if (!$archive_stmt->execute()) {
                            throw new Exception("Failed to archive student: " . $archive_stmt->error);
                        }
                        $archive_stmt->close();
                        
                        // Update user status to archived
                        $update_sql = "UPDATE users SET status = 'archived' WHERE id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param("i", $student_id);
                        
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to update user status: " . $update_stmt->error);
                        }
                        $update_stmt->close();
                        
                        // Verify the archive
                        $verify_sql = "SELECT COUNT(*) as count FROM archived_students WHERE student_id = ?";
                        $verify_stmt = $conn->prepare($verify_sql);
                        $verify_stmt->bind_param("i", $student_id);
                        $verify_stmt->execute();
                        $verify_result = $verify_stmt->get_result();
                        $verify_row = $verify_result->fetch_assoc();
                        
                        if ($verify_row['count'] == 0) {
                            throw new Exception("Archive verification failed - no record found");
                        }
                        $verify_stmt->close();
                        
                        $conn->commit();
                        error_log("Transaction committed successfully");
                        
                        $success_msg = "Student '" . htmlspecialchars($student['name']) . "' has been successfully archived.";
                        
                        // Log the activity
                        if (function_exists('logActivity')) {
                            logActivity($_SESSION['user_id'], 'Archive Student', "Archived student: {$student['name']} (ID: {$student_id})");
                        }
                    } else {
                        throw new Exception("Student not found or already archived");
                    }
                    $stmt->close();
                }
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Archive failed: " . $e->getMessage());
                $error_msg = "Failed to archive student: " . $e->getMessage();
            }
            
            error_log("=== ARCHIVE FROM ARCHIVES.PHP ENDED ===");
            
        } elseif ($action === 'restore' && isset($_POST['student_id'])) {
            $student_id = intval($_POST['student_id']);
            
            error_log("=== RESTORE STUDENT STARTED ===");
            error_log("Student ID: $student_id");
            
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Update user status back to active
                $update_sql = "UPDATE users SET status = 'active' WHERE id = ? AND status = 'archived'";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $student_id);
                
                if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
                    error_log("User status updated to active");
                    
                    // Remove from archives
                    $delete_sql = "DELETE FROM archived_students WHERE student_id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $student_id);
                    
                    if ($delete_stmt->execute()) {
                        error_log("Removed from archived_students table");
                    } else {
                        throw new Exception("Failed to remove from archives: " . $delete_stmt->error);
                    }
                    $delete_stmt->close();
                    
                    $conn->commit();
                    error_log("Restore transaction committed");
                    
                    $success_msg = "Student has been successfully restored to active status.";
                    
                    if (function_exists('logActivity')) {
                        logActivity($_SESSION['user_id'], 'Restore Student', "Restored student ID: {$student_id}");
                    }
                } else {
                    throw new Exception("Failed to update user status - student may not be archived");
                }
                $update_stmt->close();
                
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Restore failed: " . $e->getMessage());
                $error_msg = "Failed to restore student: " . $e->getMessage();
            }
            
            error_log("=== RESTORE STUDENT ENDED ===");
            
        } elseif ($action === 'delete' && isset($_POST['student_id'])) {
            $student_id = intval($_POST['student_id']);
            $confirm = isset($_POST['confirm_delete']) ? $_POST['confirm_delete'] : '';
            
            if ($confirm === 'DELETE') {
                error_log("=== PERMANENT DELETE STARTED ===");
                error_log("Student ID: $student_id");
                
                // Begin transaction
                $conn->begin_transaction();
                
                try {
                    // Delete from archived_students
                    $delete_archive_sql = "DELETE FROM archived_students WHERE student_id = ?";
                    $delete_stmt = $conn->prepare($delete_archive_sql);
                    $delete_stmt->bind_param("i", $student_id);
                    $delete_stmt->execute();
                    error_log("Deleted from archived_students: " . $delete_stmt->affected_rows . " rows");
                    $delete_stmt->close();
                    
                    // Delete user completely
                    $delete_user_sql = "DELETE FROM users WHERE id = ? AND status = 'archived'";
                    $user_delete_stmt = $conn->prepare($delete_user_sql);
                    $user_delete_stmt->bind_param("i", $student_id);
                    $user_delete_stmt->execute();
                    error_log("Deleted from users: " . $user_delete_stmt->affected_rows . " rows");
                    $user_delete_stmt->close();
                    
                    $conn->commit();
                    error_log("Delete transaction committed");
                    
                    $success_msg = "Student has been permanently deleted from the system.";
                    
                    if (function_exists('logActivity')) {
                        logActivity($_SESSION['user_id'], 'Permanent Delete', "Permanently deleted student ID: {$student_id}");
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Delete failed: " . $e->getMessage());
                    $error_msg = "Failed to delete student: " . $e->getMessage();
                }
                
                error_log("=== PERMANENT DELETE ENDED ===");
            } else {
                $error_msg = "Please type 'DELETE' to confirm permanent deletion.";
            }
        }
    }
}

// ============================================
// FETCH DATA FOR DISPLAY
// ============================================

// Diagnostic: Check archived_students table directly
$diagnostic_sql = "SELECT COUNT(*) as total_archived FROM archived_students";
$diagnostic_result = $conn->query($diagnostic_sql);
$diagnostic_data = $diagnostic_result->fetch_assoc();
error_log("=== ARCHIVES.PHP DIAGNOSTIC ===");
error_log("Total records in archived_students table: " . $diagnostic_data['total_archived']);

// Fetch archived students with all available information
$archived_sql = "SELECT 
    a.id,
    a.student_id,
    a.name,
    a.archived_at,
    u.email,
    u.phone,
    u.status as user_status,
    DATEDIFF(NOW(), a.archived_at) as days_archived
FROM archived_students a
LEFT JOIN users u ON a.student_id = u.id
ORDER BY a.archived_at DESC";

error_log("Executing archived students query: " . $archived_sql);

$archived_result = $conn->query($archived_sql);
$archived_students = [];

if ($archived_result && $archived_result->num_rows > 0) {
    while ($row = $archived_result->fetch_assoc()) {
        $archived_students[] = $row;
        error_log("Archived student found: ID={$row['student_id']}, Name={$row['name']}, Status={$row['user_status']}");
    }
    $archived_result->free();
} else {
    error_log("No archived students found or query failed: " . ($archived_result ? "0 rows" : $conn->error));
    
    // Diagnostic: Check for students with 'archived' status in users table
    $check_users_sql = "SELECT id, name, status FROM users WHERE status = 'archived'";
    $check_users_result = $conn->query($check_users_sql);
    if ($check_users_result && $check_users_result->num_rows > 0) {
        error_log("Found students with 'archived' status in users table but not in archived_students:");
        while ($row = $check_users_result->fetch_assoc()) {
            error_log("  - ID: {$row['id']}, Name: {$row['name']}, Status: {$row['status']}");
        }
    }
}

// Statistics
$total_archived = count($archived_students);
$archived_this_month = 0;
$archived_last_month = 0;

foreach ($archived_students as $student) {
    $archive_month = date('Y-m', strtotime($student['archived_at']));
    $current_month = date('Y-m');
    $last_month = date('Y-m', strtotime('-1 month'));
    
    if ($archive_month === $current_month) {
        $archived_this_month++;
    } elseif ($archive_month === $last_month) {
        $archived_last_month++;
    }
}

// Fetch active students for potential archiving
$active_students_sql = "SELECT 
    u.id, 
    u.name, 
    u.email, 
    u.phone, 
    u.gender, 
    u.created_at
FROM users u
WHERE u.role = 'student' AND u.status = 'active'
ORDER BY u.name ASC";

$active_students_result = $conn->query($active_students_sql);
$active_students = [];

if ($active_students_result && $active_students_result->num_rows > 0) {
    while ($row = $active_students_result->fetch_assoc()) {
        $active_students[] = $row;
    }
    $active_students_result->free();
}

$total_active = count($active_students);

error_log("Archives page stats: Total Archived=$total_archived, Total Active=$total_active, This Month=$archived_this_month");
error_log("=== END DIAGNOSTIC ===");

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
    <meta name="description" content="Archive and manage former students at Queen's Park Montessori School.">
    <meta name="robots" content="noindex, nofollow">
    <title>Student Archives · Queen's Park Montessori</title>
    
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
            transition: all 0.3s ease;
        }
        
        .glass-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            transition: all 0.3s ease;
        }
        
        .glass-card:hover {
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.15);
        }
        
        .tab-active {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
        }
        
        .modal-overlay {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }
        
        .archive-row:hover {
            background-color: rgba(249, 115, 22, 0.05);
            transform: translateX(2px);
        }
        
        .stat-card {
            border-left: 4px solid #f97316;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            border-left-width: 8px;
        }
        
        /* Refresh indicator */
        .refresh-indicator {
            animation: spin 2s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
        
        <!-- Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8">
            <div class="animate-fade-in">
                <h1 class="text-3xl font-bold bg-gradient-to-r from-primary-500 to-primary-700 bg-clip-text text-transparent">
                    <i class="fas fa-archive mr-2"></i>Student Archives
                </h1>
                <p class="text-secondary mt-1">
                    Manage archived and former student records securely
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a href="/admin/manage_student_classes.php" 
                   class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white px-5 py-2.5 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-arrow-left"></i>
                    Back to Class Management
                </a>
                <button onclick="location.reload()" 
                        class="inline-flex items-center gap-2 bg-white dark:bg-gray-700 border-2 border-primary-500 text-primary-600 dark:text-primary-400 px-5 py-2.5 rounded-xl font-medium hover:bg-primary-50 dark:hover:bg-gray-600 transition-all">
                    <i class="fas fa-sync-alt"></i>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($success_msg)): ?>
            <div class="glass-card border-l-4 border-green-500 bg-green-50 dark:bg-green-900/20 p-4 mb-6 animate-fade-in" id="successAlert">
                <div class="flex items-center gap-3">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    <p class="text-green-800 dark:text-green-200"><?php echo $success_msg; ?></p>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-green-600 hover:text-green-800">
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
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-red-600 hover:text-red-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="glass-card stat-card p-5 animate-slide-up">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                        <i class="fas fa-archive text-2xl text-gray-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary">Total Archived</p>
                        <p class="text-2xl font-bold"><?php echo $total_archived; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-5 animate-slide-up" style="animation-delay: 0.1s; border-left-color: #3b82f6;">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                        <i class="fas fa-calendar-check text-2xl text-blue-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary">Archived This Month</p>
                        <p class="text-2xl font-bold"><?php echo $archived_this_month; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-5 animate-slide-up" style="animation-delay: 0.2s; border-left-color: #10b981;">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                        <i class="fas fa-user-check text-2xl text-green-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary">Active Students</p>
                        <p class="text-2xl font-bold"><?php echo $total_active; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="glass-card stat-card p-5 animate-slide-up" style="animation-delay: 0.3s; border-left-color: #8b5cf6;">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                        <i class="fas fa-exchange-alt text-2xl text-purple-600"></i>
                    </div>
                    <div>
                        <p class="text-sm text-secondary">Total Records</p>
                        <p class="text-2xl font-bold"><?php echo $total_archived + $total_active; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Auto-refresh note -->
        <div class="text-center text-sm text-secondary mb-4">
            <i class="fas fa-info-circle mr-1"></i>
            If you don't see recently archived students, click the Refresh button or check error logs.
        </div>

        <!-- Search Bar -->
        <div class="glass-card p-4 mb-6">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-3.5 text-secondary"></i>
                <input type="text" id="searchInput" placeholder="Search students by name or ID..." 
                       class="w-full pl-10 pr-4 py-2.5 border border-[var(--border-color)] rounded-xl bg-[var(--bg-card)] focus:ring-2 focus:ring-primary-500">
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-2 mb-6 border-b border-[var(--border-color)]">
            <button onclick="switchTab('archived')" id="tab-archived" 
                    class="tab-active px-6 py-3 rounded-t-xl font-medium transition-all">
                <i class="fas fa-archive mr-2"></i>Archived Students
                <span class="ml-2 bg-white/20 px-2 py-0.5 rounded-full text-sm"><?php echo $total_archived; ?></span>
            </button>
            <button onclick="switchTab('active')" id="tab-active" 
                    class="px-6 py-3 rounded-t-xl font-medium hover:bg-gray-100 dark:hover:bg-gray-700 transition-all">
                <i class="fas fa-users mr-2"></i>Active Students
                <span class="ml-2 bg-gray-200 dark:bg-gray-600 px-2 py-0.5 rounded-full text-sm"><?php echo $total_active; ?></span>
            </button>
        </div>

        <!-- Archived Students Tab -->
        <div id="archivedTab">
            <?php if (empty($archived_students)): ?>
                <div class="glass-card p-12 text-center">
                    <i class="fas fa-archive text-6xl text-secondary mb-4"></i>
                    <h3 class="text-xl font-semibold mb-2">No Archived Students Found</h3>
                    <p class="text-secondary mb-4">
                        Archived students will appear here. If you recently archived a student and don't see them, 
                        try refreshing the page.
                    </p>
                    <button onclick="location.reload()" 
                            class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white px-6 py-3 rounded-xl font-medium transition-all">
                        <i class="fas fa-sync-alt"></i>
                        Refresh Page
                    </button>
                    <?php if ($total_archived === 0 && $conn->query("SELECT COUNT(*) as cnt FROM archived_students")->fetch_assoc()['cnt'] > 0): ?>
                        <div class="mt-4 p-4 bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-500 rounded">
                            <p class="text-yellow-800 dark:text-yellow-200">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                There are records in the database but they couldn't be displayed. Please check error logs.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="glass-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50">
                                    <th class="p-4 text-left font-medium">Student</th>
                                    <th class="p-4 text-left font-medium hidden md:table-cell">Student ID</th>
                                    <th class="p-4 text-left font-medium hidden lg:table-cell">Email</th>
                                    <th class="p-4 text-left font-medium">Archived Date</th>
                                    <th class="p-4 text-left font-medium hidden md:table-cell">Days Archived</th>
                                    <th class="p-4 text-left font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="archivedTableBody">
                                <?php foreach ($archived_students as $student): ?>
                                    <tr class="archive-row border-t border-[var(--border-color)]" 
                                        data-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>"
                                        data-id="<?php echo $student['student_id']; ?>">
                                        <td class="p-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-600 flex items-center justify-center flex-shrink-0">
                                                    <span class="font-bold text-gray-600 dark:text-gray-300">
                                                        <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <p class="font-medium"><?php echo htmlspecialchars($student['name']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-4 hidden md:table-cell">
                                            <span class="text-sm text-secondary">#<?php echo htmlspecialchars($student['student_id']); ?></span>
                                        </td>
                                        <td class="p-4 hidden lg:table-cell">
                                            <span class="text-sm"><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td class="p-4">
                                            <div class="text-sm">
                                                <p><?php echo date('M j, Y', strtotime($student['archived_at'])); ?></p>
                                                <p class="text-xs text-secondary"><?php echo date('g:i A', strtotime($student['archived_at'])); ?></p>
                                            </div>
                                        </td>
                                        <td class="p-4 hidden md:table-cell">
                                            <span class="inline-block px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200 rounded-full text-xs">
                                                <?php echo $student['days_archived']; ?> days
                                            </span>
                                        </td>
                                        <td class="p-4">
                                            <div class="flex items-center gap-2">
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                    <button type="submit" 
                                                            class="text-green-500 hover:text-green-700 p-2 rounded-lg hover:bg-green-50 dark:hover:bg-gray-700 transition-all"
                                                            title="Restore Student"
                                                            onclick="return confirm('Restore <?php echo addslashes(htmlspecialchars($student['name'])); ?> to active status?')">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                </form>
                                                <button onclick="confirmDelete(<?php echo $student['student_id']; ?>, '<?php echo addslashes(htmlspecialchars($student['name'])); ?>')" 
                                                        class="text-red-500 hover:text-red-700 p-2 rounded-lg hover:bg-red-50 dark:hover:bg-gray-700 transition-all"
                                                        title="Permanently Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Active Students Tab -->
        <div id="activeTab" class="hidden">
            <?php if (empty($active_students)): ?>
                <div class="glass-card p-12 text-center">
                    <i class="fas fa-users text-6xl text-secondary mb-4"></i>
                    <h3 class="text-xl font-semibold mb-2">No Active Students</h3>
                    <p class="text-secondary">All students have been archived or no students are currently enrolled.</p>
                </div>
            <?php else: ?>
                <div class="glass-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50">
                                    <th class="p-4 text-left font-medium">Student</th>
                                    <th class="p-4 text-left font-medium hidden md:table-cell">Email</th>
                                    <th class="p-4 text-left font-medium hidden lg:table-cell">Phone</th>
                                    <th class="p-4 text-left font-medium hidden md:table-cell">Gender</th>
                                    <th class="p-4 text-left font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="activeTableBody">
                                <?php foreach ($active_students as $student): ?>
                                    <tr class="archive-row border-t border-[var(--border-color)]" 
                                        data-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>"
                                        data-id="<?php echo $student['id']; ?>">
                                        <td class="p-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center flex-shrink-0">
                                                    <span class="font-bold text-primary-600">
                                                        <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <p class="font-medium"><?php echo htmlspecialchars($student['name']); ?></p>
                                                    <p class="text-xs text-secondary">ID: <?php echo $student['id']; ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-4 hidden md:table-cell">
                                            <span class="text-sm"><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td class="p-4 hidden lg:table-cell">
                                            <span class="text-sm"><?php echo htmlspecialchars($student['phone'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td class="p-4 hidden md:table-cell">
                                            <span class="text-sm capitalize"><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td class="p-4">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                <button type="submit" 
                                                        class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm transition-all"
                                                        onclick="return confirm('Archive <?php echo addslashes(htmlspecialchars($student['name'])); ?>?')">
                                                    <i class="fas fa-archive mr-1"></i>Archive
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 modal-overlay hidden z-50 flex items-center justify-center">
        <div class="glass-card w-full max-w-md mx-4 p-6 animate-slide-up border-l-4 border-red-500">
            <h3 class="text-xl font-bold text-red-600 mb-4">
                <i class="fas fa-exclamation-triangle mr-2"></i>Permanent Deletion
            </h3>
            <p class="mb-2">Are you sure you want to permanently delete:</p>
            <p class="text-lg font-semibold text-red-600 mb-4" id="deleteStudentName"></p>
            <p class="text-sm text-secondary mb-4">⚠️ This action cannot be undone. All student data will be permanently removed.</p>
            
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="student_id" id="deleteStudentId">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Type <span class="text-red-600 font-bold">DELETE</span> to confirm</label>
                    <input type="text" name="confirm_delete" 
                           class="w-full px-4 py-2.5 border-2 border-red-300 rounded-xl focus:ring-2 focus:ring-red-500"
                           placeholder="DELETE" required autocomplete="off">
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 bg-red-500 hover:bg-red-600 text-white py-2.5 rounded-xl font-medium transition-all">
                        <i class="fas fa-trash mr-2"></i>Delete Permanently
                    </button>
                    <button type="button" onclick="closeModal('deleteModal')" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2.5 rounded-xl font-medium transition-all">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tab) {
            document.getElementById('tab-archived').classList.remove('tab-active');
            document.getElementById('tab-active').classList.remove('tab-active');
            document.getElementById(`tab-${tab}`).classList.add('tab-active');
            
            document.getElementById('archivedTab').classList.add('hidden');
            document.getElementById('activeTab').classList.add('hidden');
            document.getElementById(`${tab}Tab`).classList.remove('hidden');
            
            // Clear search when switching tabs
            document.getElementById('searchInput').value = '';
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function confirmDelete(studentId, studentName) {
            document.getElementById('deleteStudentId').value = studentId;
            document.getElementById('deleteStudentName').textContent = studentName;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const activeTab = document.getElementById('archivedTab').classList.contains('hidden') ? 'active' : 'archived';
            const tableBody = document.getElementById(`${activeTab}TableBody`);
            
            if (tableBody) {
                tableBody.querySelectorAll('tr').forEach(row => {
                    const name = row.getAttribute('data-name') || '';
                    const id = row.getAttribute('data-id') || '';
                    row.style.display = (name.includes(searchTerm) || id.includes(searchTerm)) ? '' : 'none';
                });
            }
        });

        // Close modals on outside click
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.classList.add('hidden');
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(modal => {
                    if (!modal.classList.contains('hidden')) {
                        modal.classList.add('hidden');
                    }
                });
            }
        });

        // Theme toggle
        const themeBtn = document.createElement('button');
        themeBtn.className = 'fixed bottom-6 right-6 bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800 w-12 h-12 rounded-full shadow-lg flex items-center justify-center transition-all z-50 hover:scale-110';
        themeBtn.innerHTML = '<i class="fas fa-moon"></i>';
        themeBtn.title = 'Toggle dark/light theme';
        document.body.appendChild(themeBtn);
        
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        themeBtn.innerHTML = savedTheme === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        
        themeBtn.addEventListener('click', () => {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme');
            const next = current === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            themeBtn.innerHTML = next === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
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