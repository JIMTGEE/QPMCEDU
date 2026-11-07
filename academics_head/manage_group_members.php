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
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$academic_year = isset($_GET['academic_year']) ? htmlspecialchars($_GET['academic_year']) : '2024/2025';
// Verify class exists and teacher is authorized
$class_sql = "SELECT c.id, c.class_name FROM classes c 
              LEFT JOIN class_schedules cs ON c.id = cs.class_id 
              WHERE c.id = ? AND (c.teacher_id = ? OR cs.teacher_id = ?)";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param("iii", $class_id, $teacher_id, $teacher_id);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
$class = $class_result->fetch_assoc();
$class_result->free();
$class_stmt->close();
if (!$class || !$class_id) {
    $_SESSION['error_msg'] = "No class selected or you are not authorized.";
    header("Location: /teacher/view_classes.php");
    exit();
}
// Validate academic year
$academic_years_sql = "SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC";
$academic_years_stmt = $conn->prepare($academic_years_sql);
$academic_years_stmt->execute();
$academic_years_result = $academic_years_stmt->get_result();
$academic_years = $academic_years_result->fetch_all(MYSQLI_ASSOC);
$academic_years_result->free();
$academic_years_stmt->close();
if (!in_array(['year' => $academic_year], $academic_years)) {
    $academic_year = '2024/2025';
    error_log("Invalid academic year selected: " . ($_GET['academic_year'] ?? 'none'));
}
// Log access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Manage Group Members', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed manage group members for class_id: $class_id" . ($group_id ? ", group_id: $group_id" : "");
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();
// Fetch active groups for the class
$groups_sql = "SELECT id, name, project_title FROM groups WHERE class_id = ? AND status = 'active' ORDER BY name";
$groups_stmt = $conn->prepare($groups_sql);
$groups_stmt->bind_param("i", $class_id);
$groups_stmt->execute();
$groups_result = $groups_stmt->get_result();
$groups = $groups_result->fetch_all(MYSQLI_ASSOC);
$groups_result->free();
$groups_stmt->close();
// Fetch all groups (active and archived) for history
$history_sql = "SELECT g.id, g.name, g.project_title, g.status, g.created_at 
                FROM groups g 
                JOIN academic_calendar ac ON g.created_at BETWEEN ac.start_date AND ac.end_date 
                WHERE g.class_id = ? AND ac.year = ? 
                ORDER BY g.created_at DESC";
$history_stmt = $conn->prepare($history_sql);
$history_stmt->bind_param("is", $class_id, $academic_year);
$history_stmt->execute();
$history_result = $history_stmt->get_result();
$history_groups = $history_result->fetch_all(MYSQLI_ASSOC);
$history_result->free();
$history_stmt->close();
$group = null;
$members = [];
$available_students = [];
if ($group_id) {
    // Verify group exists and belongs to the class
    $group_sql = "SELECT id, name, project_title FROM groups WHERE id = ? AND class_id = ? AND status = 'active'";
    $group_stmt = $conn->prepare($group_sql);
    $group_stmt->bind_param("ii", $group_id, $class_id);
    $group_stmt->execute();
    $group_result = $group_stmt->get_result();
    $group = $group_result->fetch_assoc();
    $group_result->free();
    $group_stmt->close();
    if (!$group) {
        $_SESSION['error_msg'] = "Invalid or inactive group selected.";
        header("Location: /teacher/manage_group_members.php?class_id=$class_id&class_name=" . urlencode($class_name) . "&academic_year=$academic_year");
        exit();
    }
    // Fetch current group members
    $members_sql = "SELECT gm.id, u.id as user_id, u.name 
                    FROM group_members gm 
                    JOIN users u ON gm.user_id = u.id 
                    WHERE gm.group_id = ? AND u.role = 'student'";
    $members_stmt = $conn->prepare($members_sql);
    $members_stmt->bind_param("i", $group_id);
    $members_stmt->execute();
    $members_result = $members_stmt->get_result();
    $members = $members_result->fetch_all(MYSQLI_ASSOC);
    $members_result->free();
    $members_stmt->close();
    // Fetch available students (enrolled in class but not in this group)
    $available_sql = "SELECT u.id, u.name 
                     FROM users u 
                     JOIN student_classes sc ON u.id = sc.user_id 
                     WHERE sc.class_id = ? AND u.role = 'student' 
                     AND u.id NOT IN (SELECT user_id FROM group_members WHERE group_id = ?)";
    $available_stmt = $conn->prepare($available_sql);
    $available_stmt->bind_param("ii", $class_id, $group_id);
    $available_stmt->execute();
    $available_result = $available_stmt->get_result();
    $available_students = $available_result->fetch_all(MYSQLI_ASSOC);
    $available_result->free();
    $available_stmt->close();
}
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_member']) && $group_id) {
        $user_id = (int)$_POST['user_id'];
        // Verify student is in class
        $check_sql = "SELECT user_id FROM student_classes WHERE user_id = ? AND class_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $user_id, $class_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $insert_sql = "INSERT INTO group_members (group_id, user_id) VALUES (?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ii", $group_id, $user_id);
            if ($insert_stmt->execute()) {
                $_SESSION['success_msg'] = "Student added to group.";
                $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                            VALUES (?, 'Added Group Member', ?, ?, ?, NOW())";
                $log_stmt = $conn->prepare($log_sql);
                $log_desc = "Teacher added user_id: $user_id to group_id: $group_id";
                $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $_SESSION['error_msg'] = "Failed to add student.";
                error_log("Failed to add group member: " . $insert_stmt->error);
            }
            $insert_stmt->close();
        } else {
            $_SESSION['error_msg'] = "Student is not enrolled in this class.";
        }
        $check_result->free();
        $check_stmt->close();
    } elseif (isset($_POST['remove_member']) && $group_id) {
        $member_id = (int)$_POST['member_id'];
        $delete_sql = "DELETE FROM group_members WHERE id = ? AND group_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $member_id, $group_id);
        if ($delete_stmt->execute()) {
            $_SESSION['success_msg'] = "Student removed from group.";
            $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                        VALUES (?, 'Removed Group Member', ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $log_desc = "Teacher removed member_id: $member_id from group_id: $group_id";
            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            $_SESSION['error_msg'] = "Failed to remove student.";
            error_log("Failed to remove group member: " . $delete_stmt->error);
        }
        $delete_stmt->close();
    } elseif (isset($_POST['dismiss_group']) && $group_id) {
        $dismiss_sql = "UPDATE groups SET status = 'archived' WHERE id = ? AND class_id = ?";
        $dismiss_stmt = $conn->prepare($dismiss_sql);
        $dismiss_stmt->bind_param("ii", $group_id, $class_id);
        if ($dismiss_stmt->execute()) {
            $_SESSION['success_msg'] = "Group dismissed successfully.";
            $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                        VALUES (?, 'Dismissed Group', ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $log_desc = "Teacher dismissed group_id: $group_id in class_id: $class_id";
            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            $_SESSION['error_msg'] = "Failed to dismiss group.";
            error_log("Failed to dismiss group: " . $dismiss_stmt->error);
        }
        $dismiss_stmt->close();
        header("Location: /teacher/manage_group_members.php?class_id=$class_id&class_name=" . urlencode($class_name) . "&academic_year=$academic_year");
        exit();
    }
    header("Location: /teacher/manage_group_members.php?class_id=$class_id&class_name=" . urlencode($class_name) . "&group_id=$group_id&academic_year=$academic_year");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage group members and view group history for project groups at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher, group management, project-based learning">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Manage Group Members - QueensPa School">
    <meta property="og:description" content="Teacher interface to manage project group members and view group history for assigned classes.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/manage_group_members.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Manage Group Members - QueensPa School">
    <meta name="twitter:description" content="Teacher interface to manage project group members and view group history for assigned classes.">
    <title>Manage Group Members - QueensPa School</title>
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
        .history-status-active {
            color: #28a745;
            font-weight: bold;
        }
        .history-status-archived {
            color: #6c757d;
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
                <h3 class="mb-0">Manage Group Members for <?php echo htmlspecialchars($class_name); ?> (<?php echo htmlspecialchars($academic_year); ?>)</h3>
            </div>
            <div class="card-body">
                <?php if (empty($groups)): ?>
                    <div class="alert alert-info">No active groups found for this class. <a href="create_group.php?class_id=<?php echo $class_id; ?>&class_name=<?php echo urlencode($class_name); ?>&academic_year=<?php echo urlencode($academic_year); ?>">Create a group</a>.</div>
                <?php elseif (!$group_id): ?>
                    <h4>Select a Group</h4>
                    <div class="list-group mb-4">
                        <?php foreach ($groups as $g): ?>
                            <a href="manage_group_members.php?class_id=<?php echo $class_id; ?>&class_name=<?php echo urlencode($class_name); ?>&group_id=<?php echo $g['id']; ?>&academic_year=<?php echo urlencode($academic_year); ?>" class="list-group-item list-group-item-action">
                                <strong><?php echo htmlspecialchars($g['name']); ?></strong> (Project: <?php echo htmlspecialchars($g['project_title']); ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <a href="create_group.php?class_id=<?php echo $class_id; ?>&class_name=<?php echo urlencode($class_name); ?>&academic_year=<?php echo urlencode($academic_year); ?>" class="btn btn-primary mb-3"><i class="fas fa-users"></i> Create New Group</a>
                <?php else: ?>
                    <h4>Group: <?php echo htmlspecialchars($group['name']); ?> (Project: <?php echo htmlspecialchars($group['project_title']); ?>)</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Add Members</h5>
                            <?php if (empty($available_students)): ?>
                                <div class="alert alert-info">No students available to add to this group.</div>
                            <?php else: ?>
                                <form method="POST" class="mb-4">
                                    <div class="mb-3">
                                        <label for="user_id" class="form-label">Select Student</label>
                                        <select name="user_id" id="user_id" class="form-select" required>
                                            <option value="">-- Select Student --</option>
                                            <?php foreach ($available_students as $student): ?>
                                                <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" name="add_member" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add to Group</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <h5>Current Members</h5>
                            <?php if (empty($members)): ?>
                                <div class="alert alert-info">No members in this group yet.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Student Name</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $member): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($member['name']); ?></td>
                                                    <td>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this student from the group?');">
                                                            <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                            <button type="submit" name="remove_member" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="POST" class="mt-4" onsubmit="return confirm('Are you sure you want to dismiss this group? It will be archived and no longer active.');">
                        <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
                        <button type="submit" name="dismiss_group" class="btn btn-danger"><i class="fas fa-archive"></i> Dismiss Group</button>
                    </form>
                    <a href="manage_group_members.php?class_id=<?php echo $class_id; ?>&class_name=<?php echo urlencode($class_name); ?>&academic_year=<?php echo urlencode($academic_year); ?>" class="btn btn-secondary mt-3"><i class="fas fa-arrow-left"></i> Back to Group Selection</a>
                <?php endif; ?>
                <a href="/teacher/view_classes.php" class="btn btn-secondary mt-3"><i class="fas fa-arrow-left"></i> Back to Classes</a>
            </div>
        </section>
        <section class="card mb-5 animate__animated animate__fadeInUp">
            <div class="card-header">
                <h3 class="mb-0">Group History for <?php echo htmlspecialchars($class_name); ?> (<?php echo htmlspecialchars($academic_year); ?>)</h3>
            </div>
            <div class="card-body">
                <?php if (empty($history_groups)): ?>
                    <div class="alert alert-info">No groups created for this class in <?php echo htmlspecialchars($academic_year); ?>.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead>
                                <tr>
                                    <th>Group Name</th>
                                    <th>Project Title</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history_groups as $hg): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($hg['name']); ?></td>
                                        <td><?php echo htmlspecialchars($hg['project_title']); ?></td>
                                        <td class="history-status-<?php echo strtolower($hg['status']); ?>">
                                            <?php echo ucfirst(htmlspecialchars($hg['status'])); ?>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($hg['created_at'])); ?></td>
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
<?php
mysqli_close($conn);
ob_end_flush();
?>