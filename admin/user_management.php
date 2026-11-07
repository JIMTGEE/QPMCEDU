<?php
ob_start();
session_start();

// ============================================================
// SECURITY: Restrict to admins only
// ============================================================
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log(
        "Unauthorized access attempt to user_management.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'),
        3,
        $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt'
    );
    header("Location: ../login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// ============================================================
// CLASS NAME MAPPING (internal name => display alias)
// ============================================================
$class_mapping = [
    'Esther Foundation' => 'Creche',
    'Sheba One'         => 'Nursery 1',
    'Sheba Two'         => 'Nursery 2',
    'Yaasant One'       => 'KG 1',
    'Yaasant Two'       => 'KG 2',
    'Cleopatra One'     => 'Basic 1',
    'Cleopatra Two'     => 'Basic 2',
    'Nefertiti One'     => 'Basic 3',
    'Nefertiti Two'     => 'Basic 4',
    'Malika One'        => 'Basic 5',
    'Malika Two'        => 'Basic 6',
    'Makeda One'        => 'Basic 7',
    'Makeda Two'        => 'Basic 8',
    'An Majesty'        => 'Basic 9',
];

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function getViewUrl(int $userId, string $role): string {
    $map = [
        'admin' => 'view_admin', 
        'teacher' => 'view_teacher', 
        'student' => 'view_student', 
        'parent' => 'view_parent'
    ];
    $page = $map[$role] ?? 'view_user';
    return "{$page}.php?id={$userId}";
}

function getEditUrl(int $userId, string $role): string {
    $map = [
        'admin' => 'edit_admin', 
        'teacher' => 'edit_teacher', 
        'student' => 'edit_student', 
        'parent' => 'edit_parent'
    ];
    $page = $map[$role] ?? 'edit_user';
    return "{$page}.php?id={$userId}";
}

function getProgressUrl(int $userId, string $role): ?string {
    return ($role === 'student') ? "view_progress.php?id={$userId}" : null;
}

function normalizeProfilePicture(?string $raw): string {
    if (empty($raw) || $raw === '0') {
        return '/assets/images/default_profile.png';
    }
    $clean = ltrim(str_replace(['uploads/profiles/', 'Uploads/profiles/', '../', '/Uploads/profiles/'], '', $raw), '/');
    $full = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean;
    return (file_exists($full) && filesize($full) > 100)
        ? '/Uploads/profiles/' . $clean . '?v=' . filemtime($full)
        : '/assets/images/default_profile.png';
}

// ============================================================
// DATABASE QUERIES
// ============================================================
$success_msg = isset($_GET['success']) ? htmlspecialchars(trim($_GET['success'])) : '';
$error_msg = '';
$stats = [
    'total_users' => 0, 
    'active_users' => 0, 
    'new_today' => 0, 
    'online_now' => 0, 
    'admin_count' => 0, 
    'teacher_count' => 0, 
    'student_count' => 0, 
    'parent_count' => 0
];
$users_by_role = ['admin' => [], 'teacher' => [], 'student' => [], 'parent' => []];

// Check connection before proceeding
if (!$conn || $conn->connect_error) {
    error_log("Connection failed: " . ($conn->connect_error ?? 'Unknown error'), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    $error_msg = "Database connection failed. Please try again later or contact support.";
} else {
    // --- Stats ---
    $stat_sql = "
        SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS new_today,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admin_count,
            SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) AS teacher_count,
            SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS student_count,
            SUM(CASE WHEN role = 'parent' THEN 1 ELSE 0 END) AS parent_count
        FROM users
    ";
    
    if ($stat_stmt = $conn->prepare($stat_sql)) {
        $stat_stmt->execute();
        $stats_result = $stat_stmt->get_result();
        $stats_data = $stats_result->fetch_assoc();
        if ($stats_data) {
            $stats = array_merge($stats, $stats_data);
        }
        $stat_stmt->close();
    }

    // Online (active in last 15 minutes)
    $online_sql = "SELECT COUNT(*) AS online_now FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
    if ($online_stmt = $conn->prepare($online_sql)) {
        $online_stmt->execute();
        $online_result = $online_stmt->get_result();
        $online_data = $online_result->fetch_assoc();
        $stats['online_now'] = (int)($online_data['online_now'] ?? 0);
        $online_stmt->close();
    }

    // --- Main user fetch with corrected parent data join ---
    // The parents table has user_id that links to users.id
    // parent_student_mapping has parent_id (which is users.id) and student_id (which is users.id)
    $user_sql = "
        SELECT
            u.id, u.name, u.email, u.role, u.status,
            u.profile_picture, u.phone, u.gender,
            u.address, u.admission_date, u.last_login,
            u.last_ip, u.created_at, u.department_id,
            u.national_id, u.updated_at, u.phone_number,
            d.name AS department_name,
            s.student_name,
            s.date_of_birth,
            s.nationality,
            s.allergy,
            s.special_child,
            s.previous_school,
            s.emergency_contact_name,
            s.emergency_contact_phone,
            sc.class_id,
            c.class_name,
            parent_info.parent_name,
            parent_info.parent_email,
            parent_info.parent_phone,
            parent_info.parent_occupation
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN students s ON u.id = s.user_id
        LEFT JOIN student_classes sc ON u.id = sc.user_id
        LEFT JOIN classes c ON sc.class_id = c.id
        LEFT JOIN (
            SELECT 
                psm.student_id,
                p.parent_name,
                up.email AS parent_email,
                p.phone_number AS parent_phone,
                p.occupation AS parent_occupation
            FROM parent_student_mapping psm
            INNER JOIN users up ON psm.parent_id = up.id
            LEFT JOIN parents p ON up.id = p.user_id
            GROUP BY psm.student_id
        ) parent_info ON u.id = parent_info.student_id
        ORDER BY u.name ASC
    ";

    if (!$stmt = $conn->prepare($user_sql)) {
        $error_msg = "Query prepare failed: " . $conn->error;
        logActivity($conn, $_SESSION['user_id'], "Prepare failed for user fetch", $conn->error);
    } else {
        $stmt->execute();
        $result = $stmt->get_result();

        // Also get parent count separately
        $parent_count_sql = "
            SELECT student_id, COUNT(*) as pc 
            FROM parent_student_mapping 
            GROUP BY student_id
        ";
        $parent_counts = [];
        if ($pc_stmt = $conn->prepare($parent_count_sql)) {
            $pc_stmt->execute();
            $pc_result = $pc_stmt->get_result();
            while ($pc_row = $pc_result->fetch_assoc()) {
                $parent_counts[$pc_row['student_id']] = (int)$pc_row['pc'];
            }
            $pc_stmt->close();
        }

        while ($row = $result->fetch_assoc()) {
            $row['profile_picture'] = normalizeProfilePicture($row['profile_picture']);

            // Online status
            $last_login = $row['last_login'] ? strtotime($row['last_login']) : 0;
            $row['is_online'] = $last_login > 0 && (time() - $last_login) <= 900;

            // URLs
            $row['view_url'] = getViewUrl($row['id'], $row['role']);
            $row['edit_url'] = getEditUrl($row['id'], $row['role']);
            $row['progress_url'] = getProgressUrl($row['id'], $row['role']);

            // Class display
            $cn = $row['class_name'] ?? '';
            $row['class_display_name'] = $cn
                ? ($cn . (isset($class_mapping[$cn]) ? ' (' . $class_mapping[$cn] . ')' : ''))
                : '';

            // Display name: prefer student_name for students
            $row['display_name'] = ($row['role'] === 'student' && !empty($row['student_name']))
                ? $row['student_name']
                : $row['name'];

            // Parent count for students
            $row['parent_count'] = $parent_counts[$row['id']] ?? 0;

            $users_by_role[$row['role']][] = $row;
        }
        $stmt->close();
    }
    
    // DO NOT CLOSE THE CONNECTION HERE - header files may need it
}

// Role counts
$role_counts = [
    'admin' => count($users_by_role['admin']),
    'teacher' => count($users_by_role['teacher']),
    'student' => count($users_by_role['student']),
    'parent' => count($users_by_role['parent']),
];

// Include the header AFTER all database work is done
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — Queen's Paradise Montessori</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
    /* ============================================================
       ROOT TOKENS
    ============================================================ */
    :root {
        --accent:       #FF6200;
        --accent-dark:  #D95200;
        --accent-soft:  #FFF0E6;
        --accent-glow:  rgba(255,98,0,.12);
        --emerald:  #10b981;
        --sapphire: #3b82f6;
        --violet:   #8b5cf6;
        --amber:    #f59e0b;
        --rose:     #ef4444;
        --bg:       #f0f2f7;
        --surface:  #ffffff;
        --border:   #e4e8f0;
        --muted:    #94a3b8;
        --text:     #0d1b2a;
        --text-2:   #4a5568;
        --shadow-sm: 0 1px 3px rgba(0,0,0,.07);
        --shadow-md: 0 4px 12px rgba(0,0,0,.08);
        --shadow-lg: 0 8px 24px rgba(0,0,0,.10);
        --shadow-xl: 0 20px 60px rgba(0,0,0,.15);
        --radius-sm: 8px;
        --radius-md: 12px;
        --radius-lg: 16px;
        --radius-xl: 24px;
    }

    [data-theme="dark"] {
        --bg:      #0c1220;
        --surface: #161e2e;
        --border:  #1e2d40;
        --muted:   #4a6080;
        --text:    #e8f0fe;
        --text-2:  #8ba3c0;
        --accent-soft: #1f1408;
        --shadow-sm: 0 1px 3px rgba(0,0,0,.3);
        --shadow-md: 0 4px 12px rgba(0,0,0,.35);
        --shadow-lg: 0 8px 24px rgba(0,0,0,.4);
        --shadow-xl: 0 20px 60px rgba(0,0,0,.5);
    }

    /* ============================================================
       RESET & BASE
    ============================================================ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'DM Sans', system-ui, sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        -webkit-font-smoothing: antialiased;
    }

    a { color: inherit; text-decoration: none; }

    /* ============================================================
       LAYOUT
    ============================================================ */
    .app-wrapper {
        display: flex;
        min-height: 100vh;
        width: 100%;
    }

    .main-panel {
        flex: 1;
        margin-left: 280px;
        padding: 24px 32px;
        width: calc(100% - 280px);
        transition: margin 0.3s ease, width 0.3s ease;
    }

    .sidebar.collapsed ~ .main-panel,
    .main-panel.expanded {
        margin-left: 80px;
        width: calc(100% - 80px);
    }

    @media (max-width: 1024px) {
        .main-panel {
            margin-left: 0;
            width: 100%;
            padding: 16px 20px;
        }
    }

    /* ============================================================
       ALERTS
    ============================================================ */
    .alert {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        border-radius: var(--radius-md);
        font-size: 14px;
        font-weight: 500;
        margin-bottom: 24px;
        animation: slideDown .3s ease;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-danger  { background: #fee2e2; color: #991b1b; }
    [data-theme="dark"] .alert-success { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .alert-danger  { background: #7f1d1d; color: #fca5a5; }
    .alert-close {
        margin-left: auto; background: none; border: none;
        cursor: pointer; font-size: 16px; opacity: .6; color: inherit;
    }
    .alert-close:hover { opacity: 1; }

    /* ============================================================
       PAGE HEADER
    ============================================================ */
    .page-header {
        background: linear-gradient(135deg, #FF6200 0%, #FF9100 100%);
        border-radius: var(--radius-xl);
        padding: 28px 32px;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 28px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(255,98,0,.3);
    }
    .page-header::before {
        content: '';
        position: absolute;
        inset: 0;
        background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='200'%3E%3Ccircle cx='350' cy='30'  r='120' fill='rgba(255,255,255,.05)'/%3E%3Ccircle cx='20'  cy='160' r='80'  fill='rgba(255,255,255,.04)'/%3E%3C/svg%3E") no-repeat right center;
        background-size: cover;
        pointer-events: none;
    }
    .ph-title { position: relative; z-index: 1; }
    .ph-title h1 {
        font-size: 1.75rem;
        font-weight: 700;
        letter-spacing: -0.5px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .ph-title p {
        font-size: 14px;
        opacity: .85;
        margin-top: 6px;
        font-weight: 400;
    }
    .ph-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }
    .ph-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 22px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .2s;
        white-space: nowrap;
        font-family: 'DM Sans', sans-serif;
    }
    .ph-btn-primary {
        background: #fff;
        color: var(--accent);
    }
    .ph-btn-primary:hover { 
        background: var(--accent-soft); 
        transform: translateY(-2px); 
        box-shadow: 0 6px 20px rgba(0,0,0,.15); 
    }
    .ph-btn-ghost {
        background: rgba(255,255,255,.15);
        color: #fff;
        border: 1.5px solid rgba(255,255,255,.3);
        backdrop-filter: blur(8px);
    }
    .ph-btn-ghost:hover { 
        background: rgba(255,255,255,.25); 
        transform: translateY(-2px); 
    }

    /* ============================================================
       STATS GRID
    ============================================================ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 32px;
    }
    @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 640px)  { .stats-grid { grid-template-columns: 1fr; } }

    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: var(--shadow-sm);
        transition: transform .25s, box-shadow .25s;
    }
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-md);
    }
    .stat-icon {
        width: 56px;
        height: 56px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        flex-shrink: 0;
    }
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: -1px;
    }
    .stat-label {
        font-size: 13px;
        color: var(--text-2);
        font-weight: 500;
        margin-top: 4px;
    }
    .stat-sub {
        font-size: 11px;
        color: var(--muted);
        margin-top: 2px;
        font-family: 'DM Mono', monospace;
    }

    /* ============================================================
       SEARCH & FILTERS
    ============================================================ */
    .search-section {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 20px 24px;
        margin-bottom: 32px;
        box-shadow: var(--shadow-sm);
    }
    .search-row {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .search-box {
        flex: 2;
        min-width: 260px;
        position: relative;
    }
    .search-box .icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
        font-size: 14px;
        pointer-events: none;
    }
    .search-box input {
        width: 100%;
        padding: 12px 14px 12px 42px;
        border: 1.5px solid var(--border);
        border-radius: 40px;
        font-size: 14px;
        font-family: 'DM Sans', sans-serif;
        background: var(--bg);
        color: var(--text);
        transition: all .2s;
        outline: none;
    }
    .search-box input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-glow);
    }
    .search-box input::placeholder { color: var(--muted); }

    .filter-group {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        flex: 1;
    }
    .filter-select {
        padding: 10px 16px;
        border: 1.5px solid var(--border);
        border-radius: 40px;
        font-size: 13px;
        font-family: 'DM Sans', sans-serif;
        background: var(--bg);
        color: var(--text);
        cursor: pointer;
        outline: none;
        transition: all .2s;
        min-width: 130px;
    }
    .filter-select:focus { border-color: var(--accent); }
    
    .results-count {
        background: var(--accent-soft);
        color: var(--accent);
        font-size: 13px;
        font-weight: 700;
        padding: 8px 18px;
        border-radius: 40px;
        white-space: nowrap;
        font-family: 'DM Mono', monospace;
    }

    /* Role filter tabs */
    .role-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 8px;
        border-top: 1px solid var(--border);
        padding-top: 16px;
    }
    .role-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 20px;
        border-radius: 40px;
        font-size: 13px;
        font-weight: 600;
        border: 1.5px solid var(--border);
        background: transparent;
        color: var(--text-2);
        cursor: pointer;
        transition: all .2s;
        font-family: 'DM Sans', sans-serif;
    }
    .role-tab:hover { 
        border-color: var(--accent); 
        color: var(--accent); 
        transform: translateY(-1px);
    }
    .role-tab.active { 
        background: var(--accent); 
        border-color: var(--accent); 
        color: #fff;
        box-shadow: 0 2px 8px rgba(255,98,0,.3);
    }
    .role-tab .badge {
        background: rgba(0,0,0,.1);
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
    }
    .role-tab.active .badge { background: rgba(255,255,255,.2); }

    /* ============================================================
       USERS SECTIONS (Role-based)
    ============================================================ */
    .role-section {
        margin-bottom: 40px;
        animation: fadeIn 0.4s ease;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
    }
    .section-header h2 {
        font-size: 1.3rem;
        font-weight: 600;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .section-header .count {
        background: var(--accent-soft);
        color: var(--accent);
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 700;
    }
    .section-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }

    /* Users Grid */
    .users-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
        gap: 20px;
    }

    /* User Card */
    .user-card {
        background: var(--surface);
        border: 1.5px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 20px;
        display: flex;
        align-items: flex-start;
        gap: 16px;
        box-shadow: var(--shadow-sm);
        transition: all .25s ease;
        position: relative;
        min-height: 160px;
        height: auto;
    }
    .user-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-md);
        border-color: var(--accent);
    }

    /* Role accent stripe */
    .user-card::before {
        content: '';
        position: absolute;
        left: 0;
        top: 16px;
        bottom: 16px;
        width: 4px;
        border-radius: 0 4px 4px 0;
        background: var(--role-color, var(--muted));
    }

    /* Avatar */
    .avatar-wrap {
        position: relative;
        flex-shrink: 0;
    }
    .avatar-wrap img {
        width: 64px;
        height: 64px;
        border-radius: 16px;
        object-fit: cover;
        border: 2px solid var(--border);
        display: block;
    }
    .online-dot {
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 2px solid var(--surface);
        background: #cbd5e1;
    }
    .online-dot.on { background: var(--emerald); }

    /* User Info */
    .user-info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .name-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .user-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 180px;
        cursor: pointer;
    }
    .user-name:hover { 
        color: var(--accent); 
        text-decoration: underline; 
    }

    .role-badge {
        font-size: 10px;
        font-weight: 800;
        padding: 4px 10px;
        border-radius: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        flex-shrink: 0;
    }
    .rb-admin   { background: #ede9fe; color: #6d28d9; }
    .rb-teacher { background: #dbeafe; color: #1d4ed8; }
    .rb-student { background: #d1fae5; color: #047857; }
    .rb-parent  { background: #fed7aa; color: #c2410c; }

    [data-theme="dark"] .rb-admin   { background: #2e1065; color: #c4b5fd; }
    [data-theme="dark"] .rb-teacher { background: #1e3a8a; color: #93c5fd; }
    [data-theme="dark"] .rb-student { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .rb-parent  { background: #431407; color: #fb923c; }

    .user-email {
        font-size: 12px;
        color: var(--text-2);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .user-email i { 
        font-size: 11px; 
        margin-right: 6px;
        width: 14px;
    }

    .meta-row {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        font-size: 11.5px;
        color: var(--muted);
    }
    .meta-item {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .meta-item i { 
        font-size: 11px;
        width: 14px;
    }

    .status-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
    }
    .st-active    { background: #d1fae5; color: #065f46; }
    .st-inactive  { background: #fee2e2; color: #991b1b; }
    .st-suspended { background: #fef3c7; color: #92400e; }
    .st-graduated { background: #e0e7ff; color: #3730a3; }
    .st-archived  { background: #f1f5f9; color: #64748b; }

    [data-theme="dark"] .st-active    { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .st-inactive  { background: #7f1d1d; color: #fca5a5; }
    [data-theme="dark"] .st-suspended { background: #422006; color: #fcd34d; }
    [data-theme="dark"] .st-graduated { background: #1e1b4b; color: #a5b4fc; }
    [data-theme="dark"] .st-archived  { background: #1e293b; color: #94a3b8; }

    /* Card Actions */
    .card-actions {
        display: flex;
        gap: 6px;
        flex-shrink: 0;
        align-items: center;
    }
    .act-btn {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-2);
        font-size: 14px;
        transition: all .18s;
        border: none;
        background: transparent;
        cursor: pointer;
    }
    .act-btn:hover { 
        background: var(--accent-soft); 
        color: var(--accent); 
    }
    .act-btn.act-delete:hover { 
        background: #fee2e2; 
        color: var(--rose); 
    }
    .act-btn.act-progress:hover { 
        background: #d1fae5; 
        color: var(--emerald); 
    }
    .act-btn.act-view { 
        color: var(--sapphire);
    }
    .act-btn.act-view:hover { 
        background: #dbeafe; 
        color: var(--sapphire); 
    }

    /* Empty state */
    .empty-role {
        grid-column: 1 / -1;
        text-align: center;
        padding: 60px 24px;
        color: var(--muted);
        background: var(--surface);
        border-radius: var(--radius-lg);
        border: 1px dashed var(--border);
    }
    .empty-role .icon {
        font-size: 56px;
        opacity: 0.3;
        margin-bottom: 16px;
        display: block;
    }
    .empty-role h3 { 
        font-size: 16px; 
        font-weight: 600; 
        color: var(--text-2); 
        margin-bottom: 6px; 
    }
    .empty-role p { font-size: 13px; }

    /* ============================================================
       MODAL STYLES
    ============================================================ */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.6);
        backdrop-filter: blur(4px);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: fadeIn .2s ease;
    }
    .modal-content {
        background: var(--surface);
        border-radius: var(--radius-xl);
        width: 90%;
        max-width: 650px;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: var(--shadow-xl);
        animation: scaleIn .25s ease;
    }
    @keyframes scaleIn {
        from { transform: scale(.95); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        background: var(--surface);
        z-index: 10;
        border-radius: var(--radius-xl) var(--radius-xl) 0 0;
    }
    .modal-header h2 {
        font-size: 1.25rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .modal-close {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 18px;
        color: var(--text-2);
        transition: all .2s;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .modal-close:hover {
        background: var(--bg);
        color: var(--rose);
    }
    .modal-body {
        padding: 24px;
    }
    .modal-body .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px 24px;
    }
    .modal-body .detail-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .modal-body .detail-item.full {
        grid-column: 1 / -1;
    }
    .modal-body .detail-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--muted);
    }
    .modal-body .detail-value {
        font-size: 14px;
        font-weight: 500;
        color: var(--text);
    }
    .modal-body .detail-value.empty {
        color: var(--muted);
        font-style: italic;
    }
    .modal-body .profile-section {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 24px;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--border);
    }
    .modal-body .profile-section img {
        width: 80px;
        height: 80px;
        border-radius: 20px;
        object-fit: cover;
        border: 3px solid var(--accent);
    }
    .modal-body .profile-section .user-main-info h3 {
        font-size: 1.25rem;
        font-weight: 700;
    }
    .modal-body .profile-section .user-main-info .role-line {
        font-size: 13px;
        color: var(--text-2);
        margin-top: 4px;
    }
    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
        position: sticky;
        bottom: 0;
        background: var(--surface);
        border-radius: 0 0 var(--radius-xl) var(--radius-xl);
    }
    .modal-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 40px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .2s;
        font-family: 'DM Sans', sans-serif;
    }
    .modal-btn-primary {
        background: var(--accent);
        color: #fff;
    }
    .modal-btn-primary:hover {
        background: var(--accent-dark);
        transform: translateY(-1px);
    }
    .modal-btn-secondary {
        background: var(--bg);
        color: var(--text);
        border: 1px solid var(--border);
    }
    .modal-btn-secondary:hover {
        background: var(--border);
    }
    .modal-btn-danger {
        background: var(--rose);
        color: #fff;
    }
    .modal-btn-danger:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    /* ============================================================
       RESPONSIVE
    ============================================================ */
    @media (max-width: 768px) {
        .page-header { flex-direction: column; text-align: center; }
        .ph-actions { justify-content: center; }
        .search-row { flex-direction: column; align-items: stretch; }
        .filter-group { justify-content: stretch; }
        .results-count { text-align: center; }
        .users-grid { grid-template-columns: 1fr; }
        .user-card { flex-wrap: wrap; }
        .card-actions { margin-top: 8px; justify-content: flex-end; width: 100%; }
        .modal-body .detail-grid { grid-template-columns: 1fr; }
        .modal-content { width: 95%; max-width: 100%; }
    }

    @media print {
        .app-wrapper > *:first-child { display: none; }
        .main-panel { margin-left: 0; width: 100%; padding: 0; }
        .stats-grid, .search-section { break-inside: avoid; }
        .user-card { break-inside: avoid; }
    }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php // Admin header already included above ?>
    
    <div class="main-panel" id="mainPanel">

        <!-- ALERTS -->
        <?php if ($success_msg): ?>
        <div class="alert alert-success" role="alert">
            <i class="fas fa-circle-check"></i>
            <?= $success_msg ?>
            <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Dismiss">&times;</button>
        </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-triangle-exclamation"></i>
            <?= $error_msg ?>
            <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Dismiss">&times;</button>
        </div>
        <?php endif; ?>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="ph-title">
                <h1><i class="fas fa-users-cog"></i> User Management</h1>
                <p>Manage all users across Queen's Paradise Montessori School</p>
            </div>
            <div class="ph-actions">
                <a href="launchcards.php" class="ph-btn ph-btn-ghost"><i class="fas fa-id-card"></i> Launch Cards</a>
                <a href="parent_student.php" class="ph-btn ph-btn-ghost"><i class="fas fa-link"></i> Parent–Student</a>
                <a href="add_user.php" class="ph-btn ph-btn-primary"><i class="fas fa-plus-circle"></i> Add User</a>
            </div>
        </div>

        <!-- STATS GRID -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff7ed;color:#f97316;">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="stat-value"><?= (int)$stats['total_users'] ?></div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-sub"><?= (int)$stats['active_users'] ?> active</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ecfdf5;color:#10b981;">
                    <i class="fas fa-signal"></i>
                </div>
                <div>
                    <div class="stat-value"><?= (int)$stats['online_now'] ?></div>
                    <div class="stat-label">Online Now</div>
                    <div class="stat-sub">Active in last 15 min</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#eff6ff;color:#3b82f6;">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div>
                    <div class="stat-value"><?= (int)$stats['new_today'] ?></div>
                    <div class="stat-label">New Today</div>
                    <div class="stat-sub">Registered today</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fdf4ff;color:#8b5cf6;">
                    <i class="fas fa-chalkboard-user"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $role_counts['teacher'] ?></div>
                    <div class="stat-label">Teaching Staff</div>
                    <div class="stat-sub"><?= $role_counts['student'] ?> students, <?= $role_counts['parent'] ?> parents</div>
                </div>
            </div>
        </div>

        <!-- SEARCH & FILTERS -->
        <div class="search-section">
            <div class="search-row">
                <div class="search-box">
                    <span class="icon"><i class="fas fa-magnifying-glass"></i></span>
                    <input type="text" id="globalSearch" placeholder="Search users by name, email, ID, class, or department... (Ctrl+K)">
                </div>
                <div class="filter-group">
                    <select class="filter-select" id="statusFilter">
                        <option value="all">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                        <option value="graduated">Graduated</option>
                        <option value="archived">Archived</option>
                    </select>
                    <select class="filter-select" id="genderFilter">
                        <option value="all">All Genders</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                    <select class="filter-select" id="sortFilter">
                        <option value="name-asc">Name A→Z</option>
                        <option value="name-desc">Name Z→A</option>
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="recent-login">Recent Login</option>
                    </select>
                </div>
                <span class="results-count" id="resultsCount">Loading...</span>
            </div>
            <div class="role-tabs" id="roleTabs">
                <button class="role-tab active" data-role="all">
                    <i class="fas fa-layer-group"></i> All
                    <span class="badge"><?= (int)$stats['total_users'] ?></span>
                </button>
                <button class="role-tab" data-role="admin">
                    <i class="fas fa-crown"></i> Administrators
                    <span class="badge"><?= $role_counts['admin'] ?></span>
                </button>
                <button class="role-tab" data-role="teacher">
                    <i class="fas fa-chalkboard-user"></i> Teachers
                    <span class="badge"><?= $role_counts['teacher'] ?></span>
                </button>
                <button class="role-tab" data-role="student">
                    <i class="fas fa-graduation-cap"></i> Students
                    <span class="badge"><?= $role_counts['student'] ?></span>
                </button>
                <button class="role-tab" data-role="parent">
                    <i class="fas fa-user-friends"></i> Parents/Guardians
                    <span class="badge"><?= $role_counts['parent'] ?></span>
                </button>
            </div>
        </div>

        <!-- USERS BY ROLE SECTIONS -->
        <div id="usersContainer">
            <?php 
            $role_order = ['admin', 'teacher', 'student', 'parent'];
            $role_icons = [
                'admin' => 'fa-crown',
                'teacher' => 'fa-chalkboard-user',
                'student' => 'fa-graduation-cap',
                'parent' => 'fa-user-friends'
            ];
            $role_colors = [
                'admin' => '#8b5cf6',
                'teacher' => '#3b82f6',
                'student' => '#10b981',
                'parent' => '#f97316'
            ];
            $role_bg_colors = [
                'admin' => '#f3e8ff',
                'teacher' => '#dbeafe',
                'student' => '#d1fae5',
                'parent' => '#fed7aa'
            ];
            
            foreach ($role_order as $role):
                $users = $users_by_role[$role];
                $count = count($users);
                $display_name = ucfirst($role) . (($role === 'parent') ? 's/Guardians' : (($role === 'teacher') ? 's' : 's'));
                if ($role === 'admin') $display_name = 'Administrators';
            ?>
            <div class="role-section" data-role-section="<?= $role ?>">
                <div class="section-header">
                    <div class="section-icon" style="background: <?= $role_bg_colors[$role] ?>; color: <?= $role_colors[$role] ?>;">
                        <i class="fas <?= $role_icons[$role] ?>"></i>
                    </div>
                    <h2>
                        <?= $display_name ?>
                        <span class="count"><?= $count ?></span>
                    </h2>
                </div>
                
                <div class="users-grid" data-role-grid="<?= $role ?>">
                    <?php if (empty($users)): ?>
                        <div class="empty-role">
                            <i class="fas <?= $role_icons[$role] ?> icon"></i>
                            <h3>No <?= strtolower($display_name) ?> Found</h3>
                            <p>Click the "Add User" button to add a new <?= rtrim(strtolower($display_name), 's/Guardians') ?>.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($users as $u):
                            $status = $u['status'] ?? 'active';
                            $dn = htmlspecialchars($u['display_name'], ENT_QUOTES);
                            $email = htmlspecialchars($u['email'] ?? '', ENT_QUOTES);
                            $dept = htmlspecialchars($u['department_name'] ?? '', ENT_QUOTES);
                            $cls = htmlspecialchars($u['class_display_name'], ENT_QUOTES);
                            $gender = strtolower($u['gender'] ?? 'other');
                            $pc = (int)($u['parent_count'] ?? 0);

                            // Status CSS class
                            $stClass = match($status) {
                                'active' => 'st-active',
                                'inactive' => 'st-inactive',
                                'suspended' => 'st-suspended',
                                'graduated' => 'st-graduated',
                                default => 'st-archived',
                            };

                            // Encode user data for modal
                            $userData = [
                                'id' => $u['id'],
                                'display_name' => $u['display_name'],
                                'email' => $u['email'] ?? '',
                                'role' => $u['role'],
                                'status' => $status,
                                'profile_picture' => $u['profile_picture'],
                                'is_online' => $u['is_online'],
                                'phone' => $u['phone'] ?? $u['phone_number'] ?? '',
                                'gender' => $u['gender'] ?? '',
                                'address' => $u['address'] ?? '',
                                'national_id' => $u['national_id'] ?? '',
                                'admission_date' => $u['admission_date'] ?? '',
                                'created_at' => $u['created_at'] ?? '',
                                'last_login' => $u['last_login'] ?? '',
                                'last_ip' => $u['last_ip'] ?? '',
                                'department_name' => $u['department_name'] ?? '',
                                'class_display_name' => $cls,
                                'parent_count' => $pc,
                                'student_name' => $u['student_name'] ?? '',
                                'date_of_birth' => $u['date_of_birth'] ?? '',
                                'nationality' => $u['nationality'] ?? '',
                                'allergy' => $u['allergy'] ?? '',
                                'special_child' => $u['special_child'] ?? '',
                                'previous_school' => $u['previous_school'] ?? '',
                                'emergency_contact_name' => $u['emergency_contact_name'] ?? '',
                                'emergency_contact_phone' => $u['emergency_contact_phone'] ?? '',
                                'parent_name' => $u['parent_name'] ?? '',
                                'parent_email' => $u['parent_email'] ?? '',
                                'parent_phone' => $u['parent_phone'] ?? '',
                                'parent_occupation' => $u['parent_occupation'] ?? '',
                                'edit_url' => $u['edit_url'],
                                'progress_url' => $u['progress_url'] ?? '',
                            ];
                            $userDataJson = htmlspecialchars(json_encode($userData), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="user-card"
                             data-role="<?= $role ?>"
                             data-status="<?= $status ?>"
                             data-gender="<?= $gender ?>"
                             data-name="<?= strtolower(htmlspecialchars($u['display_name'])) ?>"
                             data-email="<?= strtolower($email) ?>"
                             data-id="<?= $u['id'] ?>"
                             data-class="<?= strtolower($cls) ?>"
                             data-department="<?= strtolower($dept) ?>"
                             data-created="<?= (int)strtotime($u['created_at']) ?>"
                             data-login="<?= (int)strtotime($u['last_login'] ?? '2000-01-01') ?>"
                             data-user='<?= $userDataJson ?>'
                             style="--role-color: <?= $role_colors[$role] ?>">

                            <!-- Avatar -->
                            <div class="avatar-wrap">
                                <img src="<?= htmlspecialchars($u['profile_picture'], ENT_QUOTES) ?>"
                                     alt="<?= $dn ?>"
                                     loading="lazy"
                                     onerror="this.src='/assets/images/default_profile.png'">
                                <span class="online-dot <?= $u['is_online'] ? 'on' : 'off' ?>"
                                      title="<?= $u['is_online'] ? 'Online now' : 'Offline' ?>"></span>
                            </div>

                            <!-- Info -->
                            <div class="user-info">
                                <div class="name-row">
                                    <span class="user-name" onclick="openUserModal(this.closest('.user-card'))" title="Click to view details"><?= $dn ?></span>
                                    <span class="role-badge rb-<?= $role ?>"><?= ucfirst($role) ?></span>
                                </div>
                                <?php if ($email): ?>
                                <div class="user-email">
                                    <i class="fas fa-envelope"></i><?= $email ?>
                                </div>
                                <?php endif; ?>
                                <div class="meta-row">
                                    <span class="meta-item" title="User ID">
                                        <i class="fas fa-hashtag"></i>#<?= $u['id'] ?>
                                    </span>

                                    <?php if ($role === 'student' && $cls): ?>
                                    <span class="meta-item" title="Class">
                                        <i class="fas fa-chalkboard"></i><?= $cls ?>
                                    </span>
                                    <?php endif; ?>

                                    <?php if ($dept): ?>
                                    <span class="meta-item" title="Department">
                                        <i class="fas fa-building"></i><?= $dept ?>
                                    </span>
                                    <?php endif; ?>

                                    <?php if ($role === 'student' && $pc > 0): ?>
                                    <span class="meta-item" title="Linked parents">
                                        <i class="fas fa-users"></i><?= $pc ?> parent<?= $pc > 1 ? 's' : '' ?>
                                    </span>
                                    <?php endif; ?>

                                    <span class="status-tag <?= $stClass ?>">
                                        <i class="fas <?= $status === 'active' ? 'fa-circle' : 'fa-circle' ?>"></i>
                                        <?= ucfirst($status) ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="card-actions">
                                <button class="act-btn act-view" title="Quick View" onclick="openUserModal(this.closest('.user-card'))">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="<?= $u['edit_url'] ?>" class="act-btn" title="Edit User">
                                    <i class="fas fa-pen-to-square"></i>
                                </a>
                                <?php if ($u['progress_url']): ?>
                                <a href="<?= $u['progress_url'] ?>" class="act-btn act-progress" title="Academic Progress">
                                    <i class="fas fa-chart-line"></i>
                                </a>
                                <?php endif; ?>
                                <a href="delete_user.php?id=<?= $u['id'] ?>"
                                   class="act-btn act-delete"
                                   title="Delete User"
                                   onclick="return confirmDelete(event, '<?= addslashes($dn) ?>')">
                                    <i class="fas fa-trash-can"></i>
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

<!-- USER DETAIL MODAL -->
<div class="modal-overlay" id="userModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">
                <i class="fas fa-user"></i> User Details
            </h2>
            <button class="modal-close" onclick="closeUserModal()" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Dynamically filled -->
        </div>
        <div class="modal-footer" id="modalFooter">
            <!-- Dynamically filled -->
        </div>
    </div>
</div>

<script>
/* ============================================================
   USER MANAGEMENT — CLIENT SCRIPT
============================================================ */
(function () {
    'use strict';

    const roleTabs = document.getElementById('roleTabs');
    const globalSearch = document.getElementById('globalSearch');
    const statusFilter = document.getElementById('statusFilter');
    const genderFilter = document.getElementById('genderFilter');
    const sortFilter = document.getElementById('sortFilter');
    const resultsCount = document.getElementById('resultsCount');
    const userModal = document.getElementById('userModal');
    const modalBody = document.getElementById('modalBody');
    const modalFooter = document.getElementById('modalFooter');
    const modalTitle = document.getElementById('modalTitle');

    let currentRole = 'all';
    let searchTimer = null;

    const roleSections = {
        admin: document.querySelector('[data-role-section="admin"]'),
        teacher: document.querySelector('[data-role-section="teacher"]'),
        student: document.querySelector('[data-role-section="student"]'),
        parent: document.querySelector('[data-role-section="parent"]')
    };
    
    let allCardsByRole = {
        admin: [],
        teacher: [],
        student: [],
        parent: []
    };
    
    Object.keys(roleSections).forEach(role => {
        const grid = document.querySelector(`[data-role-grid="${role}"]`);
        if (grid) {
            allCardsByRole[role] = Array.from(grid.querySelectorAll('.user-card'));
        }
    });

    roleTabs.addEventListener('click', e => {
        const btn = e.target.closest('.role-tab');
        if (!btn) return;
        
        roleTabs.querySelectorAll('.role-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        currentRole = btn.dataset.role;
        
        if (currentRole === 'all') {
            Object.keys(roleSections).forEach(role => {
                if (roleSections[role]) roleSections[role].style.display = 'block';
            });
        } else {
            Object.keys(roleSections).forEach(role => {
                if (roleSections[role]) {
                    roleSections[role].style.display = role === currentRole ? 'block' : 'none';
                }
            });
        }
        
        applyFilters();
    });

    [statusFilter, genderFilter, sortFilter].forEach(el => {
        el.addEventListener('change', () => applyFilters());
    });

    globalSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 250);
    });

    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            globalSearch.focus();
        }
        if (e.key === '/' && document.activeElement === document.body) {
            e.preventDefault();
            globalSearch.focus();
        }
        if (e.key === 'Escape' && userModal.style.display !== 'none') {
            closeUserModal();
        }
    });

    userModal.addEventListener('click', e => {
        if (e.target === userModal) closeUserModal();
    });

    function applyFilters() {
        const searchTerm = globalSearch.value.trim().toLowerCase();
        const status = statusFilter.value;
        const gender = genderFilter.value;
        const sort = sortFilter.value;
        
        let totalVisible = 0;
        
        Object.keys(allCardsByRole).forEach(role => {
            const cards = allCardsByRole[role];
            if (!cards.length) return;
            
            const visibleCards = [];
            
            cards.forEach(card => {
                const matchStatus = status === 'all' || card.dataset.status === status;
                const matchGender = gender === 'all' || card.dataset.gender === gender;
                const matchSearch = !searchTerm || [
                    card.dataset.name,
                    card.dataset.email,
                    card.dataset.id,
                    card.dataset.class,
                    card.dataset.department
                ].some(v => v && v.includes(searchTerm));
                
                const isVisible = matchStatus && matchGender && matchSearch;
                card.style.display = isVisible ? 'flex' : 'none';
                if (isVisible) visibleCards.push(card);
            });
            
            visibleCards.sort((a, b) => {
                switch (sort) {
                    case 'name-asc': return (a.dataset.name || '').localeCompare(b.dataset.name || '');
                    case 'name-desc': return (b.dataset.name || '').localeCompare(a.dataset.name || '');
                    case 'newest': return (parseInt(b.dataset.created) || 0) - (parseInt(a.dataset.created) || 0);
                    case 'oldest': return (parseInt(a.dataset.created) || 0) - (parseInt(b.dataset.created) || 0);
                    case 'recent-login': return (parseInt(b.dataset.login) || 0) - (parseInt(a.dataset.login) || 0);
                    default: return 0;
                }
            });
            
            visibleCards.forEach((card, index) => { card.style.order = index; });
            
            totalVisible += visibleCards.length;
            
            const grid = document.querySelector(`[data-role-grid="${role}"]`);
            if (grid) {
                const existingEmpty = grid.querySelector('.empty-role:not(.static-empty)');
                if (visibleCards.length === 0 && cards.length > 0) {
                    if (!existingEmpty) {
                        const emptyDiv = document.createElement('div');
                        emptyDiv.className = 'empty-role';
                        emptyDiv.innerHTML = `
                            <i class="fas ${getRoleIcon(role)} icon"></i>
                            <h3>No matching users found</h3>
                            <p>Try adjusting your search or filters</p>
                        `;
                        grid.appendChild(emptyDiv);
                    }
                } else if (existingEmpty && visibleCards.length > 0) {
                    existingEmpty.remove();
                }
            }
        });
        
        resultsCount.textContent = `${totalVisible} user${totalVisible !== 1 ? 's' : ''} found`;
    }
    
    function getRoleIcon(role) {
        const icons = { admin: 'fa-crown', teacher: 'fa-chalkboard-user', student: 'fa-graduation-cap', parent: 'fa-user-friends' };
        return icons[role] || 'fa-user';
    }

    // ============================================================
    // MODAL FUNCTIONS
    // ============================================================
    window.openUserModal = function(card) {
        if (!card || !card.dataset.user) return;
        
        let user;
        try {
            user = JSON.parse(card.dataset.user);
        } catch (e) {
            console.error('Failed to parse user data:', e);
            return;
        }
        
        const roleIcons = { admin: 'fa-crown', teacher: 'fa-chalkboard-user', student: 'fa-graduation-cap', parent: 'fa-user-friends' };
        const roleColors = { admin: '#8b5cf6', teacher: '#3b82f6', student: '#10b981', parent: '#f97316' };
        const roleNames = { admin: 'Administrator', teacher: 'Teacher', student: 'Student', parent: 'Parent/Guardian' };
        
        const roleIcon = roleIcons[user.role] || 'fa-user';
        const roleColor = roleColors[user.role] || '#6b7280';
        const roleName = roleNames[user.role] || user.role;
        const statusClass = user.status === 'active' ? 'st-active' : 
                           user.status === 'inactive' ? 'st-inactive' : 
                           user.status === 'suspended' ? 'st-suspended' : 
                           user.status === 'graduated' ? 'st-graduated' : 'st-archived';
        
        modalTitle.innerHTML = `<i class="fas ${roleIcon}" style="color:${roleColor}"></i> ${roleName} Details`;
        
        let bodyHTML = `
            <div class="profile-section">
                <img src="${user.profile_picture || '/assets/images/default_profile.png'}" 
                     alt="${escapeHtml(user.display_name)}" 
                     onerror="this.src='/assets/images/default_profile.png'">
                <div class="user-main-info">
                    <h3>${escapeHtml(user.display_name)}</h3>
                    <div class="role-line">
                        <span class="role-badge rb-${user.role}">${roleName}</span>
                        <span class="status-tag ${statusClass}" style="margin-left:8px;">
                            <i class="fas fa-circle"></i> ${capitalize(user.status)}
                        </span>
                        ${user.is_online ? '<span style="margin-left:8px;color:#10b981;font-size:12px;"><i class="fas fa-circle"></i> Online</span>' : ''}
                    </div>
                </div>
            </div>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">User ID</span>
                    <span class="detail-value">#${user.id}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Email</span>
                    <span class="detail-value ${!user.email ? 'empty' : ''}">${user.email || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Phone</span>
                    <span class="detail-value ${!user.phone ? 'empty' : ''}">${user.phone || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Gender</span>
                    <span class="detail-value ${!user.gender ? 'empty' : ''}">${capitalize(user.gender) || 'Not specified'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Address</span>
                    <span class="detail-value ${!user.address ? 'empty' : ''}">${user.address || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">National ID</span>
                    <span class="detail-value ${!user.national_id ? 'empty' : ''}">${user.national_id || 'Not provided'}</span>
                </div>`;
        
        if (user.role === 'student' || user.role === 'teacher') {
            bodyHTML += `
                <div class="detail-item">
                    <span class="detail-label">Department</span>
                    <span class="detail-value ${!user.department_name ? 'empty' : ''}">${user.department_name || 'Not assigned'}</span>
                </div>`;
        }
        
        if (user.role === 'student') {
            bodyHTML += `
                <div class="detail-item">
                    <span class="detail-label">Class</span>
                    <span class="detail-value ${!user.class_display_name ? 'empty' : ''}">${user.class_display_name || 'Not assigned'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Date of Birth</span>
                    <span class="detail-value ${!user.date_of_birth ? 'empty' : ''}">${user.date_of_birth || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nationality</span>
                    <span class="detail-value ${!user.nationality ? 'empty' : ''}">${user.nationality || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Previous School</span>
                    <span class="detail-value ${!user.previous_school ? 'empty' : ''}">${user.previous_school || 'Not provided'}</span>
                </div>
                <div class="detail-item full">
                    <span class="detail-label">Allergies</span>
                    <span class="detail-value ${!user.allergy || user.allergy === 'None' ? 'empty' : ''}">${user.allergy && user.allergy !== 'None' ? user.allergy : 'None'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Special Child</span>
                    <span class="detail-value">${user.special_child === 'yes' ? 'Yes' : 'No'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Linked Parents</span>
                    <span class="detail-value">${user.parent_count || 0} parent${user.parent_count !== 1 ? 's' : ''}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Emergency Contact</span>
                    <span class="detail-value ${!user.emergency_contact_name ? 'empty' : ''}">
                        ${user.emergency_contact_name ? escapeHtml(user.emergency_contact_name) + (user.emergency_contact_phone ? ' — ' + user.emergency_contact_phone : '') : 'Not provided'}
                    </span>
                </div>`;
        }
        
        if (user.role === 'parent') {
            bodyHTML += `
                <div class="detail-item">
                    <span class="detail-label">Parent Name</span>
                    <span class="detail-value ${!user.parent_name ? 'empty' : ''}">${user.parent_name || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Parent Email</span>
                    <span class="detail-value ${!user.parent_email ? 'empty' : ''}">${user.parent_email || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Parent Phone</span>
                    <span class="detail-value ${!user.parent_phone ? 'empty' : ''}">${user.parent_phone || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Occupation</span>
                    <span class="detail-value ${!user.parent_occupation ? 'empty' : ''}">${user.parent_occupation || 'Not provided'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Linked Children</span>
                    <span class="detail-value">${user.parent_count || 0} student${user.parent_count !== 1 ? 's' : ''}</span>
                </div>`;
        }
        
        if (user.role === 'teacher') {
            bodyHTML += `
                <div class="detail-item">
                    <span class="detail-label">Class Assigned</span>
                    <span class="detail-value ${!user.class_display_name ? 'empty' : ''}">${user.class_display_name || 'Not assigned'}</span>
                </div>`;
        }
        
        bodyHTML += `
                <div class="detail-item">
                    <span class="detail-label">Admission Date</span>
                    <span class="detail-value ${!user.admission_date ? 'empty' : ''}">${user.admission_date || 'Not recorded'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Joined On</span>
                    <span class="detail-value">${formatDate(user.created_at)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Last Login</span>
                    <span class="detail-value ${!user.last_login ? 'empty' : ''}">${user.last_login ? formatDateTime(user.last_login) : 'Never'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Last IP</span>
                    <span class="detail-value ${!user.last_ip ? 'empty' : ''}">${user.last_ip || 'N/A'}</span>
                </div>
            </div>`;
        
        modalBody.innerHTML = bodyHTML;
        
        modalFooter.innerHTML = `
            <a href="${user.edit_url}" class="modal-btn modal-btn-primary">
                <i class="fas fa-pen-to-square"></i> Edit User
            </a>
            ${user.progress_url ? `
            <a href="${user.progress_url}" class="modal-btn modal-btn-secondary">
                <i class="fas fa-chart-line"></i> View Progress
            </a>` : ''}
            <a href="delete_user.php?id=${user.id}" class="modal-btn modal-btn-danger" onclick="return confirmDelete(event, '${escapeHtml(user.display_name)}')">
                <i class="fas fa-trash-can"></i> Delete
            </a>
            <button class="modal-btn modal-btn-secondary" onclick="closeUserModal()">
                <i class="fas fa-times"></i> Close
            </button>
        `;
        
        userModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };
    
    window.closeUserModal = function() {
        userModal.style.display = 'none';
        document.body.style.overflow = '';
    };

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }
    
    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }
    
    function formatDateTime(dateStr) {
        if (!dateStr) return 'N/A';
        const d = new Date(dateStr);
        return d.toLocaleString('en-US', { 
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    window.confirmDelete = function(e, name) {
        const confirmed = confirm(`⚠️ Delete user "${name}"?\n\nThis action cannot be undone.`);
        if (!confirmed) e.preventDefault();
        return confirmed;
    };

    const applyTheme = theme => {
        document.documentElement.setAttribute('data-theme', theme || 'light');
    };
    applyTheme(localStorage.getItem('qpms_theme'));
    window.addEventListener('storage', e => {
        if (e.key === 'qpms_theme') applyTheme(e.newValue);
    });

    applyFilters();
})();
</script>
</body>
</html>
<?php 
ob_end_flush(); 
?>