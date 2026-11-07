<?php
// public/templates/teacher_header.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "Error: Database configuration is missing. Please contact the administrator.";
    header("Location: /login.php");
    exit();
}
require_once $db_path;

$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'teacher'");
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    error_log("Error fetching teacher: " . $stmt->error);
    $_SESSION['error_msg'] = "Error fetching teacher account.";
    header("Location: /login.php");
    exit();
}
$result = $stmt->get_result();
$user = $result->fetch_assoc();
if (!$user) {
    error_log("Teacher not found for user_id: $user_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}
$teacherName = htmlspecialchars($user['name'] ?? 'Teacher');
$profile_picture = '/assets/default_avatar.png';
if (!empty($user['profile_picture'])) {
    $potential_url = htmlspecialchars($user['profile_picture'], ENT_QUOTES, 'UTF-8');
    if (filter_var($potential_url, FILTER_VALIDATE_URL) && @getimagesize($potential_url)) {
        $profile_picture = $potential_url;
    } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . $potential_url)) {
        $profile_picture = $potential_url;
    } else {
        error_log("Invalid profile picture for user_id: $user_id, path: $potential_url");
        $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent) 
                    VALUES (?, 'Error', ?, ?, ?)";
        $log_stmt = $conn->prepare($log_sql);
        $description = "Invalid profile picture path: $potential_url";
        $ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
        $log_stmt->bind_param("isss", $user_id, $description, $ip_address, $user_agent);
        $log_stmt->execute();
        $log_stmt->close();
    }
}
$stmt->close();

$unread_sql = "SELECT COUNT(*) as unread_count 
               FROM notifications 
               WHERE is_read = 0 AND (user_id = ? OR target_role IN ('teacher', 'all'))";
$unread_stmt = $conn->prepare($unread_sql);
$unread_stmt->bind_param("i", $user_id);
if (!$unread_stmt->execute()) {
    error_log("Error fetching notifications: " . $unread_stmt->error);
    $unread_count = 0;
} else {
    $unread_result = $unread_stmt->get_result();
    $unread_count = $unread_result->fetch_assoc()['unread_count'];
    $unread_stmt->close();
}

$eval_unread_sql = "SELECT COUNT(*) as eval_unread_count 
                    FROM teacher_evaluations 
                    WHERE is_read = 0 AND teacher_id = ?";
$eval_unread_stmt = $conn->prepare($eval_unread_sql);
$eval_unread_stmt->bind_param("i", $user_id);
if (!$eval_unread_stmt->execute()) {
    error_log("Error fetching evaluations: " . $eval_unread_stmt->error);
    $eval_unread_count = 0;
} else {
    $eval_unread_result = $eval_unread_stmt->get_result();
    $eval_unread_count = $eval_unread_result->fetch_assoc()['eval_unread_count'];
    $eval_unread_stmt->close();
}

$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent) VALUES (?, 'Accessed Header', ?, ?, ?)";
$log_stmt = $conn->prepare($log_sql);
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$description = "Teacher header loaded, unread notifications: $unread_count, unread evaluations: $eval_unread_count";
$log_stmt->bind_param("isss", $user_id, $description, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Error logging header access: " . $log_stmt->error);
}
$log_stmt->close();

$page_title = isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') : "Teacher Portal - QueensPa School";
$page_description = isset($page_description) ? htmlspecialchars($page_description, ENT_QUOTES, 'UTF-8') : "Manage classes, assignments, attendance, and student progress with QueensPa's teacher portal.";
$page_keywords = isset($page_keywords) ? htmlspecialchars($page_keywords, ENT_QUOTES, 'UTF-8') : "QueensPa School, teacher portal, class management, lesson plans";
$base_url = 'https://' . $_SERVER['HTTP_HOST'];
$current_url = $base_url . "/teacher/" . basename($_SERVER['PHP_SELF']);

// Navigation structure for dropdowns
$nav_groups = [
    'Classes' => [
        'icon' => 'fa-chalkboard',
        'items' => [
            ['url' => '/teacher/view_classes.php', 'label' => 'View Classes'],
            ['url' => '/teacher/manage_students.php', 'label' => 'Manage Students'],
            ['url' => '/teacher/students_diagnosis.php', 'label' => 'Students Diagnosis'],
        ]
    ],
    'Academics' => [
        'icon' => 'fa-book',
        'items' => [
            ['url' => '/teacher/manage_assignments.php', 'label' => 'Manage Assignments'],
            ['url' => '/teacher/submit_grades.php', 'label' => 'Manage Grades'],
            ['url' => '/teacher/class_schedule.php', 'label' => 'Manage Schedules'],
            ['url' => '/teacher/lesson_plan.php', 'label' => 'Manage Lesson Plans'],
            ['url' => '/teacher/manage_chats.php', 'label' => 'Live Chat'],
            ['url' => '/teacher/manage_resources.php', 'label' => 'Manage Resources'],
            ['url' => '/teacher/manage_exam_schedules.php', 'label' => 'Manage Exam Schedules'],
            ['url' => '/teacher/manage_attendance.php', 'label' => 'Manage Attendance'],
        ]
    ],
    'Progress' => [
        'icon' => 'fa-chart-area',
        'items' => [
            ['url' => '/teacher/view_progress.php', 'label' => 'View Student Progress'],
            ['url' => '/teacher/view_student_reports.php', 'label' => 'View Student Reports'],
            ['url' => '/teacher/manage_behavior_comments.php', 'label' => 'Manage Behavior Comments'],
            ['url' => '/teacher/view_evaluations.php?teacher_id=' . $user_id, 'label' => 'View Evaluations', 'badge' => $eval_unread_count > 0 ? $eval_unread_count : null],
            ['url' => '/teacher/notifications.php', 'label' => 'Notifications', 'badge' => $unread_count > 0 ? $unread_count : null],
        ]
    ],
    'Profile' => [
        'icon' => 'fa-user',
        'items' => [
            ['url' => '/teacher/edit_profile.php', 'label' => 'Edit Profile', 'avatar' => $profile_picture],
            ['url' => '#', 'label' => 'Toggle Dark Mode', 'toggle_theme' => true],
            ['url' => '/logout.php', 'label' => 'Logout'],
        ]
    ]
];

$current_path = $_SERVER['REQUEST_URI'];
$active_group = '';
foreach ($nav_groups as $group_name => $group) {
    foreach ($group['items'] as $item) {
        if (isset($item['url']) && strpos($current_path, $item['url']) !== false && $item['url'] !== '#') {
            $active_group = $group_name;
            break 2;
        }
    }
}
$is_dashboard = (strpos($current_path, '/teacher/dashboard.php') !== false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo $page_description; ?>">
    <meta name="keywords" content="<?php echo $page_keywords; ?>">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="<?php echo $page_title; ?>">
    <meta property="og:description" content="<?php echo $page_description; ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo $current_url; ?>">
    <meta property="og:image" content="<?php echo $base_url; ?>/assets/images/logo.png">
    <meta property="og:site_name" content="QueensPa School">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $page_title; ?>">
    <meta name="twitter:description" content="<?php echo $page_description; ?>">
    <meta name="twitter:image" content="<?php echo $base_url; ?>/assets/images/logo.png">
    <meta name="twitter:site" content="@QueensPaSchool">
    <link rel="canonical" href="<?php echo $current_url; ?>">
    <title>👩‍🏫 <?php echo $page_title; ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $base_url; ?>/apple-touch-icon.png">
    <link rel="icon" type="image/x-icon" href="<?php echo $base_url; ?>/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $base_url; ?>/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $base_url; ?>/favicon-16x16.png">
    <link rel="manifest" href="<?php echo $base_url; ?>/site.webmanifest">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script defer src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'sans': ['Poppins', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.5s ease-out',
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'pulse-slow': 'pulseSlow 2s infinite',
                    },
                    keyframes: {
                        slideUp: { '0%': { transform: 'translateY(10px)', opacity: '0' }, '100%': { transform: 'translateY(0)', opacity: '1' } },
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        pulseSlow: { '0%': { transform: 'scale(1)' }, '50%': { transform: 'scale(1.08)' }, '100%': { transform: 'scale(1)' } },
                    },
                },
            },
        };
    </script>

    <style>
        /* ----- Glassmorphism base ----- */
        .glass {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.25);
        }
        .dark .glass {
            background: rgba(15, 23, 42, 0.7);
            border-color: rgba(255, 255, 255, 0.08);
        }

        body {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
            transition: background 0.3s, color 0.3s;
        }
        /* Light mode default background */
        body {
            background: #f1f5f9;
            color: #0f172a;
        }
        .dark body {
            background: #0f172a;
            color: #e2e8f0;
        }

        /* Fixed top navbar */
        .navbar-fixed {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 0.75rem 1.5rem;
            transition: background 0.3s, border-color 0.3s;
        }
        .main-content {
            padding-top: 5rem;
        }

        /* Dropdown container – relative for positioning */
        .dropdown-group {
            position: relative;
        }

        /* Desktop dropdown menu – appears below the button */
        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 200px;
            padding: 0.5rem 0;
            border-radius: 0.75rem;
            box-shadow: 0 12px 30px rgba(0,0,0,0.1);
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            z-index: 60;
        }
        .dark .dropdown-menu {
            background: rgba(15, 23, 42, 0.85);
            border-color: rgba(255, 255, 255, 0.1);
            box-shadow: 0 12px 30px rgba(0,0,0,0.4);
        }
        /* On desktop, show on hover or focus */
        .dropdown-group:hover .dropdown-menu,
        .dropdown-group:focus-within .dropdown-menu {
            display: block;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.2rem;
            color: #1e293b;
            transition: background 0.15s, color 0.15s;
            font-size: 0.9rem;
            border-radius: 0.25rem;
            text-decoration: none;
        }
        .dark .dropdown-item {
            color: #e2e8f0;
        }
        .dropdown-item:hover {
            background: rgba(14, 165, 233, 0.1);
            color: #0284c7;
        }
        .dark .dropdown-item:hover {
            background: rgba(14, 165, 233, 0.2);
            color: #38bdf8;
        }
        .dropdown-item .badge {
            margin-left: auto;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            padding: 0.1rem 0.5rem;
            border-radius: 9999px;
            animation: pulse-slow 2s infinite;
        }

        .nav-link-active {
            color: #0284c7 !important;
            font-weight: 600;
        }
        .dark .nav-link-active {
            color: #38bdf8 !important;
        }

        /* Navbar buttons and links */
        .nav-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            padding: 0.3rem 0.6rem;
            border-radius: 0.5rem;
            transition: background 0.2s, color 0.2s;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .dark .nav-btn {
            color: #e2e8f0;
        }
        .nav-btn:hover {
            background: rgba(14, 165, 233, 0.1);
        }
        .dark .nav-btn:hover {
            background: rgba(14, 165, 233, 0.15);
        }

        /* Search input */
        .search-bar input {
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 9999px;
            padding: 0.3rem 0.8rem;
            font-size: 0.85rem;
            color: #0f172a;
            transition: all 0.2s;
            outline: none;
            width: 160px;
        }
        .dark .search-bar input {
            background: rgba(15, 23, 42, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #e2e8f0;
        }
        .search-bar input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
        }
        .dark .search-bar input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.3);
        }

        /* Mobile hamburger */
        .hamburger {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #1e293b;
            cursor: pointer;
            padding: 0.3rem;
        }
        .dark .hamburger {
            color: #e2e8f0;
        }
        .hamburger:hover {
            transform: scale(1.05);
        }

        /* Mobile styles */
        @media (max-width: 1023px) {
            .hamburger {
                display: inline-block;
            }
            .desktop-nav {
                display: none;
                flex-direction: column;
                gap: 0.25rem;
                width: 100%;
                max-height: 70vh;
                overflow-y: auto;
                padding: 1rem 0.5rem;
                margin-top: 0.5rem;
                border-radius: 1rem;
                background: rgba(255, 255, 255, 0.7);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border: 1px solid rgba(255, 255, 255, 0.3);
                box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            }
            .dark .desktop-nav {
                background: rgba(15, 23, 42, 0.8);
                border-color: rgba(255, 255, 255, 0.05);
                box-shadow: 0 8px 30px rgba(0,0,0,0.4);
            }
            .desktop-nav.open {
                display: flex;
            }

            /* Each dropdown group is a block */
            .dropdown-group {
                position: relative;
                width: 100%;
            }
            .dropdown-group > .nav-btn {
                width: 100%;
                justify-content: space-between;
                padding: 0.5rem 0.2rem;
            }
            /* On mobile, dropdown menu is position: static, flows below the button */
            .dropdown-menu {
                position: static;
                box-shadow: none;
                background: transparent;
                backdrop-filter: none;
                border: none;
                padding: 0 0 0 1rem;
                margin-top: 0.2rem;
                display: none;
                width: 100%;
            }
            .dark .dropdown-menu {
                background: transparent;
                box-shadow: none;
                border: none;
            }
            .dropdown-group.open .dropdown-menu {
                display: block;
            }
            /* override desktop hover */
            .dropdown-group:hover .dropdown-menu {
                display: none;
            }
            .dropdown-group.open .dropdown-menu {
                display: block;
            }
            .dropdown-item {
                padding: 0.4rem 1rem;
            }

            /* Hide search on mobile to save space, but keep icon */
            .search-bar {
                display: none;
            }
            /* Adjust right side icons */
            .navbar-fixed .flex.items-center.gap-3 {
                gap: 0.5rem;
            }
        }

        @media (min-width: 1024px) {
            .desktop-nav {
                display: flex !important;
                align-items: center;
                gap: 0.5rem;
            }
            .dropdown-group > .nav-btn .fa-chevron-down {
                font-size: 0.7rem;
                opacity: 0.6;
            }
        }
    </style>
</head>
<body>

    <!-- Fixed Top Navbar with Glassmorphism -->
    <nav class="navbar-fixed glass" role="navigation">
        <div class="flex items-center justify-between flex-wrap w-full">
            <!-- Left: Brand + Hamburger -->
            <div class="flex items-center gap-3">
                <button class="hamburger" id="hamburgerBtn" onclick="toggleMobileMenu()" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <a href="/teacher/dashboard.php" class="flex items-center gap-2 font-semibold text-lg text-gray-800 dark:text-white">
                    <img src="/assets/images/logo.png" alt="QueensPa" onerror="this.src='/assets/images/default_logo.png'" class="w-8 h-8 rounded-full border-2 border-primary-500">
                    <span>QueensPa</span>
                </a>
            </div>

            <!-- Desktop Navigation -->
            <div class="desktop-nav" id="desktopNav">
                <!-- Dashboard link -->
                <a href="/teacher/dashboard.php" class="nav-btn <?php echo $is_dashboard ? 'nav-link-active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>

                <!-- Dropdown groups -->
                <?php foreach ($nav_groups as $group_name => $group): 
                    $is_active = ($group_name === $active_group);
                ?>
                <div class="dropdown-group <?php echo $is_active ? 'open' : ''; ?>">
                    <button class="nav-btn <?php echo $is_active ? 'nav-link-active' : ''; ?>" onclick="toggleDropdown(this)">
                        <i class="fas <?php echo $group['icon']; ?>"></i>
                        <?php echo htmlspecialchars($group_name); ?>
                        <i class="fas fa-chevron-down text-xs ml-1"></i>
                    </button>
                    <div class="dropdown-menu">
                        <?php foreach ($group['items'] as $item): 
                            $is_item_active = (isset($item['url']) && $item['url'] !== '#' && strpos($current_path, $item['url']) !== false);
                        ?>
                        <a href="<?php echo isset($item['url']) ? htmlspecialchars($item['url']) : '#'; ?>" 
                           class="dropdown-item <?php echo $is_item_active ? 'nav-link-active' : ''; ?>"
                           <?php echo isset($item['toggle_theme']) ? 'data-toggle-theme' : ''; ?>
                           onclick="<?php echo isset($item['toggle_theme']) ? 'toggleTheme(); return false;' : ''; ?>">
                            <?php if (isset($item['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($item['avatar']); ?>" alt="Profile" class="w-5 h-5 rounded-full border-2 border-primary-500">
                            <?php else: ?>
                                <i class="fas fa-circle text-[6px] text-primary-400"></i>
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($item['label']); ?></span>
                            <?php if (isset($item['badge']) && $item['badge']): ?>
                                <span class="badge"><?php echo $item['badge']; ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Right side: Search, Notification, Theme -->
            <div class="flex items-center gap-3">
                <div class="search-bar">
                    <input type="text" placeholder="Search..." id="navSearch" aria-label="Search">
                </div>
                <div class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300" id="notificationStatus">
                    <i class="fas fa-bell"></i>
                    <span id="notification-status-label">Checking...</span>
                </div>
                <button onclick="toggleTheme()" class="text-gray-700 dark:text-gray-200 hover:text-primary-500 transition" aria-label="Toggle dark mode">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content container mx-auto px-4 py-6">

    <script>
        // ----- Global functions -----
        function toggleMobileMenu() {
            const menu = document.getElementById('desktopNav');
            menu.classList.toggle('open');
            localStorage.setItem('mobileMenuOpen', menu.classList.contains('open') ? 'true' : 'false');
        }

        function toggleDropdown(btn) {
            // For mobile: toggle the dropdown group
            if (window.innerWidth < 1024) {
                const parent = btn.closest('.dropdown-group');
                // Close other dropdowns
                const siblings = parent.parentElement.querySelectorAll('.dropdown-group');
                siblings.forEach(group => {
                    if (group !== parent) {
                        group.classList.remove('open');
                    }
                });
                parent.classList.toggle('open');
            }
        }

        function toggleTheme() {
            document.documentElement.classList.toggle('dark');
            const isDark = document.documentElement.classList.contains('dark');
            localStorage.setItem('nightMode', isDark);
            const icon = document.getElementById('themeIcon');
            icon.classList.toggle('fa-moon');
            icon.classList.toggle('fa-sun');
        }

        // ----- Document ready -----
        $(document).ready(function() {
            // Apply theme
            if (localStorage.getItem('nightMode') === 'true') {
                $('html').addClass('dark');
                $('#themeIcon').removeClass('fa-moon').addClass('fa-sun');
            }

            // Restore mobile menu state
            if (localStorage.getItem('mobileMenuOpen') === 'true') {
                $('#desktopNav').addClass('open');
            }

            // Close mobile menu when clicking outside
            $(document).on('click', function(e) {
                if ($(window).width() < 1024) {
                    if (!$(e.target).closest('.navbar-fixed').length) {
                        $('#desktopNav').removeClass('open');
                        localStorage.setItem('mobileMenuOpen', 'false');
                    }
                }
            });

            // Close mobile menu when a link is clicked (except toggle-theme)
            $(document).on('click', '.dropdown-item, .desktop-nav > a', function() {
                if ($(window).width() < 1024) {
                    if (!$(this).attr('data-toggle-theme')) {
                        $('#desktopNav').removeClass('open');
                        localStorage.setItem('mobileMenuOpen', 'false');
                    }
                }
            });

            // ----- Search -----
            let searchTimeout;
            $('#navSearch').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const query = $(this).val().toLowerCase();
                    $('.dropdown-item span, .desktop-nav > a span').each(function() {
                        const text = $(this).text().toLowerCase();
                        const parent = $(this).closest('.dropdown-item, .desktop-nav > a');
                        if (text.includes(query) || query === '') {
                            parent.show();
                            // If inside a dropdown, ensure its parent dropdown is visible
                            parent.closest('.dropdown-group').find('.dropdown-menu').show();
                        } else {
                            parent.hide();
                        }
                    });
                }, 300);
            }).on('keypress', function(e) {
                if (e.key === 'Enter') {
                    const query = $(this).val().toLowerCase();
                    const firstMatch = $('.dropdown-item span, .desktop-nav > a span').filter(function() {
                        return $(this).text().toLowerCase().includes(query);
                    }).first();
                    if (firstMatch.length) {
                        window.location.href = firstMatch.closest('a').attr('href');
                    }
                }
            });

            // ----- Notification status -----
            updateNotificationStatus();

            // ----- Session keep-alive -----
            setInterval(() => {
                $.ajax({
                    url: '/keep_alive.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        if (data.status === 'expired') {
                            window.location.href = '/login.php';
                        }
                    }
                });
            }, 300000);

            // ----- Service Worker & Notifications (preserved) -----
            if ('serviceWorker' in navigator && 'Notification' in window && 'indexedDB' in window) {
                navigator.serviceWorker.register('/sw.js').then(registration => {
                    console.log('Service Worker registered:', registration);
                    checkNotificationPermission();
                    fetchAndCacheData();
                }).catch(err => {
                    console.error('Service Worker registration failed:', err);
                    $('#notification-status-label').text('Unsupported');
                    logNotificationError('Service Worker registration failed: ' + err);
                });
            } else {
                $('#notification-status-label').text('Unsupported');
                logNotificationError('Browser does not support Service Worker or Notifications');
            }

            function checkNotificationPermission() {
                if (Notification.permission === 'granted') {
                    $('#notification-status-label').text('Enabled');
                } else if (Notification.permission === 'denied') {
                    $('#notification-status-label').text('Disabled');
                } else {
                    $('#notification-status-label').text('Requesting...');
                    Notification.requestPermission().then(permission => {
                        $('#notification-status-label').text(permission === 'granted' ? 'Enabled' : 'Disabled');
                        if (permission !== 'granted') {
                            logNotificationError('Notification permission denied');
                        }
                    }).catch(err => {
                        $('#notification-status-label').text('Error');
                        logNotificationError('Notification permission error: ' + err);
                    });
                }
            }

            function fetchAndCacheData() {
                $.ajax({
                    url: '/api/get_teacher_schedule.php',
                    method: 'GET',
                    data: { user_id: <?php echo $user_id; ?> },
                    dataType: 'json',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    success: function(data) {
                        if (data.error) {
                            $('#notification-status-label').text('Schedule Error');
                            logNotificationError('Schedule fetch error: ' + data.error);
                            return;
                        }
                        saveToIndexedDB('schedules', data.schedules);
                        navigator.serviceWorker.controller?.postMessage({
                            type: 'UPDATE_SCHEDULES',
                            schedules: data.schedules
                        });
                    },
                    error: function() {
                        $('#notification-status-label').text('Schedule Error');
                    }
                });

                $.ajax({
                    url: '/api/get_teacher_notifications.php',
                    method: 'GET',
                    data: { user_id: <?php echo $user_id; ?> },
                    dataType: 'json',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    success: function(data) {
                        if (data.error) {
                            $('#notification-status-label').text('Notif Error');
                            logNotificationError('Notifications fetch error: ' + data.error);
                            return;
                        }
                        saveToIndexedDB('notifications', data.notifications);
                        navigator.serviceWorker.controller?.postMessage({
                            type: 'UPDATE_NOTIFICATIONS',
                            notifications: data.notifications
                        });
                    },
                    error: function() {
                        $('#notification-status-label').text('Notif Error');
                    }
                });
            }

            function initIndexedDB() {
                return new Promise((resolve, reject) => {
                    const request = indexedDB.open('QueensPaDB', 1);
                    request.onupgradeneeded = function(event) {
                        const db = event.target.result;
                        if (!db.objectStoreNames.contains('schedules')) {
                            db.createObjectStore('schedules', { keyPath: 'id' });
                        }
                        if (!db.objectStoreNames.contains('notifications')) {
                            db.createObjectStore('notifications', { keyPath: 'id' });
                        }
                    };
                    request.onsuccess = function(event) {
                        resolve(event.target.result);
                    };
                    request.onerror = function(event) {
                        reject('IndexedDB error: ' + event.target.error);
                    };
                });
            }

            function saveToIndexedDB(storeName, data) {
                initIndexedDB().then(db => {
                    const transaction = db.transaction([storeName], 'readwrite');
                    const store = transaction.objectStore(storeName);
                    data.forEach(item => store.put(item));
                    transaction.oncomplete = () => {
                        console.log(`${storeName} saved to IndexedDB`);
                    };
                    transaction.onerror = (err) => {
                        logNotificationError(`Error saving ${storeName} to IndexedDB: ${err}`);
                    };
                }).catch(err => {
                    logNotificationError('IndexedDB initialization error: ' + err);
                });
            }

            function logNotificationError(description) {
                $.ajax({
                    url: '/api/log_error.php',
                    method: 'POST',
                    data: {
                        user_id: <?php echo $user_id; ?>,
                        action: 'Notification Error',
                        description: description,
                        ip_address: '<?php echo $ip_address; ?>',
                        user_agent: '<?php echo $user_agent; ?>'
                    },
                    success: function() {
                        console.log('Notification error logged');
                    },
                    error: function() {
                        console.error('Failed to log notification error');
                    }
                });
            }

            function updateNotificationStatus() {
                let total = <?php echo $unread_count + $eval_unread_count; ?>;
                let status = total > 0 ? total + ' unread' : 'No new';
                $('#notification-status-label').text(status);
            }
        });
    </script>

    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "QueensPa School",
        "url": "https://qpmsedu.com",
        "logo": "https://qpmsedu.com/assets/images/logo.png"
    }
    </script>
</body>
</html>