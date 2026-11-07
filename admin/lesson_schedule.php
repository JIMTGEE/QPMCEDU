<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an admin.";
    header("Location: /admin/login.php");
    exit();
}

// Include database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /admin/login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /admin/login.php");
    exit();
}

$admin_id = (int)$_SESSION['user_id'];

// Validate admin exists
$admin_sql = "SELECT id, name FROM users WHERE id = ? AND role = 'admin'";
$admin_stmt = $conn->prepare($admin_sql);
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

if (!$admin) {
    error_log("Admin not found for user_id: $admin_id");
    $_SESSION['error_msg'] = "Admin account not found.";
    header("Location: /admin/login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Lesson Schedule Oversight Page', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Admin accessed lesson schedule oversight page";
$log_stmt->bind_param("isss", $admin_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log page access: " . $log_stmt->error);
}
$log_stmt->close();

// Fetch all teachers
$teachers_sql = "SELECT id, name, email, department_id FROM users WHERE role = 'teacher' ORDER BY name LIMIT 100";
$teachers_stmt = $conn->prepare($teachers_sql);
$teachers_stmt->execute();
$teachers = $teachers_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$teachers_stmt->close();

// Fetch departments for reference
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Create department lookup
$dept_lookup = [];
foreach ($departments as $dept) {
    $dept_lookup[$dept['id']] = $dept['name'];
}

// Fetch all active terms
$terms_sql = "SELECT id, term_name FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() ORDER BY term_name";
$terms_stmt = $conn->prepare($terms_sql);
$terms_stmt->execute();
$terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$terms_stmt->close();

// Create term lookup
$term_lookup = [];
foreach ($terms as $term) {
    $term_lookup[$term['id']] = $term['term_name'];
}

// Handle teacher selection (via GET parameter)
$selected_teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$selected_teacher = null;
$schedules = [];
$lesson_plans = [];

if ($selected_teacher_id > 0) {
    // Validate selected teacher
    $teacher_check_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher'";
    $teacher_check_stmt = $conn->prepare($teacher_check_sql);
    $teacher_check_stmt->bind_param("i", $selected_teacher_id);
    $teacher_check_stmt->execute();
    $selected_teacher = $teacher_check_stmt->get_result()->fetch_assoc();
    $teacher_check_stmt->close();

    if ($selected_teacher) {
        // Fetch schedules for the selected teacher
        $schedules_sql = "SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time, 
                                 c.class_name, s.subject_name, cs.term_id, d.name AS department_name,
                                 cs.class_id, cs.subject_id, cs.department_id
                          FROM class_schedules cs
                          JOIN classes c ON cs.class_id = c.id
                          LEFT JOIN subjects s ON cs.subject_id = s.id
                          LEFT JOIN departments d ON cs.department_id = d.id
                          WHERE cs.teacher_id = ?
                          ORDER BY cs.day_of_week, cs.start_time";
        $schedules_stmt = $conn->prepare($schedules_sql);
        $schedules_stmt->bind_param("i", $selected_teacher_id);
        $schedules_stmt->execute();
        $schedules = $schedules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $schedules_stmt->close();

        // Fetch lesson plans (teacher_notes) for the selected teacher
        $plans_sql = "SELECT tn.id, tn.week, c.class_name, ac.term_name, s.subject_name,
                             tn.strand, tn.sub_strand, tn.content_standard, tn.indicator,
                             tn.created_at
                      FROM teacher_notes tn
                      JOIN classes c ON tn.class_id = c.id
                      JOIN academic_calendar ac ON tn.term_id = ac.id
                      LEFT JOIN subjects s ON tn.subject_id = s.id
                      WHERE tn.teacher_id = ?
                      ORDER BY tn.created_at DESC LIMIT 100";
        $plans_stmt = $conn->prepare($plans_sql);
        $plans_stmt->bind_param("i", $selected_teacher_id);
        $plans_stmt->execute();
        $lesson_plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $plans_stmt->close();
    }
}

// Handle lesson plan view (if plan_id is set)
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
$plan = null;
if ($plan_id > 0) {
    $plan_sql = "SELECT tn.*, u.name AS teacher_name, c.class_name, ac.term_name, s.subject_name, d.name AS department_name
                 FROM teacher_notes tn
                 JOIN users u ON tn.teacher_id = u.id
                 JOIN classes c ON tn.class_id = c.id
                 JOIN academic_calendar ac ON tn.term_id = ac.id
                 LEFT JOIN subjects s ON tn.subject_id = s.id
                 LEFT JOIN departments d ON u.department_id = d.id
                 WHERE tn.id = ?";
    $plan_stmt = $conn->prepare($plan_sql);
    $plan_stmt->bind_param("i", $plan_id);
    $plan_stmt->execute();
    $plan = $plan_stmt->get_result()->fetch_assoc();
    $plan_stmt->close();

    if ($plan) {
        // Log view access
        $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                    VALUES (?, 'Viewed Lesson Plan', ?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $log_desc = "Admin viewed lesson plan ID: $plan_id";
        $log_stmt->bind_param("isss", $admin_id, $log_desc, $ip_address, $user_agent);
        $log_stmt->execute();
        $log_stmt->close();
    } else {
        $_SESSION['error_msg'] = "Lesson plan not found.";
    }
}

// Days of the week for timetable
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Group schedules by day for timetable view
$schedules_by_day = [];
$max_periods = 0;
foreach ($schedules as $schedule) {
    $day = $schedule['day_of_week'];
    if (!isset($schedules_by_day[$day])) {
        $schedules_by_day[$day] = [];
    }
    $schedules_by_day[$day][] = $schedule;
    $max_periods = max($max_periods, count($schedules_by_day[$day]));
}
foreach ($schedules_by_day as $day => &$day_schedules) {
    usort($day_schedules, function($a, $b) {
        return strcmp($a['start_time'], $b['start_time']);
    });
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin oversight for teachers' lesson plans and schedules at QueensPa School.">
    <meta name="keywords" content="QueensPa School, admin portal, lesson plans, schedules">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Admin Lesson Schedule Oversight">
    <meta property="og:description" content="Admin interface for monitoring teachers' schedules and lesson plans in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpms.com/admin/lesson_schedule.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Admin Lesson Schedule Oversight">
    <meta name="twitter:description" content="Admin interface for monitoring teachers' schedules and lesson plans in QueensPa School.">
    <title>Lesson Schedule Oversight - QueensPa School Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --secondary-color: #6c757d;
            --accent-color: #007bff;
            --text-color: #212529;
            --bg-color: #f8f9fa;
            --shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            --border-radius: 0.75rem;
            --transition: all 0.3s ease;
        }
        body {
            background: linear-gradient(135deg, var(--bg-color), #e9ecef);
            color: var(--text-color);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1440px;
            padding: 2.5rem 1.5rem;
            margin: 0 auto;
        }
        .card {
            border: none;
            border-radius: var(--border-radius);
            background: #fff;
            box-shadow: var(--shadow);
            transition: var(--transition);
            margin-bottom: 2.5rem;
            overflow: hidden;
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 1.5rem 3rem rgba(0, 0, 0, 0.2);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            padding: 1.75rem;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }
        .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.05);
            pointer-events: none;
        }
        .card-header h2, .card-header h3 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            z-index: 1;
        }
        .section-header {
            background: linear-gradient(to right, var(--bg-color), #e9ecef);
            padding: 1.25rem;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            font-size: 1.3rem;
            color: var(--primary-color);
        }
        .detail-pair {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1rem;
            margin-bottom: 1.25rem;
            padding: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
            transition: var(--transition);
        }
        .detail-pair:hover {
            background: rgba(0, 0, 0, 0.02);
        }
        .detail-label {
            font-weight: 600;
            color: var(--text-color);
        }
        .detail-value {
            word-break: break-word;
            color: #495057;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: var(--border-radius);
            padding: 0.75rem 1.75rem;
            font-weight: 500;
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            border-radius: var(--border-radius);
            padding: 0.75rem 1.75rem;
            transition: var(--transition);
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
            transform: translateY(-2px);
        }
        .btn-accent {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            border-radius: var(--border-radius);
            padding: 0.5rem 1rem;
            transition: var(--transition);
        }
        .btn-accent:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        .table {
            border-radius: var(--border-radius);
            overflow: hidden;
            margin-bottom: 0;
            background: #fff;
        }
        .table th {
            background: var(--primary-color);
            color: #fff;
            padding: 1.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .table td {
            padding: 1.25rem;
            vertical-align: middle;
            transition: var(--transition);
        }
        .table tr:hover {
            background: rgba(0, 0, 0, 0.05);
        }
        .timetable-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            padding: 1rem 0;
        }
        .day-card {
            background: #fff;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .day-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 4px;
            background: var(--primary-color);
            transition: var(--transition);
        }
        .day-card:hover::before {
            left: 0;
        }
        .day-card:hover {
            transform: scale(1.03);
        }
        .day-card h6 {
            color: var(--primary-color);
            margin-bottom: 1.25rem;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .period-card {
            background: var(--bg-color);
            border-radius: var(--border-radius);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.08);
            transition: var(--transition);
        }
        .period-card:hover {
            background: #e9ecef;
        }
        .period-card strong {
            display: block;
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
        }
        .alert {
            border-radius: var(--border-radius);
            padding: 1.25rem;
            position: relative;
            animation: slideIn 0.5s ease;
        }
        .form-select {
            border-radius: var(--border-radius);
            padding: 0.75rem 1.5rem;
            font-size: 1.1rem;
            transition: var(--transition);
            border: 2px solid #dee2e6;
        }
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 69, 0, 0.25);
        }
        .print-btn {
            cursor: pointer;
            padding: 0.5rem 1.5rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }
        .print-btn:hover {
            background: var(--primary-hover);
            color: #fff;
        }
        .back-btn {
            font-size: 0.9rem;
        }
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @media (max-width: 768px) {
            .timetable-grid {
                grid-template-columns: 1fr;
            }
            .detail-pair {
                grid-template-columns: 1fr;
            }
            .detail-label, .detail-value {
                width: 100%;
            }
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .card {
                box-shadow: none;
                border: none;
            }
            .card-header {
                background: none;
                color: #000;
            }
            .section-header {
                background: none;
                border-bottom: 1px solid #000;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($plan && $plan_id > 0): ?>
            <section class="card animate__animated animate__fadeIn">
                <div class="card-header">
                    <h2>Lesson Plan Details</h2>
                    <div class="no-print">
                        <button class="btn btn-light print-btn" onclick="window.print();">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="section-header">Basic Information</div>
                    <div class="detail-pair">
                        <span class="detail-label">Teacher:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($plan['teacher_name']); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Department:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($plan['department_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Class:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($plan['class_name']); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Subject:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Term:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($plan['term_name']); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Week:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($plan['week']); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Created At:</span>
                        <span class="detail-value"><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($plan['created_at']))); ?></span>
                    </div>

                    <div class="section-header">Curriculum Details</div>
                    <div class="detail-pair">
                        <span class="detail-label">Strand:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['strand'] ?? 'N/A')); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Sub-Strand:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['sub_strand'] ?? 'N/A')); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Content Standard:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['content_standard'] ?? 'N/A')); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Indicator:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['indicator'] ?? 'N/A')); ?></span>
                    </div>

                    <div class="section-header">Lesson Details</div>
                    <div class="detail-pair">
                        <span class="detail-label">Objectives:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['objectives'])); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Reference:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['reference'])); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Resources:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['resources'])); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Keywords:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['keywords'])); ?></span>
                    </div>

                    <div class="section-header">Lesson Delivery</div>
                    <div class="detail-pair">
                        <span class="detail-label">Starter:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['starter'])); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Presentation:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['presentation'])); ?></span>
                    </div>
                    <div class="detail-pair">
                        <span class="detail-label">Summary:</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($plan['summary'])); ?></span>
                    </div>
                </div>
                <div class="card-footer no-print d-flex justify-content-end gap-3">
                    <a href="?teacher_id=<?php echo $plan['teacher_id']; ?>" class="btn btn-secondary back-btn">Back to Schedules</a>
                </div>
            </section>
        <?php else: ?>
            <section class="card mb-5 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h2>Teachers' Lesson Schedules Oversight</h2>
                    <div class="no-print">
                        <a href="/admin/dashboard.php" class="btn btn-light"><i class="fas fa-home"></i> Dashboard</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($teachers)): ?>
                        <div class="alert alert-info" role="alert">No teachers found in the system.</div>
                    <?php else: ?>
                        <div class="mb-4">
                            <h5 class="mb-3">Select Teacher to View Details</h5>
                            <select class="form-select form-select-lg" id="teacher-select" onchange="window.location.href = '?teacher_id=' + this.value;">
                                <option value="">Select a Teacher</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>" <?php echo $teacher['id'] == $selected_teacher_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['name']) . ' (' . htmlspecialchars($dept_lookup[$teacher['department_id']] ?? 'N/A') . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($selected_teacher): ?>
                        <h4 class="mb-4 animate__animated animate__fadeIn">Details for <?php echo htmlspecialchars($selected_teacher['name']); ?></h4>

                        <!-- Schedules Section -->
                        <div class="mb-5">
                            <h5 class="mb-3">Schedules</h5>
                            <?php if (empty($schedules)): ?>
                                <div class="alert alert-warning" role="alert">No schedules found for this teacher.</div>
                            <?php else: ?>
                                <div class="timetable-grid">
                                    <?php foreach ($days_of_week as $day): ?>
                                        <div class="day-card animate__animated animate__fadeInUp">
                                            <h6><?php echo $day; ?></h6>
                                            <?php if (isset($schedules_by_day[$day])): ?>
                                                <?php foreach ($schedules_by_day[$day] as $schedule): ?>
                                                    <div class="period-card">
                                                        <strong><?php echo htmlspecialchars($schedule['start_time'] . ' - ' . $schedule['end_time']); ?></strong>
                                                        <p class="mb-1">Class: <?php echo htmlspecialchars($schedule['class_name']); ?></p>
                                                        <p class="mb-1">Subject: <?php echo htmlspecialchars($schedule['subject_name'] ?? 'N/A'); ?></p>
                                                        <p class="mb-1">Term: <?php echo htmlspecialchars($term_lookup[$schedule['term_id']] ?? 'N/A'); ?></p>
                                                        <p class="mb-0">Dept: <?php echo htmlspecialchars($schedule['department_name'] ?? 'N/A'); ?></p>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <p class="text-muted">No periods scheduled.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Lesson Plans Section -->
                        <div>
                            <h5 class="mb-3">Lesson Plans</h5>
                            <?php if (empty($lesson_plans)): ?>
                                <div class="alert alert-warning" role="alert">No lesson plans submitted by this teacher.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Week</th>
                                                <th>Class</th>
                                                <th>Subject</th>
                                                <th>Term</th>
                                                <th>Strand</th>
                                                <th>Sub-Strand</th>
                                                <th>Content Standard</th>
                                                <th>Indicator</th>
                                                <th>Created At</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lesson_plans as $plan): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($plan['week']); ?></td>
                                                    <td><?php echo htmlspecialchars($plan['class_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($plan['term_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($plan['strand'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($plan['sub_strand'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($plan['content_standard'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($plan['indicator'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></td>
                                                    <td>
                                                        <a href="?plan_id=<?php echo $plan['id']; ?>&teacher_id=<?php echo $selected_teacher_id; ?>" class="btn btn-accent btn-sm" aria-label="View Lesson Plan" data-bs-toggle="tooltip" title="View Lesson Plan">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; // Assuming there's an admin footer ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            // Smooth scroll to section
            document.querySelectorAll('a[href^="?"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = this.href;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            });

            // Add active class to selected teacher
            const teacherSelect = document.getElementById('teacher-select');
            if (teacherSelect) {
                teacherSelect.addEventListener('change', () => {
                    teacherSelect.classList.add('animate__animated', 'animate__pulse');
                    setTimeout(() => {
                        teacherSelect.classList.remove('animate__animated', 'animate__pulse');
                    }, 1000);
                });
            }
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>