<?php
ob_start();
session_start();

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include the database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check if the database connection was successful
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}

// Fetch available academic years from academic_calendar
$academic_years_sql = "SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC";
$academic_years_result = mysqli_query($conn, $academic_years_sql);
$academic_years = [];
while ($row = mysqli_fetch_assoc($academic_years_result)) {
    $academic_years[] = $row['year'];
}
mysqli_free_result($academic_years_result);

// Validate and get selected academic year from GET or default to first available
$selected_academic_year = $_GET['academic_year'] ?? ($academic_years[0] ?? '');
if (!in_array($selected_academic_year, $academic_years)) {
    $selected_academic_year = $academic_years[0] ?? '';
}

// Extract year part for queries (e.g., "2025/2026" -> 2025)
$selected_year_part = explode('/', $selected_academic_year)[0] ?? '';

// Fetch current term details for selected academic year
$current_term_sql = "
    SELECT id, year, term_name, start_date, end_date
    FROM academic_calendar
    WHERE year = '" . mysqli_real_escape_string($conn, $selected_academic_year) . "'
    ORDER BY 
        CASE term_name 
            WHEN 'Term 1' THEN 1
            WHEN 'Term 2' THEN 2
            WHEN 'Term 3' THEN 3
            ELSE 4
        END DESC
    LIMIT 1";
$current_term_result = mysqli_query($conn, $current_term_sql);
$current_term = mysqli_fetch_assoc($current_term_result) ?: null;
mysqli_free_result($current_term_result);

// If no term found for selected academic year, get latest term overall
if (!$current_term) {
    $current_term_sql = "SELECT id, year, term_name, start_date, end_date FROM academic_calendar ORDER BY start_date DESC LIMIT 1";
    $current_term_result = mysqli_query($conn, $current_term_sql);
    $current_term = mysqli_fetch_assoc($current_term_result) ?: null;
    mysqli_free_result($current_term_result);
}

$current_term_id = $current_term['id'] ?? null;
$current_term_name = $current_term['term_name'] ?? 'No Term';
$current_term_year = $current_term['year'] ?? $selected_academic_year;

// Fetch user statistics - REMOVED status condition since it doesn't exist
$user_stats_sql = "
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
        SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) as teacher_count,
        SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as student_count,
        SUM(CASE WHEN role = 'parent' THEN 1 ELSE 0 END) as parent_count
    FROM users";
$user_stats_result = mysqli_query($conn, $user_stats_sql);
$user_stats = mysqli_fetch_assoc($user_stats_result) ?: ['total_users' => 0, 'admin_count' => 0, 'teacher_count' => 0, 'student_count' => 0, 'parent_count' => 0];
mysqli_free_result($user_stats_result);

// Fetch class and subject counts - REMOVED status condition if columns don't exist
$class_count_sql = "SELECT COUNT(*) as class_count FROM classes";
$class_count_result = mysqli_query($conn, $class_count_sql);
$class_count = mysqli_fetch_assoc($class_count_result)['class_count'] ?? 0;
mysqli_free_result($class_count_result);

$subject_count_sql = "SELECT COUNT(*) as subject_count FROM subjects";
$subject_count_result = mysqli_query($conn, $subject_count_sql);
$subject_count = mysqli_fetch_assoc($subject_count_result)['subject_count'] ?? 0;
mysqli_free_result($subject_count_result);

// Check if fees table exists
$check_fees_table = mysqli_query($conn, "SHOW TABLES LIKE 'fees'");
$fees_table_exists = mysqli_num_rows($check_fees_table) > 0;
mysqli_free_result($check_fees_table);

$total_fees = 0;
$total_debts = 0;

if ($fees_table_exists) {
    // Fetch financial statistics for selected academic year
    $fees_sql = "SELECT SUM(amount) as total_fees FROM fees WHERE academic_year = '" . mysqli_real_escape_string($conn, $selected_academic_year) . "'";
    $fees_result = mysqli_query($conn, $fees_sql);
    $total_fees = mysqli_fetch_assoc($fees_result)['total_fees'] ?? 0;
    mysqli_free_result($fees_result);

    // Check if debts table exists
    $check_debts_table = mysqli_query($conn, "SHOW TABLES LIKE 'debts'");
    $debts_table_exists = mysqli_num_rows($check_debts_table) > 0;
    mysqli_free_result($check_debts_table);

    if ($debts_table_exists) {
        $debts_sql = "SELECT SUM(amount_due) as total_debts FROM debts WHERE academic_year = '" . mysqli_real_escape_string($conn, $selected_academic_year) . "'";
        $debts_result = mysqli_query($conn, $debts_sql);
        $total_debts = mysqli_fetch_assoc($debts_result)['total_debts'] ?? 0;
        mysqli_free_result($debts_result);
    }
}

// Check if teacher_evaluations table exists
$check_eval_table = mysqli_query($conn, "SHOW TABLES LIKE 'teacher_evaluations'");
$eval_table_exists = mysqli_num_rows($check_eval_table) > 0;
mysqli_free_result($check_eval_table);

$eval_count = 0;
$obs_count = 0;

if ($eval_table_exists) {
    // Fetch evaluation count for selected academic year
    $eval_count_sql = "
        SELECT COUNT(*) as eval_count 
        FROM teacher_evaluations te
        JOIN academic_calendar ac ON te.term_id = ac.id
        WHERE ac.year = '" . mysqli_real_escape_string($conn, $selected_academic_year) . "'";
    $eval_count_result = mysqli_query($conn, $eval_count_sql);
    $eval_count = mysqli_fetch_assoc($eval_count_result)['eval_count'] ?? 0;
    mysqli_free_result($eval_count_result);
}

// Check if lesson_observations table exists
$check_obs_table = mysqli_query($conn, "SHOW TABLES LIKE 'lesson_observations'");
$obs_table_exists = mysqli_num_rows($check_obs_table) > 0;
mysqli_free_result($check_obs_table);

if ($obs_table_exists) {
    // Fetch observation count for selected academic year
    $obs_count_sql = "
        SELECT COUNT(*) as obs_count 
        FROM lesson_observations lo
        JOIN academic_calendar ac ON lo.term_id = ac.id
        WHERE ac.year = '" . mysqli_real_escape_string($conn, $selected_academic_year) . "'";
    $obs_count_result = mysqli_query($conn, $obs_count_sql);
    $obs_count = mysqli_fetch_assoc($obs_count_result)['obs_count'] ?? 0;
    mysqli_free_result($obs_count_result);
}

// Fetch average teacher evaluation score for current term
$avg_eval_score = 'N/A';
if ($current_term_id && $eval_table_exists) {
    $avg_eval_score_sql = "
        SELECT 
            AVG((
                COALESCE(score_1, 0) + COALESCE(score_2, 0) + COALESCE(score_3, 0) + 
                COALESCE(score_4, 0) + COALESCE(score_5, 0) + COALESCE(score_6, 0) + 
                COALESCE(score_7, 0) + COALESCE(score_8, 0) + COALESCE(score_9, 0) + 
                COALESCE(score_10, 0) + COALESCE(score_11, 0) + COALESCE(score_12, 0) + 
                COALESCE(score_13, 0) + COALESCE(score_14, 0) + COALESCE(score_15, 0) + 
                COALESCE(score_16, 0)
            ) / 16) as avg_eval_score
        FROM teacher_evaluations te
        WHERE te.term_id = $current_term_id";
    $avg_eval_score_result = mysqli_query($conn, $avg_eval_score_sql);
    if ($avg_eval_score_result) {
        $avg_eval_score_data = mysqli_fetch_assoc($avg_eval_score_result);
        $avg_eval_score = $avg_eval_score_data['avg_eval_score'] ?? 'N/A';
        mysqli_free_result($avg_eval_score_result);
    }
}

// Fetch average lesson observation score for current term
$avg_obs_score = 'N/A';
if ($current_term_id && $obs_table_exists) {
    $avg_obs_score_sql = "
        SELECT 
            AVG((
                COALESCE(score_1, 0) + COALESCE(score_2, 0) + COALESCE(score_3, 0) + 
                COALESCE(score_4, 0) + COALESCE(score_5, 0) + COALESCE(score_6, 0) + 
                COALESCE(score_7, 0) + COALESCE(score_8, 0) + COALESCE(score_9, 0) + 
                COALESCE(score_10, 0)
            ) / 10) as avg_obs_score
        FROM lesson_observations lo
        WHERE lo.term_id = $current_term_id";
    $avg_obs_score_result = mysqli_query($conn, $avg_obs_score_sql);
    if ($avg_obs_score_result) {
        $avg_obs_score_data = mysqli_fetch_assoc($avg_obs_score_result);
        $avg_obs_score = $avg_obs_score_data['avg_obs_score'] ?? 'N/A';
        mysqli_free_result($avg_obs_score_result);
    }
}

// Fetch academic progress entries count for selected academic year
$progress_count_sql = "
    SELECT COUNT(*) as progress_count 
    FROM academic_progress_entries 
    WHERE academic_year = '" . mysqli_real_escape_string($conn, $selected_academic_year) . "'";
$progress_count_result = mysqli_query($conn, $progress_count_sql);
$progress_count = mysqli_fetch_assoc($progress_count_result)['progress_count'] ?? 0;
mysqli_free_result($progress_count_result);

// Check if user_logs table exists
$check_logs_table = mysqli_query($conn, "SHOW TABLES LIKE 'user_logs'");
$logs_table_exists = mysqli_num_rows($check_logs_table) > 0;
mysqli_free_result($check_logs_table);

$recent_logs = [];
if ($logs_table_exists) {
    // Fetch recent user activity
    $recent_logs_sql = "
        SELECT ul.*, u.name
        FROM user_logs ul
        JOIN users u ON ul.user_id = u.id
        ORDER BY ul.created_at DESC
        LIMIT 5";
    $recent_logs_result = mysqli_query($conn, $recent_logs_sql);
    while ($log = mysqli_fetch_assoc($recent_logs_result)) {
        $recent_logs[] = $log;
    }
    mysqli_free_result($recent_logs_result);
}

// Include admin header template
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin dashboard for QueensPa School, managing users, classes, subjects, financials, and teacher evaluations.">
    <meta name="keywords" content="QueensPa School, admin dashboard, school management, financial statistics, teacher evaluations">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Admin Dashboard">
    <meta property="og:description" content="Manage school operations, including financials and evaluations, with QueensPa's admin dashboard.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/dashboard.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Admin Dashboard">
    <meta name="twitter:description" content="Manage school operations, including financials and evaluations, with QueensPa's admin dashboard.">
    <title>Admin Dashboard - QueensPa School</title>
    
    <!-- CSS Links -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #ff6b00;
            --accent-color: #ff8c00;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-bg: #f8f9fa;
            --dark-bg: #343a40;
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --hover-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }
        
        body {
            background: linear-gradient(135deg, #ff9a00 0%, #ff4500 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .content {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            margin: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .dashboard-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        
        .dashboard-header h1 {
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-bottom: none;
            padding: 15px 20px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            height: 100%;
            border-top: 4px solid var(--primary-color);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--hover-shadow);
            border-top-color: var(--accent-color);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .stat-card h5 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .stat-card .fs-3 {
            font-weight: 700;
            color: var(--secondary-color);
        }
        
        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }
        
        .form-select {
            border-radius: 10px;
            border: 2px solid var(--secondary-color);
            padding: 10px 15px;
            font-weight: 500;
        }
        
        .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 69, 0, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 69, 0, 0.4);
            background: linear-gradient(to right, var(--secondary-color), var(--accent-color));
        }
        
        .quick-links {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
        }
        
        .quick-links .btn {
            margin: 5px;
            flex: 1;
            min-width: 180px;
        }
        
        .table {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table thead {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(255, 69, 0, 0.1);
        }
        
        .term-info {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: var(--card-shadow);
        }
        
        .term-info h5 {
            color: white;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .term-info p {
            margin-bottom: 5px;
            font-size: 1.1rem;
        }
        
        .summary-card {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
            color: white;
            border: none;
        }
        
        .summary-card .stat-card {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
        }
        
        .summary-card .stat-card i,
        .summary-card .stat-card h5,
        .summary-card .stat-card .fs-3 {
            color: white;
        }
        
        .badge-primary {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        @media (max-width: 768px) {
            .content {
                margin: 10px;
                padding: 15px;
            }
            
            .quick-links .btn {
                min-width: 100%;
                margin: 5px 0;
            }
            
            .dashboard-header {
                padding: 15px;
            }
            
            .stat-card {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <!-- Dashboard Header -->
        <div class="dashboard-header animate__animated animate__fadeInDown">
            <div class="d-flex justify-content-between align-items-center">
                <h1><i class="fas fa-tachometer-alt me-2"></i>Admin Dashboard</h1>
                <div class="d-flex align-items-center">
                    <span class="me-3">Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?>!</span>
                    <i class="fas fa-user-circle fa-2x"></i>
                </div>
            </div>
        </div>

        <!-- Academic Year Selector -->
        <section class="mb-4 animate__animated animate__fadeIn">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calendar-alt me-2"></i>Academic Year Selection
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-center">
                        <div class="col-md-8">
                            <label for="academic_year" class="form-label fw-bold">Select Academic Year:</label>
                            <select name="academic_year" id="academic_year" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= htmlspecialchars($year) ?>" <?= $selected_academic_year === $year ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100 mt-4">
                                <i class="fas fa-sync-alt me-2"></i>Load Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- Current Term Information -->
        <section class="mb-4 animate__animated animate__fadeIn">
            <div class="term-info">
                <div class="row">
                    <div class="col-md-4">
                        <h5><i class="fas fa-graduation-cap me-2"></i>Current Academic Year</h5>
                        <p><strong><?= htmlspecialchars($selected_academic_year) ?></strong></p>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="fas fa-school me-2"></i>Current Term</h5>
                        <p><strong><?= htmlspecialchars($current_term_name) ?></strong></p>
                        <?php if ($current_term): ?>
                            <small>ID: <?= $current_term_id ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="fas fa-calendar me-2"></i>Term Dates</h5>
                        <?php if ($current_term): ?>
                            <p><strong>Start:</strong> <?= date('M d, Y', strtotime($current_term['start_date'])) ?></p>
                            <p><strong>End:</strong> <?= date('M d, Y', strtotime($current_term['end_date'])) ?></p>
                        <?php else: ?>
                            <p>No term data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Key Metrics - Orange Summary Card -->
        <section class="mb-4 animate__animated animate__zoomIn">
            <div class="card summary-card">
                <div class="card-header">
                    <i class="fas fa-chart-line me-2"></i>Key Metrics
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <!-- Total Users -->
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="stat-card">
                                <i class="fas fa-users"></i>
                                <h5>Total Users</h5>
                                <p class="fs-3"><?= number_format($user_stats['total_users']) ?></p>
                                <small>All users in system</small>
                            </div>
                        </div>
                        
                        <!-- Total Fees -->
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="stat-card">
                                <i class="fas fa-money-bill-wave"></i>
                                <h5>Total Fees</h5>
                                <p class="fs-3"><?= number_format($total_fees, 2) ?></p>
                                <small>For <?= htmlspecialchars($selected_academic_year) ?></small>
                            </div>
                        </div>
                        
                        <!-- Total Debts -->
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="stat-card">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h5>Total Debts</h5>
                                <p class="fs-3"><?= number_format($total_debts, 2) ?></p>
                                <small>Outstanding balances</small>
                            </div>
                        </div>
                        
                        <!-- Academic Progress -->
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="stat-card">
                                <i class="fas fa-chart-bar"></i>
                                <h5>Progress Entries</h5>
                                <p class="fs-3"><?= number_format($progress_count) ?></p>
                                <small>For <?= htmlspecialchars($selected_academic_year) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- User Statistics -->
        <section class="mb-4 animate__animated animate__zoomIn">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-users me-2"></i>User Statistics
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="userRolesChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="stat-card">
                                        <i class="fas fa-user-shield"></i>
                                        <h5>Admins</h5>
                                        <p class="fs-4"><?= $user_stats['admin_count'] ?></p>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-card">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                        <h5>Teachers</h5>
                                        <p class="fs-4"><?= $user_stats['teacher_count'] ?></p>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-card">
                                        <i class="fas fa-user-graduate"></i>
                                        <h5>Students</h5>
                                        <p class="fs-4"><?= $user_stats['student_count'] ?></p>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-card">
                                        <i class="fas fa-user-friends"></i>
                                        <h5>Parents</h5>
                                        <p class="fs-4"><?= $user_stats['parent_count'] ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Financial Overview -->
        <?php if ($fees_table_exists): ?>
        <section class="mb-4 animate__animated animate__zoomIn">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-pie me-2"></i>Financial Overview (<?= htmlspecialchars($selected_academic_year) ?>)
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="financialChart"></canvas>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Academic Structure -->
        <section class="mb-4 animate__animated animate__zoomIn">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-university me-2"></i>Academic Structure
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="stat-card">
                                <i class="fas fa-chalkboard"></i>
                                <h5>Classes</h5>
                                <p class="fs-3"><?= number_format($class_count) ?></p>
                                <small class="text-muted">All classes</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-card">
                                <i class="fas fa-book"></i>
                                <h5>Subjects</h5>
                                <p class="fs-3"><?= number_format($subject_count) ?></p>
                                <small class="text-muted">All subjects</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Evaluation Metrics -->
        <?php if ($eval_table_exists || $obs_table_exists): ?>
        <section class="mb-4 animate__animated animate__zoomIn">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-star me-2"></i>Evaluation Metrics (<?= htmlspecialchars($current_term_name) ?>)
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <?php if ($eval_table_exists): ?>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-clipboard-check"></i>
                                <h5>Evaluations</h5>
                                <p class="fs-3"><?= number_format($eval_count) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($obs_table_exists): ?>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-eye"></i>
                                <h5>Observations</h5>
                                <p class="fs-3"><?= number_format($obs_count) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($eval_table_exists && $current_term_id): ?>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-chart-line"></i>
                                <h5>Avg. Eval Score</h5>
                                <p class="fs-3"><?= is_numeric($avg_eval_score) ? round($avg_eval_score, 2) : $avg_eval_score ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($obs_table_exists && $current_term_id): ?>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-chart-bar"></i>
                                <h5>Avg. Obs Score</h5>
                                <p class="fs-3"><?= is_numeric($avg_obs_score) ? round($avg_obs_score, 2) : $avg_obs_score ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Recent Activity -->
        <?php if ($logs_table_exists): ?>
        <section class="mb-4 animate__animated animate__zoomIn">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history me-2"></i>Recent Activity
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-user me-1"></i> User</th>
                                    <th><i class="fas fa-bolt me-1"></i> Action</th>
                                    <th><i class="fas fa-info-circle me-1"></i> Description</th>
                                    <th><i class="fas fa-clock me-1"></i> Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_logs)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4">
                                            <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                            <p class="text-muted">No recent activity found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_logs as $log): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($log['name']) ?></strong></td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?= htmlspecialchars($log['action']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($log['description']) ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= date('M d, Y H:i', strtotime($log['created_at'])) ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Quick Links -->
        <section class="mb-4 animate__animated animate__fadeInUp">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-rocket me-2"></i>Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap quick-links">
                        <a href="user_management.php" class="btn btn-primary">
                            <i class="fas fa-users-cog me-2"></i> Manage Users
                        </a>
                        <a href="class_management.php" class="btn btn-primary">
                            <i class="fas fa-chalkboard-teacher me-2"></i> Manage Classes
                        </a>
                        <a href="subject_management.php" class="btn btn-primary">
                            <i class="fas fa-book-open me-2"></i> Manage Subjects
                        </a>
                        
                        <a href="parent_student.php" class="btn btn-primary">
                        <i class="fas fa-book-open me-2"></i> Parent Student Mapping    
                        </a>
                        <?php if ($fees_table_exists): ?>
                        <a href="financial_management.php" class="btn btn-primary">
                            <i class="fas fa-money-check-alt me-2"></i> Financials
                        </a>
                        <?php endif; ?>
                        <?php if ($eval_table_exists): ?>
                        <a href="evaluate_teacher.php?term_id=<?= $current_term_id ?>" class="btn btn-primary">
                            <i class="fas fa-user-check me-2"></i> Evaluate Teacher
                        </a>
                        <?php endif; ?>
                        <?php if ($obs_table_exists): ?>
                        <a href="observe_lesson.php?term_id=<?= $current_term_id ?>" class="btn btn-primary">
                            <i class="fas fa-binoculars me-2"></i> Observe Lesson
                        </a>
                        <?php endif; ?>
                        <a href="academic_progress.php" class="btn btn-primary">
                            <i class="fas fa-chart-line me-2"></i> Academic Progress
                        </a>
                        <a href="launchcards.php" class="btn btn-primary">
                            <i class="fas fa-file-alt me-2"></i> Generate Launchcards
                        </a>
                                                </a>
                        <a href="gallery.php" class="btn btn-primary">
                            <i class="fas fa-file-alt me-2"></i> Update Gallery
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // User Roles Pie Chart
            const userRolesCtx = document.getElementById('userRolesChart');
            if (userRolesCtx) {
                const userRolesChart = new Chart(userRolesCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Admins', 'Teachers', 'Students', 'Parents'],
                        datasets: [{
                            data: [
                                <?= $user_stats['admin_count'] ?>,
                                <?= $user_stats['teacher_count'] ?>,
                                <?= $user_stats['student_count'] ?>,
                                <?= $user_stats['parent_count'] ?>
                            ],
                            backgroundColor: [
                                '#ff4500',  // Orange for admins
                                '#ff6b00',  // Darker orange for teachers
                                '#ff8c00',  // Lighter orange for students
                                '#ff9a00'   // Light orange for parents
                            ],
                            borderColor: '#fff',
                            borderWidth: 2,
                            hoverOffset: 15
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true,
                                    font: {
                                        size: 12,
                                        family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                                    }
                                }
                            },
                            title: {
                                display: true,
                                text: 'User Role Distribution',
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                },
                                padding: {
                                    top: 10,
                                    bottom: 20
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        let value = context.raw || 0;
                                        let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        let percentage = ((value / total) * 100).toFixed(1);
                                        return `${label}: ${value} users (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Financial Bar Chart
            const financialCtx = document.getElementById('financialChart');
            if (financialCtx) {
                const financialChart = new Chart(financialCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Total Fees', 'Total Debts'],
                        datasets: [{
                            label: 'Amount (GHS)',
                            data: [<?= $total_fees ?>, <?= $total_debts ?>],
                            backgroundColor: [
                                'rgba(255, 107, 0, 0.8)',   // Orange for fees
                                'rgba(255, 69, 0, 0.8)'     // Red-orange for debts
                            ],
                            borderColor: [
                                'rgba(255, 107, 0, 1)',
                                'rgba(255, 69, 0, 1)'
                            ],
                            borderWidth: 1,
                            borderRadius: 5,
                            barPercentage: 0.6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return 'GHS ' + value.toLocaleString();
                                    },
                                    font: {
                                        size: 12
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Amount (GHS)',
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
                                        size: 13,
                                        weight: 'bold'
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: 'Financial Overview - <?= htmlspecialchars($selected_academic_year) ?>',
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                },
                                padding: {
                                    top: 10,
                                    bottom: 20
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `${context.label}: GHS ${context.raw.toLocaleString()}`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Add animation to stat cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate__animated', 'animate__pulse');
                    }
                });
            }, observerOptions);

            // Observe all stat cards
            document.querySelectorAll('.stat-card').forEach(card => {
                observer.observe(card);
            });
        });

        // Add smooth scroll to top functionality
        window.addEventListener('scroll', function() {
            const scrollTop = document.documentElement.scrollTop || document.body.scrollTop;
            const scrollBtn = document.getElementById('scrollToTop');
            
            if (scrollBtn) {
                if (scrollTop > 300) {
                    scrollBtn.style.display = 'block';
                } else {
                    scrollBtn.style.display = 'none';
                }
            }
        });

        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
    </script>

    <!-- Scroll to Top Button -->
    <button onclick="scrollToTop()" id="scrollToTop" class="btn btn-primary" style="position: fixed; bottom: 20px; right: 20px; display: none; z-index: 1000; border-radius: 50%; width: 50px; height: 50px; padding: 0; background: linear-gradient(to right, #ff4500, #ff6b00);">
        <i class="fas fa-arrow-up"></i>
    </button>
</body>
</html>