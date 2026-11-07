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

// Determine current academic year (September to August)
$today = new DateTime();
$month = (int)$today->format('m');
$year = (int)$today->format('Y');
$current_academic_year = $month >= 9 ? "$year/" . ($year + 1) : ($year - 1) . "/$year";

// Fetch available academic years from academic_calendar
$academic_years_sql = "SELECT DISTINCT year FROM academic_calendar WHERE year REGEXP '^[0-9]{4}$' ORDER BY year DESC";
$academic_years_result = mysqli_query($conn, $academic_years_sql);
$academic_years = [];
while ($row = mysqli_fetch_assoc($academic_years_result)) {
    $academic_years[] = (int)$row['year'];
}
mysqli_free_result($academic_years_result);

// Validate and get selected academic year from GET or default to current
$selected_academic_year = $_GET['academic_year'] ?? $current_academic_year;
if (!preg_match('/^\d{4}\/\d{4}$/', $selected_academic_year)) {
    $selected_academic_year = $current_academic_year;
    error_log("Invalid academic year format: " . ($_GET['academic_year'] ?? 'none'));
}

// Fetch classes (no academic_year filter)
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_sql);
if (!$classes_result) {
    error_log("Classes query failed: " . $conn->error);
    $_SESSION['error_msg'] = "Failed to load classes. Please try again.";
}

// Handle form submission
$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$selected_date = isset($_POST['attendance_date']) ? $_POST['attendance_date'] : date('Y-m-d');
$attendance_records = [];

if ($class_id > 0) {
    $sql = "SELECT a.id, a.user_id, a.date, a.status, a.type, u.name 
            FROM attendance a 
            JOIN users u ON a.user_id = u.id 
            JOIN student_classes sc ON a.user_id = sc.user_id AND a.class_id = sc.class_id 
            WHERE a.class_id = ? AND a.date = ?
            ORDER BY u.name";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $class_id, $selected_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $attendance_records[] = $row;
        }
        $stmt->close();
    } else {
        error_log("Prepare statement failed: " . $conn->error);
        $_SESSION['error_msg'] = "Failed to load attendance records.";
    }
}

// Include admin header template
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage student attendance records for QueensPa School classes.">
    <meta name="keywords" content="QueensPa School, attendance management, admin, student attendance, class records">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Manage Attendance - QueensPa School">
    <meta property="og:description" content="View and manage student attendance records for classes at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/attendance.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Manage Attendance - QueensPa School">
    <meta name="twitter:description" content="View and manage student attendance records for classes at QueensPa School.">
    <title>Manage Attendance - QueensPa School</title>
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
        }
        .btn-primary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
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
        .status-present {
            color: #28a745;
            font-weight: bold;
        }
        .status-absent {
            color: #dc3545;
            font-weight: bold;
        }
        .status-late {
            color: #ffc107;
            font-weight: bold;
        }
        @media (max-width: 767.98px) {
            .form-select, .form-control {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <h1 class="mb-4 text-center animate__animated animate__fadeIn">📅 Manage Attendance</h1>

            <!-- Academic Year Selector -->
            <section class="mb-4 animate__animated animate__fadeInDown">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Select Academic Year</h3>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-center">
                            <div class="col-auto">
                                <label for="academic_year" class="form-label">Academic Year:</label>
                            </div>
                            <div class="col-auto">
                                <select name="academic_year" id="academic_year" class="form-select" onchange="this.form.submit()">
                                    <?php foreach ($academic_years as $year): ?>
                                        <option value="<?= $year . '/' . ($year + 1) ?>" <?= $selected_academic_year === ($year . '/' . ($year + 1)) ? 'selected' : '' ?>>
                                            <?= $year . '/' . ($year + 1) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Attendance Form -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">View Attendance (<?= htmlspecialchars($selected_academic_year) ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['error_msg'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_SESSION['error_msg'], ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_msg']); ?>
                    <?php endif; ?>
                    <form method="POST" class="row g-3">
                        <div class="col-md-6">
                            <label for="class_id" class="form-label">Select Class</label>
                            <select name="class_id" id="class_id" class="form-select" required>
                                <option value="0">Select a class</option>
                                <?php while ($class = $classes_result->fetch_assoc()): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="attendance_date" class="form-label">Select Date</label>
                            <input type="date" name="attendance_date" id="attendance_date" value="<?php echo htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8'); ?>" 
                                   class="form-control" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i> View Attendance
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Attendance Records -->
            <?php if ($class_id > 0): ?>
                <section class="card mb-4 animate__animated animate__zoomIn">
                    <div class="card-header">
                        <h3 class="mb-0">Attendance Records</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-bordered">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($attendance_records)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center">No attendance records found for the selected class and date.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($attendance_records as $record): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($record['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($record['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="status-<?php echo strtolower($record['status']); ?>">
                                                    <?php echo htmlspecialchars($record['status'], ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($record['type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>