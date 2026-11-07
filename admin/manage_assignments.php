<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "Database connection failed.";
    header("Location: /login.php");
    exit();
}
$teacher_id = $_SESSION['user_id'];
$teacher_sql = "SELECT id, name, profile_picture FROM users WHERE id = ? AND role = 'teacher'";
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
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Manage Assignments', 'Teacher accessed manage assignments page', ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_stmt->bind_param("iss", $teacher_id, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    error_log("Invalid or missing class_id for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Invalid class ID.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$class_id = (int)$_GET['class_id'];
$class_sql = "SELECT c.id, c.class_name, c.department_id, ac.year, ac.id as term_id 
              FROM classes c 
              LEFT JOIN class_schedules cs ON c.id = cs.class_id 
              LEFT JOIN academic_calendar ac ON cs.term_id = ac.id 
              WHERE c.id = ? AND (c.teacher_id = ? OR cs.teacher_id = ?)";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param("iii", $class_id, $teacher_id, $teacher_id);
$class_stmt->execute();
$class = $class_stmt->get_result()->fetch_assoc();
$class_stmt->close();
if (!$class) {
    error_log("Class not found or unauthorized for class_id: $class_id, user_id: $teacher_id");
    $_SESSION['error_msg'] = "Class not found or you are not authorized.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$action = isset($_GET['action']) && in_array($_GET['action'], ['add', 'remove', 'promote']) ? $_GET['action'] : null;
$success_msg = $error_msg = null;
$enrolled_students = [];
$available_students = [];
$target_classes = [];
if ($action === 'add') {
    $available_students_sql = "SELECT u.id, u.email 
                              FROM users u 
                              WHERE u.role = 'student' AND u.department_id = ? 
                              AND u.id NOT IN (
                                  SELECT sc.user_id 
                                  FROM student_classes sc 
                                  WHERE sc.class_id = ? AND sc.academic_year = ?
                              )";
    $available_students_stmt = $conn->prepare($available_students_sql);
    $available_students_stmt->bind_param("iis", $class['department_id'], $class_id, $class['year']);
    $available_students_stmt->execute();
    $available_students = $available_students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $available_students_stmt->close();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_ids'])) {
        $student_ids = array_filter($_POST['student_ids'], 'is_numeric');
        if (empty($student_ids)) {
            $error_msg = "No learners selected.";
        } else {
            $conn->begin_transaction();
            try {
                $insert_sql = "INSERT INTO student_classes (user_id, class_id, academic_year) VALUES (?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("iis", $student_id, $class_id, $class['year']);
                $success_count = 0;
                foreach ($student_ids as $student_id) {
                    // Verify student role before adding
                    $verify_sql = "SELECT 1 FROM users WHERE id = ? AND role = 'student'";
                    $verify_stmt = $conn->prepare($verify_sql);
                    $verify_stmt->bind_param("i", $student_id);
                    $verify_stmt->execute();
                    if ($verify_stmt->get_result()->fetch_assoc()) {
                        if ($insert_stmt->execute()) {
                            $success_count++;
                        } else {
                            error_log("Failed to add student_id: $student_id to class_id: $class_id");
                        }
                    } else {
                        error_log("Attempted to add non-student user_id: $student_id to class_id: $class_id");
                    }
                    $verify_stmt->close();
                }
                $insert_stmt->close();
                if ($success_count > 0) {
                    $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                                VALUES (?, 'Added Learners', ?, ?, ?, NOW())";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_desc = "Teacher added $success_count learners to class_id: $class_id";
                    $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                    $log_stmt->execute();
                    $log_stmt->close();
                    $conn->commit();
                    $success_msg = "$success_count learner(s) added successfully.";
                } else {
                    $conn->rollback();
                    $error_msg = "Failed to add learners.";
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error adding learners: " . $e->getMessage();
                error_log("Add learners error: " . $e->getMessage());
            }
            header("Location: /teacher/manage_assignments.php?class_id=$class_id&action=add");
            exit();
        }
    }
} elseif ($action === 'remove' || $action === 'promote') {
    $enrolled_students_sql = "SELECT u.id, u.email 
                             FROM student_classes sc 
                             JOIN users u ON sc.user_id = u.id 
                             WHERE u.role = 'student' AND sc.class_id = ? AND sc.academic_year = ?";
    $enrolled_students_stmt = $conn->prepare($enrolled_students_sql);
    $enrolled_students_stmt->bind_param("is", $class_id, $class['year']);
    $enrolled_students_stmt->execute();
    $enrolled_students = $enrolled_students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $enrolled_students_stmt->close();
    if ($action === 'promote') {
        $target_classes_sql = "SELECT c.id, c.class_name 
                               FROM classes c 
                               LEFT JOIN class_schedules cs ON c.id = cs.class_id 
                               WHERE c.id != ? AND c.department_id = ? 
                               AND (c.teacher_id = ? OR cs.teacher_id = ?)";
        $target_classes_stmt = $conn->prepare($target_classes_sql);
        $target_classes_stmt->bind_param("iiii", $class_id, $class['department_id'], $teacher_id, $teacher_id);
        $target_classes_stmt->execute();
        $target_classes = $target_classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $target_classes_stmt->close();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_ids'])) {
        $student_ids = array_filter($_POST['student_ids'], 'is_numeric');
        if (empty($student_ids)) {
            $error_msg = "No learners selected.";
        } elseif ($action === 'remove') {
            $conn->begin_transaction();
            try {
                $delete_sql = "DELETE FROM student_classes WHERE user_id = ? AND class_id = ? AND academic_year = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("iis", $student_id, $class_id, $class['year']);
                $success_count = 0;
                foreach ($student_ids as $student_id) {
                    if ($delete_stmt->execute()) {
                        $success_count++;
                    } else {
                        error_log("Failed to remove student_id: $student_id from class_id: $class_id");
                    }
                }
                $delete_stmt->close();
                if ($success_count > 0) {
                    $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                                VALUES (?, 'Removed Learners', ?, ?, ?, NOW())";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_desc = "Teacher removed $success_count learners from class_id: $class_id";
                    $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                    $log_stmt->execute();
                    $log_stmt->close();
                    $conn->commit();
                    $success_msg = "$success_count learner(s) removed successfully.";
                } else {
                    $conn->rollback();
                    $error_msg = "Failed to remove learners.";
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error removing learners: " . $e->getMessage();
                error_log("Remove learners error: " . $e->getMessage());
            }
            header("Location: /teacher/manage_assignments.php?class_id=$class_id&action=remove");
            exit();
        } elseif ($action === 'promote' && isset($_POST['target_class_id']) && is_numeric($_POST['target_class_id'])) {
            $target_class_id = (int)$_POST['target_class_id'];
            $target_class_valid_sql = "SELECT 1 FROM classes c 
                                      LEFT JOIN class_schedules cs ON c.id = cs.class_id 
                                      WHERE c.id = ? AND c.department_id = ? 
                                      AND (c.teacher_id = ? OR cs.teacher_id = ?)";
            $target_class_valid_stmt = $conn->prepare($target_class_valid_sql);
            $target_class_valid_stmt->bind_param("iiii", $target_class_id, $class['department_id'], $teacher_id, $teacher_id);
            $target_class_valid_stmt->execute();
            if (!$target_class_valid_stmt->get_result()->fetch_assoc()) {
                $error_msg = "Invalid target class selected.";
                header("Location: /teacher/manage_assignments.php?class_id=$class_id&action=promote");
                exit();
            }
            $target_class_valid_stmt->close();
            $conn->begin_transaction();
            try {
                $delete_sql = "DELETE FROM student_classes WHERE user_id = ? AND class_id = ? AND academic_year = ?";
                $insert_sql = "INSERT INTO student_classes (user_id, class_id, academic_year) VALUES (?, ?, ?)";
                $delete_stmt = $conn->prepare($delete_sql);
                $insert_stmt = $conn->prepare($insert_sql);
                $delete_stmt->bind_param("iis", $student_id, $class_id, $class['year']);
                $insert_stmt->bind_param("iis", $student_id, $target_class_id, $class['year']);
                $success_count = 0;
                foreach ($student_ids as $student_id) {
                    $delete_stmt->execute();
                    if ($insert_stmt->execute()) {
                        $success_count++;
                    } else {
                        error_log("Failed to promote student_id: $student_id from class_id: $class_id to $target_class_id");
                    }
                }
                $delete_stmt->close();
                $insert_stmt->close();
                if ($success_count > 0) {
                    $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                                VALUES (?, 'Promoted Learners', ?, ?, ?, NOW())";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_desc = "Teacher promoted $success_count learners from class_id: $class_id to $target_class_id";
                    $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                    $log_stmt->execute();
                    $log_stmt->close();
                    $conn->commit();
                    $success_msg = "$success_count learner(s) promoted successfully.";
                } else {
                    $conn->rollback();
                    $error_msg = "Failed to promote learners.";
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error promoting learners: " . $e->getMessage();
                error_log("Promote learners error: " . $e->getMessage());
            }
            header("Location: /teacher/manage_assignments.php?class_id=$class_id&action=promote");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage learner assignments in a class at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher, manage assignments, add learners, remove learners, promote learners">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Manage Assignments - QueensPa School">
    <meta property="og:description" content="Teacher interface to add, remove, or promote learners in a class.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/manage_assignments.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Manage Assignments - QueensPa School">
    <meta name="twitter:description" content="Teacher interface to add, remove, or promote learners in a class.">
    <title>Manage Assignments - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --hover-color: #e03e00;
            --secondary-color: #ff6b00;
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
            border-radius: 8px;
            overflow-x: auto;
        }
        .table thead {
            background: var(--primary-color);
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
        .action-buttons .btn {
            margin: 0 5px;
        }
        .alert {
            border-radius: 6px;
            border-color: var(--primary-color);
            background-color: #fff3e0;
            color: var(--text-color);
        }
        @media (max-width: 767.98px) {
            .quick-links, .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            .quick-links .btn, .action-buttons .btn {
                width: 100%;
                margin-bottom: 0.5rem;
                min-width: auto;
            }
            .table-responsive {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    if (!file_exists($header_path)) {
        error_log("Teacher header file missing: $header_path");
        $_SESSION['error_msg'] = "Error: Teacher header template is missing.";
        header("Location: /login.php");
        exit();
    }
    include $header_path;
    ?>
    <main class="container my-4">
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
        <?php elseif ($error_msg): ?>
            <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <section class="card mb-4 animate__animated animate__fadeInDown">
            <div class="card-header d-flex align-items-center">
                <img src="<?php echo htmlspecialchars($teacher['profile_picture'] ?: '/Uploads/profiles/default.jpg'); ?>" alt="Profile Picture" class="profile-img me-3">
                <h3 class="mb-0">Manage Assignments: <?php echo htmlspecialchars($class['class_name']); ?></h3>
                <a href="/teacher/edit_user.php?id=<?php echo $teacher_id; ?>" class="btn btn-primary btn-sm ms-auto"><i class="fas fa-edit"></i> Edit Profile</a>
            </div>
        </section>
        <?php if (!$action): ?>
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Select Action</h3>
                </div>
                <div class="card-body">
                    <div class="d-flex action-buttons gap-3 flex-wrap">
                        <a href="/teacher/manage_assignments.php?class_id=<?php echo $class_id; ?>&action=add" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Learners</a>
                        <a href="/teacher/manage_assignments.php?class_id=<?php echo $class_id; ?>&action=remove" class="btn btn-primary"><i class="fas fa-user-minus"></i> Remove Learners</a>
                        <a href="/teacher/manage_assignments.php?class_id=<?php echo $class_id; ?>&action=promote" class="btn btn-primary"><i class="fas fa-arrow-up"></i> Promote Learners</a>
                    </div>
                </div>
            </section>
        <?php elseif ($action === 'add'): ?>
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Add Learners to <?php echo htmlspecialchars($class['class_name']); ?></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($available_students)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No available learners to add to this class.
                        </div>
                    <?php else: ?>
                        <form method="POST" class="table-responsive">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Learner ID</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($available_students as $student): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="student_ids[]" value="<?php echo htmlspecialchars($student['id']); ?>" class="form-check-input">
                                            </td>
                                            <td><?php echo htmlspecialchars($student['id']); ?></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Selected Learners</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($action === 'remove'): ?>
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Remove Learners from <?php echo htmlspecialchars($class['class_name']); ?></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($enrolled_students)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No learners enrolled in this class.
                        </div>
                    <?php else: ?>
                        <form method="POST" class="table-responsive">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Learner ID</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled_students as $student): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="student_ids[]" value="<?php echo htmlspecialchars($student['id']); ?>" class="form-check-input">
                                            </td>
                                            <td><?php echo htmlspecialchars($student['id']); ?></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-trash"></i> Remove Selected Learners</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($action === 'promote'): ?>
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Promote Learners from <?php echo htmlspecialchars($class['class_name']); ?></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($enrolled_students)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No learners enrolled in this class.
                        </div>
                    <?php elseif (empty($target_classes)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No other classes available for promotion in this department.
                        </div>
                    <?php else: ?>
                        <form method="POST" class="table-responsive">
                            <div class="mb-3">
                                <label for="target_class_id" class="form-label">Target Class:</label>
                                <select name="target_class_id" id="target_class_id" class="form-select" required>
                                    <option value="">Select a class</option>
                                    <?php foreach ($target_classes as $target_class): ?>
                                        <option value="<?php echo htmlspecialchars($target_class['id']); ?>">
                                            <?php echo htmlspecialchars($target_class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Learner ID</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolled_students as $student): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="student_ids[]" value="<?php echo htmlspecialchars($student['id']); ?>" class="form-check-input">
                                            </td>
                                            <td><?php echo htmlspecialchars($student['id']); ?></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-up"></i> Promote Selected Learners</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
        <section class="d-flex flex-wrap quick-links gap-2 mb-4 animate__animated animate__fadeInUp">
            <a href="/teacher/dashboard.php" class="btn btn-primary flex-grow-1 flex-md-grow-0"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="/teacher/view_classes.php" class="btn btn-primary flex-grow-1 flex-md-grow-0"><i class="fas fa-chalkboard"></i> View Classes</a>
            <a href="/teacher/submit_grades.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0"><i class="fas fa-chart-line"></i> Submit Grades</a>
            <a href="/teacher/manage_assignments.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0"><i class="fas fa-tasks"></i> Manage Assignments</a>
            <a href="/teacher/manage_attendance.php?class_id=<?php echo $class_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0"><i class="fas fa-users"></i> Manage Attendance</a>
            <a href="/teacher/view_evaluations.php?teacher_id=<?php echo $teacher_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0"><i class="fas fa-star"></i> View Evaluations</a>
            <a href="/teacher/view_class.php?id=<?php echo $class_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0"><i class="fas fa-arrow-left"></i> Back to Class</a>
        </section>
    </main>
    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Teacher footer file missing: $footer_path");
        $_SESSION['error_msg'] = "Error: Teacher footer template is missing.";
        $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                    VALUES (?, 'Error', ?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $error_desc = "Failed to load teacher footer template at $footer_path";
        $log_stmt->bind_param("isss", $teacher_id, $error_desc, $ip_address, $user_agent);
        $log_stmt->execute();
        $log_stmt->close();
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    mysqli_close($conn);
    ob_end_flush();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.classList.remove('animate__fadeIn');
                    alert.classList.add('animate__fadeOut');
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
        });
    </script>
</body>
</html>