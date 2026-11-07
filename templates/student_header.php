<?php
// public/templates/student_header.php

// Start session only if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify user is logged in and has student role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a student.";
    header("Location: /login.php");
    exit();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "Error: Database configuration is missing. Please contact the administrator.";
    header("Location: /login.php");
    exit();
}
require_once $db_path;

// Fetch student data
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    error_log("Error fetching student: " . $stmt->error);
    $_SESSION['error_msg'] = "Error fetching student account.";
    header("Location: /login.php");
    exit();
}
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    error_log("Student not found for user_id: $user_id");
    $_SESSION['error_msg'] = "Student account not found.";
    header("Location: /login.php");
    exit();
}

// Validate profile picture
$profile_picture = '/assets/default_avatar.png'; // Default
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
$studentName = htmlspecialchars($user['name'] ?? 'Student');

// Fetch unread notifications count
$unread_sql = "SELECT COUNT(*) as unread_count 
               FROM notifications 
               WHERE is_read = 0 AND (user_id = ? OR target_role IN ('student', 'all'))";
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

// Log header access
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent) 
            VALUES (?, 'Accessed Header', ?, ?, ?)";
$log_stmt = $conn->prepare($log_sql);
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$description = "Student header loaded, unread notifications: $unread_count";
$log_stmt->bind_param("isss", $user_id, $description, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Error logging header access: " . $log_stmt->error);
}
$log_stmt->close();

// Dynamic SEO meta tags
$page_title = isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') : "Student Portal - QueensPa School";
$page_description = isset($page_description) ? htmlspecialchars($page_description, ENT_QUOTES, 'UTF-8') : "Access assignments, grades, schedules, and learning resources with QueensPa's student portal.";
$page_keywords = isset($page_keywords) ? htmlspecialchars($page_keywords, ENT_QUOTES, 'UTF-8') : "QueensPa School, student portal, assignments, grades, lesson plans";
$base_url = 'https://' . $_SERVER['HTTP_HOST'];
$current_url = "https://qpmsedu.com/student/" . basename($_SERVER['PHP_SELF']);
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
    <meta property="og:image" content="https://qpmsedu.com/assets/images/logo.png">
    <meta property="og:site_name" content="QueensPa School">
    <meta property="og:locale" content="en_US">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $page_title; ?>">
    <meta name="twitter:description" content="<?php echo $page_description; ?>">
    <meta name="twitter:image" content="https://qpmsedu.com/assets/images/logo.png">
    <meta name="twitter:site" content="@QueensPaSchool">
    <link rel="canonical" href="<?php echo $current_url; ?>">
    <title><?php echo $page_title; ?></title>

    <!-- Favicon links -->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $base_url; ?>/apple-touch-icon.png">
    <link rel="icon" type="image/x-icon" href="<?php echo $base_url; ?>/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $base_url; ?>/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $base_url; ?>/favicon-16x16.png">
    <link rel="manifest" href="<?php echo $base_url; ?>/site.webmanifest">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Poppins', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        orange: {
                            50: '#fff7ed',
                            500: '#ff6200',
                            600: '#e55b00',
                            700: '#cc5200',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.5s ease-out',
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'pulse-slow': 'pulseSlow 2s infinite',
                    },
                    keyframes: {
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        pulseSlow: {
                            '0%': { transform: 'scale(1)' },
                            '50%': { transform: 'scale(1.08)' },
                            '100%': { transform: 'scale(1)' },
                        },
                    },
                },
            },
        };
    </script>

    <style>
        body {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
        }
        .sidebar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
        }
        .dark-mode .sidebar {
            background: rgba(31, 41, 55, 0.95);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.2);
        }
        .sidebar-brand img {
            transition: transform 0.2s ease;
        }
        .sidebar-brand:hover img {
            transform: scale(1.1);
        }
        .nav-link:hover {
            background-color: rgba(255, 98, 0, 0.1);
            transform: translateX(4px);
        }
        .nav-link.active {
            background: linear-gradient(45deg, #ff6200, #ff8c00);
            color: white !important;
        }
        .search-bar input {
            transition: all 0.3s ease;
        }
        .search-bar input:focus {
            box-shadow: 0 0 0 3px rgba(255, 98, 0, 0.2);
        }
        .hamburger:hover {
            transform: scale(1.1);
        }
        .badge-notifications {
            animation: pulse-slow 2s infinite;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Hamburger Menu -->
    <button class="hamburger fixed top-3 left-3 z-[1001] bg-gradient-to-r from-orange-500 to-orange-600 text-white p-2 rounded-md shadow-sm hover:shadow-md transition-all duration-300 ease-in-out" onclick="toggleSidebar()" aria-label="Toggle Sidebar" role="button" tabindex="0">
        <i class="fas fa-bars text-sm"></i>
    </button>

    <!-- Sidebar -->
    <nav class="sidebar fixed top-0 left-0 w-56 h-full overflow-y-auto z-[1000] transform lg:translate-x-0 -translate-x-full lg:w-60 transition-transform duration-300 ease-in-out" id="sidebar" role="navigation" aria-label="Main Navigation">
        <!-- Brand -->
        <a class="sidebar-brand flex items-center p-3 bg-gradient-to-r from-orange-500 to-orange-600 text-white font-bold text-lg rounded-b-xl mb-3 hover:bg-gradient-to-l" href="/student/dashboard.php" aria-label="QueensPa School Student Portal">
            <img src="/assets/images/logo.png" alt="QueensPa School Logo" onerror="this.src='/assets/images/default_logo.png'" class="w-8 h-8 rounded-full border-2 border-white mr-2">
            <span>QueensPa</span>
        </a>

        <!-- Search Bar -->
        <div class="search-bar px-3 mb-3">
            <input type="text" class="form-control w-full px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-full bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-orange-500 focus:border-transparent" placeholder="Search..." aria-label="Search student panel">
        </div>

        <!-- Navigation -->
        <ul class="sidebar-nav px-1">
            <li class="nav-item animate-slide-up" style="--delay: 0.1s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/dashboard.php" aria-label="Student Dashboard">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-section-title text-gray-500 dark:text-gray-400 text-xs font-semibold px-3 mt-3 mb-1 uppercase">Academics</li>
            <li class="nav-item animate-slide-up" style="--delay: 0.2s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/manage_student_assignments.php">
                    <i class="fas fa-book"></i> <span>Assignments</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 0.3s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/view_grades.php">
                    <i class="fas fa-chart-line"></i> <span>Grades</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 0.4s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/view_schedule.php">
                    <i class="fas fa-calendar"></i> <span>Schedule</span>
                </a>
            </li>
           
            <li class="nav-item animate-slide-up" style="--delay: 0.6s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/live_chat.php">
                    <i class="fas fa-comments"></i> <span>Live Chat</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 0.7s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/learning_resources.php">
                    <i class="fas fa-folder-open"></i> <span>Learning Resources</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 0.8s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/attendance.php">
                    <i class="fas fa-check-circle"></i> <span>Attendance</span>
                </a>
            </li>
            <li class="nav-section-title text-gray-500 dark:text-gray-400 text-xs font-semibold px-3 mt-3 mb-1 uppercase">Progress</li>
            <li class="nav-item animate-slide-up" style="--delay: 0.9s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/edubot.php">
                    <i class="fas fa-chart-area"></i> <span>EduBot</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 1.0s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/games.php">
                    <i class="fas fa-trophy"></i> <span>Games</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 1.1s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/ebook.php">
                    <i class="fas fa-book"></i> <span>Ebook</span>
                </a>
            </li>
            <li class="nav-section-title text-gray-500 dark:text-gray-400 text-xs font-semibold px-3 mt-3 mb-1 uppercase">Support</li>
            <li class="nav-item animate-slide-up" style="--delay: 1.2s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/submit_feedback.php">
                    <i class="fas fa-comment-dots"></i> <span>Submit Feedback</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 1.3s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/notifications.php">
                    <i class="fas fa-bell"></i> <span>Notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge-notifications bg-red-500 text-white text-xs font-medium px-1.5 py-0.5 rounded-full ml-1 animate-pulse-slow"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-section-title text-gray-500 dark:text-gray-400 text-xs font-semibold px-3 mt-3 mb-1 uppercase">Profile</li>
            <li class="nav-item animate-slide-up" style="--delay: 1.4s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/student/edit_profile.php">
                    <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Student Profile Picture" class="w-5 h-5 rounded-full border-2 border-orange-500">
                    <span>Edit Profile (<?php echo htmlspecialchars($studentName); ?>)</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 1.5s;">
                <a class="nav-link toggle-theme flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="#">
                    <i class="fas fa-moon"></i> <span>Toggle Dark Mode</span>
                </a>
            </li>
            <li class="nav-item animate-slide-up" style="--delay: 1.6s;">
                <a class="nav-link flex items-center gap-2 px-3 py-1.5 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-orange-50 dark:hover:bg-gray-700 transition-all duration-200 text-sm" href="/logout.php">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
        <div class="session-status text-center py-3 text-gray-500 dark:text-gray-400 text-xs animate-slide-up" style="--delay: 1.7s;">
            Session: <span id="session-status">Active</span>
        </div>
    </nav>

    <!-- Main Content Wrapper -->
    <div class="main-content lg:ml-60 transition-margin duration-300 ease-in-out">
        <div class="container mx-auto px-4 py-12">

    <script>
        $(document).ready(function() {
            // Apply theme immediately
            if (localStorage.getItem('nightMode') === 'true') {
                $('html').addClass('dark');
            }

            // Persist sidebar state
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                $('#sidebar').addClass('-translate-x-full').removeClass('lg:translate-x-0');
                $('.main-content').removeClass('lg:ml-60');
            }

            // Active nav link highlighting
            const currentPath = window.location.pathname.split('?')[0];
            $('.nav-link').each(function() {
                const href = $(this).attr('href')?.split('?')[0];
                if (href && currentPath.includes(href)) {
                    $(this).addClass('active bg-gradient-to-r from-orange-500 to-orange-600 text-white');
                }
            });

            // Search functionality
            $('.search-bar input').on('input', function() {
                const query = $(this).val().toLowerCase();
                $('.nav-link span').each(function() {
                    const text = $(this).text().toLowerCase();
                    const parent = $(this).closest('.nav-item');
                    parent.css('display', text.includes(query) || query === '' ? '' : 'none');
                });
            }).on('keypress', function(e) {
                if (e.key === 'Enter') {
                    const query = $(this).val().toLowerCase();
                    const firstMatch = $('.nav-link span').filter(function() {
                        return $(this).text().toLowerCase().includes(query);
                    }).first();
                    if (firstMatch.length) {
                        window.location.href = firstMatch.closest('a').attr('href');
                    }
                }
            });

            // Theme toggle
            $('.toggle-theme').on('click', function(e) {
                e.preventDefault();
                toggleTheme();
            });

            // Accessibility for hamburger
            $('.hamburger').on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleSidebar();
                }
            });

            // Focus management for sidebar
            $('#sidebar').on('transitionend', function() {
                if (!$(this).hasClass('-translate-x-full')) {
                    $(this).find('a').first().focus();
                }
            });

            // Session keep-alive
            setInterval(() => {
                $.ajax({
                    url: '/keep_alive.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        $('#session-status').text(data.status === 'active' ? 'Active' : 'Expired');
                        if (data.status === 'expired') {
                            window.location.href = '/login.php';
                        }
                    },
                    error: function() {
                        $('#session-status').text('Error');
                    }
                });
            }, 300000); // Every 5 minutes
        });

        function toggleSidebar() {
            const sidebar = $('#sidebar');
            const isCollapsed = sidebar.hasClass('lg:translate-x-0');
            if (isCollapsed) {
                sidebar.addClass('-translate-x-full').removeClass('lg:translate-x-0');
                $('.main-content').removeClass('lg:ml-60');
                localStorage.setItem('sidebarCollapsed', 'true');
            } else {
                sidebar.removeClass('-translate-x-full').addClass('lg:translate-x-0');
                $('.main-content').addClass('lg:ml-60');
                localStorage.setItem('sidebarCollapsed', 'false');
            }
        }

        function toggleTheme() {
            $('html').toggleClass('dark');
            localStorage.setItem('nightMode', $('html').hasClass('dark'));
        }
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