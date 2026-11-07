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
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$class_name = isset($_GET['class_name']) ? htmlspecialchars(urldecode($_GET['class_name'])) : '';
if (!$class_id) {
    $_SESSION['error_msg'] = "No class selected.";
    header("Location: /teacher/view_classes.php");
    exit();
}
// Verify teacher is assigned to the class
$class_sql = "SELECT c.id, c.class_name FROM classes c 
              LEFT JOIN class_schedules cs ON c.id = cs.class_id 
              WHERE c.id = ? AND (c.teacher_id = ? OR cs.teacher_id = ?)";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param("iii", $class_id, $teacher_id, $teacher_id);
$class_stmt->execute();
$class = $class_stmt->get_result()->fetch_assoc();
$class_stmt->close();
if (!$class) {
    $_SESSION['error_msg'] = "You are not authorized to manage groups for this class.";
    header("Location: /teacher/view_classes.php");
    exit();
}
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Create Group', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed create group page for class_id: $class_id";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();
// Handle group creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name'] ?? '');
    $project_title = trim($_POST['project_title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $deadline = trim($_POST['deadline'] ?? '');
    $max_points = isset($_POST['max_points']) ? (int)$_POST['max_points'] : 0;
    $errors = [];
    if (empty($group_name)) {
        $errors[] = "Group name is required.";
    }
    if (empty($project_title)) {
        $errors[] = "Project title is required.";
    }
    if (empty($deadline)) {
        $errors[] = "Deadline is required.";
    } elseif (strtotime($deadline) === false) {
        $errors[] = "Invalid deadline format.";
    }
    if ($max_points <= 0) {
        $errors[] = "Maximum points must be greater than 0.";
    }
    if (empty($errors)) {
        $insert_sql = "INSERT INTO groups (class_id, name, project_title, description, deadline, max_points, status, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssi", $class_id, $group_name, $project_title, $description, $deadline, $max_points);
        if ($insert_stmt->execute()) {
            $_SESSION['success_msg'] = "Group '$group_name' for project '$project_title' created successfully.";
            $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                        VALUES (?, 'Created Group', ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $log_desc = "Teacher created group '$group_name' for project '$project_title' in class_id: $class_id";
            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();
            header("Location: /teacher/view_classes.php");
            exit();
        } else {
            $_SESSION['error_msg'] = "Failed to create group.";
            error_log("Failed to create group: " . $insert_stmt->error);
        }
        $insert_stmt->close();
    } else {
        $_SESSION['error_msg'] = implode(" ", $errors);
    }
}
// Handle group archiving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_group'])) {
    $group_id = (int)$_POST['group_id'];
    // Verify group belongs to the class
    $check_sql = "SELECT id FROM groups WHERE id = ? AND class_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $group_id, $class_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $update_sql = "UPDATE groups SET status = 'archived' WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $group_id);
        if ($update_stmt->execute()) {
            $_SESSION['success_msg'] = "Group archived successfully.";
            $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                        VALUES (?, 'Archived Group', ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $log_desc = "Teacher archived group_id: $group_id in class_id: $class_id";
            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            $_SESSION['error_msg'] = "Failed to archive group.";
            error_log("Failed to archive group: " . $update_stmt->error);
        }
        $update_stmt->close();
    } else {
        $_SESSION['error_msg'] = "Invalid group.";
    }
    $check_stmt->close();
    header("Location: create_group.php?class_id=$class_id&class_name=" . urlencode($class_name));
    exit();
}
// Fetch active groups
$groups_sql = "SELECT id, name, project_title, description, deadline, max_points, created_at 
               FROM groups WHERE class_id = ? AND status = 'active' ORDER BY deadline ASC";
$groups_stmt = $conn->prepare($groups_sql);
$groups_stmt->bind_param("i", $class_id);
$groups_stmt->execute();
$groups = $groups_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$groups_stmt->close();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create and manage project groups for classes at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher, group management, project-based learning">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Create Project Group - QueensPa School">
    <meta property="og:description" content="Teacher interface to create and manage project-based groups for assigned classes.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/create_group.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Create Project Group - QueensPa School">
    <meta name="twitter:description" content="Teacher interface to create and manage project-based groups for assigned classes.">
    <title>Create Project Group - QueensPa School</title>
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
            font-family: 'Arial', sans-serif;
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
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 6px;
            color: white;
            transition: background-color 0.3s ease;
        }
        .btn-primary:hover {
            background-color: var(--hover-color);
            border-color: var(--hover-color);
            animation: animate__pulse 0.5s;
        }
        .btn-danger {
            border-radius: 6px;
        }
        .form-control, .form-select {
            border-radius: 6px;
            border-color: var(--primary-color);
            color: var(--text-color);
            transition: border-color 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
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
        .alert {
            border-radius: 6px;
            border-color: var(--primary-color);
            background-color: #fff3e0;
            color: var(--text-color);
            font-weight: 500;
        }
        .form-label {
            font-weight: 500;
            color: var(--text-color);
        }
        .deadline-passed {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>
    <main class="container my-5">
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
        <?php elseif (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div>
        <?php endif; ?>
        <section class="card mb-5 animate__animated animate__fadeInDown">
            <div class="card-header">
                <h3 class="mb-0">Create Project Group for <?php echo htmlspecialchars($class_name); ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-4">
                    <div class="col-md-6">
                        <label for="group_name" class="form-label">Group Name</label>
                        <input type="text" name="group_name" id="group_name" class="form-control" required maxlength="100" aria-label="Group Name" placeholder="e.g., Team Alpha">
                    </div>
                    <div class="col-md-6">
                        <label for="project_title" class="form-label">Project Title</label>
                        <input type="text" name="project_title" id="project_title" class="form-control" required maxlength="255" aria-label="Project Title" placeholder="e.g., Science Fair Project">
                    </div>
                    <div class="col-md-12">
                        <label for="description" class="form-label">Project Description (Optional)</label>
                        <textarea name="description" id="description" class="form-control" rows="4" maxlength="500" aria-label="Project Description" placeholder="Describe the project objectives..."></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="deadline" class="form-label">Project Deadline</label>
                        <input type="datetime-local" name="deadline" id="deadline" class="form-control" required aria-label="Project Deadline">
                    </div>
                    <div class="col-md-6">
                        <label for="max_points" class="form-label">Maximum Points</label>
                        <input type="number" name="max_points" id="max_points" class="form-control" required min="1" max="1000" aria-label="Maximum Points" placeholder="e.g., 100">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="create_group" class="btn btn-primary"><i class="fas fa-users"></i> Create Group</button>
                        <a href="/teacher/view_classes.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Classes</a>
                    </div>
                </form>
            </div>
        </section>
        <section class="card mb-5 animate__animated animate__fadeInUp">
            <div class="card-header">
                <h3 class="mb-0">Active Project Groups</h3>
            </div>
            <div class="card-body">
                <?php if (empty($groups)): ?>
                    <div class="alert alert-info">No active project groups for this class yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead>
                                <tr>
                                    <th>Group Name</th>
                                    <th>Project Title</th>
                                    <th>Description</th>
                                    <th>Deadline</th>
                                    <th>Max Points</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $group): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($group['name']); ?></td>
                                        <td><?php echo htmlspecialchars($group['project_title']); ?></td>
                                        <td><?php echo htmlspecialchars($group['description'] ?: 'N/A'); ?></td>
                                        <td class="<?php echo strtotime($group['deadline']) < time() ? 'deadline-passed' : ''; ?>">
                                            <?php echo date('Y-m-d H:i', strtotime($group['deadline'])); ?>
                                        </td>
                                        <td><?php echo (int)$group['max_points']; ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($group['created_at'])); ?></td>
                                        <td>
                                            <a href="manage_group_members.php?class_id=<?php echo $class_id; ?>&group_id=<?php echo $group['id']; ?>&class_name=<?php echo urlencode($class_name); ?>" class="btn btn-primary btn-sm" title="Manage Members"><i class="fas fa-user-plus"></i></a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to archive this group? It will be marked as completed and hidden.');">
                                                <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                <button type="submit" name="archive_group" class="btn btn-danger btn-sm" title="Archive Group"><i class="fas fa-archive"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.classList.remove('animate__fadeIn');
                    alert.classList.add('animate__fadeOut');
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
            // Set minimum deadline to current time
            const deadlineInput = document.getElementById('deadline');
            const now = new Date();
            const minDate = now.toISOString().slice(0, 16);
            deadlineInput.setAttribute('min', minDate);
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>