<?php
ob_start();

// Start session only if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify teacher authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher to access this page.";
    header("Location: /login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "Database connection failed. Please try again later.";
    header("Location: /login.php");
    exit();
}

// Fetch teacher details
$teacher_id = $_SESSION['user_id'];
$teacher_sql = "SELECT name, profile_picture FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();
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
            VALUES (?, 'Accessed Mark Attendance', 'Teacher accessed mark attendance page', ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_stmt->bind_param("iss", $teacher_id, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();

// Validate POST data
if (!isset($_POST['class_id']) || !isset($_POST['academic_year'])) {
    $_SESSION['error_msg'] = "Invalid request. Please select a class.";
    header("Location: manage_attendance.php");
    exit();
}
$class_id = (int)$_POST['class_id'];
$academic_year = $_POST['academic_year'];

// Validate academic year
$academic_years_sql = "SELECT DISTINCT year FROM academic_calendar";
$academic_years_stmt = $conn->prepare($academic_years_sql);
$academic_years_stmt->execute();
$academic_years_result = $academic_years_stmt->get_result();
$academic_years = [];
while ($row = $academic_years_result->fetch_assoc()) {
    $academic_years[] = $row['year'];
}
$academic_years_stmt->close();
if (!in_array($academic_year, $academic_years)) {
    error_log("Invalid academic year: $academic_year");
    $_SESSION['error_msg'] = "Invalid academic year selected.";
    header("Location: manage_attendance.php");
    exit();
}

// Verify teacher is assigned to the class
$class_sql = "
    SELECT c.class_name
    FROM classes c
    LEFT JOIN class_schedules cs ON c.id = cs.class_id
    LEFT JOIN academic_calendar ac ON cs.term_id = ac.id
    WHERE c.id = ? AND (c.teacher_id = ? OR cs.teacher_id = ?) AND (ac.year = ? OR ac.year IS NULL)";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param("iiis", $class_id, $teacher_id, $teacher_id, $academic_year);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
$class = $class_result->fetch_assoc();
$class_stmt->close();
if (!$class) {
    error_log("Teacher $teacher_id not authorized for class_id: $class_id");
    $_SESSION['error_msg'] = "You are not authorized to mark attendance for this class.";
    header("Location: manage_attendance.php");
    exit();
}
$class_name = $class['class_name'];

// Handle form submission
$success_msg = '';
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = $_POST['attendance_date'] ?? date('Y-m-d');
    $statuses = $_POST['status'] ?? [];
    $student_ids = $_POST['student_id'] ?? [];

    // Validate date
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $attendance_date)) {
        $error_msg = "Invalid date format.";
    } else {
        // Verify date is within academic year
        $date_sql = "SELECT id FROM academic_calendar WHERE year = ? AND start_date <= ? AND end_date >= ?";
        $date_stmt = $conn->prepare($date_sql);
        $date_stmt->bind_param("sss", $academic_year, $attendance_date, $attendance_date);
        $date_stmt->execute();
        $date_result = $date_stmt->get_result();
        if (!$date_result->fetch_assoc()) {
            $error_msg = "Selected date is outside the academic year.";
        }
        $date_stmt->close();
    }

    if (empty($error_msg) && count($statuses) !== count($student_ids)) {
        $error_msg = "Invalid attendance data submitted.";
    }

    if (empty($error_msg)) {
        $conn->begin_transaction();
        try {
            $insert_sql = "INSERT INTO attendance (user_id, class_id, date, status, created_at) 
                           VALUES (?, ?, ?, ?, NOW()) 
                           ON DUPLICATE KEY UPDATE status = ?";
            $insert_stmt = $conn->prepare($insert_sql);
            $valid_statuses = ['present', 'absent'];

            foreach ($student_ids as $index => $student_id) {
                $status = $statuses[$index] ?? 'absent';
                if (!in_array($status, $valid_statuses)) {
                    $status = 'absent';
                }
                $insert_stmt->bind_param("iisss", $student_id, $class_id, $attendance_date, $status, $status);
                $insert_stmt->execute();
            }
            $insert_stmt->close();

            $conn->commit();

            // Log successful submission
            $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                        VALUES (?, 'Marked Attendance', ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $description = "Teacher marked attendance for class_id: $class_id on $attendance_date";
            $log_stmt->bind_param("isss", $teacher_id, $description, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();

            $success_msg = "Attendance successfully recorded for $class_name on $attendance_date.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Failed to save attendance: " . $e->getMessage();
            error_log("Attendance save error: " . $e->getMessage());
            $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                        VALUES (?, 'Error', ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $description = "Failed to mark attendance for class_id: $class_id: " . $e->getMessage();
            $log_stmt->bind_param("is", $teacher_id, $description);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }
}

// Fetch students for the class
$students_sql = "
    SELECT u.id, u.name
    FROM users u
    JOIN student_classes sc ON u.id = sc.user_id
    WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
    ORDER BY u.name";
$students_stmt = $conn->prepare($students_sql);
$students_stmt->bind_param("is", $class_id, $academic_year);
$students_stmt->execute();
$students_result = $students_stmt->get_result();
$students = [];
while ($row = $students_result->fetch_assoc()) {
    $students[] = $row;
}
$students_stmt->close();

// Include header template
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
if (!file_exists($header_path)) {
    error_log("Teacher header file missing: $header_path");
    $_SESSION['error_msg'] = "Error: Teacher header template is missing. Please contact the administrator.";
    $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                VALUES (?, 'Error', ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $error_desc = "Failed to load teacher header template at $header_path";
    $log_stmt->bind_param("is", $teacher_id, $error_desc);
    $log_stmt->execute();
    $log_stmt->close();
    header("Location: /login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Mark attendance for students in a class at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher, mark attendance, class management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Mark Attendance - QueensPa School">
    <meta property="og:description" content="Teacher interface for marking student attendance in a class at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/mark_attendance.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Mark Attendance - QueensPa School">
    <meta name="twitter:description" content="Teacher interface for marking student attendance in a class at QueensPa School.">
    <title>Mark Attendance - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
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
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
            animation: animate__pulse 0.5s;
        }
        .btn-secondary {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            border-radius: 8px;
            color: var(--text-color);
            transition: background-color 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            animation: animate__pulse 0.5s;
        }
        .form-select, .form-control {
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
        .profile-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        .alert {
            border-radius: 8px;
        }
        @media (max-width: 767.98px) {
            .quick-links {
                flex-direction: column;
                align-items: stretch;
            }
            .quick-links .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include $header_path; ?>

    <div class="content" id="content">
        <main class="container my-4">
            <!-- Messages -->
            <?php if ($success_msg): ?>
                <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
            <?php endif; ?>

            <!-- Teacher Profile Header -->
            <section class="card mb-4 animate__animated animate__fadeInDown">
                <div class="card-header d-flex align-items-center">
                    <img src="/<?php echo htmlspecialchars($teacher['profile_picture'] ?: 'Uploads/profiles/default.jpg'); ?>" alt="Profile Picture" class="profile-img me-3">
                    <h3 class="mb-0">Mark Attendance for <?php echo htmlspecialchars($class_name); ?>, <?php echo htmlspecialchars($teacher['name']); ?> (Teacher)</h3>
                    <a href="edit_user.php?id=<?php echo $teacher_id; ?>" class="btn btn-sm btn-secondary ms-auto animate__animated animate__fadeIn"><i class="fas fa-edit"></i> Edit Profile</a>
                </div>
            </section>

            <!-- Attendance Form -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Attendance for <?php echo htmlspecialchars($class_name); ?> (<?php echo htmlspecialchars($academic_year); ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($students)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">No students enrolled in <?php echo htmlspecialchars($class_name); ?> for <?php echo htmlspecialchars($academic_year); ?>.</div>
                    <?php else: ?>
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                            <div class="row g-3 align-items-center mb-3">
                                <div class="col-auto">
                                    <label for="attendance_date" class="form-label">Attendance Date:</label>
                                </div>
                                <div class="col-auto">
                                    <input type="date" name="attendance_date" id="attendance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                                <td>
                                                    <input type="hidden" name="student_id[<?php echo $index; ?>]" value="<?php echo $student['id']; ?>">
                                                    <select name="status[<?php echo $index; ?>]" class="form-select" required>
                                                        <option value="present">Present</option>
                                                        <option value="absent">Absent</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="submit_attendance" class="btn btn-primary animate__animated animate__fadeIn"><i class="fas fa-save"></i> Save Attendance</button>
                                <a href="manage_attendance.php" class="btn btn-secondary animate__animated animate__fadeIn"><i class="fas fa-arrow-left"></i> Back to Classes</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="d-flex flex-wrap quick-links gap-2 mb-4 animate__animated animate__fadeInUp">
                <a href="dashboard.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="submit_grades.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-chart-line"></i> Submit Grades</a>
                <a href="view_evaluations.php?teacher_id=<?php echo $teacher_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-person-check"></i> View Evaluations</a>
                <a href="manage_students.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-user-graduate"></i> Manage Students</a>
                <a href="manage_assignments.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-tasks"></i> Manage Assignments</a>
                <a href="manage_attendance.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-users"></i> Manage Attendance</a>
            </section>
        </main>
    </div>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Teacher footer file missing: $footer_path");
        $_SESSION['error_msg'] = "Error: Teacher footer template is missing. Please contact the administrator.";
        $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                    VALUES (?, 'Error', ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $error_desc = "Failed to load teacher footer template at $footer_path";
        $log_stmt->bind_param("is", $teacher_id, $error_desc);
        $log_stmt->execute();
        $log_stmt->close();
        header("Location: /login.php");
        exit();
    }
    include $footer_path;

    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>