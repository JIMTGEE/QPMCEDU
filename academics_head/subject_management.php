<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to subject_management.php");
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
} else {
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
}

// Verify database connection
if (!isset($conn) || !$conn) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    die("Database connection failed. Please try again later.");
}

// Initialize variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$dept_filter = isset($_GET['department']) ? (int)$_GET['department'] : 'all';
$year_filter = isset($_GET['academic_year']) ? (int)$_GET['academic_year'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$order = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'asc' : 'desc';

// Build query with filters and statistics
$query = "SELECT 
            s.id, s.subject_name, s.subject_code, s.description, s.status, 
            s.created_at, s.updated_at, s.created_by, s.updated_by,
            d.id AS department_id, d.name AS department_name, 
            u.id AS teacher_id, u.name AS teacher_name, u.email AS teacher_email,
            COUNT(DISTINCT cs.class_id) AS class_count,
            COUNT(DISTINCT lp.id) AS lesson_plan_count,
            COUNT(DISTINCT a.id) AS assignment_count,
            COUNT(DISTINCT te.id) AS evaluation_count,
            (SELECT COUNT(*) FROM class_subjects cs2 WHERE cs2.subject_id = s.id) AS total_classes
          FROM subjects s 
          LEFT JOIN departments d ON s.department_id = d.id 
          LEFT JOIN users u ON s.teacher_id = u.id
          LEFT JOIN class_subjects cs ON s.id = cs.subject_id
          LEFT JOIN lesson_plans lp ON s.id = lp.subject_id AND lp.status = 'published'
          LEFT JOIN assignments a ON s.id = a.subject_id
          LEFT JOIN teacher_evaluations te ON s.id = te.subject_id
          WHERE 1=1";

$params = [];
$types = "";

if ($search) {
    $query .= " AND (s.subject_name LIKE ? OR s.subject_code LIKE ? OR s.description LIKE ? OR d.name LIKE ? OR u.name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= "sssss";
}

if ($status_filter !== 'all') {
    $query .= " AND s.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($dept_filter !== 'all' && $dept_filter > 0) {
    $query .= " AND s.department_id = ?";
    $params[] = $dept_filter;
    $types .= "i";
}

$query .= " GROUP BY s.id, s.subject_name, s.subject_code, s.description, s.status, 
            s.created_at, s.updated_at, s.created_by, s.updated_by,
            d.id, d.name, u.id, u.name, u.email";

// Add sorting
$allowed_sorts = ['subject_name', 'subject_code', 'status', 'created_at', 'class_count', 'lesson_plan_count'];
if (in_array($sort, $allowed_sorts)) {
    $query .= " ORDER BY " . ($sort === 'class_count' ? 'total_classes' : $sort) . " " . $order;
} else {
    $query .= " ORDER BY s.created_at DESC";
}

// Prepare and execute query
$stmt = $conn->prepare($query);
if ($stmt) {
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $subjects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Query failed: " . $conn->error);
    die("System error: Unable to load subjects. Please try again later.");
}

// Fetch departments for filter
$dept_query = "SELECT id, name FROM departments ORDER BY name";
$dept_stmt = $conn->prepare($dept_query);
$departments = [];
if ($dept_stmt) {
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    $departments = $dept_result->fetch_all(MYSQLI_ASSOC);
    $dept_stmt->close();
}

// Fetch academic years for filter
$years_query = "SELECT id, year_label FROM academic_years ORDER BY start_date DESC";
$years_stmt = $conn->prepare($years_query);
$academic_years = [];
if ($years_stmt) {
    $years_stmt->execute();
    $years_result = $years_stmt->get_result();
    $academic_years = $years_result->fetch_all(MYSQLI_ASSOC);
    $years_stmt->close();
}

// Get comprehensive statistics
$stats_query = "SELECT 
    COUNT(DISTINCT s.id) as total,
    SUM(CASE WHEN s.status = 'Active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN s.status = 'Inactive' THEN 1 ELSE 0 END) as inactive_count,
    SUM(CASE WHEN s.teacher_id IS NOT NULL THEN 1 ELSE 0 END) as with_teacher,
    COUNT(DISTINCT s.department_id) as departments_count,
    SUM(CASE WHEN s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_last_30_days,
    (SELECT COUNT(DISTINCT subject_id) FROM class_subjects) as assigned_to_classes,
    (SELECT COUNT(DISTINCT subject_id) FROM lesson_plans WHERE status = 'published') as with_lesson_plans
    FROM subjects s";
$stats_stmt = $conn->prepare($stats_query);
$stats = [];
if ($stats_stmt) {
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
    $stats_stmt->close();
}

// Log activity
$admin_id = $_SESSION['user_id'];
$action = "Viewed Subject Management Dashboard";
$description = "Admin viewed subject management with filters - Search: '$search', Status: '$status_filter', Department: '$dept_filter'";
logActivity($conn, $admin_id, $action, $description);

// Include header with error handling
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
    <meta name="description" content="Manage subjects in QueensPa School, including adding, editing, and deleting subjects.">
    <meta name="keywords" content="QueensPa School, subject management, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Subject Management - QueensPa School</title>
    
    <!-- CSS Links -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
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
            --purple-color: #8b5cf6;
            --pink-color: #ec4899;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
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
        
        .stat-card.total::before { background: linear-gradient(to right, var(--primary-color), var(--primary-light)); }
        .stat-card.active::before { background: linear-gradient(to right, var(--success-color), #34d399); }
        .stat-card.inactive::before { background: linear-gradient(to right, var(--warning-color), #fbbf24); }
        .stat-card.teacher::before { background: linear-gradient(to right, var(--info-color), #60a5fa); }
        .stat-card.departments::before { background: linear-gradient(to right, var(--purple-color), #c084fc); }
        .stat-card.new::before { background: linear-gradient(to right, var(--pink-color), #f472b6); }
        .stat-card.assigned::before { background: linear-gradient(to right, #14b8a6, #2dd4bf); }
        .stat-card.plans::before { background: linear-gradient(to right, #f97316, #fb923c); }
        
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
        
        .stat-card.total .stat-icon { background: linear-gradient(135deg, rgba(255, 69, 0, 0.1), rgba(255, 107, 0, 0.1)); }
        .stat-card.active .stat-icon { background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(52, 211, 153, 0.1)); }
        .stat-card.inactive .stat-icon { background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(251, 191, 36, 0.1)); }
        .stat-card.teacher .stat-icon { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(96, 165, 250, 0.1)); }
        .stat-card.departments .stat-icon { background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(192, 132, 252, 0.1)); }
        
        .stat-icon i {
            font-size: 24px;
        }
        
        .stat-card.total .stat-icon i { color: var(--primary-color); }
        .stat-card.active .stat-icon i { color: var(--success-color); }
        .stat-card.inactive .stat-icon i { color: var(--warning-color); }
        .stat-card.teacher .stat-icon i { color: var(--info-color); }
        .stat-card.departments .stat-icon i { color: var(--purple-color); }
        .stat-card.new .stat-icon i { color: var(--pink-color); }
        .stat-card.assigned .stat-icon i { color: #14b8a6; }
        .stat-card.plans .stat-icon i { color: #f97316; }
        
        .stat-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.2;
        }
        
        .stat-trend {
            font-size: 0.85rem;
            color: #10b981;
            margin-top: 0.25rem;
        }
        
        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.75rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(255, 69, 0, 0.1);
            background: linear-gradient(to bottom, white, #fffaf5);
        }
        
        .filter-card .card-title {
            color: var(--primary-color);
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .filter-card .form-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .filter-card .form-control,
        .filter-card .form-select {
            border: 2px solid #f3e8e2;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: var(--transition);
            background-color: white;
        }
        
        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(255, 69, 0, 0.1);
            outline: none;
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
            cursor: pointer;
            border: none;
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
        
        .btn-custom.btn-info {
            background: linear-gradient(135deg, var(--info-color), #2563eb);
        }
        
        .btn-custom.btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }
        
        .btn-custom.btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
        }
        
        .btn-custom.btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        .btn-custom.btn-icon {
            padding: 0.5rem;
            border-radius: 8px;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }
        
        /* Table Container */
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(255, 69, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .table {
            margin: 0;
            font-size: 0.9rem;
        }
        
        .table thead th {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            border: none;
            padding: 1rem 1.25rem;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            cursor: pointer;
            position: relative;
        }
        
        .table thead th:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
        }
        
        .table thead th i {
            margin-left: 0.5rem;
            font-size: 0.8rem;
        }
        
        .table tbody tr {
            transition: var(--transition);
        }
        
        .table tbody tr:hover {
            background-color: #fff5f0;
        }
        
        .table tbody td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgba(255, 69, 0, 0.1);
            vertical-align: middle;
        }
        
        /* Badges */
        .badge-status {
            padding: 0.4rem 0.85rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }
        
        .badge-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .badge-inactive {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .badge-department {
            background: linear-gradient(135deg, rgba(255, 69, 0, 0.1), rgba(255, 107, 0, 0.1));
            color: var(--primary-color);
            border: 1px solid rgba(255, 69, 0, 0.2);
            padding: 0.35rem 0.75rem;
            border-radius: 100px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .badge-stat {
            background: #f3e8e2;
            color: var(--primary-color);
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        /* Subject Code */
        .subject-code {
            font-family: 'Courier New', monospace;
            background: #f3e8e2;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.85rem;
            color: var(--primary-color);
            font-weight: 600;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        /* Action Buttons Group */
        .btn-group-action {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-group-action .btn {
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.85rem;
            transition: var(--transition);
            border: none;
        }
        
        .btn-group-action .btn-view {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info-color);
        }
        
        .btn-group-action .btn-view:hover {
            background: var(--info-color);
            color: white;
        }
        
        .btn-group-action .btn-edit {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }
        
        .btn-group-action .btn-edit:hover {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-group-action .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }
        
        .btn-group-action .btn-delete:hover {
            background: var(--danger-color);
            color: white;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem;
            color: #9ca3af;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: #f3e8e2;
        }
        
        .empty-state h4 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            font-size: 1rem;
            margin-bottom: 2rem;
            color: #6b7280;
        }
        
        /* Results Summary */
        .results-summary {
            padding: 1.25rem;
            background: linear-gradient(to right, #fffaf5, white);
            border-top: 1px solid rgba(255, 69, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
        
        /* Progress Bars */
        .progress {
            height: 6px;
            background: #f3e8e2;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-bar {
            background: linear-gradient(to right, var(--primary-color), var(--primary-light));
            border-radius: 3px;
        }
        
        /* Quick Stats */
        .quick-stats {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .stat-item i {
            color: var(--primary-color);
            font-size: 0.75rem;
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
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .animate__fadeIn { animation: fadeIn 0.5s ease; }
        .animate__slideInRight { animation: slideInRight 0.5s ease; }
        
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
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
            }
            
            .stat-icon i {
                font-size: 20px;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .stat-label {
                font-size: 0.75rem;
            }
            
            .filter-card {
                padding: 1.25rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-custom {
                width: 100%;
                justify-content: center;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .table {
                min-width: 1200px;
            }
            
            .results-summary {
                flex-direction: column;
                text-align: center;
            }
            
            .btn-group-action {
                justify-content: center;
            }
        }
        
        /* Loading Spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #f3e8e2;
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Modal Styling */
        .modal-content {
            border: none;
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(to right, var(--primary-color), var(--primary-light));
            color: white;
            border: none;
            padding: 1.5rem;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .modal-footer {
            border-top: 1px solid rgba(255, 69, 0, 0.1);
            padding: 1.5rem;
        }
        
        /* Tooltip */
        .custom-tooltip {
            position: relative;
            display: inline-block;
        }
        
        .custom-tooltip:hover:after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1f2937;
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
        }
        
        /* DataTables Customization */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 1rem;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 8px;
            margin: 0 0.25rem;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light)) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }
    </style>
</head>
<body>
    <div class="spinner-overlay" id="spinnerOverlay">
        <div class="spinner"></div>
    </div>

    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) {
        error_log("Admin header file missing: $header_path");
        echo "<div class='dashboard-container'><div class='alert alert-danger'>Error: Admin header template is missing.</div></div>";
    } else {
        include $header_path; 
    }
    ?>

    <div class="dashboard-container">
        <!-- Page Header -->
        <div class="page-header animate__animated animate__fadeIn">
            <div class="header-content">
                <h1><i class="fas fa-book-open me-2"></i> Subject Management</h1>
                <p class="subtitle">Manage academic subjects, assignments, and teacher allocations</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn-custom btn-outline" id="refreshBtn">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <a href="help.php?page=subjects" class="btn-custom btn-outline" target="_blank">
                    <i class="fas fa-question-circle me-1"></i> Help
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid animate__animated animate__fadeIn">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-label">Total Subjects</div>
                <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                <?php if (($stats['new_last_30_days'] ?? 0) > 0): ?>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up me-1"></i>
                        +<?php echo $stats['new_last_30_days']; ?> new (30d)
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="stat-card active">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-label">Active Subjects</div>
                <div class="stat-value"><?php echo number_format($stats['active_count'] ?? 0); ?></div>
                <?php if (($stats['total'] ?? 0) > 0): ?>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo round(($stats['active_count'] / $stats['total']) * 100); ?>%"></div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="stat-card inactive">
                <div class="stat-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-label">Inactive Subjects</div>
                <div class="stat-value"><?php echo number_format($stats['inactive_count'] ?? 0); ?></div>
            </div>
            
            <div class="stat-card teacher">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-label">With Teacher</div>
                <div class="stat-value"><?php echo number_format($stats['with_teacher'] ?? 0); ?></div>
                <?php if (($stats['total'] ?? 0) > 0): ?>
                    <div class="stat-trend">
                        <?php echo round(($stats['with_teacher'] / $stats['total']) * 100); ?>% assigned
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="stat-card departments">
                <div class="stat-icon">
                    <i class="fas fa-university"></i>
                </div>
                <div class="stat-label">Departments</div>
                <div class="stat-value"><?php echo number_format($stats['departments_count'] ?? 0); ?></div>
            </div>
            
            <div class="stat-card new">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-label">Assigned to Classes</div>
                <div class="stat-value"><?php echo number_format($stats['assigned_to_classes'] ?? 0); ?></div>
            </div>
            
            <div class="stat-card plans">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-label">With Lesson Plans</div>
                <div class="stat-value"><?php echo number_format($stats['with_lesson_plans'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card animate__animated animate__fadeIn">
            <div class="card-title">
                <i class="fas fa-filter"></i> Filter Subjects
                <span class="badge-department ms-2">
                    <?php echo count($subjects); ?> subjects found
                </span>
            </div>
            <form method="GET" id="filterForm" class="row g-3 align-items-end">
                <div class="col-lg-4 col-md-6">
                    <label for="search" class="form-label">
                        <i class="fas fa-search me-1"></i> Search
                    </label>
                    <div class="input-group">
                        <input type="text" id="search" name="search" class="form-control" 
                               placeholder="Search by name, code, description, department, teacher..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <?php if ($search): ?>
                            <button class="btn btn-outline-secondary" type="button" id="clearSearch">
                                <i class="fas fa-times"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-2 col-md-3">
                    <label for="status" class="form-label">
                        <i class="fas fa-toggle-on me-1"></i> Status
                    </label>
                    <select name="status" id="status" class="form-select">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label for="department" class="form-label">
                        <i class="fas fa-building me-1"></i> Department
                    </label>
                    <select name="department" id="department" class="form-select">
                        <option value="all" <?php echo $dept_filter === 'all' ? 'selected' : ''; ?>>All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" 
                                <?php echo $dept_filter === $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-12">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-custom flex-grow-1">
                            <i class="fas fa-search me-1"></i> Apply Filters
                        </button>
                        <a href="subject_management.php" class="btn-custom btn-outline" title="Reset Filters">
                            <i class="fas fa-redo-alt"></i>
                        </a>
                    </div>
                </div>
            </form>
            
            <!-- Active Filters -->
            <?php if ($search || $status_filter !== 'all' || $dept_filter !== 'all'): ?>
                <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
                    <span class="badge-department">
                        <i class="fas fa-filter me-1"></i> Active Filters:
                    </span>
                    <?php if ($search): ?>
                        <span class="badge-department">
                            Search: "<?php echo htmlspecialchars($search); ?>"
                            <a href="?<?php 
                                $params = $_GET;
                                unset($params['search']);
                                echo http_build_query($params);
                            ?>" class="ms-2 text-decoration-none">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if ($status_filter !== 'all'): ?>
                        <span class="badge-<?php echo strtolower($status_filter); ?>">
                            Status: <?php echo $status_filter; ?>
                            <a href="?<?php 
                                $params = $_GET;
                                unset($params['status']);
                                echo http_build_query($params);
                            ?>" class="ms-2 text-decoration-none">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php if ($dept_filter !== 'all'): ?>
                        <span class="badge-department">
                            Dept: <?php 
                                foreach ($departments as $dept) {
                                    if ($dept['id'] == $dept_filter) {
                                        echo htmlspecialchars($dept['name']);
                                        break;
                                    }
                                }
                            ?>
                            <a href="?<?php 
                                $params = $_GET;
                                unset($params['department']);
                                echo http_build_query($params);
                            ?>" class="ms-2 text-decoration-none">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons animate__animated animate__fadeIn">
            <a href="add_subject.php" class="btn-custom btn-success">
                <i class="fas fa-plus-circle me-1"></i> Add New Subject
            </a>
            <a href="add_subject.php?mode=list" class="btn-custom btn-info">
                <i class="fas fa-list-ul me-1"></i> Department View
            </a>
            <button class="btn-custom btn-warning" id="exportBtn">
                <i class="fas fa-file-export me-1"></i> Export CSV
            </button>
            <button class="btn-custom btn-outline" id="printBtn">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <button class="btn-custom btn-outline" id="batchActionsBtn">
                <i class="fas fa-tasks me-1"></i> Batch Actions
            </button>
        </div>

        <!-- Subjects Table -->
        <div class="table-container animate__animated animate__fadeIn">
            <?php if (empty($subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h4>No Subjects Found</h4>
                    <p>No subjects match your current filter criteria. Try adjusting your filters or add a new subject.</p>
                    <?php if ($search || $status_filter !== 'all' || $dept_filter !== 'all'): ?>
                        <a href="subject_management.php" class="btn-custom">Clear All Filters</a>
                    <?php else: ?>
                        <a href="add_subject.php" class="btn-custom btn-success">
                            <i class="fas fa-plus-circle me-1"></i> Add Your First Subject
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="subjectsTable">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th onclick="sortTable('subject_name')">
                                    Subject Name <i class="fas fa-sort"></i>
                                </th>
                                <th onclick="sortTable('subject_code')">
                                    Code <i class="fas fa-sort"></i>
                                </th>
                                <th>Department</th>
                                <th>Teacher</th>
                                <th onclick="sortTable('status')">
                                    Status <i class="fas fa-sort"></i>
                                </th>
                                <th>Stats</th>
                                <th onclick="sortTable('created_at')">
                                    Created <i class="fas fa-sort"></i>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $index => $subject): ?>
                                <tr class="subject-row" data-subject-id="<?php echo $subject['id']; ?>" 
                                    data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>">
                                    <td>
                                        <input type="checkbox" class="form-check-input subject-select" 
                                               value="<?php echo $subject['id']; ?>">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-start gap-2">
                                            <div>
                                                <div class="fw-semibold mb-1">
                                                    <a href="view_subject.php?id=<?php echo $subject['id']; ?>" class="text-decoration-none" style="color: var(--primary-color);">
                                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                    </a>
                                                </div>
                                                <?php if ($subject['description']): ?>
                                                    <small class="text-muted d-block" style="font-size: 0.75rem;">
                                                        <i class="fas fa-align-left me-1" style="font-size: 0.7rem;"></i>
                                                        <?php echo htmlspecialchars(substr($subject['description'], 0, 60)); ?>
                                                        <?php if (strlen($subject['description']) > 60): ?>...<?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="subject-code">
                                            <?php echo htmlspecialchars($subject['subject_code']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($subject['department_name']): ?>
                                            <span class="badge-department">
                                                <i class="fas fa-building me-1"></i>
                                                <?php echo htmlspecialchars($subject['department_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($subject['teacher_name']): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="position-relative custom-tooltip" data-tooltip="<?php echo htmlspecialchars($subject['teacher_email'] ?? ''); ?>">
                                                    <i class="fas fa-user-circle" style="color: var(--info-color);"></i>
                                                </div>
                                                <div>
                                                    <div><?php echo htmlspecialchars($subject['teacher_name']); ?></div>
                                                    <?php if ($subject['teacher_email']): ?>
                                                        <small class="text-muted" style="font-size: 0.7rem;">
                                                            <?php echo htmlspecialchars($subject['teacher_email']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-<?php echo strtolower($subject['status']); ?>">
                                            <i class="fas fa-<?php echo $subject['status'] === 'Active' ? 'check-circle' : 'minus-circle'; ?>"></i>
                                            <?php echo $subject['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="quick-stats">
                                            <span class="stat-item custom-tooltip" data-tooltip="Classes using this subject">
                                                <i class="fas fa-users"></i>
                                                <?php echo $subject['class_count'] ?? 0; ?>
                                            </span>
                                            <span class="stat-item custom-tooltip" data-tooltip="Lesson Plans">
                                                <i class="fas fa-file-alt"></i>
                                                <?php echo $subject['lesson_plan_count'] ?? 0; ?>
                                            </span>
                                            <span class="stat-item custom-tooltip" data-tooltip="Assignments">
                                                <i class="fas fa-tasks"></i>
                                                <?php echo $subject['assignment_count'] ?? 0; ?>
                                            </span>
                                            <?php if (($subject['evaluation_count'] ?? 0) > 0): ?>
                                                <span class="stat-item custom-tooltip" data-tooltip="Teacher Evaluations">
                                                    <i class="fas fa-star"></i>
                                                    <?php echo $subject['evaluation_count']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="date-cell">
                                        <div class="d-flex flex-column">
                                            <span><i class="far fa-calendar-alt me-1"></i><?php echo date('M d, Y', strtotime($subject['created_at'])); ?></span>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($subject['created_at'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-group-action">
                                            <a href="view_subject.php?id=<?php echo $subject['id']; ?>" 
                                               class="btn btn-view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_subject.php?id=<?php echo $subject['id']; ?>" 
                                               class="btn btn-edit" title="Edit Subject">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" 
                                               class="btn btn-delete" 
                                               title="Delete Subject"
                                               onclick="confirmDelete(<?php echo $subject['id']; ?>, '<?php echo htmlspecialchars(addslashes($subject['subject_name'])); ?>')">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                            <button type="button" 
                                               class="btn btn-view" 
                                               title="More Actions"
                                               onclick="showMoreActions(<?php echo $subject['id']; ?>)">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Results Summary -->
                <div class="results-summary">
                    <div class="d-flex align-items-center gap-3">
                        <span class="badge-department">
                            <i class="fas fa-list me-1"></i>
                            Showing <?php echo count($subjects); ?> subject<?php echo count($subjects) !== 1 ? 's' : ''; ?>
                        </span>
                        <?php if (count($subjects) > 0): ?>
                            <span class="badge-department">
                                <i class="fas fa-check-circle me-1" style="color: var(--success-color);"></i>
                                <?php echo array_reduce($subjects, function($carry, $item) { 
                                    return $carry + ($item['status'] === 'Active' ? 1 : 0); 
                                }, 0); ?> Active
                            </span>
                            <span class="badge-department">
                                <i class="fas fa-chalkboard-teacher me-1" style="color: var(--info-color);"></i>
                                <?php echo array_reduce($subjects, function($carry, $item) { 
                                    return $carry + (!empty($item['teacher_name']) ? 1 : 0); 
                                }, 0); ?> With Teacher
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="text-muted">
                            <i class="far fa-clock me-1"></i>
                            Last updated: <?php echo date('F j, Y, g:i A'); ?>
                        </span>
                    </div>
                </div>

                <!-- Batch Actions Bar -->
                <div class="p-3 bg-light border-top" id="batchActionsBar" style="display: none;">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="fw-semibold" style="color: var(--primary-color);">
                            <span id="selectedCount">0</span> subject(s) selected
                        </span>
                        <button class="btn-custom btn-sm btn-success" onclick="batchAction('activate')">
                            <i class="fas fa-check-circle me-1"></i> Activate
                        </button>
                        <button class="btn-custom btn-sm btn-warning" onclick="batchAction('deactivate')">
                            <i class="fas fa-times-circle me-1"></i> Deactivate
                        </button>
                        <button class="btn-custom btn-sm btn-info" onclick="batchAction('assign_teacher')">
                            <i class="fas fa-chalkboard-teacher me-1"></i> Assign Teacher
                        </button>
                        <button class="btn-custom btn-sm btn-danger" onclick="batchAction('delete')">
                            <i class="fas fa-trash-alt me-1"></i> Delete
                        </button>
                        <button class="btn-custom btn-sm btn-outline" onclick="clearSelection()">
                            <i class="fas fa-times me-1"></i> Clear
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Tips -->
        <div class="alert alert-info alert-custom mt-4 animate__animated animate__fadeIn">
            <div class="d-flex align-items-center gap-3">
                <i class="fas fa-lightbulb fa-2x" style="color: var(--info-color);"></i>
                <div>
                    <strong>Quick Tips:</strong>
                    <ul class="mb-0 mt-1">
                        <li>Click on column headers to sort the table</li>
                        <li>Use the search box to find subjects by name, code, or description</li>
                        <li>Select multiple subjects for batch actions</li>
                        <li>Hover over stats icons to see details</li>
                        <li>Press <kbd>Ctrl+F</kbd> to focus search, <kbd>Ctrl+N</kbd> for new subject</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- More Actions Modal -->
    <div class="modal fade" id="moreActionsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-tasks me-2"></i> More Actions
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="list-group">
                        <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-3" id="viewClassesLink">
                            <i class="fas fa-users fa-fw" style="color: var(--info-color);"></i>
                            <div>
                                <div class="fw-semibold">View Assigned Classes</div>
                                <small class="text-muted">See all classes where this subject is taught</small>
                            </div>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-3" id="viewLessonPlansLink">
                            <i class="fas fa-file-alt fa-fw" style="color: var(--success-color);"></i>
                            <div>
                                <div class="fw-semibold">View Lesson Plans</div>
                                <small class="text-muted">Browse all lesson plans for this subject</small>
                            </div>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-3" id="viewAssignmentsLink">
                            <i class="fas fa-tasks fa-fw" style="color: var(--warning-color);"></i>
                            <div>
                                <div class="fw-semibold">View Assignments</div>
                                <small class="text-muted">See all assignments created for this subject</small>
                            </div>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-3" id="viewEvaluationsLink">
                            <i class="fas fa-star fa-fw" style="color: var(--purple-color);"></i>
                            <div>
                                <div class="fw-semibold">View Teacher Evaluations</div>
                                <small class="text-muted">Check evaluation history for this subject</small>
                            </div>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-3" id="cloneSubjectLink">
                            <i class="fas fa-copy fa-fw" style="color: var(--primary-color);"></i>
                            <div>
                                <div class="fw-semibold">Clone Subject</div>
                                <small class="text-muted">Create a copy of this subject</small>
                            </div>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-3" id="exportSubjectLink">
                            <i class="fas fa-file-export fa-fw" style="color: var(--secondary-color);"></i>
                            <div>
                                <div class="fw-semibold">Export Subject Data</div>
                                <small class="text-muted">Download all information about this subject</small>
                            </div>
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-custom btn-outline btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Admin footer file missing: $footer_path");
        echo "</div></body></html>";
    } else {
        include $footer_path;
    }
    $conn->close();
    ?>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#subjectsTable').DataTable({
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[7, 'desc']], // Sort by created_at descending by default
                columnDefs: [
                    { orderable: false, targets: [0, 3, 4, 6, 8] } // Disable sorting on certain columns
                ],
                language: {
                    search: "<i class='fas fa-search'></i>",
                    searchPlaceholder: "Search table..."
                },
                initComplete: function() {
                    // Style the search input
                    $('.dataTables_filter input').addClass('form-control form-control-sm');
                    $('.dataTables_filter input').attr('placeholder', 'Search...');
                }
            });

            // Clear search button
            $('#clearSearch').click(function() {
                $('#search').val('');
                $('#filterForm').submit();
            });

            // Auto-submit when filters change (with debounce)
            let filterTimeout;
            $('#status, #department').change(function() {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(() => $('#filterForm').submit(), 500);
            });

            // Export functionality
            $('#exportBtn').click(function() {
                const params = new URLSearchParams($('#filterForm').serialize());
                window.location.href = 'export_subjects.php?' + params.toString();
            });

            // Print functionality
            $('#printBtn').click(function() {
                window.print();
            });

            // Batch actions
            const batchBar = $('#batchActionsBar');
            const selectAll = $('#selectAll');
            const selectedCount = $('#selectedCount');

            // Select all functionality
            selectAll.change(function() {
                $('.subject-select').prop('checked', $(this).prop('checked'));
                updateBatchBar();
            });

            // Individual checkbox changes
            $(document).on('change', '.subject-select', function() {
                const allChecked = $('.subject-select:checked').length === $('.subject-select').length;
                selectAll.prop('checked', allChecked);
                updateBatchBar();
            });

            function updateBatchBar() {
                const count = $('.subject-select:checked').length;
                if (count > 0) {
                    selectedCount.text(count);
                    batchBar.slideDown();
                } else {
                    batchBar.slideUp();
                }
            }

            window.clearSelection = function() {
                $('.subject-select').prop('checked', false);
                selectAll.prop('checked', false);
                batchBar.slideUp();
            };

            // Batch actions
            window.batchAction = function(action) {
                const selected = [];
                $('.subject-select:checked').each(function() {
                    selected.push($(this).val());
                });

                if (selected.length === 0) return;

                let title, text, confirmText, icon;
                switch(action) {
                    case 'activate':
                        title = 'Activate Subjects';
                        text = `Are you sure you want to activate ${selected.length} subject(s)?`;
                        confirmText = 'Yes, activate them';
                        icon = 'question';
                        break;
                    case 'deactivate':
                        title = 'Deactivate Subjects';
                        text = `Are you sure you want to deactivate ${selected.length} subject(s)?`;
                        confirmText = 'Yes, deactivate them';
                        icon = 'warning';
                        break;
                    case 'assign_teacher':
                        title = 'Assign Teacher';
                        text = `Please select a teacher to assign to the ${selected.length} selected subject(s).`;
                        // Open teacher selection modal
                        $('#teacherAssignmentModal').modal('show');
                        return;
                    case 'delete':
                        title = 'Delete Subjects';
                        text = `Are you sure you want to delete ${selected.length} subject(s)? This action cannot be undone.`;
                        confirmText = 'Yes, delete them';
                        icon = 'error';
                        break;
                }

                Swal.fire({
                    title: title,
                    text: text,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonColor: action === 'delete' ? '#ef4444' : '#10b981',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: confirmText
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: 'batch_subject_action.php',
                            method: 'POST',
                            data: {
                                action: action,
                                subjects: selected,
                                csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire({
                                        title: 'Success!',
                                        text: response.message,
                                        icon: 'success',
                                        timer: 2000
                                    }).then(() => {
                                        location.reload();
                                    });
                                } else {
                                    Swal.fire('Error!', response.message, 'error');
                                }
                            }
                        });
                    }
                });
            };

            // Refresh button
            $('#refreshBtn').click(function() {
                $('#spinnerOverlay').fadeIn();
                location.reload();
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + F to focus search
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    $('#search').focus();
                }
                // Ctrl/Cmd + N for new subject
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    window.location.href = 'add_subject.php';
                }
                // Escape to clear selection
                if (e.key === 'Escape') {
                    clearSelection();
                }
            });

            // Initialize tooltips
            $('[data-tooltip]').tooltip({
                placement: 'top',
                trigger: 'hover'
            });

            // More actions modal
            let currentSubjectId = null;
            window.showMoreActions = function(subjectId) {
                currentSubjectId = subjectId;
                const subject = $(`.subject-row[data-subject-id="${subjectId}"]`);
                const subjectName = subject.data('subject-name');
                
                $('#viewClassesLink').attr('href', `view_subject_classes.php?id=${subjectId}`);
                $('#viewLessonPlansLink').attr('href', `lesson_plans.php?subject_id=${subjectId}`);
                $('#viewAssignmentsLink').attr('href', `assignments.php?subject_id=${subjectId}`);
                $('#viewEvaluationsLink').attr('href', `evaluations.php?subject_id=${subjectId}`);
                $('#cloneSubjectLink').attr('href', `clone_subject.php?id=${subjectId}`);
                $('#exportSubjectLink').attr('href', `export_subject.php?id=${subjectId}`);
                
                $('#moreActionsModal').modal('show');
            };
        });

        // Delete confirmation with SweetAlert
        function confirmDelete(id, name) {
            Swal.fire({
                title: 'Delete Subject?',
                html: `Are you sure you want to delete <strong>${name}</strong>?<br><br>This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#spinnerOverlay').fadeIn();
                    window.location.href = `delete_subject.php?id=${id}&confirm=1`;
                }
            });
            return false;
        }

        // Sorting function
        function sortTable(column) {
            const url = new URL(window.location.href);
            const currentOrder = url.searchParams.get('order') || 'desc';
            url.searchParams.set('sort', column);
            url.searchParams.set('order', currentOrder === 'desc' ? 'asc' : 'desc');
            window.location.href = url.toString();
        }

        // Show loading spinner on form submissions
        $('#filterForm').submit(function() {
            $('#spinnerOverlay').fadeIn();
        });

        $('a:not([target="_blank"])').click(function(e) {
            if ($(this).attr('href') && !$(this).hasClass('btn-delete')) {
                $('#spinnerOverlay').fadeIn();
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>