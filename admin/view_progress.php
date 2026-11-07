<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to view_progress.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    header("Location: ../login.php");
    exit();
}

// Include database connection and logging function
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    $error_msg = "Connection failed. Please try again later or contact support.";
} else {
    // Initialize variables
    $success_msg = '';
    $error_msg = '';
    $student_id = intval($_GET['id'] ?? 0);
    $student_name = '';
    $class_id = 0;
    $subjects = [];
    $weekly_progress = [];
    $monthly_progress = [];
    $class_averages = [];
    $lesson_plans = [];
    $graph_data = ['labels' => [], 'student_scores' => [], 'class_averages' => []];

    if ($student_id <= 0) {
        $error_msg = "Invalid student ID.";
    } else {
        // Fetch student details
        $student_sql = "
            SELECT u.name, sc.class_id
            FROM users u
            JOIN student_classes sc ON u.id = sc.user_id
            WHERE u.id = ? AND u.role = 'student'";
        $student_stmt = $conn->prepare($student_sql);
        $student_stmt->bind_param("i", $student_id);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        if ($student_result->num_rows === 0) {
            $error_msg = "Student not found.";
        } else {
            $student_data = $student_result->fetch_assoc();
            $student_name = $student_data['name'];
            $class_id = $student_data['class_id'];
        }
        $student_stmt->close();

        if ($class_id > 0) {
            // Fetch subjects for the class
            $subjects_sql = "
                SELECT DISTINCT s.id, s.subject_name
                FROM subjects s
                JOIN class_schedules cs ON s.id = cs.subject_id
                WHERE cs.class_id = ?
                ORDER BY s.subject_name ASC";
            $subjects_stmt = $conn->prepare($subjects_sql);
            $subjects_stmt->bind_param("i", $class_id);
            $subjects_stmt->execute();
            $subjects_result = $subjects_stmt->get_result();
            while ($row = $subjects_result->fetch_assoc()) {
                $subjects[] = $row;
            }
            $subjects_stmt->close();

            // Fetch weekly progress
            $weekly_sql = "
                SELECT subject_id, period_start_date, AVG(total_scaled) as avg_score
                FROM academic_progress_entries
                WHERE student_id = ?
                GROUP BY subject_id, period_start_date
                ORDER BY period_start_date DESC";
            $weekly_stmt = $conn->prepare($weekly_sql);
            $weekly_stmt->bind_param("i", $student_id);
            $weekly_stmt->execute();
            $weekly_result = $weekly_stmt->get_result();
            while ($row = $weekly_result->fetch_assoc()) {
                $weekly_progress[$row['subject_id']][] = $row;
            }
            $weekly_stmt->close();

            // Fetch monthly progress
            $monthly_sql = "
                SELECT subject_id, DATE_FORMAT(period_start_date, '%Y-%m') as month, AVG(total_scaled) as avg_score
                FROM academic_progress_entries
                WHERE student_id = ?
                GROUP BY subject_id, month
                ORDER BY month DESC";
            $monthly_stmt = $conn->prepare($monthly_sql);
            $monthly_stmt->bind_param("i", $student_id);
            $monthly_stmt->execute();
            $monthly_result = $monthly_stmt->get_result();
            while ($row = $monthly_result->fetch_assoc()) {
                $monthly_progress[$row['subject_id']][] = $row;
            }
            $monthly_stmt->close();

            // Fetch class averages for comparison
            $class_avg_sql = "
                SELECT subject_id, AVG(total_scaled) as class_avg
                FROM academic_progress_entries
                WHERE class_id = ?
                GROUP BY subject_id";
            $class_avg_stmt = $conn->prepare($class_avg_sql);
            $class_avg_stmt->bind_param("i", $class_id);
            $class_avg_stmt->execute();
            $class_avg_result = $class_avg_stmt->get_result();
            while ($row = $class_avg_result->fetch_assoc()) {
                $class_averages[$row['subject_id']] = $row['class_avg'];
            }
            $class_avg_stmt->close();

            // Fetch lesson plans for the class during the week
            $lesson_plans_sql = "
                SELECT lp.id, lp.topic, lp.objectives, lp.resources, lp.lesson_date, u.name AS teacher_name
                FROM lesson_plans lp
                JOIN users u ON lp.teacher_id = u.id
                WHERE lp.class_id = ? AND lp.lesson_date BETWEEN ? AND ?
                ORDER BY lp.lesson_date ASC";
            $lesson_plans_stmt = $conn->prepare($lesson_plans_sql);
            $start_week = '2025-10-13'; // Example week start
            $end_week = '2025-10-19'; // Example week end
            $lesson_plans_stmt->bind_param("iss", $class_id, $start_week, $end_week);
            $lesson_plans_stmt->execute();
            $lesson_plans_result = $lesson_plans_stmt->get_result();
            while ($row = $lesson_plans_result->fetch_assoc()) {
                $lesson_plans[] = $row;
            }
            $lesson_plans_stmt->close();

            // Prepare graph data (weekly scores)
            $graph_labels = [];
            $student_scores = [];
            $class_scores = [];
            // Assuming we take the last 4 weeks for graph
            $recent_weeks_sql = "
                SELECT DISTINCT period_start_date
                FROM academic_progress_entries
                WHERE student_id = ?
                ORDER BY period_start_date DESC
                LIMIT 4";
            $recent_weeks_stmt = $conn->prepare($recent_weeks_sql);
            $recent_weeks_stmt->bind_param("i", $student_id);
            $recent_weeks_stmt->execute();
            $recent_weeks_result = $recent_weeks_stmt->get_result();
            while ($row = $recent_weeks_result->fetch_assoc()) {
                $graph_labels[] = $row['period_start_date'];
            }
            $recent_weeks_stmt->close();

            // Fetch student and class scores for graph
            foreach ($graph_labels as $week) {
                $student_week_sql = "
                    SELECT AVG(total_scaled) as avg_score
                    FROM academic_progress_entries
                    WHERE student_id = ? AND period_start_date = ?";
                $student_week_stmt = $conn->prepare($student_week_sql);
                $student_week_stmt->bind_param("is", $student_id, $week);
                $student_week_stmt->execute();
                $student_scores[] = $student_week_stmt->get_result()->fetch_assoc()['avg_score'] ?? 0;
                $student_week_stmt->close();

                $class_week_sql = "
                    SELECT AVG(total_scaled) as avg_score
                    FROM academic_progress_entries
                    WHERE class_id = ? AND period_start_date = ?";
                $class_week_stmt = $conn->prepare($class_week_sql);
                $class_week_stmt->bind_param("is", $class_id, $week);
                $class_week_stmt->execute();
                $class_scores[] = $class_week_stmt->get_result()->fetch_assoc()['avg_score'] ?? 0;
                $class_week_stmt->close();
            }

            $graph_data = [
                'labels' => $graph_labels,
                'student_scores' => $student_scores,
                'class_scores' => $class_scores
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View student progress in QueensPa School, including scores, comparisons, and lesson plans.">
    <meta name="keywords" content="QueensPa School, student progress, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Student Progress">
    <meta property="og:description" content="Admin interface for viewing student progress in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/view_progress.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Student Progress">
    <meta name="twitter:description" content="Admin interface for viewing student progress in QueensPa School.">
    <title>View Progress - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://www.w3schools.com/w3css/4/w3.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            background: linear-gradient(to right, var(--primary-color), --secondary-color);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-success, .btn-secondary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-success:hover, .btn-secondary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .form-select {
            border-radius: 8px;
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
        .quick-links .btn {
            min-width: 150px;
        }
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
            margin: 0 auto;
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
        .summary-card {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
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
        }
    </style>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>
    <div class="content" id="content">
        <main class="container my-4">
            <h1 class="mb-4 text-center animate__animated animate__fadeIn">📊 Student Progress - <?= htmlspecialchars($student_name) ?></h1>

            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger mb-4">
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <!-- Weekly Progress -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Weekly Progress</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Week Start</th>
                                    <th>Student Avg Score</th>
                                    <th>Class Avg Score</th>
                                    <th>Comparison</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects as $subject): ?>
                                    <?php if (isset($weekly_progress[$subject['id']])): ?>
                                        <?php foreach ($weekly_progress[$subject['id']] as $week): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                                <td><?= htmlspecialchars($week['period_start_date']) ?></td>
                                                <td><?= round($week['avg_score'], 2) ?></td>
                                                <td><?= round($class_averages[$subject['id']] ?? 0, 2) ?></td>
                                                <td><?= $week['avg_score'] > ($class_averages[$subject['id']] ?? 0) ? 'Above Average' : 'Below Average' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                            <td colspan="4" class="text-center">No data</td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Monthly Progress -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Monthly Progress</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Month</th>
                                    <th>Student Avg Score</th>
                                    <th>Class Avg Score</th>
                                    <th>Comparison</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects as $subject): ?>
                                    <?php if (isset($monthly_progress[$subject['id']])): ?>
                                        <?php foreach ($monthly_progress[$subject['id']] as $month): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                                <td><?= htmlspecialchars($month['month']) ?></td>
                                                <td><?= round($month['avg_score'], 2) ?></td>
                                                <td><?= round($class_averages[$subject['id']] ?? 0, 2) ?></td>
                                                <td><?= $month['avg_score'] > ($class_averages[$subject['id']] ?? 0) ? 'Above Average' : 'Below Average' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                            <td colspan="4" class="text-center">No data</td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Lesson Plans Cross-Reference -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Lesson Plans for the Week</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead>
                                <tr>
                                    <th>Topic</th>
                                    <th>Objectives</th>
                                    <th>Resources</th>
                                    <th>Date</th>
                                    <th>Teacher</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($lesson_plans)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No lesson plans found for this week.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($lesson_plans as $plan): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($plan['topic']) ?></td>
                                            <td><?= htmlspecialchars($plan['objectives']) ?></td>
                                            <td><?= htmlspecialchars($plan['resources']) ?></td>
                                            <td><?= htmlspecialchars($plan['lesson_date']) ?></td>
                                            <td><?= htmlspecialchars($plan['teacher_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Linear Graph -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Progress Graph (Last 4 Weeks)</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="progressChart"></canvas>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>

    <script>
        const graphData = <?= json_encode($graph_data) ?>;
        const ctx = document.getElementById('progressChart').getContext('2d');
        const progressChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: graphData.labels,
                datasets: [{
                    label: 'Student Scores',
                    data: graphData.student_scores,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.2)',
                    fill: true
                }, {
                    label: 'Class Averages',
                    data: graphData.class_scores,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>