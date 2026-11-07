<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) die("Fatal error: Database configuration file not found at $db_path");
require_once($db_path);
if (!$conn || mysqli_connect_error()) die("Database connection failed: " . mysqli_connect_error());

$teacher_id = (int)$_SESSION['user_id'];

// Verify teacher
$teacher_sql = "SELECT id, name FROM users WHERE id = ? AND role = 'teacher'";
$stmt = $conn->prepare($teacher_sql);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$teacher) {
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Accessed GES Annual Plan Page', ?, ?, ?, NOW())");
$log_desc = "Teacher accessed GES annual plan page";
$log->bind_param("isss", $teacher_id, $log_desc, $ip, $ua);
$log->execute();
$log->close();

// CSRF token
$csrf_token = bin2hex(random_bytes(32));
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
$cs = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$cs->bind_param("is", $teacher_id, $csrf_token);
$cs->execute();
$cs->close();

// ---------- AJAX endpoints ----------

// 1. Get classes for a department
if (isset($_GET['action']) && $_GET['action'] === 'get_classes') {
    $dept_id = (int)$_GET['department_id'];
    $out = ['classes' => []];
    if ($dept_id > 0) {
        $q = $conn->prepare("SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name");
        $q->bind_param("i", $dept_id);
        $q->execute();
        $out['classes'] = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit();
}

// 2. Get subjects for a department
if (isset($_GET['action']) && $_GET['action'] === 'get_subjects') {
    $dept_id = (int)$_GET['department_id'];
    $out = ['subjects' => []];
    if ($dept_id > 0) {
        $q = $conn->prepare("SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name");
        $q->bind_param("i", $dept_id);
        $q->execute();
        $out['subjects'] = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit();
}

// 3. Load plan data for a given class+subject
if (isset($_GET['action']) && $_GET['action'] === 'load_plan') {
    $class_id = (int)$_GET['class_id'];
    $subject_id = (int)$_GET['subject_id'];
    $data = [];
    if ($class_id > 0 && $subject_id > 0) {
        $q = $conn->prepare("SELECT week, term_1, term_2, term_3, id FROM annual_plans WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND format = 'ges'");
        $q->bind_param("iii", $teacher_id, $class_id, $subject_id);
        $q->execute();
        $res = $q->get_result();
        while ($row = $res->fetch_assoc()) {
            $data[$row['week']] = $row;
        }
        $q->close();
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// 4. Update a single cell (AJAX POST)
if (isset($_POST['action']) && $_POST['action'] === 'update_cell') {
    $response = ['success' => false, 'error' => ''];
    // Validate CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $response['error'] = 'Invalid CSRF token.';
        echo json_encode($response);
        exit();
    }
    $csq = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $csq->bind_param("is", $teacher_id, $token);
    $csq->execute();
    if ($csq->get_result()->num_rows === 0) {
        $response['error'] = 'Invalid or expired CSRF token.';
        echo json_encode($response);
        exit();
    }
    $csq->close();

    $class_id = (int)$_POST['class_id'];
    $subject_id = (int)$_POST['subject_id'];
    $week = (int)$_POST['week'];
    $field = $_POST['field']; // term_1, term_2, or term_3
    $value = trim($_POST['value']);

    if (!in_array($field, ['term_1','term_2','term_3']) || $week < 1 || $week > 16 || $class_id <= 0 || $subject_id <= 0) {
        $response['error'] = 'Invalid parameters.';
        echo json_encode($response);
        exit();
    }

    // Check if record exists
    $check = $conn->prepare("SELECT id FROM annual_plans WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND week = ? AND format = 'ges'");
    $check->bind_param("iiii", $teacher_id, $class_id, $subject_id, $week);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if ($exists) {
        $upd = $conn->prepare("UPDATE annual_plans SET $field = ?, updated_at = NOW() WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND week = ? AND format = 'ges'");
        $upd->bind_param("siiii", $value, $teacher_id, $class_id, $subject_id, $week);
        $ok = $upd->execute();
        $upd->close();
    } else {
        // Get department_id from class
        $dept_sql = "SELECT department_id FROM classes WHERE id = ?";
        $ds = $conn->prepare($dept_sql);
        $ds->bind_param("i", $class_id);
        $ds->execute();
        $res = $ds->get_result();
        $dept_id = 0;
        if ($row = $res->fetch_assoc()) {
            $dept_id = (int)$row['department_id'];
        }
        $ds->close();
        if ($dept_id <= 0) {
            $response['error'] = 'Could not determine department for this class.';
            echo json_encode($response);
            exit();
        }

        // Insert new record – now with department_id
        $ins = $conn->prepare("INSERT INTO annual_plans (teacher_id, class_id, subject_id, department_id, week, format, $field) VALUES (?, ?, ?, ?, ?, 'ges', ?)");
        $ins->bind_param("iiiiis", $teacher_id, $class_id, $subject_id, $dept_id, $week, $value);
        $ok = $ins->execute();
        $new_id = $ins->insert_id;
        $ins->close();
    }

    if ($ok) {
        $response['success'] = true;
        if (!$exists) $response['plan_id'] = $new_id ?? 0;
        // Log
        $log = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Updated GES Cell', ?, ?, ?, NOW())");
        $desc = "Updated $field for week $week, class $class_id, subject $subject_id";
        $log->bind_param("isss", $teacher_id, $desc, $ip, $ua);
        $log->execute();
        $log->close();
    } else {
        $response['error'] = 'Database error: ' . $conn->error;
        error_log("Annual plan insert error: " . $conn->error);
    }

    echo json_encode($response);
    exit();
}

// ---------- Main page ----------

// Fetch departments (Upper Primary & JHS)
$depts = [];
$dq = $conn->prepare("SELECT id, name FROM departments WHERE name LIKE '%Junior High%' OR name LIKE '%JHS%' OR name LIKE '%Upper Primary%' OR name LIKE '%Primary%' ORDER BY name");
$dq->execute();
$depts = $dq->get_result()->fetch_all(MYSQLI_ASSOC);
$dq->close();

// Fetch existing plans (for quick load list)
$plans_list = [];
$pq = $conn->prepare("SELECT DISTINCT ap.class_id, ap.subject_id, c.class_name, s.subject_name FROM annual_plans ap JOIN classes c ON ap.class_id = c.id JOIN subjects s ON ap.subject_id = s.id WHERE ap.teacher_id = ? AND ap.format = 'ges' ORDER BY c.class_name, s.subject_name");
$pq->bind_param("i", $teacher_id);
$pq->execute();
$plans_list = $pq->get_result()->fetch_all(MYSQLI_ASSOC);
$pq->close();

// DO NOT CLOSE $conn HERE – the header and footer need it
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GES Annual Scheme of Learning – QueensPa</title>
    <style>
        /* Reset & base */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f8f9fa;
            color: #1e293b;
            line-height: 1.6;
            padding: 1rem;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #0f172a;
        }
        .page-header h1 i { color: #f97316; margin-right: 0.5rem; }
        .theme-toggle {
            background: #e2e8f0;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.2s;
        }
        .theme-toggle:hover { background: #cbd5e1; }
        /* Cards */
        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 0.75rem;
        }
        /* Selection row */
        .selection-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }
        .selection-row .form-group {
            flex: 1 1 180px;
            min-width: 150px;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            color: #475569;
            margin-bottom: 0.25rem;
        }
        .form-group select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #fff;
            font-size: 0.95rem;
            transition: border 0.2s;
        }
        .form-group select:focus { outline: none; border-color: #f97316; }
        /* Buttons */
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #f97316;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            text-decoration: none;
        }
        .btn:hover { background: #ea580c; }
        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.8rem; }

        /* Loading progress */
        #loading-area {
            margin: 0.5rem 0 1rem 0;
            display: none;
        }
        #loading-area .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.3rem;
        }
        #loading-area .progress-bar .progress-fill {
            height: 100%;
            width: 0%;
            background: #f97316;
            transition: width 0.3s ease;
        }
        #loading-area .status-text {
            font-size: 0.9rem;
            color: #475569;
        }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            border: 1px solid #e2e8f0;
        }
        thead {
            background: #f1f5f9;
            border-bottom: 2px solid #e2e8f0;
        }
        th {
            padding: 0.75rem 0.5rem;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
            border-right: 1px solid #e2e8f0;
        }
        th:last-child { border-right: none; }
        td {
            padding: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            vertical-align: top;
            min-width: 200px;
        }
        td:last-child { border-right: none; }
        .week-col {
            font-weight: 600;
            text-align: center;
            min-width: 60px;
            background: #f8fafc;
        }
        /* Editable cell */
        .editable-cell {
            cursor: pointer;
            padding: 0.3rem 0.4rem;
            border-radius: 4px;
            transition: background 0.15s;
            min-height: 2.2rem;
            word-break: break-word;
        }
        .editable-cell:hover {
            background: #f1f5f9;
        }
        .editable-cell:focus {
            outline: 2px solid #f97316;
            outline-offset: -2px;
            background: #fff;
        }
        /* Skeleton row */
        .skeleton-row td {
            height: 3rem;
        }
        .skeleton-cell {
            background: #e2e8f0;
            border-radius: 4px;
            height: 1.2rem;
            width: 100%;
            animation: shimmer 1.5s infinite linear;
            background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
            background-size: 200% 100%;
        }
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        /* Existing plans list */
        .plan-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .plan-item:last-child { border-bottom: none; }
        .plan-item .plan-info {
            font-weight: 500;
        }
        .plan-item .plan-info small {
            font-weight: normal;
            color: #64748b;
            margin-left: 0.75rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .selection-row { flex-direction: column; }
            .form-group { flex: 1 1 auto; }
        }

        /* Dark mode */
        body.dark {
            background: #0f172a;
            color: #e2e8f0;
        }
        body.dark .card {
            background: #1e293b;
            border-color: #334155;
        }
        body.dark .card-title { color: #f1f5f9; }
        body.dark .form-group label { color: #94a3b8; }
        body.dark .form-group select {
            background: #1e293b;
            color: #e2e8f0;
            border-color: #475569;
        }
        body.dark .form-group select:focus { border-color: #f97316; }
        body.dark thead { background: #1e293b; }
        body.dark th { color: #f1f5f9; border-color: #334155; }
        body.dark td { border-color: #334155; }
        body.dark .week-col { background: #0f172a; }
        body.dark .editable-cell:hover { background: #334155; }
        body.dark .editable-cell:focus { outline-color: #f97316; }
        body.dark .skeleton-cell { background: #334155; background: linear-gradient(90deg, #334155 25%, #475569 50%, #334155 75%); }
        body.dark .btn-secondary { background: #475569; color: #f1f5f9; }
        body.dark .btn-secondary:hover { background: #64748b; }
        body.dark .plan-item { border-color: #334155; }
        body.dark .page-header h1 { color: #f1f5f9; }
        body.dark .page-header { border-color: #334155; }
        body.dark .theme-toggle { background: #334155; color: #f1f5f9; }
        body.dark .theme-toggle:hover { background: #475569; }
    </style>
</head>
<body>
    <?php
    // Safely include the header
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    if (file_exists($header_path)) {
        include $header_path;
    } else {
        echo '<div style="background:#f8d7da;padding:0.5rem;border-bottom:2px solid #f5c6cb;">⚠️ Header file not found at ' . htmlspecialchars($header_path) . '</div>';
    }
    ?>

    <div class="container">
        <!-- Page title & theme toggle -->
        <header class="page-header">
            <h1><i class="fas fa-calendar-alt"></i> GES Annual Scheme of Learning</h1>
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">🌙 Dark</button>
        </header>

        <!-- Selection Card -->
        <div class="card">
            <div class="card-title">Select Class & Subject</div>
            <div class="selection-row">
                <div class="form-group">
                    <label for="department_id">Department</label>
                    <select id="department_id">
                        <option value="">— Select —</option>
                        <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="class_id">Class</label>
                    <select id="class_id">
                        <option value="">Select class first</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="subject_id">Subject</label>
                    <select id="subject_id">
                        <option value="">Select subject</option>
                    </select>
                </div>
                <div class="form-group" style="flex: 0 0 auto;">
                    <button class="btn" id="loadPlanBtn">Load Plan</button>
                </div>
            </div>
        </div>

        <!-- Quick Load Existing Plans -->
        <?php if (!empty($plans_list)): ?>
        <div class="card">
            <div class="card-title">📂 Your Saved Plans</div>
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                <?php foreach ($plans_list as $p): ?>
                    <button class="btn btn-secondary btn-sm load-existing" 
                            data-class="<?= $p['class_id'] ?>" 
                            data-subject="<?= $p['subject_id'] ?>">
                        <?= htmlspecialchars($p['class_name']) ?> – <?= htmlspecialchars($p['subject_name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Loading Progress -->
        <div id="loading-area">
            <div class="status-text" id="statusText">Loading plan...</div>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
        </div>

        <!-- Main Table -->
        <div class="card">
            <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
                <span>Weekly Strands (GES)</span>
                <span style="font-size: 0.8rem; font-weight: normal; color: #64748b;" id="planInfo"></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width: 60px;">Week</th>
                            <th>Term 1 Strands</th>
                            <th>Term 2 Strands</th>
                            <th>Term 3 Strands</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <!-- Skeleton rows will be generated by JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- CSRF token (used in AJAX) -->
        <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token) ?>">
    </div>

    <?php
    // Safely include the footer
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        echo '<div style="background:#f8d7da;padding:0.5rem;border-top:2px solid #f5c6cb;">⚠️ Footer file not found at ' . htmlspecialchars($footer_path) . '</div>';
    }
    ?>

    <!-- Font Awesome (free) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // DOM refs
        const deptSelect = document.getElementById('department_id');
        const classSelect = document.getElementById('class_id');
        const subjectSelect = document.getElementById('subject_id');
        const loadBtn = document.getElementById('loadPlanBtn');
        const tableBody = document.getElementById('tableBody');
        const loadingArea = document.getElementById('loading-area');
        const progressFill = document.getElementById('progressFill');
        const statusText = document.getElementById('statusText');
        const planInfo = document.getElementById('planInfo');
        const csrfToken = document.getElementById('csrfToken').value;

        // Theme toggle
        const themeToggle = document.getElementById('themeToggle');
        themeToggle.addEventListener('click', function() {
            document.body.classList.toggle('dark');
            const isDark = document.body.classList.contains('dark');
            themeToggle.textContent = isDark ? '☀️ Light' : '🌙 Dark';
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark');
            themeToggle.textContent = '☀️ Light';
        }

        // Load classes on department change
        deptSelect.addEventListener('change', function() {
            const deptId = this.value;
            classSelect.innerHTML = '<option value="">Select class</option>';
            subjectSelect.innerHTML = '<option value="">Select subject</option>';
            if (!deptId) return;
            fetch(`?action=get_classes&department_id=${deptId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.classes) {
                        data.classes.forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = c.class_name;
                            classSelect.appendChild(opt);
                        });
                    }
                });
            // Also load subjects
            fetch(`?action=get_subjects&department_id=${deptId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.subjects) {
                        data.subjects.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = s.id;
                            opt.textContent = s.subject_name;
                            subjectSelect.appendChild(opt);
                        });
                    }
                });
        });

        // Load plan
        function loadPlan(classId, subjectId) {
            if (!classId || !subjectId) {
                alert('Please select both class and subject.');
                return;
            }
            // Show loading
            loadingArea.style.display = 'block';
            statusText.textContent = 'Loading plan...';
            progressFill.style.width = '0%';
            // Show skeleton rows
            renderSkeleton(16);

            // Simulate progress
            let prog = 0;
            const interval = setInterval(() => {
                prog += 5;
                if (prog > 90) clearInterval(interval);
                progressFill.style.width = Math.min(prog, 90) + '%';
            }, 100);

            fetch(`?action=load_plan&class_id=${classId}&subject_id=${subjectId}`)
                .then(r => r.json())
                .then(data => {
                    clearInterval(interval);
                    progressFill.style.width = '100%';
                    statusText.textContent = 'Plan loaded.';
                    setTimeout(() => {
                        loadingArea.style.display = 'none';
                    }, 400);
                    renderTable(data, classId, subjectId);
                    // Update info
                    const classText = classSelect.options[classSelect.selectedIndex]?.text || '';
                    const subjectText = subjectSelect.options[subjectSelect.selectedIndex]?.text || '';
                    planInfo.textContent = `${classText} – ${subjectText}`;
                })
                .catch(err => {
                    clearInterval(interval);
                    statusText.textContent = 'Error loading plan.';
                    progressFill.style.width = '100%';
                    setTimeout(() => {
                        loadingArea.style.display = 'none';
                    }, 1000);
                    console.error(err);
                });
        }

        // Render skeleton rows
        function renderSkeleton(weeks) {
            let html = '';
            for (let w = 1; w <= weeks; w++) {
                html += `<tr class="skeleton-row">
                    <td class="week-col">${w}</td>
                    <td><div class="skeleton-cell"></div></td>
                    <td><div class="skeleton-cell"></div></td>
                    <td><div class="skeleton-cell"></div></td>
                </tr>`;
            }
            tableBody.innerHTML = html;
        }

        // Render actual data
        function renderTable(data, classId, subjectId) {
            let html = '';
            for (let w = 1; w <= 16; w++) {
                const row = data[w] || {};
                const planId = row.id || '';
                const t1 = row.term_1 || '';
                const t2 = row.term_2 || '';
                const t3 = row.term_3 || '';
                html += `<tr data-week="${w}" data-plan-id="${planId}">
                    <td class="week-col">${w}</td>
                    <td><div class="editable-cell" data-field="term_1" data-week="${w}" contenteditable="false">${escapeHtml(t1)}</div></td>
                    <td><div class="editable-cell" data-field="term_2" data-week="${w}" contenteditable="false">${escapeHtml(t2)}</div></td>
                    <td><div class="editable-cell" data-field="term_3" data-week="${w}" contenteditable="false">${escapeHtml(t3)}</div></td>
                </tr>`;
            }
            tableBody.innerHTML = html;
            // Attach click-to-edit events
            document.querySelectorAll('.editable-cell').forEach(cell => {
                cell.addEventListener('click', enableEditing);
            });
            // Store current class/subject in data attributes for saving
            tableBody.dataset.classId = classId;
            tableBody.dataset.subjectId = subjectId;
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Inline editing
        function enableEditing(e) {
            const cell = e.currentTarget;
            if (cell.getAttribute('contenteditable') === 'true') return; // already editing
            cell.setAttribute('contenteditable', 'true');
            cell.focus();
            // Select all text
            const range = document.createRange();
            range.selectNodeContents(cell);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            // Save on blur
            cell.addEventListener('blur', saveCell);
            // Cancel on Escape
            cell.addEventListener('keydown', function(ev) {
                if (ev.key === 'Escape') {
                    cell.textContent = cell.dataset.oldText || '';
                    cell.removeAttribute('contenteditable');
                    cell.removeEventListener('blur', saveCell);
                }
            });
            // Store old text for revert
            cell.dataset.oldText = cell.textContent;
        }

        function saveCell(e) {
            const cell = e.currentTarget;
            const newValue = cell.textContent.trim();
            const week = cell.dataset.week;
            const field = cell.dataset.field;
            const classId = tableBody.dataset.classId;
            const subjectId = tableBody.dataset.subjectId;

            // Remove contenteditable
            cell.removeAttribute('contenteditable');
            cell.removeEventListener('blur', saveCell);

            // If value didn't change, do nothing
            if (cell.dataset.oldText === newValue) return;

            // Show saving status
            loadingArea.style.display = 'block';
            statusText.textContent = `Saving week ${week}...`;
            progressFill.style.width = '0%';
            let prog = 0;
            const interval = setInterval(() => {
                prog += 10;
                if (prog > 80) clearInterval(interval);
                progressFill.style.width = Math.min(prog, 80) + '%';
            }, 50);

            // AJAX
            const formData = new FormData();
            formData.append('action', 'update_cell');
            formData.append('csrf_token', csrfToken);
            formData.append('class_id', classId);
            formData.append('subject_id', subjectId);
            formData.append('week', week);
            formData.append('field', field);
            formData.append('value', newValue);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                clearInterval(interval);
                if (res.success) {
                    statusText.textContent = 'Saved.';
                    progressFill.style.width = '100%';
                    // Update plan-id if new
                    if (res.plan_id) {
                        const tr = cell.closest('tr');
                        tr.dataset.planId = res.plan_id;
                    }
                } else {
                    statusText.textContent = 'Error: ' + (res.error || 'Unknown');
                    // revert
                    cell.textContent = cell.dataset.oldText || '';
                }
                setTimeout(() => {
                    loadingArea.style.display = 'none';
                }, 600);
            })
            .catch(err => {
                clearInterval(interval);
                statusText.textContent = 'Error saving.';
                progressFill.style.width = '100%';
                setTimeout(() => loadingArea.style.display = 'none', 800);
                cell.textContent = cell.dataset.oldText || '';
                console.error(err);
            });
        }

        // Load button
        loadBtn.addEventListener('click', function() {
            const classId = classSelect.value;
            const subjectId = subjectSelect.value;
            loadPlan(classId, subjectId);
        });

        // Click on existing plan buttons
        document.querySelectorAll('.load-existing').forEach(btn => {
            btn.addEventListener('click', function() {
                const classId = this.dataset.class;
                const subjectId = this.dataset.subject;
                loadPlan(classId, subjectId);
            });
        });
    });
    </script>
</body>
</html>