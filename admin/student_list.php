<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

include $_SERVER['DOCUMENT_ROOT'] . '/queenspa/config/db.php';

$teacher_id = $_SESSION['user_id'];
$class = isset($_GET['class']) ? $_GET['class'] : '';
$students_sql = "SELECT u.id, u.name, u.email, c.class_name 
                 FROM users u 
                 JOIN class_assignments ca ON u.id = ca.student_id 
                 JOIN classes c ON ca.class_id = c.id 
                 WHERE c.class_teacher_id = ? " . ($class ? "AND c.class_name = ?" : "");
$students_stmt = $conn->prepare($students_sql);
if ($class) {
    $students_stmt->bind_param('is', $teacher_id, $class);
} else {
    $students_stmt->bind_param('i', $teacher_id);
}
$students_stmt->execute();
$students_result = $students_stmt->get_result();
$students = [];
while ($student = $students_result->fetch_assoc()) {
    $students[] = $student;
}

$classes_sql = "SELECT class_name FROM classes WHERE class_teacher_id = ?";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param('i', $teacher_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$teacher_classes = [];
while ($class_row = $classes_result->fetch_assoc()) {
    $teacher_classes[] = $class_row['class_name'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List | QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #ffffff; color: #000000; }
        .sidebar { height: 100vh; position: fixed; top: 0; left: 0; width: 250px; background-color: #ff4500; padding-top: 20px; }
        .sidebar a { color: #ffffff; padding: 15px 20px; display: block; text-decoration: none; }
        .sidebar a:hover { background-color: #e03e00; }
        .main-content { margin-left: 250px; padding: 20px; }
        .card-header { background-color: #ff4500; color: #ffffff; }
        .btn-primary { background-color: #ff4500; border-color: #ff4500; }
        .btn-primary:hover { background-color: #e03e00; border-color: #e03e00; }
        .card { box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .table thead { background-color: #ff4500; color: #ffffff; }
        .table-hover tbody tr:hover { background-color: #f5f5f5; }
        .night-mode { background-color: #000000; color: #ffffff; }
        .night-mode .sidebar { background-color: #333333; }
        .night-mode .sidebar a { color: #ffffff; }
        .night-mode .sidebar a:hover { background-color: #555555; }
        .night-mode .card { background-color: #333333; color: #ffffff; }
        .night-mode .table thead { background-color: #ff4500; color: #ffffff; }
        .night-mode .table tbody { background-color: #333333; color: #ffffff; }
    </style>
</head>
<body>
    <div class="sidebar">
        <a href="dashboard.php">Dashboard</a>
        <a href="class_schedules.php">Class Schedules</a>
        <a href="student_list.php">Student List</a>
        <a href="lesson_plans.php">Lesson Plans</a>
        <a href="attendance.php">Attendance</a>
        <a href="assignments.php">Assignments</a>
        <a href="../logout.php">Logout</a>
    </div>
    <div class="main-content">
        <div class="container mt-5 animate__animated animate__fadeIn">
            <h1 class="mb-4 text-center">👥 Student List</h1>
            <div class="mb-3">
                <select class="form-select w-auto d-inline-block me-2" onchange="window.location.href='?class='+this.value">
                    <option value="">All Classes</option>
                    <?php foreach ($teacher_classes as $tc): ?>
                        <option value="<?= htmlspecialchars($tc) ?>" <?= $class === $tc ? 'selected' : '' ?>><?= htmlspecialchars($tc) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="card">
                <div class="card-header"><h3>Students <?= $class ? "in $class" : '' ?></h3></div>
                <div class="card-body">
                    <table class="table table-striped table-hover table-bordered">
                        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Class</th></tr></thead>
                        <tbody><?php if (empty($students)): ?><tr><td colspan="4" class="text-center">No students found.</td></tr><?php else: ?><?php foreach ($students as $student): ?><tr><td><?= htmlspecialchars($student['id']) ?></td><td><?= htmlspecialchars($student['name']) ?></td><td><?= htmlspecialchars($student['email']) ?></td><td><?= htmlspecialchars($student['class_name']) ?></td></tr><?php endforeach; ?><?php endif; ?></tbody>
                    </table>
                </div>
            </div>
            <div class="mt-4 d-flex justify-content-between flex-wrap">
                <a href="dashboard.php" class="btn btn-primary mb-2"><i class="bi bi-house"></i> Back to Dashboard</a>
                <a href="class_schedules.php" class="btn btn-primary mb-2"><i class="bi bi-calendar"></i> Class Schedules</a>
                <a href="lesson_plans.php" class="btn btn-primary mb-2"><i class="bi bi-book"></i> Lesson Plans</a>
                <a href="attendance.php" class="btn btn-primary mb-2"><i class="bi bi-check-circle"></i> Attendance</a>
                <a href="assignments.php" class="btn btn-primary mb-2"><i class="bi bi-pencil-square"></i> Assignments</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleButton = document.createElement('button');
            toggleButton.textContent = 'Toggle Night Mode';
            toggleButton.className = 'btn btn-secondary position-fixed top-0 end-0 m-2';
            document.body.appendChild(toggleButton);
            const isNightMode = localStorage.getItem('nightMode') === 'true';
            if (isNightMode) document.body.classList.add('night-mode');
            toggleButton.addEventListener('click', () => {
                document.body.classList.toggle('night-mode');
                localStorage.setItem('nightMode', document.body.classList.contains('night-mode'));
            });
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>