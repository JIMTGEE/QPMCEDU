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

// ─── AJAX: Fetch students for admin marking ───
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_students') {
    ob_clean();
    header('Content-Type: application/json');

    $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $attendance_date = isset($_GET['date']) ? trim($_GET['date']) : '';
    $ajax_academic_year = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : $selected_academic_year;

    if (!$class_id || !$attendance_date) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
        exit();
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format']);
        exit();
    }

    $students_sql = "
        SELECT 
            u.id,
            u.name,
            u.email,
            u.profile_picture,
            COALESCE(a.status, 'absent') AS current_status
        FROM users u
        JOIN student_classes sc ON u.id = sc.user_id
            AND sc.class_id = ?
            AND sc.academic_year = ?
        LEFT JOIN attendance a ON a.user_id = u.id
            AND a.class_id = ?
            AND a.date = ?
        WHERE u.role = 'student'
          AND u.status = 'active'
        ORDER BY u.name";

    $st = $conn->prepare($students_sql);
    if ($st) {
        $st->bind_param("isis", $class_id, $ajax_academic_year, $class_id, $attendance_date);
        $st->execute();
        $res = $st->get_result();
        $students = [];
        while ($row = $res->fetch_assoc()) {
            // Simple profile picture resolution
            $profile = $row['profile_picture'];
            if (empty($profile) || !file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($profile, '/'))) {
                $profile = '/assets/images/default_profile.png';
            }
            $students[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'profile_picture' => $profile,
                'current_status' => $row['current_status']
            ];
        }
        $st->close();

        $class_name = '';
        $cn_stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        if ($cn_stmt) {
            $cn_stmt->bind_param("i", $class_id);
            $cn_stmt->execute();
            $cn_row = $cn_stmt->get_result()->fetch_assoc();
            $class_name = $cn_row['class_name'] ?? 'Unknown Class';
            $cn_stmt->close();
        }

        echo json_encode([
            'success' => true,
            'students' => $students,
            'class_name' => $class_name,
            'total_students' => count($students),
            'academic_year' => $ajax_academic_year
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit();
}

// ─── Handle admin attendance submission ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_mark_attendance'])) {
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $attendance_date = $_POST['attendance_date'] ?? '';
    $attendance_data = $_POST['attendance'] ?? [];

    if (!$class_id || !$attendance_date || empty($attendance_data)) {
        $_SESSION['error_msg'] = "Invalid submission.";
    } else {
        $conn->begin_transaction();
        try {
            $success = 0;
            foreach ($attendance_data as $student_id => $data) {
                $status = (isset($data['present']) && $data['present'] === '1') ? 'present' : 'absent';

                $check = $conn->prepare("SELECT id FROM attendance WHERE class_id=? AND user_id=? AND date=?");
                $check->bind_param("iis", $class_id, $student_id, $attendance_date);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $upd = $conn->prepare("UPDATE attendance SET status=?, updated_at=NOW() WHERE class_id=? AND user_id=? AND date=?");
                    $upd->bind_param("siis", $status, $class_id, $student_id, $attendance_date);
                    $upd->execute();
                    $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO attendance (class_id, user_id, date, status, type, created_at) VALUES (?,?,?,?,'in-person',NOW())");
                    $ins->bind_param("iiss", $class_id, $student_id, $attendance_date, $status);
                    $ins->execute();
                    $ins->close();
                }
                $check->close();
                $success++;
            }
            $conn->commit();
            $_SESSION['success_msg'] = "Attendance marked for $success students.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_msg'] = "Error marking attendance.";
        }
    }
    // Redirect back to same page with filters
    $redirect = "attendance.php?academic_year=" . urlencode($selected_academic_year);
    if (!empty($class_id)) $redirect .= "&class_id=" . $class_id;
    if (!empty($attendance_date)) $redirect .= "&attendance_date=" . urlencode($attendance_date);
    header("Location: $redirect");
    exit();
}

// Fetch classes (no academic_year filter)
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_sql);
if (!$classes_result) {
    error_log("Classes query failed: " . $conn->error);
    $_SESSION['error_msg'] = "Failed to load classes. Please try again.";
}

// Handle form submission for viewing attendance
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
    <title>Manage Attendance - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Reuse the same root variables and core styles from teacher page for consistency */
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
        .status-present { color: #28a745; font-weight: bold; }
        .status-absent { color: #dc3545; font-weight: bold; }
        .status-late { color: #ffc107; font-weight: bold; }
        .modal-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
        }
        .presence-toggle {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .presence-toggle input[type="checkbox"] {
            width: 1.4rem; height: 1.4rem; cursor: pointer; accent-color: #28a745;
        }
        .presence-label {
            font-weight: 600; min-width: 65px; padding: 0.25rem 0.75rem; border-radius: 20px; text-align: center;
        }
        .presence-label.present { background: #28a74520; color: #28a745; }
        .presence-label.absent { background: #dc354520; color: #dc3545; }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <h1 class="mb-4 text-center animate__animated animate__fadeIn">📅 Manage Attendance (Admin)</h1>

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

            <!-- Attendance Form & Admin Marking -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">View / Mark Attendance (<?= htmlspecialchars($selected_academic_year) ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['error_msg'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_msg']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['success_msg'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_msg']); ?>
                    <?php endif; ?>

                    <form method="POST" class="row g-3">
                        <div class="col-md-6">
                            <label for="class_id" class="form-label">Select Class</label>
                            <select name="class_id" id="class_id" class="form-select" required>
                                <option value="0">Select a class</option>
                                <?php while ($class = $classes_result->fetch_assoc()): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="attendance_date" class="form-label">Select Date</label>
                            <input type="date" name="attendance_date" id="attendance_date" value="<?php echo htmlspecialchars($selected_date); ?>" class="form-control" required>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i> View Attendance
                            </button>
                            <?php if ($class_id > 0): ?>
                            <button type="button" class="btn btn-warning" id="adminMarkBtn" data-bs-toggle="modal" data-bs-target="#adminAttendanceModal">
                                <i class="fas fa-edit me-1"></i> Mark Attendance
                            </button>
                            <?php endif; ?>
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
                                        <tr><td colspan="4" class="text-center">No attendance records found for the selected class and date.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($attendance_records as $record): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($record['name']); ?></td>
                                                <td><?php echo htmlspecialchars($record['date']); ?></td>
                                                <td class="status-<?php echo strtolower($record['status']); ?>">
                                                    <?php echo htmlspecialchars($record['status']); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($record['type']); ?></td>
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

    <!-- Admin Attendance Marking Modal -->
    <div class="modal fade" id="adminAttendanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Mark Attendance (Admin)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="admin-modal-loading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Loading students...</p>
                    </div>
                    <div id="admin-modal-error" class="alert alert-danger d-none"></div>
                    <div id="admin-modal-content" class="d-none">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="p-3 rounded bg-light">
                                    <p class="mb-1"><strong>Class:</strong> <span id="admin-modal-class-name"></span></p>
                                    <p class="mb-0"><strong>Date:</strong> <span id="admin-modal-date-display"></span></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 rounded bg-light">
                                    <p class="mb-1"><strong>Total:</strong> <span id="admin-modal-total">0</span></p>
                                    <p class="mb-1"><strong>Present:</strong> <span id="admin-summary-present" class="text-success fw-bold">0</span></p>
                                    <p class="mb-0"><strong>Absent:</strong> <span id="admin-summary-absent" class="text-danger fw-bold">0</span></p>
                                </div>
                            </div>
                        </div>
                        <form method="POST" id="adminAttendanceForm" action="attendance.php?academic_year=<?= urlencode($selected_academic_year) ?>">
                            <input type="hidden" name="admin_mark_attendance" value="1">
                            <input type="hidden" name="class_id" id="admin-modal-class-id">
                            <input type="hidden" name="attendance_date" id="admin-modal-date">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-dark">
                                        <tr><th>#</th><th>Student</th><th>Status</th></tr>
                                    </thead>
                                    <tbody id="admin-students-list"></tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary" id="adminSaveBtn" disabled>Save Attendance</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const markBtn = document.getElementById('adminMarkBtn');
            const classSelect = document.getElementById('class_id');
            const dateInput = document.getElementById('attendance_date');
            const modal = document.getElementById('adminAttendanceModal');

            if (markBtn) {
                markBtn.addEventListener('click', function() {
                    const classId = classSelect.value;
                    const date = dateInput.value;
                    if (!classId || classId == '0' || !date) {
                        alert('Please select a class and date first.');
                        return;
                    }
                    document.getElementById('admin-modal-class-id').value = classId;
                    document.getElementById('admin-modal-date').value = date;
                    document.getElementById('admin-modal-date-display').textContent = date;
                    loadAdminStudents(classId, date);
                });
            }

            function loadAdminStudents(classId, date) {
                document.getElementById('admin-modal-loading').classList.remove('d-none');
                document.getElementById('admin-modal-content').classList.add('d-none');
                document.getElementById('admin-modal-error').classList.add('d-none');
                document.getElementById('adminSaveBtn').disabled = true;

                const academicYear = '<?php echo addslashes($selected_academic_year); ?>';
                fetch(`attendance.php?ajax=get_students&class_id=${classId}&date=${date}&academic_year=${encodeURIComponent(academicYear)}`)
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('admin-modal-loading').classList.add('d-none');
                        if (data.success) {
                            document.getElementById('admin-modal-content').classList.remove('d-none');
                            document.getElementById('admin-modal-class-name').textContent = data.class_name;
                            document.getElementById('admin-modal-total').textContent = data.total_students;
                            const tbody = document.getElementById('admin-students-list');
                            tbody.innerHTML = '';
                            let present = 0, absent = 0;
                            data.students.forEach((s, i) => {
                                const isPresent = s.current_status === 'present';
                                if (isPresent) present++; else absent++;
                                const row = document.createElement('tr');
                                row.innerHTML = `
                                    <td>${i+1}</td>
                                    <td><strong>${s.name}</strong><br><small>${s.email}</small></td>
                                    <td>
                                        <div class="presence-toggle">
                                            <input type="checkbox" name="attendance[${s.id}][present]" id="admin-present-${s.id}" value="1" ${isPresent ? 'checked' : ''} onchange="updateAdminLabel(${s.id}, this.checked)">
                                            <label class="presence-label ${isPresent ? 'present' : 'absent'}" id="admin-label-${s.id}">${isPresent ? 'Present' : 'Absent'}</label>
                                        </div>
                                    </td>`;
                                tbody.appendChild(row);
                            });
                            document.getElementById('admin-summary-present').textContent = present;
                            document.getElementById('admin-summary-absent').textContent = absent;
                            document.getElementById('adminSaveBtn').disabled = false;
                        } else {
                            document.getElementById('admin-modal-error').classList.remove('d-none');
                            document.getElementById('admin-modal-error').textContent = data.error || 'Failed to load students.';
                        }
                    })
                    .catch(err => {
                        document.getElementById('admin-modal-loading').classList.add('d-none');
                        document.getElementById('admin-modal-error').classList.remove('d-none');
                        document.getElementById('admin-modal-error').textContent = 'Network error.';
                    });
            }

            window.updateAdminLabel = function(id, checked) {
                const label = document.getElementById('admin-label-' + id);
                if (checked) {
                    label.textContent = 'Present';
                    label.className = 'presence-label present';
                } else {
                    label.textContent = 'Absent';
                    label.className = 'presence-label absent';
                }
                // Update summary
                let present = 0, absent = 0;
                document.querySelectorAll('#admin-students-list input[type=checkbox]').forEach(cb => {
                    if (cb.checked) present++; else absent++;
                });
                document.getElementById('admin-summary-present').textContent = present;
                document.getElementById('admin-summary-absent').textContent = absent;
            };
        });
    </script>
</body>
</html>