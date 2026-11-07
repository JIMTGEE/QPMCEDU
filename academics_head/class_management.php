<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}

// Log activity
$admin_id = $_SESSION['user_id'];
$action = "Viewed Class Management Dashboard";
$description = "Admin viewed class management dashboard";
logActivity($conn, $admin_id, $action, $description);

// Fetch analytics
$total_classes = $assigned_students = $total_students = 0;
$students_per_class = $classes_by_dept = $activities = $class_statistics = [];
$academic_years = [];

try {
    // Fetch total classes
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM classes");
    $stmt->execute();
    $total_classes = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Fetch total active students
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'");
    $stmt->execute();
    $total_students = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Fetch assigned students
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as total FROM student_classes");
    $stmt->execute();
    $assigned_students = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Assignment percentage
    $assignment_percentage = $total_students > 0 ? round(($assigned_students / $total_students) * 100, 2) : 0;

    // Fetch students per class with department info - FIXED QUERY
    $stmt = $conn->prepare("SELECT 
                                c.id, 
                                c.class_name, 
                                d.name AS department_name, 
                                COUNT(DISTINCT sc.user_id) as student_count 
                            FROM classes c 
                            LEFT JOIN departments d ON c.department_id = d.id 
                            LEFT JOIN student_classes sc ON c.id = sc.class_id 
                            GROUP BY c.id, c.class_name, d.name 
                            ORDER BY c.class_name");
    $stmt->execute();
    $students_per_class = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate average students per class
    $avg_students_per_class = $total_classes > 0 ? round($assigned_students / $total_classes, 2) : 0;

    // Fetch classes by department
    $stmt = $conn->prepare("SELECT 
                                d.id, 
                                d.name AS department_name, 
                                COUNT(c.id) as class_count 
                            FROM departments d 
                            LEFT JOIN classes c ON d.id = c.department_id 
                            GROUP BY d.id, d.name 
                            ORDER BY d.name");
    $stmt->execute();
    $classes_by_dept = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Fetch class statistics including teachers - FIXED QUERY
    $stmt = $conn->prepare("SELECT 
                                c.id, 
                                c.class_name, 
                                d.name AS department_name,
                                c.teacher_id,
                                u.name as teacher_name,
                                u.email as teacher_email,
                                COUNT(DISTINCT sc.user_id) as student_count
                            FROM classes c 
                            LEFT JOIN departments d ON c.department_id = d.id 
                            LEFT JOIN student_classes sc ON c.id = sc.class_id 
                            LEFT JOIN users u ON c.teacher_id = u.id AND u.role = 'teacher'
                            GROUP BY c.id, c.class_name, d.name, c.teacher_id, u.name, u.email
                            ORDER BY c.class_name");
    $stmt->execute();
    $class_statistics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Fetch activities
    $search_query = isset($_GET['activity_search']) ? trim($_GET['activity_search']) : '';
    if ($search_query) {
        $stmt = $conn->prepare("SELECT al.*, u.name as user_name 
                                FROM activity_logs al
                                LEFT JOIN users u ON al.user_id = u.id
                                WHERE (al.action LIKE ? OR al.description LIKE ? OR u.name LIKE ?) 
                                AND (al.action LIKE '%Class%' OR al.description LIKE '%class%' OR al.action LIKE '%Student%' OR al.description LIKE '%student%')
                                ORDER BY al.created_at DESC 
                                LIMIT 15");
        $search_term = "%" . $search_query . "%";
        $stmt->bind_param("sss", $search_term, $search_term, $search_term);
    } else {
        $stmt = $conn->prepare("SELECT al.*, u.name as user_name 
                                FROM activity_logs al
                                LEFT JOIN users u ON al.user_id = u.id
                                WHERE al.action LIKE '%Class%' OR al.description LIKE '%class%' OR al.action LIKE '%Student%' OR al.description LIKE '%student%'
                                ORDER BY al.created_at DESC 
                                LIMIT 15");
    }
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Fetch academic years for filtering
    $stmt = $conn->prepare("SELECT DISTINCT academic_year FROM student_classes ORDER BY academic_year DESC");
    $stmt->execute();
    $academic_years = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Fetch departments for filtering
    $stmt = $conn->prepare("SELECT id, name FROM departments ORDER BY name");
    $stmt->execute();
    $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Fetch class subjects count
    $stmt = $conn->prepare("SELECT 
                                c.id,
                                c.class_name,
                                COUNT(DISTINCT cs.subject_id) as subject_count
                            FROM classes c
                            LEFT JOIN class_subjects cs ON c.id = cs.class_id
                            GROUP BY c.id, c.class_name");
    $stmt->execute();
    $class_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Create a mapping of class_id to subject_count
    $subject_count_map = [];
    foreach ($class_subjects as $cs) {
        $subject_count_map[$cs['id']] = $cs['subject_count'];
    }

} catch (Exception $e) {
    error_log("Error fetching analytics: " . $e->getMessage());
    // Initialize empty arrays to prevent errors
    if (empty($students_per_class)) $students_per_class = [];
    if (empty($classes_by_dept)) $classes_by_dept = [];
    if (empty($class_statistics)) $class_statistics = [];
    if (empty($activities)) $activities = [];
    if (empty($academic_years)) $academic_years = [];
    if (empty($departments)) $departments = [];
    if (empty($subject_count_map)) $subject_count_map = [];
}

// Set SEO meta tags
$page_title = "Class Management - QueensPa School";
$page_description = "Manage classes and view analytics for QueensPa School administrators.";
$page_keywords = "QueensPa School, class management, admin dashboard, school management, analytics";
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin dashboard for managing classes, departments, and student assignments at QueensPa School.">
    <meta name="keywords" content="QueensPa School, class management, student assignment, department management, admin dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Class Management Dashboard - QueensPa School">
    <meta property="og:description" content="Comprehensive class management interface for administrators with analytics and student assignment tools.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/class_management.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Class Management Dashboard - QueensPa School">
    <meta name="twitter:description" content="Comprehensive class management interface for administrators with analytics and student assignment tools.">
    <title>Class Management Dashboard - QueensPa School</title>
    
    <!-- CSS Links -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #ffd700;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
            --light-bg: #f8f9fa;
            --dark-bg: #343a40;
            --card-shadow: 0 8px 24px rgba(255, 98, 0, 0.08);
            --hover-shadow: 0 12px 28px rgba(255, 98, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        body {
            background: linear-gradient(135deg, #fff5f0 0%, #fff9f0 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .dashboard-container {
            max-width: 100%;
            padding: 20px;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
            border-left: 5px solid var(--primary-color);
            animation: slideInDown 0.5s ease;
            background: linear-gradient(to right, white, #fff9f0);
        }

        .page-header h1 {
            color: var(--primary-color);
            font-weight: 700;
            margin: 0;
            font-size: 1.8rem;
        }

        .page-header .subtitle {
            color: var(--secondary-color);
            font-size: 0.95rem;
            margin: 0.25rem 0 0 0;
            font-weight: 500;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: 1px solid #ffe8d6;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }

        .stat-card.total-classes::before {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }

        .stat-card.avg-students::before {
            background: linear-gradient(to right, var(--info-color), #6f42c1);
        }

        .stat-card.assigned-students::before {
            background: linear-gradient(to right, var(--success-color), #4cd964);
        }

        .stat-card.coverage::before {
            background: linear-gradient(to right, var(--warning-color), #ffd166);
        }

        .stat-card .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            color: white;
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.2);
        }

        .stat-card.total-classes .stat-icon {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .stat-card.avg-students .stat-icon {
            background: linear-gradient(135deg, var(--info-color), #6f42c1);
        }

        .stat-card.assigned-students .stat-icon {
            background: linear-gradient(135deg, var(--success-color), #4cd964);
        }

        .stat-card.coverage .stat-icon {
            background: linear-gradient(135deg, var(--warning-color), #ffd166);
        }

        .stat-card .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0.5rem 0;
            line-height: 1;
        }

        .stat-card.avg-students .stat-value {
            color: var(--info-color);
        }

        .stat-card.assigned-students .stat-value {
            color: var(--success-color);
        }

        .stat-card.coverage .stat-value {
            color: #e0a800;
        }

        .stat-card .stat-label {
            color: var(--secondary-color);
            font-size: 0.9rem;
            margin: 0;
            font-weight: 600;
        }

        .stat-card.avg-students .stat-label {
            color: var(--info-color);
        }

        .stat-card.assigned-students .stat-label {
            color: var(--success-color);
        }

        .stat-card.coverage .stat-label {
            color: #e0a800;
        }

        .filter-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border: 1px solid #ffe8d6;
            background: linear-gradient(to bottom, white, #fff9f0);
        }

        .filter-card .card-title {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-card .form-label {
            font-weight: 500;
            color: var(--primary-color);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border: 2px solid #ffe8d6;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: var(--transition);
            background-color: white;
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 98, 0, 0.15);
        }

        .btn-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.2);
        }

        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 98, 0, 0.3);
            color: white;
            background: linear-gradient(135deg, #e55a00, #e08300);
        }

        .btn-custom.btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            box-shadow: none;
        }

        .btn-custom.btn-outline:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 8px 20px rgba(255, 98, 0, 0.3);
        }

        .btn-custom.btn-success {
            background: linear-gradient(135deg, var(--success-color), #4cd964);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }

        .btn-custom.btn-success:hover {
            background: linear-gradient(135deg, #218838, #2ecc71);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-custom.btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #ffd166);
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.2);
        }

        .btn-custom.btn-warning:hover {
            background: linear-gradient(135deg, #e0a800, #ffc107);
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.3);
        }

        .btn-custom.btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #ff6b6b);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.2);
        }

        .btn-custom.btn-danger:hover {
            background: linear-gradient(135deg, #c82333, #ff5252);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.3rem;
            margin: 2rem 0 1rem 0;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #ffe8d6;
        }

        .table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
            border: 1px solid #ffe8d6;
        }

        .table {
            margin: 0;
        }

        .table thead th {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 1rem 1.25rem;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background-color: #fff9f0;
            transform: translateX(2px);
        }

        .table tbody td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #ffe8d6;
            vertical-align: middle;
        }

        .badge-status {
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .badge-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .badge-warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: #e0a800;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        .badge-info {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.1), rgba(72, 187, 210, 0.1));
            color: var(--info-color);
            border: 1px solid rgba(23, 162, 184, 0.2);
        }

        .badge-primary {
            background: linear-gradient(135deg, rgba(255, 98, 0, 0.1), rgba(255, 140, 0, 0.1));
            color: var(--primary-color);
            border: 1px solid rgba(255, 98, 0, 0.2);
        }

        .badge-status i {
            font-size: 0.7rem;
        }

        .loading-spinner {
            display: none;
            margin-left: 0.5rem;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: var(--secondary-color);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #ffe8d6;
        }

        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
            border: 1px solid #ffe8d6;
        }

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

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 10px;
            }

            .page-header {
                padding: 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
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
                font-size: 0.9rem;
            }

            .table thead th,
            .table tbody td {
                padding: 0.75rem;
            }
        }

        /* Progress bars */
        .progress {
            background-color: #ffe8d6;
            border-radius: 4px;
        }

        .progress-bar {
            border-radius: 4px;
        }

        .progress-bar.bg-success {
            background-color: var(--success-color) !important;
        }

        .progress-bar.bg-warning {
            background-color: var(--warning-color) !important;
        }

        .progress-bar.bg-info {
            background-color: var(--info-color) !important;
        }

        /* Department badges */
        .badge.bg-light {
            background-color: #fff9f0 !important;
            color: var(--primary-color) !important;
            border: 1px solid #ffe8d6;
        }

        /* Quick actions cards */
        .quick-action-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border: 1px solid #ffe8d6;
            transition: var(--transition);
            text-align: center;
            height: 100%;
        }

        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
            border-color: var(--primary-color);
        }

        .quick-action-card .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: white;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .quick-action-card h5 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .quick-action-card p {
            color: var(--secondary-color);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #fff5f0;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, #e55a00, #e08300);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Page Header -->
        <div class="page-header animate__animated animate__fadeInDown">
            <h1><i class="fas fa-chalkboard-teacher" style="color: var(--primary-color);"></i> Class Management Dashboard</h1>
            <p class="subtitle">Streamline class operations, assign students, and monitor analytics with ease</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card total-classes animate__animated animate__fadeInLeft">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard"></i>
                </div>
                <div class="stat-value"><?php echo number_format($total_classes); ?></div>
                <div class="stat-label">Total Classes</div>
                <small>Active classes in the system</small>
            </div>

            <div class="stat-card avg-students animate__animated animate__fadeInUp">
                <div class="stat-icon">
                    <i class="fas fa-user-friends"></i>
                </div>
                <div class="stat-value"><?php echo $avg_students_per_class; ?></div>
                <div class="stat-label">Avg. Students per Class</div>
                <small>Average student distribution</small>
            </div>

            <div class="stat-card assigned-students animate__animated animate__fadeInUp">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo $assigned_students; ?></div>
                <div class="stat-label">Students Assigned</div>
                <small>Out of <?php echo $total_students; ?> total students</small>
            </div>

            <div class="stat-card coverage animate__animated animate__fadeInRight">
                <div class="stat-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="stat-value"><?php echo $assignment_percentage; ?>%</div>
                <div class="stat-label">Assignment Coverage</div>
                <small>Percentage of students assigned</small>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons animate__animated animate__fadeIn">
            <a href="add_class.php" class="btn-custom">
                <i class="fas fa-plus me-1"></i>Add New Class
            </a>
            <a href="view_classes.php" class="btn-custom btn-success">
                <i class="fas fa-list me-1"></i>View All Classes
            </a>
            <a href="manage_student_classes.php" class="btn-custom btn-warning">
                <i class="fas fa-user-plus me-1"></i>Manage Student Assignments
            </a>
            <a href="department_management.php" class="btn-custom btn-info">
                <i class="fas fa-building me-1"></i>Manage Departments
            </a>
            <a href="class_schedules.php" class="btn-custom">
                <i class="fas fa-calendar-alt me-1"></i>Class Schedules
            </a>
        </div>

        <!-- Filter Card -->
        <div class="filter-card animate__animated animate__fadeIn">
            <div class="card-title">
                <i class="fas fa-filter" style="color: var(--primary-color);"></i> Filter Options
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="department_filter" class="form-label">Department</label>
                    <select id="department_filter" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['name']); ?>">
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="academic_year_filter" class="form-label">Academic Year</label>
                    <select id="academic_year_filter" class="form-select">
                        <option value="">All Years</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo htmlspecialchars($year['academic_year']); ?>">
                                <?php echo htmlspecialchars($year['academic_year']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="search_class" class="form-label">Search Class</label>
                    <div class="input-group">
                        <input type="text" id="search_class" class="form-control" placeholder="Search class name...">
                        <button class="btn btn-outline-secondary" type="button" id="clear_search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn-custom" id="apply_filters">
                            <i class="fas fa-filter me-1"></i>Apply Filters
                        </button>
                        <button type="button" class="btn-custom btn-outline" id="reset_filters">
                            <i class="fas fa-redo me-1"></i>Reset Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students per Class Chart -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-chart-bar me-2" style="color: var(--primary-color);"></i>Students per Class Distribution
        </h3>
        
        <div class="chart-container animate__animated animate__fadeIn">
            <canvas id="studentsPerClassChart" style="width: 100%; height: 400px;"></canvas>
        </div>

        <!-- Classes by Department -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-building me-2" style="color: var(--primary-color);"></i>Classes by Department
        </h3>
        
        <div class="table-container animate__animated animate__fadeIn">
            <div class="table-responsive">
                <table class="table" id="departmentsTable">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Classes</th>
                            <th>Total Students</th>
                            <th>Avg. Students/Class</th>
                            <th>Coverage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($classes_by_dept)): ?>
                            <tr>
                                <td colspan="5" class="no-data">
                                    <i class="fas fa-building"></i>
                                    <p>No department data found.</p>
                                </td>
                            </tr>
                        <?php else: 
                            foreach ($classes_by_dept as $dept): 
                                // Calculate department-specific stats
                                $dept_students = 0;
                                $dept_classes = 0;
                                foreach ($students_per_class as $class) {
                                    if ($class['department_name'] === $dept['department_name']) {
                                        $dept_students += $class['student_count'];
                                        $dept_classes++;
                                    }
                                }
                                $dept_avg = $dept_classes > 0 ? round($dept_students / $dept_classes, 2) : 0;
                                $dept_coverage = $total_students > 0 ? round(($dept_students / $total_students) * 100, 2) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-university" style="color: var(--primary-color);"></i>
                                            <span><?php echo htmlspecialchars($dept['department_name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo $dept['class_count']; ?> classes</span>
                                    </td>
                                    <td class="fw-bold" style="color: var(--primary-color);"><?php echo $dept_students; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 8px;">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo min($dept_avg * 5, 100); ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo $dept_avg; ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 8px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $dept_coverage; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo $dept_coverage; ?>%</small>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Class Statistics Table -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-list-alt me-2" style="color: var(--primary-color);"></i>Class Statistics
        </h3>
        
        <div class="table-container animate__animated animate__fadeIn">
            <div class="table-responsive">
                <table class="table" id="classStatisticsTable">
                    <thead>
                        <tr>
                            <th>Class Name</th>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Teacher</th>
                            <th>Subjects</th>
                            <th>Assignment Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($class_statistics)): ?>
                            <tr>
                                <td colspan="7" class="no-data">
                                    <i class="fas fa-chalkboard"></i>
                                    <p>No class statistics found.</p>
                                </td>
                            </tr>
                        <?php else: 
                            foreach ($class_statistics as $class): 
                                $student_count = $class['student_count'] ?? 0;
                                $percentage = $total_students > 0 ? round(($student_count / $total_students) * 100, 2) : 0;
                                $status_class = $percentage >= 80 ? 'badge-success' : ($percentage >= 50 ? 'badge-warning' : 'badge-info');
                                $status_text = $percentage >= 80 ? 'Good' : ($percentage >= 50 ? 'Moderate' : 'Low');
                                $subject_count = $subject_count_map[$class['id']] ?? 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-chalkboard" style="color: var(--primary-color);"></i>
                                            <span><?php echo htmlspecialchars($class['class_name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light">
                                            <?php echo htmlspecialchars($class['department_name'] ?? 'Unassigned'); ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold" style="color: var(--primary-color);"><?php echo $student_count; ?></td>
                                    <td>
                                        <?php if (!empty($class['teacher_name'])): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fas fa-user-tie" style="color: var(--info-color);"></i>
                                                <span><?php echo htmlspecialchars($class['teacher_name']); ?></span>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($class['teacher_email'] ?? ''); ?></small>
                                        <?php else: ?>
                                            <span class="badge badge-warning">No Teacher Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?php echo $subject_count; ?> subjects</span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $status_class; ?>">
                                            <i class="fas fa-<?php echo $status_class === 'badge-success' ? 'check-circle' : ($status_class === 'badge-warning' ? 'clock' : 'info-circle'); ?>"></i>
                                            <?php echo $status_text; ?> (<?php echo $percentage; ?>%)
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="edit_class.php?id=<?php echo $class['id']; ?>" class="btn btn-sm btn-primary" title="Edit Class">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="class_details.php?id=<?php echo $class['id']; ?>" class="btn btn-sm btn-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="manage_class_students.php?class_id=<?php echo $class['id']; ?>" class="btn btn-sm btn-success" title="Manage Students">
                                                <i class="fas fa-users"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Activities -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-history me-2" style="color: var(--primary-color);"></i>Recent Class Activities
        </h3>
        
        <div class="table-container animate__animated animate__fadeIn">
            <div class="table-responsive">
                <table class="table" id="activitiesTable">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activities)): ?>
                            <tr>
                                <td colspan="4" class="no-data">
                                    <i class="fas fa-history"></i>
                                    <p>No recent activities found.</p>
                                </td>
                            </tr>
                        <?php else: 
                            foreach ($activities as $activity): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-user" style="color: var(--primary-color);"></i>
                                            <span><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($activity['action']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($activity['created_at']); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-bolt me-2" style="color: var(--primary-color);"></i>Quick Actions
        </h3>
        
        <div class="row g-4 mb-4 animate__animated animate__fadeIn">
            <div class="col-md-3">
                <div class="quick-action-card">
                    <div class="card-icon">
                        <i class="fas fa-plus"></i>
                    </div>
                    <h5>Add Class</h5>
                    <p>Create a new class for the school</p>
                    <a href="add_class.php" class="btn-custom btn-sm">Add New</a>
                </div>
            </div>
            <div class="col-md-3">
                <div class="quick-action-card">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h5>Assign Students</h5>
                    <p>Manage student class assignments</p>
                    <a href="manage_student_classes.php" class="btn-custom btn-success btn-sm">Manage</a>
                </div>
            </div>
            <div class="col-md-3">
                <div class="quick-action-card">
                    <div class="card-icon">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <h5>Export Data</h5>
                    <p>Export class lists and statistics</p>
                    <button class="btn-custom btn-warning btn-sm" onclick="exportClassData()">Export</button>
                </div>
            </div>
            <div class="col-md-3">
                <div class="quick-action-card">
                    <div class="card-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h5>View Reports</h5>
                    <p>Generate detailed class reports</p>
                    <a href="class_reports.php" class="btn-custom btn-info btn-sm">Reports</a>
                </div>
            </div>
        </div>

        <!-- Navigation Back -->
        <div class="text-center mt-5 animate__animated animate__fadeIn">
            <a href="dashboard.php" class="btn-custom btn-outline">
                <i class="fas fa-arrow-left me-1"></i>Back to Main Dashboard
            </a>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Prepare data for chart
            const classNames = <?php echo json_encode(array_column($students_per_class, 'class_name')); ?>;
            const studentCounts = <?php echo json_encode(array_column($students_per_class, 'student_count')); ?>;
            
            // Initialize Chart
            const ctx = document.getElementById('studentsPerClassChart').getContext('2d');
            const chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: classNames,
                    datasets: [{
                        label: 'Number of Students',
                        data: studentCounts,
                        backgroundColor: 'rgba(255, 98, 0, 0.7)',
                        borderColor: 'rgba(255, 98, 0, 1)',
                        borderWidth: 1,
                        borderRadius: 6,
                        hoverBackgroundColor: 'rgba(255, 98, 0, 0.9)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    size: 12
                                }
                            },
                            title: {
                                display: true,
                                text: 'Number of Students',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12
                                },
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.parsed.y} students`;
                                }
                            }
                        }
                    }
                }
            });

            // Filter functionality
            $('#apply_filters').click(function() {
                const department = $('#department_filter').val();
                const year = $('#academic_year_filter').val();
                const search = $('#search_class').val().toLowerCase();
                
                // Filter departments table
                $('#departmentsTable tbody tr').each(function() {
                    const deptName = $(this).find('td:first').text().trim();
                    const showDept = !department || deptName === department;
                    $(this).toggle(showDept);
                });

                // Filter class statistics table
                $('#classStatisticsTable tbody tr').each(function() {
                    const classDept = $(this).find('td:nth-child(2) .badge').text().trim();
                    const className = $(this).find('td:first span').text().trim().toLowerCase();
                    const showClass = (!department || classDept === department) && 
                                     (!search || className.includes(search));
                    $(this).toggle(showClass);
                });
                
                // Update chart based on filtered classes
                updateChart(department, search);
            });

            // Update chart based on filters
            function updateChart(department, search) {
                // Get filtered classes
                const filteredData = [];
                $('#classStatisticsTable tbody tr:visible').each(function() {
                    const className = $(this).find('td:first span').text().trim();
                    const studentCount = parseInt($(this).find('td:nth-child(3)').text().trim());
                    filteredData.push({ className, studentCount });
                });
                
                // Update chart
                chart.data.labels = filteredData.map(item => item.className);
                chart.data.datasets[0].data = filteredData.map(item => item.studentCount);
                chart.update();
            }

            // Reset filters
            $('#reset_filters').click(function() {
                $('#department_filter').val('');
                $('#academic_year_filter').val('');
                $('#search_class').val('');
                $('table tbody tr').show();
                
                // Reset chart
                chart.data.labels = classNames;
                chart.data.datasets[0].data = studentCounts;
                chart.update();
            });

            // Clear search
            $('#clear_search').click(function() {
                $('#search_class').val('');
                $('#apply_filters').click();
            });

            // Search on input
            $('#search_class').on('input', function() {
                const search = $(this).val().toLowerCase();
                if (search.length === 0 || search.length > 2) {
                    $('#apply_filters').click();
                }
            });

            // Export function
            window.exportClassData = function() {
                // Get current filters
                const department = $('#department_filter').val();
                const year = $('#academic_year_filter').val();
                
                // Build export URL
                let exportUrl = 'export_classes.php?type=csv';
                if (department) exportUrl += '&department=' + encodeURIComponent(department);
                if (year) exportUrl += '&year=' + encodeURIComponent(year);
                
                // Redirect to export
                window.location.href = exportUrl;
            };

            // Table sorting
            $('table thead th').click(function() {
                const table = $(this).closest('table');
                const column = $(this).index();
                const rows = table.find('tbody tr').toArray();
                const isAscending = $(this).hasClass('asc');
                
                rows.sort(function(a, b) {
                    let aVal = $(a).find('td').eq(column).text().trim();
                    let bVal = $(b).find('td').eq(column).text().trim();
                    
                    // Try numeric comparison first
                    const aNum = parseFloat(aVal);
                    const bNum = parseFloat(bVal);
                    if (!isNaN(aNum) && !isNaN(bNum)) {
                        return isAscending ? bNum - aNum : aNum - bNum;
                    }
                    
                    // String comparison
                    return isAscending ? bVal.localeCompare(aVal) : aVal.localeCompare(bVal);
                });
                
                // Clear previous sort classes
                table.find('th').removeClass('asc desc');
                
                // Toggle sort direction
                $(this).toggleClass('asc', !isAscending);
                $(this).toggleClass('desc', isAscending);
                
                // Reorder rows
                table.find('tbody').empty().append(rows);
            });

            // Add animation to cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate__animated', 'animate__pulse');
                        setTimeout(() => {
                            entry.target.classList.remove('animate__pulse');
                        }, 1000);
                    }
                });
            }, observerOptions);

            // Observe all cards
            document.querySelectorAll('.stat-card, .quick-action-card').forEach(card => {
                observer.observe(card);
            });
        });

        // Print functionality
        function printReport() {
            window.print();
        }

        // Refresh data
        function refreshData() {
            location.reload();
        }

        // Generate report
        function generateReport() {
            alert('Report generation feature would be implemented here. This would create a PDF report of all class data.');
        }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>