<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and logging function
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    die("System error. Please contact support.");
}

// Simple CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize messages
$success_msg = isset($_GET['success']) ? htmlspecialchars(trim($_GET['success'])) : '';
$error_msg = isset($_GET['error']) ? htmlspecialchars(trim($_GET['error'])) : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $submitted_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    
    if (empty($submitted_token) || empty($session_token) || $submitted_token !== $session_token) {
        $error_msg = "Security validation failed. Please refresh the page and try again.";
        header("Location: parent_student.php?error=" . urlencode($error_msg));
        exit();
    }

    if ($_POST['action'] === 'edit_mapping') {
        $parent_id = filter_var($_POST['parent_id'], FILTER_VALIDATE_INT);
        $student_ids = isset($_POST['student_ids']) ? array_filter($_POST['student_ids'], 'is_numeric') : [];

        if ($parent_id && !empty($student_ids)) {
            // Delete existing mappings for this parent
            $stmt = $conn->prepare("DELETE FROM parent_student_mapping WHERE parent_id = ?");
            $stmt->bind_param("i", $parent_id);
            $stmt->execute();
            $stmt->close();

            // Add new mappings
            $stmt = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
            foreach ($student_ids as $student_id) {
                $student_id_int = (int)$student_id;
                $stmt->bind_param("ii", $parent_id, $student_id_int);
                $stmt->execute();
                logActivity($conn, $_SESSION['user_id'], "Edited parent-student mapping", "Parent ID: $parent_id, Student ID: $student_id");
            }
            $stmt->close();
            
            // Regenerate CSRF token after successful action
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            $success_msg = "Mapping updated successfully.";
            header("Location: parent_student.php?success=" . urlencode($success_msg));
            exit();
        }
    } elseif ($_POST['action'] === 'delete_mapping') {
        $parent_id = filter_var($_POST['parent_id'], FILTER_VALIDATE_INT);
        if ($parent_id) {
            $stmt = $conn->prepare("DELETE FROM parent_student_mapping WHERE parent_id = ?");
            $stmt->bind_param("i", $parent_id);
            $stmt->execute();
            $stmt->close();
            logActivity($conn, $_SESSION['user_id'], "Deleted parent-student mapping", "Parent ID: $parent_id");
            
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            $success_msg = "Mapping deleted successfully.";
            header("Location: parent_student.php?success=" . urlencode($success_msg));
            exit();
        }
    } elseif ($_POST['action'] === 'bulk_delete') {
        $parent_ids = isset($_POST['parent_ids']) ? array_filter($_POST['parent_ids'], 'is_numeric') : [];
        if (!empty($parent_ids)) {
            $placeholders = implode(',', array_fill(0, count($parent_ids), '?'));
            $types = str_repeat('i', count($parent_ids));
            $stmt = $conn->prepare("DELETE FROM parent_student_mapping WHERE parent_id IN ($placeholders)");
            $stmt->bind_param($types, ...array_values($parent_ids));
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            $success_msg = "Selected mappings deleted successfully.";
            header("Location: parent_student.php?success=" . urlencode($success_msg));
            exit();
        }
    }
}

// Search, sort, pagination parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) && in_array($_GET['sort_by'], ['parent_name', 'email', 'phone']) ? $_GET['sort_by'] : 'parent_name';
$sort_order = isset($_GET['sort_order']) && $_GET['sort_order'] === 'desc' ? 'DESC' : 'ASC';
$page = isset($_GET['page']) ? max(1, filter_var($_GET['page'], FILTER_VALIDATE_INT)) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['mapped', 'unmapped']) ? $_GET['tab'] : 'mapped';

// Count stats
$stats_query = "SELECT 
    COUNT(DISTINCT psm.parent_id) as mapped_parents,
    (SELECT COUNT(*) FROM users WHERE role = 'parent' AND status = 'active') as total_parents,
    (SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active') as total_students,
    (SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.status = 'active' 
     AND u.id NOT IN (SELECT student_id FROM parent_student_mapping)) as unmapped_students
FROM parent_student_mapping psm";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Fetch mappings grouped by parent with phone numbers
$where_clause = '';
$params = [];
$param_types = '';

if ($search) {
    $where_clause = "WHERE (p.name LIKE ? OR p.email LIKE ? OR u.name LIKE ? OR p.phone LIKE ? OR p.phone_number LIKE ?)";
    $search_term = "%$search%";
    $params = [$search_term, $search_term, $search_term, $search_term, $search_term];
    $param_types = "sssss";
}

// Count total mappings
$total_query = "SELECT COUNT(DISTINCT psm.parent_id) as total 
                FROM parent_student_mapping psm 
                JOIN users p ON psm.parent_id = p.id 
                JOIN users u ON psm.student_id = u.id 
                $where_clause";
$stmt = $conn->prepare($total_query);
if ($search) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$total_mappings = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total_mappings / $per_page);

// Fetch mappings with phone numbers
$mappings = [];
$query = "SELECT psm.parent_id, 
                 p.name AS parent_name, 
                 p.email AS parent_email,
                 p.phone AS parent_phone,
                 p.phone_number AS parent_phone_number,
                 GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS student_names, 
                 GROUP_CONCAT(psm.student_id ORDER BY u.name SEPARATOR ',') AS student_ids,
                 COUNT(psm.student_id) as student_count
          FROM parent_student_mapping psm 
          JOIN users p ON psm.parent_id = p.id 
          JOIN users u ON psm.student_id = u.id 
          $where_clause 
          GROUP BY psm.parent_id, p.name, p.email, p.phone, p.phone_number 
          ORDER BY $sort_by $sort_order 
          LIMIT ? OFFSET ?";

$query_params = $params;
$query_params[] = $per_page;
$query_params[] = $offset;
$query_types = $param_types . "ii";

$stmt = $conn->prepare($query);
if (!empty($query_params)) {
    $stmt->bind_param($query_types, ...$query_params);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $phone = $row['parent_phone'] ?: $row['parent_phone_number'] ?: '';
    $mappings[] = [
        'parent_id' => $row['parent_id'],
        'parent_name' => $row['parent_name'],
        'parent_email' => $row['parent_email'],
        'parent_phone' => $phone,
        'student_names' => $row['student_names'],
        'student_ids' => explode(',', $row['student_ids']),
        'student_count' => $row['student_count']
    ];
}
$stmt->close();

// Fetch unmapped students
$unmapped_students = [];
$unmapped_query = "
    SELECT u.id, u.name AS student_name, c.class_name, d.name AS department_name
    FROM users u 
    LEFT JOIN student_classes sc ON u.id = sc.user_id 
    LEFT JOIN classes c ON sc.class_id = c.id 
    LEFT JOIN departments d ON c.department_id = d.id
    WHERE u.role = 'student' 
        AND u.status = 'active' 
        AND u.id NOT IN (SELECT student_id FROM parent_student_mapping)
    ORDER BY d.name ASC, c.class_name ASC, u.name ASC
";
$unmapped_result = $conn->query($unmapped_query);
while ($row = $unmapped_result->fetch_assoc()) {
    $unmapped_students[] = $row;
}

// Fetch parents for edit modal
$parents = [];
$parent_result = $conn->query("SELECT id, name FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name ASC");
while ($row = $parent_result->fetch_assoc()) {
    $parents[] = $row;
}

// Fetch students grouped by class for edit modal
$students_by_class = [];
$student_query = "
    SELECT u.id, u.name AS student_name, c.class_name 
    FROM users u 
    LEFT JOIN student_classes sc ON u.id = sc.user_id 
    LEFT JOIN classes c ON sc.class_id = c.id 
    WHERE u.role = 'student' AND u.status = 'active' 
    ORDER BY c.class_name ASC, u.name ASC
";
$student_result = $conn->query($student_query);
while ($row = $student_result->fetch_assoc()) {
    $class_name = $row['class_name'] ?: 'Unassigned';
    if (!isset($students_by_class[$class_name])) {
        $students_by_class[$class_name] = [];
    }
    $students_by_class[$class_name][] = [
        'id' => $row['id'],
        'student_name' => $row['student_name']
    ];
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent-Student Mapping - Queen's Paradise Montessori</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #FF6200;
            --accent-dark: #D95200;
            --accent-soft: #FFF0E6;
            --accent-glow: rgba(255,98,0,.12);
            --emerald: #10b981;
            --rose: #ef4444;
            --amber: #f59e0b;
            --sapphire: #3b82f6;
            --bg: #f0f2f7;
            --surface: #ffffff;
            --border: #e4e8f0;
            --muted: #94a3b8;
            --text: #0d1b2a;
            --text-2: #4a5568;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.07);
            --shadow-md: 0 4px 12px rgba(0,0,0,.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,.10);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .app-wrapper { display: flex; min-height: 100vh; }

        .main-panel {
            flex: 1;
            margin-left: 280px;
            padding: 32px 40px;
            width: calc(100% - 280px);
            max-width: 100%;
        }

        @media (max-width: 1024px) {
            .main-panel { margin-left: 0; width: 100%; padding: 20px 24px; }
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #FF6200 0%, #FF9100 100%);
            border-radius: var(--radius-xl);
            padding: 32px 40px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(255,98,0,.3);
        }
        .page-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='200'%3E%3Ccircle cx='350' cy='30' r='120' fill='rgba(255,255,255,.05)'/%3E%3Ccircle cx='20' cy='160' r='80' fill='rgba(255,255,255,.04)'/%3E%3C/svg%3E") no-repeat right center;
            pointer-events: none;
        }
        .ph-title { position: relative; z-index: 1; }
        .ph-title h1 { font-size: 2rem; font-weight: 700; display: flex; align-items: center; gap: 14px; letter-spacing: -0.5px; }
        .ph-title p { font-size: 15px; opacity: .85; margin-top: 8px; }
        .ph-actions { display: flex; gap: 12px; flex-wrap: wrap; position: relative; z-index: 1; }

        .ph-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
            text-decoration: none;
        }
        .ph-btn-primary { background: #fff; color: var(--accent); }
        .ph-btn-primary:hover { background: var(--accent-soft); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.15); }
        .ph-btn-ghost { background: rgba(255,255,255,.15); color: #fff; border: 1.5px solid rgba(255,255,255,.3); backdrop-filter: blur(8px); }
        .ph-btn-ghost:hover { background: rgba(255,255,255,.25); transform: translateY(-2px); }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: var(--shadow-sm);
            transition: transform .25s, box-shadow .25s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-icon {
            width: 56px; height: 56px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; flex-shrink: 0;
        }
        .stat-value { font-size: 2rem; font-weight: 700; line-height: 1.2; letter-spacing: -1px; }
        .stat-label { font-size: 13px; color: var(--text-2); font-weight: 500; margin-top: 4px; }
        .stat-sub { font-size: 11px; color: var(--muted); margin-top: 2px; font-family: 'DM Mono', monospace; }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 16px 22px; border-radius: var(--radius-md);
            font-size: 15px; font-weight: 500; margin-bottom: 28px;
            animation: slideDown .3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .6; color: inherit; }
        .alert-close:hover { opacity: 1; }

        /* Tabs */
        .tabs {
            display: flex; gap: 8px; margin-bottom: 24px;
            background: var(--surface); padding: 6px;
            border-radius: var(--radius-lg); border: 1px solid var(--border);
        }
        .tab-btn {
            flex: 1; padding: 12px 24px; border-radius: var(--radius-md);
            font-size: 14px; font-weight: 600; cursor: pointer; border: none;
            background: transparent; color: var(--text-2); transition: all .2s;
            font-family: 'DM Sans', sans-serif; text-align: center;
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .tab-btn.active { background: var(--accent); color: #fff; box-shadow: 0 2px 8px rgba(255,98,0,.3); }
        .tab-btn:hover:not(.active) { color: var(--accent); background: var(--accent-soft); }
        .tab-btn .badge {
            padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;
            background: rgba(0,0,0,.1);
        }
        .tab-btn.active .badge { background: rgba(255,255,255,.2); }

        /* Search & Actions Bar */
        .action-bar {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 20px; margin-bottom: 24px;
            display: flex; gap: 16px; flex-wrap: wrap; align-items: center;
            box-shadow: var(--shadow-sm);
        }
        .search-box {
            flex: 1; min-width: 250px; position: relative;
        }
        .search-box input {
            width: 100%; padding: 12px 16px 12px 44px;
            border: 1.5px solid var(--border); border-radius: 40px;
            font-size: 14px; font-family: 'DM Sans', sans-serif;
            background: var(--bg); color: var(--text); outline: none; transition: all .2s;
        }
        .search-box input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .search-box .search-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--muted); font-size: 15px; pointer-events: none;
        }
        .action-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 40px; font-size: 13px; font-weight: 600;
            cursor: pointer; border: none; transition: all .2s;
            font-family: 'DM Sans', sans-serif; text-decoration: none; white-space: nowrap;
        }
        .btn-accent { background: var(--accent); color: #fff; }
        .btn-accent:hover { background: var(--accent-dark); transform: translateY(-1px); }
        .btn-outline { background: transparent; color: var(--text-2); border: 1.5px solid var(--border); }
        .btn-outline:hover { border-color: var(--accent); color: var(--accent); }
        .btn-danger { background: var(--rose); color: #fff; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); }
        .btn-danger:disabled { opacity: .5; cursor: not-allowed; transform: none; }

        /* Card */
        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-xl); box-shadow: var(--shadow-sm);
            overflow: hidden; margin-bottom: 24px;
        }
        .card-header {
            background: linear-gradient(135deg, #FF6200 0%, #FF9100 100%);
            color: #fff; padding: 18px 24px; font-weight: 600;
            font-size: 1.1rem; display: flex; align-items: center; gap: 10px;
        }
        .card-body { padding: 24px; overflow-x: auto; }

        /* Table */
        .table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .table th {
            background: var(--bg); padding: 14px 16px; text-align: left;
            font-weight: 600; color: var(--text-2); font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border); white-space: nowrap;
        }
        .table th a { color: var(--text-2); text-decoration: none; }
        .table th a:hover { color: var(--accent); }
        .table td {
            padding: 14px 16px; border-bottom: 1px solid var(--border);
            color: var(--text); vertical-align: middle;
        }
        .table tbody tr { transition: background .15s; }
        .table tbody tr:hover { background: var(--accent-soft); }
        .table tbody tr:last-child td { border-bottom: none; }

        .user-avatar-name {
            display: flex; align-items: center; gap: 10px;
        }
        .user-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--accent-soft); color: var(--accent);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 14px; flex-shrink: 0;
        }
        .phone-link {
            color: var(--sapphire); text-decoration: none; font-weight: 500;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .phone-link:hover { text-decoration: underline; }
        .student-tags {
            display: flex; flex-wrap: wrap; gap: 6px;
        }
        .student-tag {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 12px; border-radius: 20px;
            background: var(--accent-soft); color: var(--accent-dark);
            font-size: 12px; font-weight: 500;
        }
        .no-phone { color: var(--muted); font-style: italic; font-size: 13px; }

        .action-icons { display: flex; gap: 6px; }
        .icon-btn {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; background: transparent;
            color: var(--text-2); font-size: 14px; transition: all .15s;
        }
        .icon-btn:hover { background: var(--accent-soft); color: var(--accent); }
        .icon-btn.delete:hover { background: #fee2e2; color: var(--rose); }

        /* Unmapped Students Grid */
        .unmapped-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }
        .unmapped-card {
            background: var(--bg); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 16px;
            display: flex; align-items: center; gap: 14px;
            transition: all .2s;
        }
        .unmapped-card:hover {
            border-color: var(--rose); box-shadow: 0 2px 8px rgba(239,68,68,.1);
        }
        .unmapped-card .warning-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: #fee2e2; color: var(--rose);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .unmapped-info { flex: 1; min-width: 0; }
        .unmapped-info .name { font-weight: 600; font-size: 14px; }
        .unmapped-info .meta { font-size: 12px; color: var(--muted); margin-top: 2px; }

        .empty-state {
            text-align: center; padding: 60px 24px; color: var(--muted);
            grid-column: 1 / -1;
        }
        .empty-state i { font-size: 56px; opacity: .25; margin-bottom: 20px; display: block; }
        .empty-state h4 { font-size: 17px; font-weight: 600; color: var(--text-2); margin-bottom: 8px; }

        /* Pagination */
        .pagination {
            display: flex; gap: 6px; justify-content: center; margin-top: 24px;
        }
        .page-link {
            padding: 10px 16px; border-radius: 8px; border: 1px solid var(--border);
            background: var(--surface); color: var(--text-2); font-size: 14px;
            cursor: pointer; text-decoration: none; transition: all .15s; font-weight: 500;
        }
        .page-link:hover { border-color: var(--accent); color: var(--accent); }
        .page-link.active { background: var(--accent); color: #fff; border-color: var(--accent); }
        .page-link.disabled { opacity: .4; pointer-events: none; }

        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.6);
            backdrop-filter: blur(4px); z-index: 1000;
            display: flex; align-items: center; justify-content: center;
            animation: fadeIn .2s ease;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-content {
            background: var(--surface); border-radius: var(--radius-xl);
            width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,.15);
            animation: scaleIn .25s ease;
        }
        @keyframes scaleIn { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 24px; border-bottom: 1px solid var(--border);
            position: sticky; top: 0; background: var(--surface); z-index: 10;
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        }
        .modal-header h2 { font-size: 1.2rem; font-weight: 700; }
        .modal-close {
            width: 36px; height: 36px; border-radius: 50%; border: none;
            background: transparent; cursor: pointer; font-size: 18px;
            color: var(--text-2); transition: all .2s;
        }
        .modal-close:hover { background: var(--bg); color: var(--rose); }
        .modal-body { padding: 24px; }
        .modal-body .student-list { max-height: 350px; overflow-y: auto; }
        .modal-body .student-list .form-check { 
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 8px; margin-bottom: 4px;
            cursor: pointer; transition: background .15s;
        }
        .modal-body .student-list .form-check:hover { background: var(--bg); }
        .modal-body .student-list .form-check input { 
            width: 18px; height: 18px; accent-color: var(--accent); 
        }
        .modal-footer {
            padding: 16px 24px; border-top: 1px solid var(--border);
            display: flex; gap: 10px; justify-content: flex-end;
            position: sticky; bottom: 0; background: var(--surface);
            border-radius: 0 0 var(--radius-xl) var(--radius-xl);
        }
        .modal-btn {
            padding: 10px 22px; border-radius: 40px; font-size: 14px; font-weight: 600;
            cursor: pointer; border: none; transition: all .2s; font-family: 'DM Sans', sans-serif;
        }
        .modal-btn-primary { background: var(--accent); color: #fff; }
        .modal-btn-primary:hover { background: var(--accent-dark); }
        .modal-btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
        .modal-btn-danger { background: var(--rose); color: #fff; }
        .modal-btn-danger:hover { background: #dc2626; }

        @media (max-width: 768px) {
            .main-panel { padding: 16px; }
            .page-header { flex-direction: column; text-align: center; padding: 24px; }
            .ph-title h1 { font-size: 1.5rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .tabs { flex-wrap: wrap; }
            .tab-btn { flex: auto; min-width: 120px; font-size: 12px; }
            .action-bar { flex-direction: column; }
            .unmapped-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <div class="main-panel">
        <!-- Page Header -->
        <div class="page-header">
            <div class="ph-title">
                <h1><i class="fas fa-link"></i> Parent-Student Mapping</h1>
                <p>Manage relationships between parents and their children</p>
            </div>
            <div class="ph-actions">
                <a href="add_mapping.php" class="ph-btn ph-btn-primary">
                    <i class="fas fa-plus-circle"></i> Add Mapping
                </a>
                <a href="/admin/dashboard.php" class="ph-btn ph-btn-ghost">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff7ed;color:#f97316;">
                    <i class="fas fa-user-friends"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo (int)$stats['mapped_parents']; ?></div>
                    <div class="stat-label">Mapped Parents</div>
                    <div class="stat-sub">of <?php echo (int)$stats['total_parents']; ?> total</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ecfdf5;color:#10b981;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo (int)$stats['total_students'] - (int)$stats['unmapped_students']; ?></div>
                    <div class="stat-label">Mapped Students</div>
                    <div class="stat-sub">of <?php echo (int)$stats['total_students']; ?> total</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fee2e2;color:#ef4444;">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo (int)$stats['unmapped_students']; ?></div>
                    <div class="stat-label">Unmapped Students</div>
                    <div class="stat-sub">Need parent assignment</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#eff6ff;color:#3b82f6;">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo (int)$stats['total_parents']; ?></div>
                    <div class="stat-label">Total Parents</div>
                    <div class="stat-sub">Active in system</div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_msg): ?>
        <div class="alert alert-success">
            <i class="fas fa-circle-check" style="font-size: 20px;"></i>
            <span><?php echo $success_msg; ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
        <div class="alert alert-danger">
            <i class="fas fa-triangle-exclamation" style="font-size: 20px;"></i>
            <span><?php echo htmlspecialchars($error_msg); ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <a href="?tab=mapped&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" 
               class="tab-btn <?php echo $tab === 'mapped' ? 'active' : ''; ?>">
                <i class="fas fa-link"></i> Mapped Parents
                <span class="badge"><?php echo (int)$stats['mapped_parents']; ?></span>
            </a>
            <a href="?tab=unmapped" 
               class="tab-btn <?php echo $tab === 'unmapped' ? 'active' : ''; ?>">
                <i class="fas fa-user-slash"></i> Unmapped Students
                <span class="badge"><?php echo (int)$stats['unmapped_students']; ?></span>
            </a>
        </div>

        <?php if ($tab === 'mapped'): ?>
        <!-- Search & Actions -->
        <form method="GET" class="action-bar">
            <input type="hidden" name="tab" value="mapped">
            <input type="hidden" name="sort_by" value="<?php echo $sort_by; ?>">
            <input type="hidden" name="sort_order" value="<?php echo $sort_order; ?>">
            <div class="search-box">
                <i class="fas fa-search search-icon"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Search by parent name, email, phone or student...">
            </div>
            <button type="submit" class="action-btn btn-accent">
                <i class="fas fa-search"></i> Search
            </button>
            <a href="?export=csv&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" 
               class="action-btn btn-outline">
                <i class="fas fa-download"></i> Export CSV
            </a>
            <button type="button" class="action-btn btn-outline" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </form>

        <!-- Bulk Delete Form -->
        <form method="POST" id="bulkForm">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div style="margin-bottom: 12px;">
                <button type="submit" class="action-btn btn-danger" id="bulkDeleteBtn" disabled>
                    <i class="fas fa-trash"></i> Delete Selected
                </button>
            </div>

            <!-- Mappings Table -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> Parent-Student Mappings
                    <span style="margin-left: auto; font-size: 14px; font-weight: 400;">
                        <?php echo $total_mappings; ?> mapped parents
                    </span>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="selectAll" style="width:16px;height:16px;accent-color:var(--accent);"></th>
                                <th>
                                    <a href="?tab=mapped&sort_by=parent_name&sort_order=<?php echo $sort_by === 'parent_name' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&search=<?php echo urlencode($search); ?>">
                                        Parent Name
                                        <?php if ($sort_by === 'parent_name'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?tab=mapped&sort_by=email&sort_order=<?php echo $sort_by === 'email' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&search=<?php echo urlencode($search); ?>">
                                        Email
                                        <?php if ($sort_by === 'email'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?tab=mapped&sort_by=phone&sort_order=<?php echo $sort_by === 'phone' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&search=<?php echo urlencode($search); ?>">
                                        Phone Number
                                        <?php if ($sort_by === 'phone'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Students</th>
                                <th style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mappings)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state" style="padding: 40px;">
                                            <i class="fas fa-link-slash"></i>
                                            <h4>No Mappings Found</h4>
                                            <p><?php echo $search ? 'No results match your search.' : 'Start by adding parent-student mappings.'; ?></p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mappings as $mapping): 
                                    $initials = strtoupper(substr($mapping['parent_name'], 0, 2));
                                    $phone = $mapping['parent_phone'];
                                    $student_list = explode(', ', $mapping['student_names']);
                                ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="parent_ids[]" value="<?php echo $mapping['parent_id']; ?>" 
                                                   class="mapping-checkbox" style="width:16px;height:16px;accent-color:var(--accent);">
                                        </td>
                                        <td>
                                            <div class="user-avatar-name">
                                                <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                                <strong><?php echo htmlspecialchars($mapping['parent_name']); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="mailto:<?php echo htmlspecialchars($mapping['parent_email']); ?>" 
                                               style="color: var(--sapphire); text-decoration: none;">
                                                <?php echo htmlspecialchars($mapping['parent_email']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($phone): ?>
                                                <a href="tel:<?php echo htmlspecialchars($phone); ?>" class="phone-link">
                                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($phone); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="no-phone">No phone</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="student-tags">
                                                <?php foreach ($student_list as $student): ?>
                                                    <span class="student-tag">
                                                        <i class="fas fa-graduation-cap"></i>
                                                        <?php echo htmlspecialchars(trim($student)); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-icons">
                                                <button class="icon-btn" title="Edit Mapping" 
                                                        onclick="openEditModal(
                                                            '<?php echo $mapping['parent_id']; ?>',
                                                            '<?php echo htmlspecialchars($mapping['parent_name'], ENT_QUOTES); ?>',
                                                            <?php echo json_encode($mapping['student_ids']); ?>
                                                        )">
                                                    <i class="fas fa-pen-to-square"></i>
                                                </button>
                                                <button class="icon-btn delete" title="Delete Mapping"
                                                        onclick="openDeleteModal(<?php echo $mapping['parent_id']; ?>, '<?php echo htmlspecialchars($mapping['parent_name'], ENT_QUOTES); ?>')">
                                                    <i class="fas fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <a href="?tab=mapped&page=1&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" 
               class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <i class="fas fa-angles-left"></i>
            </a>
            <a href="?tab=mapped&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" 
               class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?tab=mapped&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" 
                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            <a href="?tab=mapped&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" 
               class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
            <a href="?tab=mapped&page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" 
               class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <i class="fas fa-angles-right"></i>
            </a>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Unmapped Students Tab -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user-slash"></i> Unmapped Students
                <span style="margin-left: auto; font-size: 14px; font-weight: 400;">
                    <?php echo count($unmapped_students); ?> students without parents
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($unmapped_students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle" style="color: var(--emerald);"></i>
                        <h4>All Students Are Mapped!</h4>
                        <p>Every student has been assigned to a parent. Great job!</p>
                    </div>
                <?php else: ?>
                    <div class="unmapped-grid">
                        <?php foreach ($unmapped_students as $student): ?>
                            <div class="unmapped-card">
                                <div class="warning-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="unmapped-info">
                                    <div class="name"><?php echo htmlspecialchars($student['student_name']); ?></div>
                                    <div class="meta">
                                        <?php echo htmlspecialchars($student['class_name'] ?: 'No Class'); ?>
                                        <?php if ($student['department_name']): ?>
                                            — <?php echo htmlspecialchars($student['department_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="add_mapping.php" class="action-btn btn-accent" style="font-size: 12px; padding: 8px 14px;">
                                    <i class="fas fa-plus"></i> Map
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Mapping</h2>
            <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_mapping">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="parent_id" id="edit_parent_id">
            <div class="modal-body">
                <div style="margin-bottom: 16px;">
                    <strong>Parent:</strong> <span id="edit_parent_name_display"></span>
                </div>
                <label style="font-weight: 600; display: block; margin-bottom: 12px;">Select Students:</label>
                <div class="student-list">
                    <?php foreach ($students_by_class as $class_name => $students): ?>
                        <div style="margin-bottom: 12px;">
                            <strong style="font-size: 13px; color: var(--text-2);"><?php echo htmlspecialchars($class_name); ?> (<?php echo count($students); ?>)</strong>
                            <?php foreach ($students as $student): ?>
                                <div class="form-check">
                                    <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>" 
                                           id="edit-student-<?php echo $student['id']; ?>">
                                    <label for="edit-student-<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['student_name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="modal-btn modal-btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal" style="display: none;">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h2><i class="fas fa-trash-can"></i> Delete Mapping</h2>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete_mapping">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="parent_id" id="delete_parent_id">
            <div class="modal-body">
                <p style="font-size: 15px;">Are you sure you want to delete all mappings for <strong id="delete_parent_name"></strong>?</p>
                <p style="color: var(--rose); font-size: 13px; margin-top: 8px;">
                    <i class="fas fa-exclamation-triangle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="modal-btn modal-btn-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </form>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
<?php $conn->close(); ?>

<script>
function openEditModal(parentId, parentName, studentIds) {
    document.getElementById('edit_parent_id').value = parentId;
    document.getElementById('edit_parent_name_display').textContent = parentName;
    
    // Reset all checkboxes
    document.querySelectorAll('#editModal input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
    });
    
    // Check the ones that are mapped
    if (studentIds && Array.isArray(studentIds)) {
        studentIds.forEach(id => {
            const cb = document.getElementById('edit-student-' + id);
            if (cb) cb.checked = true;
        });
    }
    
    document.getElementById('editModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function openDeleteModal(parentId, parentName) {
    document.getElementById('delete_parent_id').value = parentId;
    document.getElementById('delete_parent_name').textContent = parentName;
    document.getElementById('deleteModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }
    });
});

// Escape to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.style.display = 'none';
        });
        document.body.style.overflow = '';
    }
});

// Bulk delete
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.mapping-checkbox');
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

if (selectAll) {
    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateBulkDeleteBtn();
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkDeleteBtn);
    });

    function updateBulkDeleteBtn() {
        const checked = document.querySelectorAll('.mapping-checkbox:checked').length;
        bulkDeleteBtn.disabled = checked === 0;
        if (checked > 0) {
            bulkDeleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete Selected (' + checked + ')';
        } else {
            bulkDeleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete Selected';
        }
    }

    document.getElementById('bulkForm').addEventListener('submit', function(e) {
        const checked = document.querySelectorAll('.mapping-checkbox:checked').length;
        if (checked === 0) {
            e.preventDefault();
            return;
        }
        if (!confirm('Delete ' + checked + ' selected mapping(s)? This cannot be undone.')) {
            e.preventDefault();
        }
    });
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>