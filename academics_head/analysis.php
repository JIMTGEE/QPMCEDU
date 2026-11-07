<?php
ob_start();
session_start();

// Restrict to admins
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "Database connection failed. Please try again later.";
    header("Location: ../login.php");
    exit();
}

// Log page access
$admin_id = $_SESSION['user_id'];
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Analysis Dashboard', 'Admin accessed teacher evaluation analysis', ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_stmt->bind_param("iss", $admin_id, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();

// Define evaluation criteria
$evaluation_criteria = [
    1 => "Annual/Termly scheme of learning",
    2 => "Availability of curriculum",
    3 => "Curriculum reference in prepared lessons",
    4 => "Teaching & Learning resources (TLRs) prepared",
    5 => "TLRs usage during the lesson",
    6 => "Lesson notes availability and usage",
    7 => "Summary notes availability and usage",
    8 => "Lesson based on prepared timetable",
    9 => "Enough test items prepared for assessment",
    10 => "Test items meet learners' level",
    11 => "Teacher's content knowledge",
    12 => "Teacher's pedagogical skills",
    13 => "Teacher-learners collaboration",
    14 => "Integration of technology in the lesson",
    15 => "Dressing & Appearance of teacher",
    16 => "Participation in extracurricular activities",
    17 => "LDaP (Lesson Delivery and Punctuality)"
];

// Fetch years, terms, and departments
$calendar_sql = "SELECT DISTINCT year, term_name FROM academic_calendar ORDER BY year DESC, term_name";
$calendar_stmt = $conn->prepare($calendar_sql);
$calendar_stmt->execute();
$calendar_result = $calendar_stmt->get_result();
$years = [];
$terms = [];
while ($cal_row = $calendar_result->fetch_assoc()) {
    $years[$cal_row['year']] = $cal_row['year'];
    $terms[$cal_row['term_name']] = $cal_row['term_name'];
}
$calendar_stmt->close();

$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

if (empty($years) || empty($terms)) {
    error_log("No academic calendar data found");
    $_SESSION['error_msg'] = "No academic periods available. Please configure the academic calendar.";
    $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                VALUES (?, 'Error', ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $description = "No academic calendar data found";
    $log_stmt->bind_param("is", $admin_id, $description);
    $log_stmt->execute();
    $log_stmt->close();
    header("Location: calendar.php");
    exit();
}

// Handle filters
$year = isset($_GET['year']) ? trim($_GET['year']) : null;
$term = isset($_GET['term']) ? trim($_GET['term']) : null;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

if (!$year || !$term || !isset($years[$year]) || !isset($terms[$term])) {
    $page_title = "Analysis Dashboard - Select Period";
    $page_description = "Select academic period for teacher evaluation analysis at QueensPa School.";
    $page_keywords = "QueensPa School, admin, teacher evaluation, analysis";
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    ?>
    <!DOCTYPE html>
    <html lang="en" data-bs-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
        <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
        <meta name="author" content="QueensPa School">
        <meta name="robots" content="noindex, nofollow">
        <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
        <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
        <meta property="og:type" content="website">
        <meta property="og:url" content="https://qpmsedu.com/admin/analysis_dashboard.php">
        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="<?php echo htmlspecialchars($page_title); ?>">
        <meta name="twitter:description" content="<?php echo htmlspecialchars($page_description); ?>">
        <title><?php echo htmlspecialchars($page_title); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <style>
            :root {
                --primary-color: #ff4500;
                --secondary-color: #6c757d;
                --accent-color: #ffc107;
                --text-color: #333;
                --bg-color: #f8f9fa;
                --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }
            body {
                background: var(--bg-color);
                color: var(--text-color);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .card {
                border-radius: 12px;
                box-shadow: var(--shadow);
                transition: transform 0.3s ease;
            }
            .card:hover {
                transform: translateY(-5px);
            }
            .card-header {
                background: linear-gradient(to right, var(--primary-color), #ff6b00);
                color: white;
                border-radius: 12px 12px 0 0;
                padding: 1.5rem;
            }
            .btn-primary, .btn-success, .btn-secondary {
                background-color: var(--primary-color);
                border-color: var(--primary-color);
                border-radius: 8px;
                padding: 0.75rem 1.5rem;
                font-weight: 500;
            }
            .btn-primary:hover, .btn-success:hover, .btn-secondary:hover {
                background-color: #e03e00;
                border-color: #e03e00;
            }
            .form-select {
                border-radius: 8px;
                border: 1px solid #ced4da;
                padding: 0.75rem;
                transition: border-color 0.3s ease;
            }
            .form-select:focus {
                border-color: var(--primary-color);
                box-shadow: 0 0 0 0.2rem rgba(255, 69, 0, 0.25);
            }
            .quick-links .btn {
                min-width: 150px;
            }
            @media (max-width: 767.98px) {
                .quick-links {
                    flex-direction: column;
                    align-items: stretch;
                }
                .quick-links .btn {
                    width: 100%;
                    margin-bottom: 0.5rem;
                    min-width: auto;
                }
            }
        </style>
    </head>
    <body>
        <div class="content" id="content">
            <main class="container my-4">
                <h1 class="mb-4 text-center animate__animated animate__fadeIn">📊 Analysis Dashboard - Select Period</h1>
                <section class="card mb-4 animate__animated animate__fadeInDown">
                    <div class="card-header">
                        <h3 class="mb-0">Choose Period for Analysis</h3>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" id="period-form" class="row g-3 align-items-center" aria-label="Select Academic Period">
                            <div class="col-12 col-md-4">
                                <label for="year" class="form-label">Year <span class="text-danger">*</span></label>
                                <select name="year" id="year" class="form-select" required data-bs-toggle="tooltip" title="Select academic year">
                                    <option value="">Select Year</option>
                                    <?php foreach ($years as $yr): ?>
                                        <option value="<?php echo htmlspecialchars($yr); ?>" <?php echo $year === $yr ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($yr); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="term" class="form-label">Term <span class="text-danger">*</span></label>
                                <select name="term" id="term" class="form-select" required data-bs-toggle="tooltip" title="Select academic term">
                                    <option value="">Select Term</option>
                                    <?php foreach ($terms as $tm): ?>
                                        <option value="<?php echo htmlspecialchars($tm); ?>" <?php echo $term === $tm ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tm); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="department_id" class="form-label">Department</label>
                                <select name="department_id" id="department_id" class="form-select" data-bs-toggle="tooltip" title="Filter by department">
                                    <option value="0">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 d-flex flex-wrap quick-links gap-2 mt-3">
                                <button type="submit" class="btn btn-primary" data-bs-toggle="tooltip" title="View analysis for selected period">
                                    <i class="fas fa-eye me-2"></i>View Analysis
                                </button>
                                <a href="dashboard.php" class="btn btn-secondary" data-bs-toggle="tooltip" title="Return to dashboard">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                </section>
            </main>
        </div>
        <?php
        include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
        mysqli_close($conn);
        ob_end_flush();
        ?>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));
                const form = document.getElementById('period-form');
                if (form) {
                    form.addEventListener('submit', (e) => {
                        const year = document.getElementById('year');
                        const term = document.getElementById('term');
                        if (!year.value || !term.value) {
                            e.preventDefault();
                            const alert = document.createElement('div');
                            alert.className = 'alert alert-danger alert-dismissible fade show animate__animated animate__fadeIn';
                            alert.role = 'alert';
                            alert.innerHTML = `
                                <i class="fas fa-exclamation-circle me-2"></i>Please select both year and term.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            `;
                            form.prepend(alert);
                            [year, term].forEach(field => {
                                if (!field.value) field.classList.add('is-invalid');
                            });
                        }
                    });
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Fetch evaluations
$evaluations_sql = "
    SELECT 
        te.teacher_id,
        u.name AS teacher_name,
        s.subject_name,
        c.class_name,
        ac.year,
        ac.term_name,
        te.cuas,
        te.attendance_status,
        (
            COALESCE(te.score_1, 0) + COALESCE(te.score_2, 0) + COALESCE(te.score_3, 0) + 
            COALESCE(te.score_4, 0) + COALESCE(te.score_5, 0) + COALESCE(te.score_6, 0) + 
            COALESCE(te.score_7, 0) + COALESCE(te.score_8, 0) + COALESCE(te.score_9, 0) + 
            COALESCE(te.score_10, 0) + COALESCE(te.score_11, 0) + COALESCE(te.score_12, 0) + 
            COALESCE(te.score_13, 0) + COALESCE(te.score_14, 0) + COALESCE(te.score_15, 0) + 
            COALESCE(te.score_16, 0) + COALESCE(te.score_17, 0)
        ) / 17 AS average_score
    FROM teacher_evaluations te
    JOIN users u ON te.teacher_id = u.id
    JOIN subjects s ON te.subject_id = s.id
    JOIN classes c ON te.class_id = c.id
    JOIN academic_calendar ac ON te.term_id = ac.id
    WHERE ac.year = ? AND ac.term_name = ?";
if ($department_id > 0) {
    $evaluations_sql .= " AND te.department_id = ?";
}
$evaluations_sql .= " GROUP BY te.teacher_id, te.class_id, te.subject_id, te.cuas
                     ORDER BY average_score DESC";
$evaluations_stmt = $conn->prepare($evaluations_sql);
if ($department_id > 0) {
    $evaluations_stmt->bind_param("ssi", $year, $term, $department_id);
} else {
    $evaluations_stmt->bind_param("ss", $year, $term);
}
$evaluations_stmt->execute();
$evaluations_result = $evaluations_stmt->get_result();
$evaluations = $evaluations_result->fetch_all(MYSQLI_ASSOC);
$evaluations_stmt->close();

if (empty($evaluations)) {
    error_log("No evaluations found for year: $year, term: $term, department_id: $department_id");
    $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                VALUES (?, 'Error', ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $description = "No evaluations found for year: $year, term: $term, department_id: $department_id";
    $log_stmt->bind_param("is", $admin_id, $description);
    $log_stmt->execute();
    $log_stmt->close();
}

// Calculate stats
$stats_sql = "
    SELECT 
        COUNT(DISTINCT te.teacher_id) AS teacher_count,
        AVG((
            COALESCE(te.score_1, 0) + COALESCE(te.score_2, 0) + COALESCE(te.score_3, 0) + 
            COALESCE(te.score_4, 0) + COALESCE(te.score_5, 0) + COALESCE(te.score_6, 0) + 
            COALESCE(te.score_7, 0) + COALESCE(te.score_8, 0) + COALESCE(te.score_9, 0) + 
            COALESCE(te.score_10, 0) + COALESCE(te.score_11, 0) + COALESCE(te.score_12, 0) + 
            COALESCE(te.score_13, 0) + COALESCE(te.score_14, 0) + COALESCE(te.score_15, 0) + 
            COALESCE(te.score_16, 0) + COALESCE(te.score_17, 0)
        ) / 17) AS overall_average
    FROM teacher_evaluations te
    JOIN academic_calendar ac ON te.term_id = ac.id
    WHERE ac.year = ? AND ac.term_name = ?";
if ($department_id > 0) {
    $stats_sql .= " AND te.department_id = ?";
}
$stats_stmt = $conn->prepare($stats_sql);
if ($department_id > 0) {
    $stats_stmt->bind_param("ssi", $year, $term, $department_id);
} else {
    $stats_stmt->bind_param("ss", $year, $term);
}
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc() ?: ['teacher_count' => 0, 'overall_average' => null];
$stats_stmt->close();

// Fetch criterion averages
$criterion_averages_sql = "
    SELECT 
        AVG(COALESCE(score_1, 0)) AS avg_score_1,
        AVG(COALESCE(score_2, 0)) AS avg_score_2,
        AVG(COALESCE(score_3, 0)) AS avg_score_3,
        AVG(COALESCE(score_4, 0)) AS avg_score_4,
        AVG(COALESCE(score_5, 0)) AS avg_score_5,
        AVG(COALESCE(score_6, 0)) AS avg_score_6,
        AVG(COALESCE(score_7, 0)) AS avg_score_7,
        AVG(COALESCE(score_8, 0)) AS avg_score_8,
        AVG(COALESCE(score_9, 0)) AS avg_score_9,
        AVG(COALESCE(score_10, 0)) AS avg_score_10,
        AVG(COALESCE(score_11, 0)) AS avg_score_11,
        AVG(COALESCE(score_12, 0)) AS avg_score_12,
        AVG(COALESCE(score_13, 0)) AS avg_score_13,
        AVG(COALESCE(score_14, 0)) AS avg_score_14,
        AVG(COALESCE(score_15, 0)) AS avg_score_15,
        AVG(COALESCE(score_16, 0)) AS avg_score_16,
        AVG(COALESCE(score_17, 0)) AS avg_score_17
    FROM teacher_evaluations te
    JOIN academic_calendar ac ON te.term_id = ac.id
    WHERE ac.year = ? AND ac.term_name = ?";
if ($department_id > 0) {
    $criterion_averages_sql .= " AND te.department_id = ?";
}
$criterion_averages_stmt = $conn->prepare($criterion_averages_sql);
if ($department_id > 0) {
    $criterion_averages_stmt->bind_param("ssi", $year, $term, $department_id);
} else {
    $criterion_averages_stmt->bind_param("ss", $year, $term);
}
$criterion_averages_stmt->execute();
$criterion_averages_result = $criterion_averages_stmt->get_result();
$criterion_averages = $criterion_averages_result->fetch_assoc();
$criterion_averages_stmt->close();

// Set SEO
$page_title = "Teacher Evaluation Analysis - QueensPa School";
$page_description = "View teacher evaluation analysis for $year - $term at QueensPa School.";
$page_keywords = "QueensPa School, admin, teacher evaluation, analysis, $year, $term";

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/analysis_dashboard.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
        }
        .btn-primary, .btn-success, .btn-secondary, .btn-outline-light {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
        }
        .btn-primary:hover, .btn-success:hover, .btn-secondary:hover, .btn-outline-light:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .btn-outline-light {
            background-color: transparent;
            color: white;
            border-color: white;
        }
        .btn-outline-light:hover {
            background-color: white;
            color: var(--primary-color);
        }
        .form-select {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0.75rem;
            transition: border-color 0.3s ease;
        }
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 69, 0, 0.25);
        }
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
        }
        .table thead {
            background: var(--primary-color);
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .score-excellent { background-color: #d4edda; }
        .score-good { background-color: #fff3cd; }
        .score-fair { background-color: #f8d7da; }
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
            margin: 0 auto;
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .stat-card {
            background: #fff;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: background-color 0.3s ease;
        }
        .stat-card:hover {
            background-color: var(--bg-color);
        }
        .stat-card i {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        .quick-links .btn {
            min-width: 150px;
        }
        .alert {
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            align-items: center;
        }
        @media (max-width: 767.98px) {
            .quick-links {
                flex-direction: column;
                align-items: stretch;
            }
            .quick-links .btn {
                width: 100%;
                margin-bottom: 0.5rem;
                min-width: auto;
            }
            .chart-container {
                height: 200px;
            }
            .table {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <h1 class="mb-4 text-center animate__animated animate__fadeIn">📊 Teacher Evaluation Analysis</h1>

            <!-- Loading Spinner -->
            <div id="loading" class="d-none text-center my-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p>Loading analysis data...</p>
            </div>

            <!-- Overview Card -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Term Overview: <?php echo htmlspecialchars("$year - $term"); ?></h3>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="stat-card">
                                <i class="fas fa-users"></i>
                                <h5>Teachers Evaluated</h5>
                                <p class="fs-4" data-bs-toggle="tooltip" title="Number of unique teachers evaluated"><?php echo $stats['teacher_count']; ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <i class="fas fa-star"></i>
                                <h5>Overall Average Score</h5>
                                <p class="fs-4" data-bs-toggle="tooltip" title="Average score across all evaluations"><?php echo is_numeric($stats['overall_average']) ? round($stats['overall_average'], 2) : 'N/A'; ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <i class="fas fa-building"></i>
                                <h5>Department</h5>
                                <p class="fs-4" data-bs-toggle="tooltip" title="Selected department"><?php echo $department_id ? htmlspecialchars($departments[array_search($department_id, array_column($departments, 'id'))]['name']) : 'All'; ?></p>
                            </div>
                        </div>
                    </div>
                    <?php if ($stats['teacher_count'] == 0): ?>
                        <div class="alert alert-warning alert-dismissible fade show animate__animated animate__fadeIn mt-3" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>No teacher evaluations found for <?php echo htmlspecialchars("$year - $term"); ?>. 
                            Please <a href="evaluate_teacher.php?year=<?php echo urlencode($year); ?>&term=<?php echo urlencode($term); ?>" class="alert-link">add evaluations</a> or check the <a href="calendar.php" class="alert-link">academic calendar</a>.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Charts -->
            <?php if (!empty($evaluations)): ?>
                <section class="card mb-4 animate__animated animate__zoomIn">
                    <div class="card-header">
                        <h3 class="mb-0">Performance Visualizations</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <canvas id="teacherBarChart"></canvas>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <canvas id="criterionRadarChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Results Table -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0">Evaluation Results</h3>
                    <button class="btn btn-outline-light btn-sm" onclick="exportToPDF()" data-bs-toggle="tooltip" title="Export table as PDF">
                        <i class="fas fa-file-pdf me-2"></i>Export PDF
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered" aria-label="Teacher Evaluation Results">
                            <thead>
                                <tr>
                                    <th scope="col">Rank</th>
                                    <th scope="col">Teacher</th>
                                    <th scope="col">CUAS</th>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Attendance</th>
                                    <th scope="col">Average Score</th>
                                    <th scope="col">Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($evaluations)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No evaluation data available.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    $rank = 1;
                                    foreach ($evaluations as $row):
                                        $score_class = $row['average_score'] >= 4.5 ? 'score-excellent' : ($row['average_score'] >= 3.0 ? 'score-good' : 'score-fair');
                                        $performance = $row['average_score'] >= 4.5 ? 'Excellent' : ($row['average_score'] >= 3.0 ? 'Good' : 'Needs Improvement');
                                    ?>
                                        <tr class="<?php echo $score_class; ?>">
                                            <td><?php echo $rank; ?></td>
                                            <td><?php echo htmlspecialchars($row['teacher_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['cuas']); ?></td>
                                            <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['attendance_status']); ?></td>
                                            <td><?php echo round($row['average_score'], 2); ?></td>
                                            <td><?php echo $performance; ?></td>
                                        </tr>
                                    <?php
                                        $rank++;
                                    endforeach;
                                    ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="d-flex flex-wrap quick-links gap-2 mb-4 animate__animated animate__fadeInUp">
                <a href="evaluate_teacher.php?year=<?php echo urlencode($year); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Add a new teacher evaluation">
                    <i class="fas fa-clipboard-check me-2"></i>Evaluate Teacher
                </a>
                <a href="observe_lesson.php?year=<?php echo urlencode($year); ?>&term=<?php echo urlencode($term); ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Observe a lesson">
                    <i class="fas fa-eye me-2"></i>Observe Lesson
                </a>
                <a href="dashboard.php" class="btn btn-secondary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Return to dashboard">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </section>
        </main>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha512-qZvrmS2ekKPF2mSznTQsxqPgnpkI4DNTlrdUmTzrDgektczlKNRRhy5X5AAahn1mZPQw1bc87ZfUUtcg0Pq6fIQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            // Auto-dismiss alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            // Form validation for period selection
            const form = document.getElementById('period-form');
            if (form) {
                form.addEventListener('submit', (e) => {
                    const year = document.getElementById('year');
                    const term = document.getElementById('term');
                    if (!year.value || !term.value) {
                        e.preventDefault();
                        const alert = document.createElement('div');
                        alert.className = 'alert alert-danger alert-dismissible fade show animate__animated animate__fadeIn';
                        alert.role = 'alert';
                        alert.innerHTML = `
                            <i class="fas fa-exclamation-circle me-2"></i>Please select both year and term.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        `;
                        form.prepend(alert);
                        [year, term].forEach(field => {
                            if (!field.value) field.classList.add('is-invalid');
                        });
                    }
                });
            }

            // Charts
            <?php if (!empty($evaluations)): ?>
                // Teacher Bar Chart
                const teacherBarChart = new Chart(document.getElementById('teacherBarChart'), {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($row) { return '"' . addslashes($row['teacher_name']) . '"'; }, $evaluations)); ?>],
                        datasets: [{
                            label: 'Average Score',
                            data: [<?php echo implode(',', array_map(function($row) { return round($row['average_score'], 2); }, $evaluations)); ?>],
                            backgroundColor: 'rgba(255, 69, 0, 0.6)',
                            borderColor: 'rgba(255, 69, 0, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                max: 5,
                                title: { display: true, text: 'Score' },
                                ticks: { stepSize: 1 }
                            },
                            x: { title: { display: true, text: 'Teachers' } }
                        },
                        plugins: {
                            legend: { position: 'top' },
                            title: { display: true, text: 'Teacher Performance (Average Scores)' },
                            tooltip: {
                                callbacks: {
                                    label: ctx => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}`
                                }
                            }
                        }
                    }
                });

                // Criterion Radar Chart
                const criterionRadarChart = new Chart(document.getElementById('criterionRadarChart'), {
                    type: 'radar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($name) { return '"' . addslashes($name) . '"'; }, $evaluation_criteria)); ?>],
                        datasets: [{
                            label: 'Average Score per Criterion',
                            data: [
                                <?php
                                for ($i = 1; $i <= 17; $i++) {
                                    echo round($criterion_averages["avg_score_$i"], 2) . ($i < 17 ? ',' : '');
                                }
                                ?>
                            ],
                            backgroundColor: 'rgba(255, 69, 0, 0.2)',
                            borderColor: 'rgba(255, 69, 0, 1)',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            r: { 
                                beginAtZero: true, 
                                max: 5, 
                                ticks: { stepSize: 1 },
                                pointLabels: { font: { size: 10 } }
                            }
                        },
                        plugins: {
                            legend: { position: 'top' },
                            title: { display: true, text: 'Criterion Averages' }
                        }
                    }
                });
            <?php endif; ?>

            // PDF Export
            function exportToPDF() {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();
                doc.setFontSize(16);
                doc.text('Teacher Evaluation Analysis', 20, 20);
                doc.setFontSize(12);
                doc.text(`Period: <?php echo htmlspecialchars("$year - $term"); ?>`, 20, 30);
                doc.text(`Teachers Evaluated: <?php echo $stats['teacher_count']; ?>`, 20, 40);
                doc.text(`Overall Average Score: <?php echo is_numeric($stats['overall_average']) ? round($stats['overall_average'], 2) : 'N/A'; ?>`, 20, 50);

                let y = 60;
                doc.text('Evaluation Results:', 20, y);
                y += 10;
                doc.setFontSize(10);
                <?php foreach ($evaluations as $index => $row): ?>
                    doc.text(`${<?php echo $index + 1; ?>}. ${'<?php echo addslashes($row['teacher_name']); ?>'} (${'<?php echo addslashes($row['cuas']); ?>'}, <?php echo addslashes($row['subject_name']); ?>, <?php echo addslashes($row['class_name']); ?>): ${<?php echo round($row['average_score'], 2); ?>} (<?php echo $row['average_score'] >= 4.5 ? 'Excellent' : ($row['average_score'] >= 3.0 ? 'Good' : 'Needs Improvement'); ?>)`, 20, y);
                    y += 10;
                <?php endforeach; ?>

                doc.save(`analysis_<?php echo htmlspecialchars($year); ?>_<?php echo htmlspecialchars($term); ?>_${Date.now()}.pdf`);
            }
        });
    </script>
</body>
</html>