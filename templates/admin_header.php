<?php
ob_start();
// Define base URL for favicon links
$base_url = 'https://' . $_SERVER['HTTP_HOST'];
// Start session only if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header("Location: /login.php");
    exit();
}
// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "Database connection failed. Please try again later.";
    header("Location: /login.php");
    exit();
}
// Fetch user details for profile dropdown
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    error_log("Error fetching user: " . $stmt->error);
    $_SESSION['error_msg'] = "Error fetching admin account.";
    header("Location: /login.php");
    exit();
}
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
if (!$user) {
    error_log("Admin not found for user_id: $user_id");
    $_SESSION['error_msg'] = "Admin account not found.";
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
        $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at)
                    VALUES (?, 'Error', ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $description = "Invalid profile picture path: $potential_url";
        $log_stmt->bind_param("is", $user_id, $description);
        $log_stmt->execute();
        $log_stmt->close();
    }
}
$adminName = htmlspecialchars($user['name'] ?? 'Admin');
// Fetch unread notifications count
$unread_sql = "SELECT COUNT(*) as unread_count
               FROM notifications
               WHERE is_read = 0 AND (user_id = ? OR target_role IN ('admin', 'all'))";
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
$log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at)
            VALUES (?, 'Accessed Header', ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$description = "Admin header loaded, unread notifications: $unread_count";
$log_stmt->bind_param("is", $user_id, $description);
if (!$log_stmt->execute()) {
    error_log("Error logging header access: " . $log_stmt->error);
}
$log_stmt->close();
// Dynamic SEO meta tags
$page_title = isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') : "Admin Dashboard - QueensPa School";
$page_description = isset($page_description) ? htmlspecialchars($page_description, ENT_QUOTES, 'UTF-8') : "Manage QueensPa School operations, including users, classes, subjects, and lesson plans.";
$page_keywords = isset($page_keywords) ? htmlspecialchars($page_keywords, ENT_QUOTES, 'UTF-8') : "QueensPa School, admin dashboard, school management, user management, class management, lesson plans";
$current_url = "https://qpmsedu.com/admin/" . basename($_SERVER['PHP_SELF']);
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
                            100: '#ffedd5',
                            200: '#fed7aa',
                            300: '#fdba74',
                            400: '#fb923c',
                            500: '#ff6200',
                            600: '#e55b00',
                            700: '#cc5200',
                            800: '#9a3412',
                            900: '#7c2d12',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.3s ease-out',
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'pulse-slow': 'pulseSlow 2s infinite',
                        'slide-in': 'slideIn 0.3s ease-out',
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
                            '0%, 100%': { opacity: '1' },
                            '50%': { opacity: '0.7' },
                        },
                        slideIn: {
                            '0%': { transform: 'translateX(-100%)' },
                            '100%': { transform: 'translateX(0)' },
                        },
                    },
                },
            },
        };
    </script>
    <style>
        /* HIGH CONTRAST, HIGH VISIBILITY STYLES */
        body {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
            background: #f8f9fa !important;
            font-family: 'Poppins', sans-serif;
            transition: margin-left 0.3s ease;
        }
        
        /* SIDEBAR - HIGH VISIBILITY */
        .sidebar {
            background: #ffffff !important;
            border-right: 3px solid #ff6200;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.15);
            width: 280px;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
        }
        
        /* SIDEBAR HIDDEN STATE */
        .sidebar-hidden {
            transform: translateX(-100%);
        }
        
        /* MAIN CONTENT FULL WIDTH WHEN SIDEBAR HIDDEN */
        .main-content-full {
            margin-left: 0 !important;
            width: 100%;
        }
        
        /* BRAND HEADER - BOLD AND CLEAR */
        .sidebar-brand {
            background: linear-gradient(135deg, #ff6200, #ff8c00);
            padding: 20px 25px;
            border-bottom: 4px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.3);
        }
        
        .sidebar-brand span {
            font-weight: 800;
            font-size: 22px;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.2);
        }
        
        .sidebar-brand .badge-admin {
            background: rgba(255, 255, 255, 0.25);
            border: 2px solid white;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        /* SEARCH BAR - CLEAR AND VISIBLE */
        .search-bar input {
            background: #ffffff;
            border: 2px solid #e0e0e0;
            font-size: 14px;
            font-weight: 500;
            color: #333333;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .search-bar input:focus {
            border-color: #ff6200;
            box-shadow: 0 0 0 4px rgba(255, 98, 0, 0.2);
            outline: none;
        }
        
        /* SECTION TITLES - BOLD AND CLEAR */
        .nav-section-title {
            color: #ff6200 !important;
            font-weight: 700 !important;
            font-size: 11px !important;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-top: 20px !important;
            margin-bottom: 10px !important;
            padding-left: 25px !important;
            border-left: 3px solid #ff6200;
            background: rgba(255, 98, 0, 0.05);
            padding: 8px 15px 8px 25px;
            border-radius: 0 8px 8px 0;
        }
        
        /* NAVIGATION LINKS - HIGH CONTRAST */
        .nav-link {
            color: #333333 !important;
            font-weight: 500;
            font-size: 14px;
            padding: 12px 25px !important;
            margin: 4px 10px !important;
            border-radius: 10px;
            transition: all 0.25s ease;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }
        
        .nav-link:hover {
            background: rgba(255, 98, 0, 0.12) !important;
            border-color: rgba(255, 98, 0, 0.3);
            transform: translateX(5px);
            color: #222222 !important;
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.15);
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, #ff6200, #ff8c00) !important;
            color: white !important;
            font-weight: 600;
            border: none;
            box-shadow: 0 6px 20px rgba(255, 98, 0, 0.4);
            transform: translateX(8px);
        }
        
        .nav-link.active::before {
            content: '';
            position: absolute;
            left: -10px;
            top: 50%;
            transform: translateY(-50%);
            width: 6px;
            height: 70%;
            background: #ff6200;
            border-radius: 0 3px 3px 0;
        }
        
        /* ICONS - LARGER AND VISIBLE */
        .nav-link i {
            font-size: 16px;
            width: 24px;
            text-align: center;
            color: #ff6200;
        }
        
        .nav-link.active i {
            color: white !important;
        }
        
        .icon-wrapper {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: rgba(255, 98, 0, 0.1);
        }
        
        .nav-link.active .icon-wrapper {
            background: rgba(255, 255, 255, 0.2);
        }
        
        /* PROFILE SECTION - PROMINENT */
        .profile-section {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border: 2px solid rgba(255, 98, 0, 0.2);
            border-radius: 15px;
            padding: 15px;
            margin: 20px 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .profile-img {
            width: 45px;
            height: 45px;
            border: 3px solid #ff6200;
            box-shadow: 0 4px 12px rgba(255, 98, 0, 0.3);
        }
        
        .profile-name {
            font-weight: 700;
            font-size: 15px;
            color: #222222;
        }
        
        .profile-role {
            font-size: 12px;
            color: #666666;
            font-weight: 500;
        }
        
        /* NOTIFICATION BADGE - HIGHLY VISIBLE */
        .badge-notifications {
            background: #ff3b30 !important;
            color: white !important;
            font-weight: 800;
            font-size: 11px;
            padding: 4px 8px !important;
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(255, 59, 48, 0.4);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        /* HAMBURGER BUTTON - LARGE AND VISIBLE */
        .hamburger {
            background: linear-gradient(135deg, #ff6200, #ff8c00);
            color: white;
            padding: 14px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(255, 98, 0, 0.4);
            border: 2px solid white;
            z-index: 1001;
            font-size: 18px;
            transition: all 0.3s ease;
            position: fixed;
            top: 20px;
            left: 20px;
        }
        
        .hamburger:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 25px rgba(255, 98, 0, 0.6);
        }
        
        .hamburger.hamburger-hidden {
            left: 20px;
        }
        
        /* SESSION STATUS - CLEAR */
        .session-status {
            background: rgba(255, 98, 0, 0.1);
            border-radius: 10px;
            padding: 12px 15px;
            margin: 20px 15px 15px;
            border: 1px solid rgba(255, 98, 0, 0.2);
            font-size: 12px;
            font-weight: 600;
            color: #333333;
        }
        
        .session-dot {
            width: 10px;
            height: 10px;
            background: #00c853;
            animation: pulseSlow 2s infinite;
        }
        
        /* MAIN CONTENT AREA */
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
        }
        
        /* SCROLLBAR STYLING */
        .sidebar::-webkit-scrollbar {
            width: 8px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, #ff6200, #ff8c00);
            border-radius: 4px;
        }
        
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, #e55b00, #cc5200);
        }
        
        /* MOBILE RESPONSIVE */
        @media (max-width: 1023px) {
            .sidebar {
                box-shadow: 10px 0 40px rgba(0, 0, 0, 0.25);
            }
            
            .hamburger {
                padding: 12px;
                font-size: 16px;
            }
            
            .main-content {
                margin-left: 0;
            }
        }
        
        /* ANIMATIONS FOR NAV ITEMS */
        .nav-item {
            opacity: 0;
            animation: slideUp 0.3s ease-out forwards;
        }
        
        /* DARK MODE OVERRIDES - ENSURING VISIBILITY */
        .dark .sidebar {
            background: #1a1a1a !important;
            border-right: 3px solid #ff6200;
        }
        
        .dark .nav-link {
            color: #ffffff !important;
        }
        
        .dark .nav-link:hover {
            background: rgba(255, 98, 0, 0.2) !important;
        }
        
        .dark .search-bar input {
            background: #2d2d2d;
            color: #ffffff;
            border-color: #444444;
        }
        
        .dark .nav-section-title {
            background: rgba(255, 98, 0, 0.15);
        }
        
        .dark .profile-section {
            background: linear-gradient(135deg, #2d2d2d, #1a1a1a);
            border-color: rgba(255, 98, 0, 0.3);
        }
        
        .dark .session-status {
            background: rgba(255, 98, 0, 0.15);
            color: #ffffff;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Hamburger Menu - LARGE AND VISIBLE -->
    <button class="hamburger fixed top-5 left-5 z-[1001] shadow-xl" onclick="toggleSidebar()" aria-label="Toggle Sidebar" role="button" tabindex="0">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar - HIGH VISIBILITY DESIGN -->
    <nav class="sidebar fixed top-0 left-0 h-full overflow-y-auto z-[1000]" id="sidebar" role="navigation" aria-label="Main Navigation">
        
        <!-- Brand Header - BOLD AND CLEAR -->
        <a class="sidebar-brand flex items-center text-white" href="/admin/dashboard.php" aria-label="QueensPa School Admin Portal">
            <img src="/assets/images/logo.png" alt="QueensPa School Logo" onerror="this.src='/assets/images/default_logo.png'" class="w-10 h-10 rounded-full mr-3 border-2 border-white">
            <div class="flex-1">
                <span>QueensPa</span>
                <div class="text-xs opacity-90 font-normal">School Management</div>
            </div>
            <div class="badge-admin px-3 py-1 rounded-lg text-xs font-bold">
                <i class="fas fa-crown mr-1"></i>ADMIN
            </div>
        </a>
        
        <!-- Search Bar - CLEAR AND VISIBLE -->
        <div class="search-bar px-5 mb-6 mt-4">
            <div class="relative">
                <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 text-sm"></i>
                <input type="text" class="w-full pl-12 pr-4 py-3 rounded-xl focus:outline-none" placeholder="Search dashboard..." aria-label="Search admin panel">
            </div>
        </div>
        
        <!-- Navigation - HIGH CONTRAST -->
        <div class="px-3">
            <ul class="sidebar-nav">
                
                <!-- Quick Links -->
                <li class="nav-section-title">Quick Access</li>
                <li class="nav-item" style="animation-delay: 0.1s;">
                    <a class="nav-link" href="/index.php" aria-label="Home">
                        <div class="icon-wrapper">
                            <i class="fas fa-home"></i>
                        </div>
                        <span>Home Page</span>
                    </a>
                </li>
                
                <li class="nav-item" style="animation-delay: 0.15s;">
                    <a class="nav-link" href="/admin/dashboard.php" aria-label="Admin Dashboard">
                        <div class="icon-wrapper">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <span>Dashboard</span>
                    </a>
                </li>
                
                <li class="nav-item" style="animation-delay: 0.15s;">
                    <a class="nav-link" href="/admin/admissions.php">
                        <div class="icon-wrapper">
                           <i class="fas fa-door-open"></i>
                       </div>
                      <span>Admissions</span>
                    </a>
                </li>
                
                <!-- Users Management - CLEAR SECTION -->
                <li class="nav-section-title">Users Management</li>
                <li class="nav-item" style="animation-delay: 0.2s;">
                    <a class="nav-link" href="/admin/add_user.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <span>Add New User</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.25s;">
                    <a class="nav-link" href="/admin/user_management.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-users"></i>
                        </div>
                        <span>Manage Users</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.25s;">
                    <a class="nav-link" href="/admin/live_chat.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-sms"></i>
                        </div>
                        <span>Live Chat</span>
                    </a>
                </li>
                
                <!-- Classes - CLEAR SECTION -->
                <li class="nav-section-title">Class Management</li>
                <li class="nav-item" style="animation-delay: 0.3s;">
                    <a class="nav-link" href="/admin/class_management.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <span>Manage Classes</span>
                    </a>
                </li>
                
                <!-- Subjects - CLEAR SECTION -->
                <li class="nav-section-title">Subject Management</li>
                <li class="nav-item" style="animation-delay: 0.35s;">
                    <a class="nav-link" href="/admin/add_subject.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <span>Add Subject</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.4s;">
                    <a class="nav-link" href="/admin/subject_management.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-book"></i>
                        </div>
                        <span>All Subjects</span>
                    </a>
                </li>
                
                <!-- School Management - CLEAR SECTION -->
                <li class="nav-section-title">School Management</li>
                <li class="nav-item" style="animation-delay: 0.45s;">
                    <a class="nav-link" href="/admin/fees_payments.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <span>Fees & Payments</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.5s;">
                    <a class="nav-link" href="/admin/attendance.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <span>Attendance</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.55s;">
                    <a class="nav-link" href="/admin/analysis.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <span>Evaluation & Analysis</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.6s;">
                    <a class="nav-link" href="/admin/lesson_schedule.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <span>Lesson Schedule</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.65s;">
                    <a class="nav-link" href="/admin/report_card.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <span>Report Cards</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.7s;">
                    <a class="nav-link" href="/admin/calendar.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <span>Calendar Settings</span>
                    </a>
                </li>
                
                <!-- Progress Tracking - CLEAR SECTION -->
                <li class="nav-section-title">Progress Tracking</li>
                  <li class="nav-item" style="animation-delay: 0.85s;">
                    <a class="nav-link" href="/admin/students_diagnosis.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-clipboard"></i>
                        </div>
                        <span>View Diagnosis</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.75s;">
                    <a class="nav-link" href="/admin/class_progress.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <span>Class Progress</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.8s;">
                    <a class="nav-link" href="/admin/student_progress.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <span>Student Progress</span>
                    </a>
                </li>
                <li class="nav-item" style="animation-delay: 0.85s;">
                    <a class="nav-link" href="/admin/school_progress.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-school"></i>
                        </div>
                        <span>School Progress</span>
                    </a>
                </li>
              
                <!-- Account Section - PROMINENT -->
                <div class="profile-section">
                    <li class="nav-item" style="animation-delay: 0.9s; list-style: none;">
                        <a class="nav-link flex items-center gap-3 px-0" href="/admin/edit_profile.php">
                            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Admin Profile Picture" class="profile-img rounded-full object-cover">
                            <div class="flex-1">
                                <div class="profile-name"><?php echo htmlspecialchars($adminName); ?></div>
                                <div class="profile-role">Administrator</div>
                            </div>
                            <?php if ($unread_count > 0): ?>
                                <span class="badge-notifications"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </div>
                
                <!-- Account Actions -->
                <li class="nav-item" style="animation-delay: 0.95s;">
                    <a class="nav-link toggle-theme" href="#">
                        <div class="icon-wrapper">
                            <i class="fas fa-moon"></i>
                        </div>
                        <span>Toggle Theme</span>
                    </a>
                </li>
                
                <li class="nav-item" style="animation-delay: 1s;">
                    <a class="nav-link" href="/logout.php">
                        <div class="icon-wrapper">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                        <span>Logout</span>
                    </a>
                </li>
                
            </ul>
            
            <!-- Session Status - CLEARLY VISIBLE -->
            <div class="session-status">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="session-dot rounded-full"></div>
                        <span id="session-status" class="font-bold">ACTIVE</span>
                    </div>
                    <div class="text-sm font-semibold" id="current-time"><?php echo date('H:i'); ?></div>
                </div>
                <div class="text-xs text-center mt-2 opacity-80">QueensPa Admin Panel</div>
            </div>
            
        </div>
    </nav>
    
    <!-- Main Content Wrapper -->
    <div class="main-content" id="mainContent">
        <div class="px-6 py-8">
        
    <script>
        $(document).ready(function() {
            // Initialize all nav items with staggered animation
            $('.nav-item').each(function(index) {
                $(this).css('animation-delay', (index * 0.05) + 's');
                setTimeout(() => {
                    $(this).css('opacity', '1');
                }, index * 50);
            });
            
            // Set active link based on current URL
            const currentPath = window.location.pathname;
            $('.nav-link').each(function() {
                const href = $(this).attr('href');
                if (href && currentPath.includes(href)) {
                    $(this).addClass('active');
                    $(this).find('.icon-wrapper').css('background', 'rgba(255, 255, 255, 0.3)');
                }
            });
            
            // Enhanced search functionality
            $('.search-bar input').on('input', function() {
                const query = $(this).val().toLowerCase().trim();
                
                if (query.length === 0) {
                    $('.nav-item, .nav-section-title').show();
                    return;
                }
                
                let foundItems = false;
                $('.nav-item').each(function() {
                    const $item = $(this);
                    const text = $item.find('.nav-link span').text().toLowerCase();
                    if (text.includes(query)) {
                        $item.show();
                        foundItems = true;
                    } else {
                        $item.hide();
                    }
                });
                
                // Show/hide section titles based on visible items
                $('.nav-section-title').each(function() {
                    const $section = $(this);
                    const $nextItems = $section.nextUntil('.nav-section-title').filter('.nav-item');
                    const hasVisibleItems = $nextItems.filter(':visible').length > 0;
                    
                    if (hasVisibleItems || query.length === 0) {
                        $section.show();
                    } else {
                        $section.hide();
                    }
                });
            });
            
            // Theme toggle
            $('.toggle-theme').on('click', function(e) {
                e.preventDefault();
                toggleTheme();
            });
            
            // Accessibility enhancements
            $('.hamburger').on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleSidebar();
                }
            });
            
            // Close sidebar when clicking outside on mobile
            $(document).on('click', function(e) {
                if ($(window).width() < 1024) {
                    if (!$(e.target).closest('#sidebar, .hamburger').length) {
                        if (!$('#sidebar').hasClass('sidebar-hidden')) {
                            toggleSidebar();
                        }
                    }
                }
            });
            
            // Session keep-alive
            setInterval(() => {
                $.ajax({
                    url: '/keep_alive.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        const statusText = data.status === 'active' ? 'ACTIVE' : 'EXPIRED';
                        $('#session-status').text(statusText);
                        
                        if (data.status === 'expired') {
                            window.location.href = '/login.php';
                        }
                    },
                    error: function() {
                        $('#session-status').text('ERROR');
                    }
                });
            }, 300000);
            
            // Update time every minute
            function updateTime() {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit',
                    hour12: false 
                });
                $('#current-time').text(timeString);
            }
            
            setInterval(updateTime, 60000);
            updateTime(); // Initial call
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Ctrl + / to focus search
                if (e.ctrlKey && e.key === '/') {
                    e.preventDefault();
                    $('.search-bar input').focus();
                }
                
                // Esc to clear search
                if (e.key === 'Escape') {
                    $('.search-bar input').val('').trigger('input');
                }
            });
            
            // Initialize sidebar state from localStorage
            const sidebarHidden = localStorage.getItem('sidebarHidden') === 'true';
            if (sidebarHidden && $(window).width() >= 1024) {
                $('#sidebar').addClass('sidebar-hidden');
                $('#mainContent').addClass('main-content-full');
                $('.hamburger i').removeClass('fa-bars').addClass('fa-times');
            }
        });
        
        function toggleSidebar() {
            const sidebar = $('#sidebar');
            const mainContent = $('#mainContent');
            const isHidden = sidebar.hasClass('sidebar-hidden');
            
            if ($(window).width() < 1024) {
                // Mobile behavior
                if (isHidden) {
                    sidebar.removeClass('sidebar-hidden');
                    $('body').css('overflow', 'hidden');
                    $('.hamburger i').removeClass('fa-bars').addClass('fa-times');
                } else {
                    sidebar.addClass('sidebar-hidden');
                    $('body').css('overflow', 'auto');
                    $('.hamburger i').removeClass('fa-times').addClass('fa-bars');
                }
            } else {
                // Desktop behavior - toggle sidebar visibility
                if (isHidden) {
                    // Show sidebar
                    sidebar.removeClass('sidebar-hidden');
                    mainContent.removeClass('main-content-full');
                    $('.hamburger i').removeClass('fa-bars').addClass('fa-times');
                    localStorage.setItem('sidebarHidden', 'false');
                } else {
                    // Hide sidebar completely
                    sidebar.addClass('sidebar-hidden');
                    mainContent.addClass('main-content-full');
                    $('.hamburger i').removeClass('fa-times').addClass('fa-bars');
                    localStorage.setItem('sidebarHidden', 'true');
                }
            }
        }
        
        function toggleTheme() {
            const html = $('html');
            const isDark = html.hasClass('dark');
            
            if (isDark) {
                html.removeClass('dark');
                $('.toggle-theme .icon-wrapper i').removeClass('fa-sun').addClass('fa-moon');
                $('.toggle-theme span').text('Dark Mode');
                localStorage.setItem('nightMode', 'false');
            } else {
                html.addClass('dark');
                $('.toggle-theme .icon-wrapper i').removeClass('fa-moon').addClass('fa-sun');
                $('.toggle-theme span').text('Light Mode');
                localStorage.setItem('nightMode', 'true');
            }
        }
        
        // Initialize sidebar state
        $(window).on('load', function() {
            // Check if sidebar should be hidden on desktop
            if (localStorage.getItem('sidebarHidden') === 'true' && $(window).width() >= 1024) {
                $('#sidebar').addClass('sidebar-hidden');
                $('#mainContent').addClass('main-content-full');
                $('.hamburger i').removeClass('fa-bars').addClass('fa-times');
            } else if ($(window).width() >= 1024) {
                // Ensure sidebar is visible on desktop by default
                $('#sidebar').removeClass('sidebar-hidden');
                $('#mainContent').removeClass('main-content-full');
                $('.hamburger i').removeClass('fa-times').addClass('fa-bars');
            }
            
            // Initialize theme
            if (localStorage.getItem('nightMode') === 'true') {
                $('html').addClass('dark');
                $('.toggle-theme .icon-wrapper i').removeClass('fa-moon').addClass('fa-sun');
                $('.toggle-theme span').text('Light Mode');
            }
        });
        
        // Handle window resize
        $(window).on('resize', function() {
            if ($(window).width() >= 1024) {
                // On desktop
                $('body').css('overflow', 'auto');
                // Maintain sidebar state based on localStorage
                if (localStorage.getItem('sidebarHidden') !== 'true') {
                    $('#sidebar').removeClass('sidebar-hidden');
                    $('#mainContent').removeClass('main-content-full');
                    $('.hamburger i').removeClass('fa-bars').addClass('fa-times');
                } else {
                    $('#sidebar').addClass('sidebar-hidden');
                    $('#mainContent').addClass('main-content-full');
                    $('.hamburger i').removeClass('fa-times').addClass('fa-bars');
                }
            } else {
                // On mobile, ensure sidebar is hidden
                $('#sidebar').addClass('sidebar-hidden');
                $('#mainContent').removeClass('main-content-full');
                $('.hamburger i').removeClass('fa-times').addClass('fa-bars');
            }
        });
    </script>
    
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "QueensPa School",
        "url": "https://qpmsedu.com",
        "logo": "https://qpmsedu.com/assets/images/logo.png",
        "description": "Professional School Management System"
    }
    </script>
</body>
</html>