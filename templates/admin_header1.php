<?php
// public/templates/admin_header1.php

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
try {
    require_once($db_path);
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Database configuration error.";
    header("Location: /login.php");
    exit();
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Restrict to admins who are assigned as teachers
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an admin with teaching privileges.";
    header("Location: /login.php");
    exit();
}

// Validate that the admin is assigned as a teacher
$user_id = (int)$_SESSION['user_id'];
$teacher_check_sql = "SELECT 1 FROM classes WHERE teacher_id = ? UNION SELECT 1 FROM class_schedules WHERE teacher_id = ?";
$teacher_check_stmt = $conn->prepare($teacher_check_sql);
if ($teacher_check_stmt) {
    $teacher_check_stmt->bind_param("ii", $user_id, $user_id);
    $teacher_check_stmt->execute();
    if ($teacher_check_stmt->get_result()->num_rows === 0) {
        error_log("Admin not assigned as teacher: user_id=$user_id");
        $_SESSION['error_msg'] = "You do not have teaching privileges.";
        header("Location: /login.php");
        exit();
    }
    $teacher_check_stmt->close();
} else {
    error_log("Failed to prepare teacher check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate teaching privileges.";
    header("Location: /login.php");
    exit();
}

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Validate user_id exists in users table
$user_role = $_SESSION['user_role'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $user_id);
    $user_check_stmt->execute();
    if ($user_check_stmt->get_result()->num_rows === 0) {
        error_log("Invalid user_id in session: $user_id, role: $user_role");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$action = "Accessed Admin Header";
$description = "Admin with teaching role accessed admin header";
logActivity($conn, $user_id, $action, $description);

// Generate CSRF token and clean up expired tokens
$conn->begin_transaction();
try {
    $csrf_token = bin2hex(random_bytes(32));
    $csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
    $csrf_stmt = $conn->prepare($csrf_sql);
    if (!$csrf_stmt) {
        throw new Exception("Prepare failed for CSRF token: " . $conn->error);
    }
    $csrf_stmt->bind_param("is", $user_id, $csrf_token);
    $csrf_stmt->execute();
    $csrf_stmt->close();

    $cleanup_sql = "DELETE FROM csrf_tokens WHERE expires_at < NOW()";
    $conn->query($cleanup_sql);
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log("CSRF token error: " . $e->getMessage());
}

// Fetch admin name, profile picture, unread notifications count, and unread evaluations count
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$adminName = htmlspecialchars($user['name'] ?? 'Admin');
$profile_picture = null;
if (!empty($user['profile_picture'])) {
    $potential_url = htmlspecialchars($user['profile_picture'], ENT_QUOTES, 'UTF-8');
    if (filter_var($potential_url, FILTER_VALIDATE_URL) && @getimagesize($potential_url)) {
        $profile_picture = $potential_url;
    } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . $potential_url)) {
        $profile_picture = $potential_url;
    } else {
        error_log("Invalid profile picture for user_id: $user_id, path: $potential_url");
    }
}
$stmt->close();

// Fetch unread notifications count
$unread_sql = "SELECT COUNT(*) as unread_count 
               FROM notifications 
               WHERE is_read = 0 AND (user_id = ? OR target_role IN ('admin', 'teacher', 'all'))";
$unread_stmt = $conn->prepare($unread_sql);
$unread_stmt->bind_param("i", $user_id);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_count = $unread_result->fetch_assoc()['unread_count'];
$unread_stmt->close();

// Fetch unread evaluations count
$eval_unread_sql = "SELECT COUNT(*) as eval_unread_count 
                    FROM teacher_evaluations 
                    WHERE is_read = 0 AND teacher_id = ?";
$eval_unread_stmt = $conn->prepare($eval_unread_sql);
$eval_unread_stmt->bind_param("i", $user_id);
$eval_unread_stmt->execute();
$eval_unread_result = $eval_unread_stmt->get_result();
$eval_unread_count = $eval_unread_result->fetch_assoc()['eval_unread_count'];
$eval_unread_stmt->close();

// Log header access with notification and evaluation counts
$description = "Admin header loaded, unread notifications: $unread_count, unread evaluations: $eval_unread_count";
logActivity($conn, $user_id, $action, $description);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="QueensPa School Admin Portal for managing classes, assignments, attendance, grades, and lesson plans.">
    <meta name="keywords" content="QueensPa School, admin portal, school management, class management, lesson plans">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Admin Portal">
    <meta property="og:description" content="Manage classes, assignments, attendance, grades, and lesson plans with QueensPa's admin portal.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/dashboard.php">
    <meta property="og:image" content="https://qpmsedu.com/assets/images/logo.png">
    <meta property="og:site_name" content="QueensPa School">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="QueensPa School Admin Portal">
    <meta name="twitter:description" content="Manage classes, assignments, attendance, grades, and lesson plans with QueensPa's admin portal.">
    <meta name="twitter:image" content="https://qpmsedu.com/assets/images/logo.png">
    <title>👩‍💼 QueensPa | Admin Portal</title>
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --accent-hover: #e0a800;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --dark-bg: #1a1a1a;
            --dark-card-bg: #2c2c2c;
            --dark-text: #e0e0e0;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 60px;
        }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: linear-gradient(135deg, var(--bg-color), #fff3e0);
            color: var(--text-color);
            margin: 0;
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
        }
        body.dark-mode {
            background: linear-gradient(135deg, #121212, var(--dark-bg));
            color: var(--dark-text);
        }
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(to bottom, var(--primary-color), #ff6b00);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            transition: width 0.3s ease;
            box-shadow: var(--shadow);
            z-index: 1000;
        }
        .dark-mode .sidebar {
            background: linear-gradient(to bottom, #555555, #777777);
        }
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }
        .sidebar.collapsed .sidebar-brand span,
        .sidebar.collapsed .nav-link span,
        .sidebar.collapsed .dropdown-menu,
        .sidebar.collapsed .search-bar,
        .sidebar.collapsed .badge-notifications,
        .sidebar.collapsed .badge-evaluations {
            display: none;
        }
        .sidebar.collapsed .nav-link i {
            font-size: 1.5rem;
            margin: 0 auto;
        }
        .sidebar-brand {
            display: flex;
            align-items: center;
            padding: 1rem;
            color: #fff;
            font-weight: 700;
            font-size: 1.5rem;
            text-decoration: none;
            transition: transform 0.3s ease;
        }
        .sidebar-brand img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .sidebar-brand:hover {
            transform: scale(1.05);
        }
        .sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .nav-item {
            position: relative;
        }
        .nav-link {
            color: #fff !important;
            font-weight: 500;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 8px;
            margin: 0.25rem 0.5rem;
            transition: background-color 0.3s, transform 0.3s;
        }
        .nav-link:hover {
            background-color: var(--primary-hover);
            transform: translateX(5px);
        }
        .nav-link.active {
            background-color: var(--primary-hover);
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .dropdown-toggle::after {
            margin-left: auto;
        }
        .dropdown-menu {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: none;
            position: absolute;
            left: var(--sidebar-width);
            top: 0;
            animation: slideRight 0.3s ease;
        }
        .dark-mode .dropdown-menu {
            background: var(--dark-card-bg);
        }
        .dropdown-item {
            color: var(--text-color);
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.3s, color 0.3s;
        }
        .dark-mode .dropdown-item {
            color: var(--dark-text);
        }
        .dropdown-item:hover {
            background-color: var(--bg-color);
            color: var(--primary-color);
        }
        .dark-mode .dropdown-item:hover {
            background-color: #3a3a3a;
        }
        .profile-img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            transition: transform 0.3s;
        }
        .profile-img:hover {
            transform: scale(1.1);
        }
        .ripple {
            position: relative;
            overflow: hidden;
        }
        .ripple::after {
            content: '';
            position: absolute;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        @keyframes ripple {
            to { transform: scale(4); opacity: 0; }
        }
        @keyframes slideRight {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .search-bar {
            padding: 0.5rem 1rem;
        }
        .search-bar input {
            border-radius: 20px;
            border: none;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            width: 100%;
        }
        .search-bar input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.2);
        }
        .dark-mode .search-bar input {
            background: #3a3a3a;
            color: var(--dark-text);
        }
        .content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
            transition: margin-left 0.3s ease;
        }
        .sidebar.collapsed ~ .content {
            margin-left: var(--sidebar-collapsed-width);
        }
        .hamburger {
            display: none;
            color: #fff;
            font-size: 1.5rem;
            padding: 1rem;
            cursor: pointer;
        }
        .badge-notifications, .badge-evaluations {
            font-size: 0.65rem;
            padding: 0.2em 0.5em;
            margin-left: 0.5rem;
        }
        @media (min-width: 992px) {
            .nav-item:hover .dropdown-menu {
                display: block;
            }
        }
        @media (max-width: 991.98px) {
            .sidebar {
                width: 0;
                overflow: hidden;
            }
            .sidebar.active {
                width: var(--sidebar-width);
            }
            .sidebar.collapsed {
                width: 0;
            }
            .content {
                margin-left: 0;
            }
            .hamburger {
                display: block;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1001;
            }
            .sidebar.active ~ .content {
                margin-left: 0;
            }
            .dropdown-menu {
                position: static;
                width: 100%;
                margin: 0.5rem 1rem;
            }
        }
        @media (max-width: 576px) {
            .sidebar-brand {
                font-size: 1.25rem;
            }
            .nav-link {
                font-size: 0.9rem;
                padding: 0.5rem 1rem;
            }
            .profile-img {
                width: 30px;
                height: 30px;
            }
            .content {
                padding: 1rem;
            }
        }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" media="all" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" media="all" onload="this.media='all'">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" media="all" onload="this.media='all'">
</head>
<body>
    <div class="hamburger ripple" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </div>
    <nav class="sidebar" id="sidebar">
        <a class="sidebar-brand ripple" href="/admin/dashboard.php">
            <img src="/assets/images/logo.png" alt="QueensPa School Logo" onerror="this.src='/assets/images/default_logo.png'">
            <span>QueensPa</span>
        </a>
        <div class="search-bar">
            <input type="text" class="form-control" placeholder="Search..." aria-label="Search admin panel">
        </div>
        <ul class="sidebar-nav">
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle ripple" href="#" id="classesMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-chalkboard"></i> <span>Classes</span>
                </a>
                <ul class="dropdown-menu" aria-labelledby="classesMenu">
                    <li><a class="dropdown-item ripple" href="/admin/tview_classes.php"><i class="fas fa-chalkboard"></i> View Classes</a></li>
                    <li><a class="dropdown-item ripple" href="/admin/class_schedule.php"><i class="fas fa-calendar"></i> Schedule</a></li>
                </ul>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/class_schedule.php"><i class="fas fa-calendar"></i> <span>Schedule</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/manage_students.php"><i class="fas fa-user-graduate"></i> <span>Students</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/assignments.php"><i class="fas fa-book"></i> <span>Assignments</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/manage_attendance.php"><i class="fas fa-calendar-check"></i> <span>Attendance</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/manage_grades.php"><i class="fas fa-chart-line"></i> <span>Grades</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/lesson_schedule.php"><i class="fas fa-clipboard-list"></i> <span>Lesson Management</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/view_evaluations.php?teacher_id=<?= htmlspecialchars($user_id) ?>">
                    <i class="fas fa-star"></i> <span>Evaluations</span>
                    <?php if ($eval_unread_count > 0): ?>
                        <span class="badge bg-danger badge-evaluations"><?= $eval_unread_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link ripple" href="/admin/tnotifications.php">
                    <i class="fas fa-bell"></i> <span>Notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge bg-danger badge-notifications"><?= $unread_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle ripple" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php if ($profile_picture): ?>
                        <img src="<?= $profile_picture ?>" alt="Profile Picture" class="profile-img">
                    <?php else: ?>
                        <i class="fas fa-user-circle fa-2x"></i>
                    <?php endif; ?>
                    <span><?= $adminName ?></span>
                </a>
                <ul class="dropdown-menu" aria-labelledby="profileDropdown">
                    <li><a class="dropdown-item ripple" href="/admin/edit_profile.php"><i class="fas fa-user-edit"></i> Edit Profile</a></li>
                    <li><a class="dropdown-item ripple toggle-theme" href="#"><i class="fas fa-moon"></i> Toggle Dark Mode</a></li>
                    <li><a class="dropdown-item ripple" href="/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </li>
        </ul>
    </nav>
    <div class="content" id="content">
        <!-- Content continues on the page... -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script>
        // Apply theme immediately
        if (localStorage.getItem('nightMode') === 'true') {
            document.documentElement.classList.add('dark-mode');
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Ripple effect
            document.querySelectorAll('.ripple').forEach(el => {
                el.addEventListener('click', (e) => {
                    const rect = el.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const ripple = document.createElement('span');
                    ripple.style.position = 'absolute';
                    ripple.style.background = 'rgba(255, 255, 255, 0.3)';
                    ripple.style.borderRadius = '50%';
                    ripple.style.transform = 'scale(0)';
                    ripple.style.animation = 'ripple 0.6s linear';
                    ripple.style.left = `${x}px`;
                    ripple.style.top = `${y}px`;
                    el.appendChild(ripple);
                    setTimeout(() => ripple.remove(), 600);
                });
            });

            // Active nav link highlighting
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.nav-link').forEach(link => {
                const href = link.getAttribute('href').split('?')[0].split('/').pop();
                if (href === currentPage || (href === 'dashboard.php' && currentPage === '')) {
                    link.classList.add('active');
                }
            });

            // Search functionality
            const searchInput = document.querySelector('.search-bar input');
            searchInput.addEventListener('input', (e) => {
                const query = e.target.value.toLowerCase();
                document.querySelectorAll('.nav-link span').forEach(item => {
                    const text = item.textContent.toLowerCase();
                    const parent = item.closest('.nav-item');
                    parent.style.display = text.includes(query) || query === '' ? 'block' : 'none';
                });
            });

            // Sidebar collapse for larger screens
            document.querySelector('.sidebar-brand').addEventListener('click', (e) => {
                if (window.innerWidth >= 992) {
                    e.preventDefault();
                    toggleSidebar();
                }
            });
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
            sidebar.classList.toggle('active', window.innerWidth < 992);
        }

        function toggleTheme() {
            document.documentElement.classList.toggle('dark-mode');
            localStorage.setItem('nightMode', document.documentElement.classList.contains('dark-mode'));
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            const dropdowns = document.querySelectorAll('.dropdown-menu');
            dropdowns.forEach(dropdown => {
                if (!dropdown.parentElement.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>