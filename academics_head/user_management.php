<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to user_management.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    header("Location: ../login.php");
    exit();
}

// Include database connection and logging function
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Helper function to get view URL based on role
function getViewUrl($userId, $role) {
    switch ($role) {
        case 'admin':
            return "view_admin.php?id=" . $userId;
        case 'teacher':
            return "view_teacher.php?id=" . $userId;
        case 'student':
            return "view_student.php?id=" . $userId;
        case 'parent':
            return "view_parent.php?id=" . $userId;
        default:
            return "view_user.php?id=" . $userId;
    }
}

// Helper function to get edit URL based on role
function getEditUrl($userId, $role) {
    switch ($role) {
        case 'admin':
            return "edit_admin.php?id=" . $userId;
        case 'teacher':
            return "edit_teacher.php?id=" . $userId;
        case 'student':
            return "edit_student.php?id=" . $userId;
        case 'parent':
            return "edit_parent.php?id=" . $userId;
        default:
            return "edit_user.php?id=" . $userId;
    }
}

// Helper function to get progress URL for students
function getProgressUrl($userId, $role) {
    if ($role === 'student') {
        return "view_progress.php?id=" . $userId;
    }
    return null;
}

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    $error_msg = "Connection failed. Please try again later or contact support.";
} else {
    // Initialize variables
    $success_msg = isset($_GET['success']) ? htmlspecialchars(trim($_GET['success'])) : '';
    $error_msg = '';
    $users_by_role = [
        'admin' => [],
        'teacher' => [],
        'student' => [],
        'parent' => []
    ];
    
    // Get statistics using only existing tables
    $stats = [
        'total_users' => 0,
        'active_users' => 0,
        'new_today' => 0,
        'online_now' => 0
    ];
    
    // Fetch user statistics from users table
    $stat_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today
        FROM users
    ");
    
    if ($stat_stmt) {
        $stat_stmt->execute();
        $stat_result = $stat_stmt->get_result();
        if ($stat_row = $stat_result->fetch_assoc()) {
            $stats = $stat_row;
        }
        $stat_stmt->close();
    }
    
    // Calculate online users based on last_login time (last 15 minutes)
    $online_stmt = $conn->prepare("
        SELECT COUNT(*) as online_now 
        FROM users 
        WHERE last_login IS NOT NULL 
        AND last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    
    if ($online_stmt) {
        $online_stmt->execute();
        $online_result = $online_stmt->get_result();
        if ($online_row = $online_result->fetch_assoc()) {
            $stats['online_now'] = $online_row['online_now'];
        }
        $online_stmt->close();
    }

    // Fetch users grouped by role
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.email, u.role, u.status, u.profile_picture, u.phone, 
               u.gender, u.address, u.admission_date, u.last_login, u.last_ip, 
               u.created_at, u.department_id,
               d.name AS department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        ORDER BY FIELD(u.role, 'admin', 'teacher', 'student', 'parent'), u.name ASC
    ");
    if (!$stmt) {
        $error_msg = "Prepare failed: " . $conn->error;
        logActivity($conn, $_SESSION['user_id'], "Prepare failed for user fetch", "Error: " . $conn->error);
    } else {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result === false) {
            $error_msg = "Error fetching users: " . $conn->error;
            logActivity($conn, $_SESSION['user_id'], "Error fetching users", "Error: " . $conn->error);
        } else {
            while ($row = $result->fetch_assoc()) {
                // Normalize profile picture path
                if ($row['profile_picture']) {
                    $clean_path = str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $row['profile_picture']);
                    $clean_path = ltrim($clean_path, '/');
                    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean_path;
                    
                    if (file_exists($full_path) && filesize($full_path) > 100) {
                        $row['profile_picture'] = '/Uploads/profiles/' . $clean_path;
                    } else {
                        $row['profile_picture'] = '/assets/images/default_profile.png';
                    }
                } else {
                    $row['profile_picture'] = '/assets/images/default_profile.png';
                }
                
                // Calculate if user is online
                $last_login = $row['last_login'] ? strtotime($row['last_login']) : 0;
                $current_time = time();
                $row['is_online'] = ($last_login > 0) && (($current_time - $last_login) <= 900);
                
                // Add role-specific URLs
                $row['view_url'] = getViewUrl($row['id'], $row['role']);
                $row['edit_url'] = getEditUrl($row['id'], $row['role']);
                $row['progress_url'] = getProgressUrl($row['id'], $row['role']);
                
                $users_by_role[$row['role']][] = $row;
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - QueensPa School</title>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
        }

        /* Modern CSS Variables */
        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-light: #fff7ed;
            --secondary: #8b5cf6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
            --card: #ffffff;
            --text: #0f172a;
            --text-light: #475569;
        }

        [data-theme="dark"] {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-light: #2d1a0e;
            --card: #1e293b;
            --border: #334155;
            --text: #f1f5f9;
            --text-light: #94a3b8;
            --light: #0f172a;
            background: #0f172a;
        }

        /* Layout */
        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Main Content */
        .main-panel {
            flex: 1;
            margin-left: 280px;
            transition: all 0.3s ease;
            padding: 24px 32px;
        }

        .main-panel.expanded {
            margin-left: 0;
        }

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-box {
            background: var(--card);
            border-radius: 20px;
            padding: 20px 24px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 16px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 14px;
            margin-top: 4px;
        }

        /* Search Bar */
        .search-bar {
            background: var(--card);
            border-radius: 16px;
            padding: 16px 24px;
            border: 1px solid var(--border);
            margin-bottom: 32px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
        }

        .search-input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }

        .search-input-wrapper input {
            width: 100%;
            padding: 12px 16px 12px 48px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            background: var(--card);
            color: var(--text);
        }

        .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Role Filters */
        .role-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .role-filter-btn {
            padding: 8px 20px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.2s;
        }

        .role-filter-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .role-filter-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
        }

        .theme-btn, .fullscreen-btn {
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 14px;
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text-light);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-user-btn {
            padding: 8px 20px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            background: var(--primary);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        /* Role Sections - GRID LAYOUT FIX */
        .role-container {
            margin-bottom: 40px;
        }

        .role-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .role-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .role-title i {
            font-size: 28px;
        }

        .role-title h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
        }

        .role-badge {
            background: var(--border);
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-light);
        }

        /* IMPORTANT: The grid that fixes your issue */
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        /* User Card */
        .user-grid-card {
            background: var(--card);
            border-radius: 20px;
            border: 1px solid var(--border);
            padding: 20px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-grid-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }

        .user-avatar-large {
            position: relative;
            flex-shrink: 0;
        }

        .user-avatar-large img {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            object-fit: cover;
            border: 2px solid var(--border);
        }

        .online-status {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid var(--card);
        }

        .online-status.online {
            background: var(--success);
        }

        .online-status.offline {
            background: var(--gray);
        }

        .user-details {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .user-name a {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s;
        }

        .user-name a:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .role-label {
            font-size: 10px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 8px;
            text-transform: uppercase;
        }

        .role-label.admin { background: #ede9fe; color: #7c3aed; }
        .role-label.teacher { background: #dbeafe; color: #2563eb; }
        .role-label.student { background: #d1fae5; color: #059669; }
        .role-label.parent { background: #fed7aa; color: #ea580c; }

        [data-theme="dark"] .role-label.admin { background: #4c1d95; color: #a78bfa; }
        [data-theme="dark"] .role-label.teacher { background: #1e3a8a; color: #60a5fa; }
        [data-theme="dark"] .role-label.student { background: #064e3b; color: #34d399; }
        [data-theme="dark"] .role-label.parent { background: #451a03; color: #f97316; }

        .user-email {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-meta-row {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 11px;
            color: var(--text-light);
            flex-wrap: wrap;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .status-chip.active {
            background: #d1fae5;
            color: #059669;
        }

        .status-chip.inactive {
            background: #fee2e2;
            color: #dc2626;
        }

        [data-theme="dark"] .status-chip.active {
            background: #064e3b;
            color: #34d399;
        }

        [data-theme="dark"] .status-chip.inactive {
            background: #7f1d1d;
            color: #f87171;
        }

        .user-actions-grid {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .user-action-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
            transition: all 0.2s;
            text-decoration: none;
        }

        .user-action-icon:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-light);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-panel {
                margin-left: 0;
                padding: 16px;
            }
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
        }

        @media (max-width: 768px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            .search-bar {
                flex-direction: column;
            }
            .search-input-wrapper {
                width: 100%;
            }
            .users-grid {
                grid-template-columns: 1fr;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .role-container {
            animation: fadeInUp 0.4s ease-out;
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>
        
        <div class="main-panel" id="mainPanel">
            <!-- Stats Row -->
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-icon" style="background: #fff7ed; color: #f97316;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background: #ecfdf5; color: #10b981;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['active_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">
                        <i class="fas fa-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['online_now']; ?></div>
                    <div class="stat-label">Online Now</div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background: #fef3c7; color: #f59e0b;">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['new_today']; ?></div>
                    <div class="stat-label">New Today</div>
                </div>
            </div>

            <!-- Search & Filters -->
            <div class="search-bar">
                <div class="search-input-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search by name, email, or ID...">
                </div>
                <div class="role-filters">
                    <button class="role-filter-btn active" data-role="all">All</button>
                    <button class="role-filter-btn" data-role="admin"><i class="fas fa-crown"></i> Admin</button>
                    <button class="role-filter-btn" data-role="teacher"><i class="fas fa-chalkboard-user"></i> Teachers</button>
                    <button class="role-filter-btn" data-role="student"><i class="fas fa-graduation-cap"></i> Students</button>
                    <button class="role-filter-btn" data-role="parent"><i class="fas fa-user-friends"></i> Parents</button>
                </div>
                <div class="action-buttons">
                    <button class="theme-btn" id="themeToggle"><i class="fas fa-moon"></i> <span>Dark</span></button>
                    <button class="fullscreen-btn" id="fullscreenBtn"><i class="fas fa-expand"></i> <span>Full</span></button>
                    <a href="add_user.php" class="add-user-btn"><i class="fas fa-plus"></i> Add User</a>
                </div>
            </div>

            <!-- Users by Role - GRID LAYOUT -->
            <div id="usersContainer">
                <?php foreach ($users_by_role as $role => $users): ?>
                <div class="role-container" data-role-group="<?php echo $role; ?>">
                    <div class="role-header">
                        <div class="role-title">
                            <?php if ($role === 'admin'): ?>
                                <i class="fas fa-crown" style="color: #8b5cf6;"></i>
                            <?php elseif ($role === 'teacher'): ?>
                                <i class="fas fa-chalkboard-user" style="color: #3b82f6;"></i>
                            <?php elseif ($role === 'student'): ?>
                                <i class="fas fa-graduation-cap" style="color: #10b981;"></i>
                            <?php else: ?>
                                <i class="fas fa-user-friends" style="color: #f59e0b;"></i>
                            <?php endif; ?>
                            <h2><?php echo ucfirst($role); ?>s</h2>
                            <span class="role-badge"><?php echo count($users); ?></span>
                        </div>
                        <button class="role-filter-btn" onclick="exportRole('<?php echo $role; ?>')" style="background: transparent;">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                    
                    <!-- THIS IS THE GRID - fixed layout -->
                    <div class="users-grid" data-role="<?php echo $role; ?>">
                        <?php if (empty($users)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-slash" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                                <p>No <?php echo $role; ?>s found</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <div class="user-grid-card" 
                                 data-user-id="<?php echo $user['id']; ?>" 
                                 data-user-name="<?php echo strtolower(htmlspecialchars($user['name'])); ?>" 
                                 data-user-email="<?php echo strtolower(htmlspecialchars($user['email'])); ?>">
                                
                                <div class="user-avatar-large">
                                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="<?php echo htmlspecialchars($user['name']); ?>">
                                    <div class="online-status <?php echo $user['is_online'] ? 'online' : 'offline'; ?>"></div>
                                </div>
                                
                                <div class="user-details">
                                    <div class="user-name">
                                        <a href="<?php echo $user['view_url']; ?>"><?php echo htmlspecialchars($user['name']); ?></a>
                                        <span class="role-label <?php echo $role; ?>"><?php echo ucfirst($role); ?></span>
                                    </div>
                                    <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                    <div class="user-meta-row">
                                        <span>ID: <?php echo $user['id']; ?></span>
                                        <?php if ($user['department_name']): ?>
                                        <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($user['department_name']); ?></span>
                                        <?php endif; ?>
                                        <span class="status-chip <?php echo $user['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                            <i class="fas fa-circle" style="font-size: 6px;"></i> <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="user-actions-grid">
                                    <a href="<?php echo $user['view_url']; ?>" class="user-action-icon" title="View <?php echo ucfirst($role); ?>">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="<?php echo $user['edit_url']; ?>" class="user-action-icon" title="Edit <?php echo ucfirst($role); ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($user['progress_url']): ?>
                                    <a href="<?php echo $user['progress_url']; ?>" class="user-action-icon" title="View Progress">
                                        <i class="fas fa-chart-line"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="delete_user.php?id=<?php echo $user['id']; ?>" class="user-action-icon" title="Delete User" onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>

    <script>
        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        const savedTheme = localStorage.getItem('theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        
        function updateThemeBtn(theme) {
            const icon = themeToggle.querySelector('i');
            const span = themeToggle.querySelector('span');
            if (theme === 'dark') {
                icon.className = 'fas fa-sun';
                span.textContent = 'Light';
            } else {
                icon.className = 'fas fa-moon';
                span.textContent = 'Dark';
            }
        }
        updateThemeBtn(savedTheme);
        
        themeToggle.addEventListener('click', () => {
            const current = html.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            updateThemeBtn(next);
        });
        
        // Fullscreen Toggle for main panel
        const fullscreenBtn = document.getElementById('fullscreenBtn');
        const mainPanel = document.getElementById('mainPanel');
        let isFullscreen = false;
        
        fullscreenBtn.addEventListener('click', () => {
            isFullscreen = !isFullscreen;
            if (isFullscreen) {
                mainPanel.classList.add('expanded');
                fullscreenBtn.innerHTML = '<i class="fas fa-compress"></i> <span>Normal</span>';
            } else {
                mainPanel.classList.remove('expanded');
                fullscreenBtn.innerHTML = '<i class="fas fa-expand"></i> <span>Full</span>';
            }
        });
        
        // Listen for sidebar toggle from admin_header
        document.addEventListener('click', function(e) {
            const hamburger = document.querySelector('.sidebar-toggle, .hamburger, [data-sidebar-toggle]');
            if (hamburger && (e.target === hamburger || hamburger.contains(e.target))) {
                setTimeout(() => {
                    const sidebar = document.querySelector('.sidebar');
                    if (sidebar) {
                        const isHidden = sidebar.classList.contains('collapsed') || sidebar.style.width === '0px';
                        if (isHidden) {
                            mainPanel.classList.add('expanded');
                            fullscreenBtn.innerHTML = '<i class="fas fa-compress"></i> <span>Normal</span>';
                            isFullscreen = true;
                        } else {
                            if (!isFullscreen) {
                                mainPanel.classList.remove('expanded');
                            }
                        }
                    }
                }, 50);
            }
        });
        
        // Search and Filter
        const searchInput = document.getElementById('searchInput');
        const filterBtns = document.querySelectorAll('.role-filter-btn');
        let currentRole = 'all';
        
        function filterAll() {
            const searchTerm = searchInput.value.toLowerCase();
            const containers = document.querySelectorAll('.role-container');
            
            containers.forEach(container => {
                const roleGroup = container.getAttribute('data-role-group');
                const cards = container.querySelectorAll('.user-grid-card');
                let visibleCount = 0;
                
                cards.forEach(card => {
                    const name = card.getAttribute('data-user-name') || '';
                    const email = card.getAttribute('data-user-email') || '';
                    const id = card.getAttribute('data-user-id') || '';
                    const matchesSearch = name.includes(searchTerm) || email.includes(searchTerm) || id.includes(searchTerm);
                    const matchesRole = currentRole === 'all' || roleGroup === currentRole;
                    
                    if (matchesSearch && matchesRole) {
                        card.style.display = 'flex';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                const grid = container.querySelector('.users-grid');
                const emptyState = grid.querySelector('.empty-state');
                if (visibleCount === 0 && (!emptyState || emptyState.style.display === 'none')) {
                    if (!emptyState) {
                        grid.innerHTML = '<div class="empty-state"><i class="fas fa-user-slash" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i><p>No users match</p></div>';
                    } else {
                        emptyState.style.display = 'flex';
                    }
                    container.style.display = (currentRole === 'all' || roleGroup === currentRole) ? 'block' : 'none';
                } else {
                    if (emptyState) emptyState.style.display = 'none';
                    container.style.display = (currentRole === 'all' || roleGroup === currentRole) ? 'block' : 'none';
                }
            });
        }
        
        searchInput.addEventListener('input', filterAll);
        
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentRole = btn.getAttribute('data-role');
                filterAll();
            });
        });
        
        function exportRole(role) {
            alert(`Exporting ${role} data...`);
            // You can implement actual export functionality here
            // window.location.href = `export_users.php?role=${role}`;
        }
        
        filterAll();
    </script>
</body>
</html>
<?php 
if (isset($conn)) {
    $conn->close();
}
ob_end_flush();
?>