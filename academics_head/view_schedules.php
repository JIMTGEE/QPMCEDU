<?php
// Suppress display errors, log them instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/logs/php_errors.log');

ob_start();
session_start();

// Restrict to admins
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . 
              ", user_role=" . ($_SESSION['user_role'] ?? 'unset') . 
              ", requested_url=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    $_SESSION['error_msg'] = "Please log in as an administrator.";
    header("Location: /admin/login.php");
    exit();
}

// Include database connection and functions
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
    require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');
} catch (Exception $e) {
    error_log("Failed to include required files: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to initialize.";
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
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$csrf_token = isset($_GET['csrf_token']) ? trim($_GET['csrf_token']) : '';

// Validate CSRF token
$csrf_valid = false;
if (!empty($csrf_token)) {
    $csrf_sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_stmt = $conn->prepare($csrf_sql)) {
        $csrf_stmt->bind_param("is", $admin_id, $csrf_token);
        $csrf_stmt->execute();
        $csrf_valid = $csrf_stmt->get_result()->num_rows > 0;
        $csrf_stmt->close();
    } else {
        error_log("CSRF query preparation failed: " . $conn->error);
    }
}

if (!$csrf_valid) {
    error_log("Invalid CSRF token for user_id=$admin_id, token=$csrf_token");
    $_SESSION['error_msg'] = "Invalid session. Please try again.";
    header("Location: /admin/lesson_schedule.php");
    exit();
}

// Validate teacher_id
$teacher_sql = "SELECT id, name FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Invalid teacher_id=$teacher_id for user_id=$admin_id");
    $_SESSION['error_msg'] = "Teacher not found.";
    header("Location: /admin/lesson_schedule.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Schedules', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Admin viewed schedules for teacher ID $teacher_id";
$log_stmt->bind_param("isss", $admin_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log page access: " . $log_stmt->error);
}
$log_stmt->close();

// Handle AJAX request for schedules
if (isset($_GET['action']) && $_GET['action'] === 'get_schedules') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    $filter_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $filter_day = isset($_GET['day']) ? trim($_GET['day']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;

    $response = ['schedules' => [], 'total_pages' => 1, 'error' => '', 'debug' => ''];

    try {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM class_schedules cs 
                      LEFT JOIN classes c ON cs.class_id = c.id 
                      WHERE cs.teacher_id = ?";
        $params = [$teacher_id];
        $types = "i";
        $conditions = [];

        if ($filter_class_id > 0) {
            $conditions[] = "cs.class_id = ?";
            $params[] = $filter_class_id;
            $types .= "i";
        }
        if (!empty($filter_day)) {
            $conditions[] = "cs.day = ?";
            $params[] = $filter_day;
            $types .= "s";
        }

        if ($conditions) {
            $count_sql .= " AND " . implode(" AND ", $conditions);
        }

        $count_stmt = $conn->prepare($count_sql);
        if (!$count_stmt) {
            throw new Exception("Count query preparation failed: " . $conn->error);
        }
        $count_stmt->bind_param($types, ...$params);
        if (!$count_stmt->execute()) {
            throw new Exception("Count query execution failed: " . $count_stmt->error);
        }
        $total = $count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();

        $total_pages = ceil($total / $per_page);
        $response['total_pages'] = $total_pages;

        $schedules_sql = "SELECT cs.id, COALESCE(c.class_name, 'N/A') as class_name, 
                                 cs.day, cs.start_time, cs.end_time 
                          FROM class_schedules cs 
                          LEFT JOIN classes c ON cs.class_id = c.id 
                          WHERE cs.teacher_id = ?";
        if ($conditions) {
            $schedules_sql .= " AND " . implode(" AND ", $conditions);
        }
        $schedules_sql .= " ORDER BY cs.day, cs.start_time LIMIT ?, ?";
        $params[] = $offset;
        $params[] = $per_page;
        $types .= "ii";

        $schedules_stmt = $conn->prepare($schedules_sql);
        if (!$schedules_stmt) {
            throw new Exception("Schedules query preparation failed: " . $conn->error);
        }
        $schedules_stmt->bind_param($types, ...$params);
        if (!$schedules_stmt->execute()) {
            throw new Exception("Schedules query execution failed: " . $schedules_stmt->error);
        }
        $result = $schedules_stmt->get_result();
        $schedules = $result->fetch_all(MYSQLI_ASSOC);
        $schedules_stmt->close();

        $response['schedules'] = $schedules;
        $response['debug'] = "Fetched $total schedules for teacher_id=$teacher_id, page=$page, filters: class_id=$filter_class_id, day=$filter_day";
        error_log($response['debug'] . ", schedules_count=" . count($schedules));
    } catch (Exception $e) {
        $response['error'] = "Failed to load schedules.";
        $response['debug'] = "Exception: " . $e->getMessage();
        error_log("Schedules query error for teacher_id=$teacher_id: " . $e->getMessage());
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response);
    ob_end_flush();
    exit();
}

// Fetch classes for filters
$classes_sql = "SELECT DISTINCT c.id, c.class_name 
                FROM classes c 
                INNER JOIN class_schedules cs ON c.id = cs.class_id 
                WHERE cs.teacher_id = ? 
                ORDER BY c.class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $teacher_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// Fetch days for filters
$days_sql = "SELECT DISTINCT day 
             FROM class_schedules 
             WHERE teacher_id = ? 
             ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$days_stmt = $conn->prepare($days_sql);
$days_stmt->bind_param("i", $teacher_id);
$days_stmt->execute();
$days = $days_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$days_stmt->close();

// Fetch summary stats
$stats_sql = "SELECT 
                (SELECT COUNT(*) 
                 FROM class_schedules 
                 WHERE teacher_id = ?) as total_schedules,
                (SELECT COUNT(DISTINCT class_id) 
                 FROM class_schedules 
                 WHERE teacher_id = ?) as total_classes,
                (SELECT COUNT(DISTINCT day) 
                 FROM class_schedules 
                 WHERE teacher_id = ?) as total_days";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("iii", $teacher_id, $teacher_id, $teacher_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Generate new CSRF token for page
$new_csrf_token = bin2hex(random_bytes(32));
$csrf_insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
                    VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_insert_stmt = $conn->prepare($csrf_insert_sql);
$csrf_insert_stmt->bind_param("is", $admin_id, $new_csrf_token);
$csrf_insert_stmt->execute();
$csrf_insert_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin view of teacher schedules at QueensPa School.">
    <meta name="keywords" content="QueensPa School, schedules, admin dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Admin Schedules">
    <meta property="og:description" content="Admin interface for viewing teacher schedules.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/view_schedules.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Admin Schedules">
    <meta name="twitter:description" content="Admin interface for viewing teacher schedules.">
    <title>View Schedules - QueensPa School Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 1400px;
            padding: 2rem;
        }
        .card {
            border: none;
            border-radius: 16px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            border-radius: 16px 16px 0 0;
            padding: 1.5rem;
            color: #fff;
        }
        .card-header h2 {
            margin: 0;
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            animation: animate__pulse 0.5s;
        }
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
            border-radius: 10px;
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
        }
        .form-select {
            border-radius: 10px;
            border: 1px solid #ced4da;
            padding: 0.75rem;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.2);
            outline: none;
        }
        .table-responsive {
            border-radius: 10px;
            overflow-x: auto;
        }
        .table thead {
            background: var(--primary-color);
            color: #fff;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .pagination .page-link {
            color: var(--primary-color);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .stats-card {
            background: #f9f9f9;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.3s ease;
        }
        .stats-card:hover {
            transform: scale(1.05);
        }
        .stats-card h5 {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        .stats-card p {
            font-size: 1.2rem;
            color: var(--text-color);
        }
        .loading-spinner {
            display: none;
            color: var(--primary-color);
            margin-left: 10px;
        }
        #loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        #loading-spinner {
            color: var(--primary-color);
            font-size: 2rem;
        }
        @media (max-width: 991.98px) {
            .container {
                padding: 1rem;
            }
            .stats-card {
                margin-bottom: 1rem;
            }
        }
        @media (max-width: 575.98px) {
            .card-header h2 {
                font-size: 1.5rem;
            }
            .stats-card h5 {
                font-size: 1.2rem;
            }
            .stats-card p {
                font-size: 1rem;
            }
            .table-responsive {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div id="loading-overlay" role="status" aria-label="Loading">
        <i class="fas fa-spinner fa-spin" id="loading-spinner" aria-hidden="true"></i>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>
    <div class="container my-5">
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>
        <section class="card mb-5 animate__animated animate__fadeIn">
            <div class="card-header">
                <h2><i class="fas fa-calendar-alt me-2"></i>Schedules for <?php echo htmlspecialchars($teacher['name']); ?></h2>
            </div>
            <div class="card-body">
                <div class="row g-4 mb-4">
                    <div class="col-md-4">
                        <div class="stats-card animate__animated animate__fadeIn">
                            <h5>Total Schedules</h5>
                            <p><?php echo $stats['total_schedules']; ?></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card animate__animated animate__fadeIn">
                            <h5>Classes Taught</h5>
                            <p><?php echo $stats['total_classes']; ?></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card animate__animated animate__fadeIn">
                            <h5>Days Scheduled</h5>
                            <p><?php echo $stats['total_days']; ?></p>
                        </div>
                    </div>
                </div>
                <form class="filter-form mb-4" id="filter-form">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="filter_class_id" class="form-label">Filter by Class</label>
                            <select class="form-select" id="filter_class_id" name="class_id">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['id']); ?>">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="filter_day" class="form-label">Filter by Day</label>
                            <select class="form-select" id="filter_day" name="day">
                                <option value="">All Days</option>
                                <?php foreach ($days as $day): ?>
                                    <option value="<?php echo htmlspecialchars($day['day']); ?>">
                                        <?php echo htmlspecialchars($day['day']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary mt-3" data-bs-toggle="tooltip" title="Apply filters to schedules">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <span class="loading-spinner filter-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        </div>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Class</th>
                                <th>Day</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="schedules-table"></tbody>
                    </table>
                </div>
                <nav aria-label="Schedules pagination" id="pagination"></nav>
            </div>
        </section>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailsModalLabel"><i class="fas fa-calendar-alt me-2"></i>Schedule Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="details-content"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous"></script>
    <script>
        // Dependency check
        if (typeof jQuery === 'undefined') {
            console.error('jQuery is not loaded. Please check the CDN or network.');
        }
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap is not loaded. Please check the CDN or network.');
        }

        document.addEventListener('DOMContentLoaded', () => {
            console.log('DOM loaded, initializing scripts');
            document.getElementById('loading-overlay').style.display = 'none';

            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            // Auto-close alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            // Filter form submission
            document.getElementById('filter-form').addEventListener('submit', (e) => {
                e.preventDefault();
                loadSchedules(1);
            });

            // Load schedules
            loadSchedules(1);
        });

        function loadSchedules(page) {
            const tableBody = document.getElementById('schedules-table');
            const pagination = document.getElementById('pagination');
            const spinner = document.querySelector('.filter-spinner');
            const class_id = document.getElementById('filter_class_id').value;
            const day = document.getElementById('filter_day').value;

            spinner.style.display = 'inline-block';
            tableBody.innerHTML = '';
            pagination.innerHTML = '';

            $.get('<?php echo $_SERVER['PHP_SELF']; ?>?action=get_schedules', {
                teacher_id: <?php echo $teacher_id; ?>,
                class_id: class_id,
                day: day,
                page: page,
                t: new Date().getTime()
            }, function(response) {
                spinner.style.display = 'none';
                console.log('Schedules response:', response);

                if (response.error) {
                    tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">
                        Error: ${response.error} (${response.debug})
                    </td></tr>`;
                } else if (response.schedules.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="6" class="text-center">No schedules found.</td></tr>';
                } else {
                    response.schedules.forEach(schedule => {
                        const row = `
                            <tr>
                                <td>${schedule.id}</td>
                                <td>${schedule.class_name}</td>
                                <td>${schedule.day}</td>
                                <td>${schedule.start_time}</td>
                                <td>${schedule.end_time}</td>
                                <td>
                                    <button class="btn btn-info btn-sm view-details-btn" 
                                            data-id="${schedule.id}" 
                                            data-bs-toggle="tooltip" 
                                            title="View schedule details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>`;
                        tableBody.insertAdjacentHTML('beforeend', row);
                    });

                    let paginationHtml = `
                        <ul class="pagination justify-content-center">
                            <li class="page-item ${page <= 1 ? 'disabled' : ''}">
                                <a class="page-link" href="#" data-page="${page - 1}" aria-label="Previous">
                                    <span aria-hidden="true">«</span>
                                </a>
                            </li>`;
                    for (let i = 1; i <= response.total_pages; i++) {
                        paginationHtml += `
                            <li class="page-item ${i === page ? 'active' : ''}">
                                <a class="page-link" href="#" data-page="${i}">${i}</a>
                            </li>`;
                    }
                    paginationHtml += `
                            <li class="page-item ${page >= response.total_pages ? 'disabled' : ''}">
                                <a class="page-link" href="#" data-page="${page + 1}" aria-label="Next">
                                    <span aria-hidden="true">»</span>
                                </a>
                            </li>
                        </ul>`;
                    pagination.innerHTML = paginationHtml;

                    // Re-initialize tooltips
                    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

                    // Pagination click handler
                    document.querySelectorAll('.page-link').forEach(link => {
                        link.addEventListener('click', (e) => {
                            e.preventDefault();
                            const newPage = link.getAttribute('data-page');
                            if (newPage) {
                                loadSchedules(newPage);
                            }
                        });
                    });

                    // View details button handler
                    document.querySelectorAll('.view-details-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const scheduleId = btn.getAttribute('data-id');
                            const schedule = response.schedules.find(s => s.id == scheduleId);
                            if (schedule) {
                                const details = `
                                    <h5>Schedule ID: ${schedule.id}</h5>
                                    <p><strong>Class:</strong> ${schedule.class_name}</p>
                                    <p><strong>Day:</strong> ${schedule.day}</p>
                                    <p><strong>Start Time:</strong> ${schedule.start_time}</p>
                                    <p><strong>End Time:</strong> ${schedule.end_time}</p>`;
                                document.getElementById('details-content').innerHTML = details;
                                $('#detailsModal').modal('show');
                            }
                        });
                    });
                }
            }, 'json').fail(function(xhr, status, error) {
                spinner.style.display = 'none';
                console.error('Schedules AJAX error:', status, error, xhr.responseText);
                tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading schedules: ' + status + ' - ' + error + '</td></tr>';
            });
        }
    </script>
</body>
</html>

<?php
mysqli_close($conn);
ob_end_flush();
?>