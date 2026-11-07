<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

// Check database connection
if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = $_SESSION['user_id'];

// Validate teacher exists
$teacher_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Teacher not found for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed View Class', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed view class page for class_id: " . ($_GET['id'] ?? 'unset');
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();

// Validate class_id
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    error_log("Invalid or missing class_id for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Invalid class ID.";
    header("Location: /teacher/view_classes.php");
    exit();
}

$class_id = (int)$_GET['id'];

// Fetch class details
$class_sql = "
    SELECT c.id, c.class_name, c.department_id, sc.academic_year AS year
    FROM classes c
    LEFT JOIN student_classes sc ON c.id = sc.class_id
    WHERE c.id = ? AND c.teacher_id = ?
    GROUP BY c.id";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param("ii", $class_id, $teacher_id);
$class_stmt->execute();
$class = $class_stmt->get_result()->fetch_assoc();
$class_stmt->close();

if (!$class) {
    error_log("Class not found or unauthorized for class_id: $class_id, user_id: $teacher_id");
    $_SESSION['error_msg'] = "Class not found or you are not authorized.";
    header("Location: /teacher/view_classes.php");
    exit();
}

// Get current term
$term_sql = "
    SELECT id AS term_id, term_name
    FROM academic_calendar
    WHERE year = ? AND start_date <= CURDATE() AND end_date >= CURDATE()
    LIMIT 1";
$term_stmt = $conn->prepare($term_sql);
$term_stmt->bind_param("s", $class['year']);
$term_stmt->execute();
$term = $term_stmt->get_result()->fetch_assoc();
$term_stmt->close();

$class['term_id'] = $term ? $term['term_id'] : null;
$class['term_name'] = $term ? $term['term_name'] : 'N/A';

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();

// Fetch student count
$student_count_sql = "SELECT COUNT(*) as student_count 
                     FROM student_classes 
                     WHERE class_id = ? AND academic_year = ?";
$student_count_stmt = $conn->prepare($student_count_sql);
$student_count_stmt->bind_param("is", $class_id, $class['year']);
$student_count_stmt->execute();
$student_count = $student_count_stmt->get_result()->fetch_assoc()['student_count'];
$student_count_stmt->close();

// Fetch students
$students_sql = "
    SELECT u.id, u.name, u.email 
    FROM student_classes sc 
    JOIN users u ON sc.user_id = u.id 
    WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
    ORDER BY u.name";
$students_stmt = $conn->prepare($students_sql);
$students_stmt->bind_param("is", $class_id, $class['year']);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

// Fetch class schedule
$schedule_sql = "
    SELECT s.subject_name, ac.term_name, cs.day_of_week, cs.start_time, cs.end_time
    FROM class_schedules cs 
    JOIN subjects s ON cs.subject_id = s.id 
    JOIN academic_calendar ac ON cs.term_id = ac.id 
    WHERE cs.class_id = ? 
    ORDER BY cs.day_of_week, cs.start_time";
$schedule_stmt = $conn->prepare($schedule_sql);
$schedule_stmt->bind_param("i", $class_id);
$schedule_stmt->execute();
$schedule = $schedule_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$schedule_stmt->close();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View details of a specific class for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, teacher, class details, students, schedule">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Class - QueensPa School">
    <meta property="og:description" content="Teacher interface to view class details, students, and schedules.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/view_class.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Class - QueensPa School">
    <meta name="twitter:description" content="Teacher interface to view class details, students, and schedules.">
    <title>Class Details - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --hover-color: #e03e00;
            --secondary-color: #ff6b00;
            --secondary-hover: #e05a00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #fff3e0);
            color: var(--text-color);
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
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 6px;
            color: white;
            transition: background-color 0.3s ease;
            font-size: 0.875rem;
            padding: 6px 12px;
        }
        .btn-primary:hover {
            background-color: var(--hover-color);
            border-color: var(--hover-color);
            animation: animate__pulse 0.5s;
        }
        .btn-primary:focus, .btn-primary:active {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 0.2rem rgba(255, 69, 0, 0.25) !important;
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            border-radius: 6px;
            color: white;
            transition: background-color 0.3s ease;
            font-size: 0.875rem;
        }
        .btn-secondary:hover {
            background-color: var(--secondary-hover);
            border-color: var(--secondary-hover);
        }
        .btn-secondary:focus, .btn-secondary:active {
            background-color: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 0, 0.25) !important;
        }
        .form-select, .form-control {
            border-radius: 6px;
            border-color: var(--primary-color);
            color: var(--text-color);
            font-size: 0.875rem;
        }
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 69, 0, 0.25);
        }
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
        }
        .table thead {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .table td, .table th {
            vertical-align: middle;
        }
        .profile-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        .quick-links .btn {
            min-width: 150px;
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            font-size: 0.875rem;
        }
        .quick-links .btn:hover {
            background-color: var(--hover-color);
            border-color: var(--hover-color);
        }
        .stat-card {
            background: #fff;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        .alert {
            border-radius: 6px;
            border-color: var(--primary-color);
            background-color: #fff3e0;
            color: var(--text-color);
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
            .table-responsive {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <?php
    try {
        include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    } catch (Exception $e) {
        error_log("Failed to include teacher_header.php: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>
    <div class="content" id="content">
        <main class="container my-4">
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger animate__animated animate__fadeIn" id="error-alert"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
            <?php endif; ?>
            <section class="card mb-4 animate__animated animate__fadeInDown">
                <div class="card-header d-flex align-items-center">
                    <img src="/Uploads/profiles/default.jpg" alt="Teacher Profile Picture" class="profile-img me-3">
                    <h3 class="mb-0">Class Details: <?php echo htmlspecialchars($class['class_name']); ?></h3>
                    <a href="edit_user.php?id=<?php echo $teacher_id; ?>" class="btn btn-secondary btn-sm ms-auto" data-bs-toggle="tooltip" title="Edit your profile"><i class="fas fa-edit"></i> Edit Profile</a>
                </div>
            </section>
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Class Information</h3>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6 stat-card">
                            <h5>Class Name</h5>
                            <p><?php echo htmlspecialchars($class['class_name']); ?></p>
                        </div>
                        <div class="col-md-6 stat-card">
                            <h5>Term</h5>
                            <p><?php echo htmlspecialchars($class['term_name']); ?></p>
                        </div>
                        <div class="col-md-6 stat-card">
                            <h5>Academic Year</h5>
                            <p><?php echo htmlspecialchars($class['year'] ?: 'N/A'); ?></p>
                        </div>
                        <div class="col-md-6 stat-card">
                            <h5>Students Enrolled</h5>
                            <p><?php echo $student_count; ?></p>
                        </div>
                    </div>
                </div>
            </section>
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Enrolled Students</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($students)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No students enrolled in <?php echo htmlspecialchars($class['class_name']); ?>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="view_student.php?id=<?php echo $student['id']; ?>" class="btn btn-primary" data-bs-toggle="tooltip" title="View Profile"><i class="fas fa-user"></i></a>
                                                    <a href="submit_grades.php?class_id=<?php echo $class['id']; ?>&student_id=<?php echo $student['id']; ?>" class="btn btn-primary" data-bs-toggle="tooltip" title="Submit Grades"><i class="fas fa-chart-line"></i></a>
                                                    <a href="mark_attendance.php?class_id=<?php echo $class['id']; ?>&student_id=<?php echo $student['id']; ?>" class="btn btn-primary" data-bs-toggle="tooltip" title="Mark Attendance"><i class="fas fa-check-circle"></i></a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Class Schedule</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($schedule)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No schedule available for <?php echo htmlspecialchars($class['class_name']); ?>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Term</th>
                                        <th>Day</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedule as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['term_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['day_of_week']); ?></td>
                                            <td><?php echo htmlspecialchars($item['start_time'] . ' - ' . $item['end_time']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="d-flex flex-wrap quick-links gap-2 mb-4 animate__animated animate__fadeInUp">
                <a href="dashboard.php" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Return to dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="view_classes.php" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="View all classes"><i class="fas fa-chalkboard"></i> View Classes</a>
                <a href="submit_grades.php?class_id=<?php echo $class['id']; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Submit grades for this class"><i class="fas fa-chart-line"></i> Submit Grades</a>
                <a href="manage_assignments.php?class_id=<?php echo $class['id']; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Manage assignments"><i class="fas fa-tasks"></i> Manage Assignments</a>
                <a href="manage_attendance.php?class_id=<?php echo $class['id']; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="Manage attendance"><i class="fas fa-users"></i> Manage Attendance</a>
                <a href="view_evaluations.php?teacher_id=<?php echo $teacher_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0" data-bs-toggle="tooltip" title="View your evaluations"><i class="fas fa-star"></i> View Evaluations</a>
            </section>
        </main>
    </div>
    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    try {
        if (!file_exists($footer_path)) {
            throw new Exception("Teacher footer file missing: $footer_path");
        }
        include $footer_path;
    } catch (Exception $e) {
        error_log($e->getMessage());
        $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                    VALUES (?, 'Error', ?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $error_desc = "Failed to load teacher footer template at $footer_path";
        $log_stmt->bind_param("isss", $teacher_id, $error_desc, $ip_address, $user_agent);
        $log_stmt->execute();
        $log_stmt->close();
        $_SESSION['error_msg'] = "System error: Unable to load page footer.";
        header("Location: /login.php");
        exit();
    }
    mysqli_close($conn);
    ob_end_flush();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove('animate__fadeIn');
                    alert.classList.add('animate__fadeOut');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>