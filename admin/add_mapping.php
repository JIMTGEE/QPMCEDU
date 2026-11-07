<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and logging function
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    die("System error. Please contact support.");
}

// Get current academic year from database
$current_academic_year = '';
$current_academic_year_id = 0;

$year_query = "SELECT id, year_label FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
$year_result = $conn->query($year_query);
if ($year_result && $year_result->num_rows > 0) {
    $year_data = $year_result->fetch_assoc();
    $current_academic_year_id = $year_data['id'];
    $current_academic_year = $year_data['year_label'];
} else {
    $current_academic_year = '2025/2026';
    $current_academic_year_id = 2;
}

// ============================================================
// FIXED CSRF TOKEN - Only generate if it doesn't exist
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$errors = [];
$success_msg = '';
$parent_id = '';
$student_ids = [];

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success_msg = htmlspecialchars(trim($_GET['success']));
}

// Handle form submission BEFORE fetching data for the page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mapping') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (empty($submitted_token) || empty($session_token) || $submitted_token !== $session_token) {
        $errors[] = "Security validation failed. Please refresh the page and try again.";
    } else {
        $parent_id = filter_var($_POST['parent_id'] ?? 0, FILTER_VALIDATE_INT);
        $student_ids = isset($_POST['student_ids']) ? $_POST['student_ids'] : [];
        
        if (!is_array($student_ids)) {
            $student_ids = [];
        } else {
            $student_ids = array_values(array_filter(array_map('intval', $student_ids)));
        }

        if (!$parent_id || $parent_id <= 0) {
            $errors[] = "Please select a valid parent.";
        }
        
        if (empty($student_ids)) {
            $errors[] = "Please select at least one student.";
        }

        // Check for existing mappings
        if (empty($errors) && !empty($student_ids)) {
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
            $types = 'i' . str_repeat('i', count($student_ids));
            $params = array_merge([$parent_id], $student_ids);
            
            $check_sql = "SELECT student_id FROM parent_student_mapping WHERE parent_id = ? AND student_id IN ($placeholders)";
            $check_stmt = $conn->prepare($check_sql);
            
            if ($check_stmt) {
                $check_stmt->bind_param($types, ...$params);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                $existing = [];
                while ($row = $check_result->fetch_assoc()) {
                    $existing[] = $row['student_id'];
                }
                $check_stmt->close();
                
                if (!empty($existing)) {
                    $existing_names = [];
                    foreach ($students_by_class_temp ?? [] as $class_data) {
                        foreach ($class_data['students'] as $student) {
                            if (in_array($student['id'], $existing)) {
                                $existing_names[] = $student['name'];
                            }
                        }
                    }
                    if (!empty($existing_names)) {
                        $errors[] = "Already mapped: " . implode(', ', $existing_names);
                    }
                }
            }
        }

        // Insert mappings
        if (empty($errors)) {
            $insert_sql = "INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            
            if ($insert_stmt) {
                $success_count = 0;
                
                foreach ($student_ids as $student_id) {
                    $insert_stmt->bind_param("ii", $parent_id, $student_id);
                    
                    if ($insert_stmt->execute()) {
                        $success_count++;
                        logActivity($conn, $_SESSION['user_id'], "Added parent-student mapping", "Parent ID: $parent_id, Student ID: $student_id");
                    } else {
                        error_log("Failed to add mapping: Parent ID: $parent_id, Student ID: $student_id, Error: " . $insert_stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
                    }
                }
                $insert_stmt->close();
                
                if ($success_count > 0) {
                    // Regenerate CSRF token after successful submission
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    
                    $success_msg = "$success_count mapping(s) added successfully.";
                    header("Location: parent_student.php?success=" . urlencode($success_msg));
                    exit();
                } else {
                    $errors[] = "Failed to add mappings. Please try again.";
                }
            } else {
                $errors[] = "Database error. Please try again.";
                error_log("Insert prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
            }
        }
    }
}

// Fetch parents
$parents = [];
$parent_query = "SELECT u.id, u.name FROM users u WHERE u.role = 'parent' AND u.status = 'active' ORDER BY u.name ASC";
$parent_result = $conn->query($parent_query);
if ($parent_result) {
    while ($row = $parent_result->fetch_assoc()) {
        $parents[] = $row;
    }
} else {
    error_log("Parent query failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    $errors[] = "Failed to fetch parents due to a system error.";
}

// Fetch students with their class information
$students_by_class = [];

$student_query = "
    SELECT 
        u.id, 
        u.name AS student_name,
        u.status,
        sc.class_id,
        c.class_name,
        c.department_id,
        d.name AS department_name,
        sc.academic_year,
        sc.academic_year_id
    FROM users u 
    LEFT JOIN student_classes sc ON u.id = sc.user_id 
    LEFT JOIN classes c ON sc.class_id = c.id 
    LEFT JOIN departments d ON c.department_id = d.id
    WHERE u.role = 'student' 
        AND u.status = 'active'
    ORDER BY d.name ASC, c.class_name ASC, u.name ASC
";

$student_result = $conn->query($student_query);

if (!$student_result) {
    error_log("Student query failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    $errors[] = "Failed to fetch students due to a system error.";
} else {
    if ($student_result->num_rows === 0) {
        $errors[] = "No active students found in the system. Please add students first.";
    } else {
        while ($row = $student_result->fetch_assoc()) {
            $department = $row['department_name'] ?: 'Unassigned';
            $class_name = $row['class_name'] ?: 'No Class Assigned';
            $academic_year = $row['academic_year'] ?: 'Not Set';
            
            $class_key = $department . ' - ' . $class_name;
            
            if (!isset($students_by_class[$class_key])) {
                $students_by_class[$class_key] = [
                    'department' => $department,
                    'class_name' => $class_name,
                    'class_id' => $row['class_id'],
                    'students' => []
                ];
            }
            
            $students_by_class[$class_key]['students'][] = [
                'id' => $row['id'],
                'name' => $row['student_name'],
                'academic_year' => $academic_year
            ];
        }
    }
}

$total_students_query = "SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'";
$total_result = $conn->query($total_students_query);
$total_students = $total_result ? $total_result->fetch_assoc()['total'] : 0;

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Parent-Student Mapping - Queen's Paradise Montessori</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #FF6200;
            --accent-dark: #D95200;
            --accent-soft: #FFF0E6;
            --accent-glow: rgba(255,98,0,.12);
            --emerald: #10b981;
            --rose: #ef4444;
            --amber: #f59e0b;
            --bg: #f0f2f7;
            --surface: #ffffff;
            --border: #e4e8f0;
            --muted: #94a3b8;
            --text: #0d1b2a;
            --text-2: #4a5568;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.07);
            --shadow-md: 0 4px 12px rgba(0,0,0,.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,.10);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-panel {
            flex: 1;
            margin-left: 280px;
            padding: 32px 40px;
            width: calc(100% - 280px);
            transition: margin 0.3s ease;
            max-width: 100%;
        }

        @media (max-width: 1024px) {
            .main-panel {
                margin-left: 0;
                width: 100%;
                padding: 20px 24px;
            }
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #FF6200 0%, #FF9100 100%);
            border-radius: var(--radius-xl);
            padding: 32px 40px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(255,98,0,.3);
        }
        .page-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='200'%3E%3Ccircle cx='350' cy='30' r='120' fill='rgba(255,255,255,.05)'/%3E%3Ccircle cx='20' cy='160' r='80' fill='rgba(255,255,255,.04)'/%3E%3C/svg%3E") no-repeat right center;
            pointer-events: none;
        }
        .ph-title { position: relative; z-index: 1; }
        .ph-title h1 { font-size: 2rem; font-weight: 700; display: flex; align-items: center; gap: 14px; letter-spacing: -0.5px; }
        .ph-title p { font-size: 15px; opacity: .85; margin-top: 8px; font-weight: 400; }
        .ph-actions { display: flex; gap: 12px; flex-wrap: wrap; position: relative; z-index: 1; }

        .ph-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
            text-decoration: none;
        }
        .ph-btn-primary {
            background: #fff;
            color: var(--accent);
        }
        .ph-btn-primary:hover { 
            background: var(--accent-soft); 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(0,0,0,.15); 
        }
        .ph-btn-ghost {
            background: rgba(255,255,255,.15);
            color: #fff;
            border: 1.5px solid rgba(255,255,255,.3);
            backdrop-filter: blur(8px);
        }
        .ph-btn-ghost:hover { 
            background: rgba(255,255,255,.25); 
            transform: translateY(-2px); 
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 22px;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 500;
            margin-bottom: 28px;
            animation: slideDown .3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-danger ul { margin: 8px 0 0 20px; list-style: disc; }
        .alert-danger ul li { margin-bottom: 4px; }
        .alert-close {
            margin-left: auto; background: none; border: none;
            cursor: pointer; font-size: 18px; opacity: .6; color: inherit;
            padding: 2px 6px;
        }
        .alert-close:hover { opacity: 1; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: var(--shadow-sm);
            transition: transform .25s, box-shadow .25s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -1px;
        }
        .stat-label { font-size: 13px; color: var(--text-2); font-weight: 500; margin-top: 4px; }
        .stat-sub { font-size: 11px; color: var(--muted); margin-top: 2px; font-family: 'DM Mono', monospace; }

        /* Main Card */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            margin-bottom: 32px;
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #FF6200 0%, #FF9100 100%);
            color: #fff;
            padding: 20px 32px;
            font-weight: 600;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-body { padding: 32px; }

        /* Form Layout - Full Width */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 32px;
        }
        @media (max-width: 900px) {
            .form-row { grid-template-columns: 1fr; }
        }

        .form-section {
            background: var(--bg);
            border-radius: var(--radius-lg);
            padding: 24px;
            border: 1px solid var(--border);
        }
        .form-section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
        }
        .form-section-title i { font-size: 20px; }

        .form-select {
            width: 100%;
            padding: 14px 18px;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            background: var(--surface);
            color: var(--text);
            cursor: pointer;
            outline: none;
            transition: all .2s;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%2394a3b8'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
        }
        .form-select:focus { 
            border-color: var(--accent); 
            box-shadow: 0 0 0 4px var(--accent-glow); 
        }

        .search-box {
            width: 100%;
            padding: 14px 18px 14px 48px;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            background: var(--surface);
            color: var(--text);
            outline: none;
            transition: all .2s;
            margin-bottom: 18px;
        }
        .search-box:focus { 
            border-color: var(--accent); 
            box-shadow: 0 0 0 4px var(--accent-glow); 
        }
        .search-wrapper { position: relative; }
        .search-wrapper .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 16px;
            pointer-events: none;
        }

        .student-list {
            max-height: 500px;
            overflow-y: auto;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
        }
        .student-list::-webkit-scrollbar { width: 6px; }
        .student-list::-webkit-scrollbar-track { background: var(--bg); border-radius: 10px; }
        .student-list::-webkit-scrollbar-thumb { background: var(--muted); border-radius: 10px; }

        .class-group {
            border-bottom: 1px solid var(--border);
        }
        .class-group:last-child { border-bottom: none; }
        
        .class-group-header {
            background: var(--accent-soft);
            padding: 14px 20px;
            font-weight: 600;
            color: var(--accent-dark);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: background .15s;
            user-select: none;
        }
        .class-group-header:hover { background: #ffe8d6; }
        .class-group-header .toggle-icon { transition: transform .2s; font-size: 12px; }
        .class-group-header.collapsed .toggle-icon { transform: rotate(-90deg); }
        
        .class-group-body { 
            padding: 12px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 6px;
        }
        .class-group-header.collapsed + .class-group-body { display: none; }

        .student-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: background .15s;
        }
        .student-item:hover { background: var(--accent-soft); }
        .student-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--accent);
            flex-shrink: 0;
        }
        .student-item label {
            flex: 1;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 500;
            min-width: 0;
        }
        .student-item label span:first-child {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .badge-year {
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            background: #fef3c7;
            color: #92400e;
            font-weight: 600;
            flex-shrink: 0;
            margin-left: 8px;
        }

        .btn-group-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .btn-sm {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 1.5px solid var(--border);
            background: var(--surface);
            color: var(--text-2);
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
        }
        .btn-sm:hover { 
            border-color: var(--accent); 
            color: var(--accent); 
            background: var(--accent-soft);
        }
        .btn-sm.btn-accent { 
            background: var(--accent); 
            color: #fff; 
            border-color: var(--accent); 
        }
        .btn-sm.btn-accent:hover { 
            background: var(--accent-dark); 
            border-color: var(--accent-dark);
        }

        .badge-count {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 40px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 14px;
            font-weight: 700;
            font-family: 'DM Mono', monospace;
        }

        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 16px 36px;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            background: var(--accent);
            color: #fff;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
        }
        .btn-submit:hover:not(:disabled) {
            background: var(--accent-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(255,98,0,.35);
        }
        .btn-submit:disabled {
            opacity: .5;
            cursor: not-allowed;
        }
        .btn-submit i { font-size: 20px; }

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: var(--muted);
        }
        .empty-state i { font-size: 56px; opacity: .25; margin-bottom: 20px; display: block; }
        .empty-state h4 { font-size: 17px; font-weight: 600; color: var(--text-2); margin-bottom: 8px; }
        .empty-state p { font-size: 14px; color: var(--muted); }

        .submit-section {
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            align-items: center;
        }

        @media (max-width: 768px) {
            .main-panel { padding: 16px; }
            .page-header { flex-direction: column; text-align: center; padding: 24px; }
            .ph-title h1 { font-size: 1.5rem; }
            .card-body { padding: 20px; }
            .student-list { max-height: 350px; }
            .class-group-body { grid-template-columns: 1fr; }
            .submit-section { flex-direction: column; }
            .btn-submit { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <div class="main-panel">
        <!-- Page Header -->
        <div class="page-header">
            <div class="ph-title">
                <h1><i class="fas fa-link"></i> Parent-Student Mapping</h1>
                <p>Link parents to their children for the <?php echo htmlspecialchars($current_academic_year); ?> academic year</p>
            </div>
            <div class="ph-actions">
                <a href="parent_student.php" class="ph-btn ph-btn-ghost">
                    <i class="fas fa-list"></i> View All Mappings
                </a>
                <a href="/admin/dashboard.php" class="ph-btn ph-btn-primary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff7ed;color:#f97316;">
                    <i class="fas fa-user-friends"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo count($parents); ?></div>
                    <div class="stat-label">Active Parents</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ecfdf5;color:#10b981;">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $total_students; ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#eff6ff;color:#3b82f6;">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo count($students_by_class); ?></div>
                    <div class="stat-label">Classes</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fdf4ff;color:#8b5cf6;">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div>
                    <div class="stat-value" style="font-size:1.25rem;"><?php echo htmlspecialchars($current_academic_year); ?></div>
                    <div class="stat-label">Academic Year</div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_msg): ?>
        <div class="alert alert-success">
            <i class="fas fa-circle-check" style="font-size: 20px;"></i>
            <span><?php echo $success_msg; ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-triangle-exclamation" style="font-size: 20px;"></i>
            <div>
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Main Form -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-edit"></i> Create New Mapping
            </div>
            <div class="card-body">
                <form method="POST" id="mappingForm">
                    <input type="hidden" name="action" value="add_mapping">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="form-row">
                        <!-- Parent Selection Column -->
                        <div>
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-user" style="color: #f97316;"></i> 
                                    Select Parent / Guardian
                                </div>
                                <select name="parent_id" id="parent_id" class="form-select" required>
                                    <option value="">-- Choose a parent --</option>
                                    <?php foreach ($parents as $parent): ?>
                                        <option value="<?php echo $parent['id']; ?>" <?php echo ($parent_id == $parent['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($parent['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($parents)): ?>
                                    <div style="margin-top: 16px; padding: 16px; background: #fef3c7; border-radius: 12px; font-size: 14px; color: #92400e;">
                                        <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>
                                        No active parents found. Please add parents to the system first.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Student Selection Column -->
                        <div>
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="fas fa-graduation-cap" style="color: #10b981;"></i> 
                                    Select Students
                                    <span class="badge-count" id="selectedCount">0 selected</span>
                                </div>

                                <div class="search-wrapper">
                                    <i class="fas fa-search search-icon"></i>
                                    <input type="text" class="search-box" id="studentSearch" placeholder="Search students by name...">
                                </div>

                                <div class="btn-group-actions">
                                    <button type="button" class="btn-sm" onclick="selectAll()">
                                        <i class="fas fa-check-double"></i> Select All
                                    </button>
                                    <button type="button" class="btn-sm" onclick="deselectAll()">
                                        <i class="fas fa-times"></i> Deselect All
                                    </button>
                                </div>

                                <div class="student-list" id="studentList">
                                    <?php if (empty($students_by_class)): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-user-slash"></i>
                                            <h4>No Active Students</h4>
                                            <p>Please register students in the system first.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($students_by_class as $class_key => $class_data): 
                                            $class_hash = md5($class_key);
                                        ?>
                                            <div class="class-group" data-department="<?php echo htmlspecialchars($class_data['department']); ?>"
                                                 data-class="<?php echo htmlspecialchars($class_data['class_name']); ?>">
                                                <div class="class-group-header" onclick="toggleClassGroup(this)">
                                                    <span>
                                                        <i class="fas fa-chalkboard" style="margin-right: 8px;"></i>
                                                        <?php echo htmlspecialchars($class_data['department']); ?> — 
                                                        <strong><?php echo htmlspecialchars($class_data['class_name']); ?></strong>
                                                    </span>
                                                    <span style="display:flex;align-items:center;gap:12px;">
                                                        <span style="font-size:12px;color:var(--muted);"><?php echo count($class_data['students']); ?> students</span>
                                                        <i class="fas fa-chevron-down toggle-icon"></i>
                                                    </span>
                                                </div>
                                                <div class="class-group-body">
                                                    <?php foreach ($class_data['students'] as $student): ?>
                                                        <div class="student-item" data-student-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>">
                                                            <input type="checkbox" 
                                                                   name="student_ids[]" 
                                                                   value="<?php echo $student['id']; ?>" 
                                                                   id="student-<?php echo $student['id']; ?>"
                                                                   class="student-checkbox class-<?php echo $class_hash; ?>"
                                                                   <?php echo in_array($student['id'], $student_ids) ? 'checked' : ''; ?>
                                                                   onchange="updateSelectedCount()">
                                                            <label for="student-<?php echo $student['id']; ?>">
                                                                <span><?php echo htmlspecialchars($student['name']); ?></span>
                                                                <?php if ($student['academic_year'] !== $current_academic_year && $student['academic_year'] !== 'Not Set'): ?>
                                                                    <span class="badge-year"><?php echo htmlspecialchars($student['academic_year']); ?></span>
                                                                <?php endif; ?>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Section -->
                    <div class="submit-section">
                        <span class="badge-count" id="submitCount">Ready to map 0 student(s)</span>
                        <button type="submit" class="btn-submit" id="submitBtn"
                                <?php echo (empty($parents) || empty($students_by_class)) ? 'disabled' : ''; ?>>
                            <i class="fas fa-save"></i> Save Mapping
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>

<script>
function updateSelectedCount() {
    const count = document.querySelectorAll('.student-checkbox:checked').length;
    const countEl = document.getElementById('selectedCount');
    const submitCountEl = document.getElementById('submitCount');
    if (countEl) countEl.textContent = count + ' selected';
    if (submitCountEl) submitCountEl.textContent = 'Ready to map ' + count + ' student' + (count !== 1 ? 's' : '');
}

function selectAll() {
    document.querySelectorAll('.student-checkbox').forEach(cb => { cb.checked = true; });
    updateSelectedCount();
}

function deselectAll() {
    document.querySelectorAll('.student-checkbox').forEach(cb => { cb.checked = false; });
    updateSelectedCount();
}

function toggleClassGroup(header) {
    header.classList.toggle('collapsed');
}

document.getElementById('studentSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase().trim();
    const classGroups = document.querySelectorAll('.class-group');
    
    classGroups.forEach(group => {
        let hasVisibleStudents = false;
        const studentItems = group.querySelectorAll('.student-item');
        
        studentItems.forEach(item => {
            const studentName = item.getAttribute('data-student-name') || '';
            if (searchTerm === '' || studentName.includes(searchTerm)) {
                item.style.display = 'flex';
                hasVisibleStudents = true;
            } else {
                item.style.display = 'none';
            }
        });
        
        group.style.display = hasVisibleStudents || searchTerm === '' ? '' : 'none';
    });
});

document.getElementById('mappingForm').addEventListener('submit', function(e) {
    const parentId = document.getElementById('parent_id').value;
    const selectedStudents = document.querySelectorAll('.student-checkbox:checked');
    
    if (!parentId) {
        e.preventDefault();
        alert('⚠️ Please select a parent first.');
        document.getElementById('parent_id').focus();
        return;
    }
    
    if (selectedStudents.length === 0) {
        e.preventDefault();
        alert('⚠️ Please select at least one student.');
        return;
    }
    
    const parentName = document.querySelector('#parent_id option:checked')?.text || 'Unknown';
    const message = `Confirm: Link ${selectedStudents.length} student(s) to "${parentName}"?\n\nThis action will be logged.`;
    
    if (!confirm(message)) {
        e.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
});
</script>
</body>
</html>
<?php 
ob_end_flush(); 
$conn->close();
?>