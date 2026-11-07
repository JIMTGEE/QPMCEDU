<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/* -------------------------------------------------
   SECURITY & ACCESS CONTROL
------------------------------------------------- */
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin.";
    header("Location: /login.php");
    exit();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
$admin_id = (int)$_SESSION['user_id'];
$check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
$check->bind_param("i", $admin_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    $_SESSION['error_msg'] = "Invalid session.";
    header("Location: /login.php");
    exit();
}
$check->close();
logActivity($conn, $admin_id, "Accessed View Mock Report", "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

/* -------------------------------------------------
   CURRENT TERM & ACADEMIC YEAR
------------------------------------------------- */
$today = new DateTime();
$current_academic_year = $today->format('Y') . '/' . ($today->format('Y') + 1);
$term_key = "current_term_$admin_id";
if (!isset($_SESSION[$term_key])) {
    $q = $conn->query(
        "SELECT id FROM academic_calendar
         WHERE start_date <= CURDATE() AND end_date >= CURDATE()
         LIMIT 1"
    );
    $_SESSION[$term_key] = $q->fetch_assoc() ?: ['id' => 1];
}
$current_term_id = $_SESSION[$term_key]['id'] ?? 1;
$stmt = $conn->prepare("SELECT term_name FROM academic_calendar WHERE id = ?");
$stmt->bind_param("i", $current_term_id);
$stmt->execute();
$result = $stmt->get_result();
$current_term = $result->fetch_assoc();
$stmt->close();

/* -------------------------------------------------
   MAJESTY CLASS
------------------------------------------------- */
$majesty = $conn->query("SELECT id, department_id FROM classes WHERE class_name = 'Majesty' LIMIT 1")->fetch_assoc();
if (!$majesty) {
    $_SESSION['error_msg'] = "Majesty class not found.";
    header("Location: /admin/dashboard.php");
    exit();
}
$majesty_id = $majesty['id'];
$department_id = $majesty['department_id'];

/* -------------------------------------------------
   HELPER: GRADE CALCULATION
------------------------------------------------- */
function calculateGrade(float $score): string {
    if ($score >= 95) return 'Grade 1 | Highest';
    if ($score >= 85) return 'Grade 2 | Higher';
    if ($score >= 75) return 'Grade 3 | High';
    if ($score >= 65) return 'Grade 4 | High Average';
    if ($score >= 55) return 'Grade 5 | Average';
    if ($score >= 45) return 'Grade 6 | Low Average';
    if ($score >= 36) return 'Grade 7 | Low';
    if ($score >= 31) return 'Grade 8 | Lower';
    return 'Grade 9 | Lowest';
}

/* -------------------------------------------------
   CSRF – generate a *fresh* token for every AJAX request
------------------------------------------------- */
function generateCsrf($conn, $admin_id) {
    $token = bin2hex(random_bytes(32));
    $stmt = $conn->prepare(
        "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))
         ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)"
    );
    $stmt->bind_param("is", $admin_id, $token);
    $stmt->execute();
    $stmt->close();
    $conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
    return $token;
}

/* -------------------------------------------------
   AJAX HANDLERS
------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    // ---- 1. Generate fresh CSRF (used by “Load Report”) ----
    if ($_POST['action'] === 'get_csrf') {
        $token = generateCsrf($conn, $admin_id);
        echo json_encode(['csrf_token' => $token]);
        exit;
    }
    // ---- 2. Validate CSRF (all other actions) ----
    $csrf = $_POST['csrf_token'] ?? '';
    $valid = $conn->prepare("SELECT 1 FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $valid->bind_param("is", $admin_id, $csrf);
    $valid->execute();
    $ok = $valid->get_result()->num_rows === 1;
    $valid->close();
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    // ---- 3. Load mock report table ----
    if ($_POST['action'] === 'load_report') {
        $mock_number = (int)$_POST['mock_number'];
        $term_id = (int)$_POST['term_id'] ?? $current_term_id;
        if ($mock_number < 1) {
            echo json_encode(['success' => false, 'message' => 'Mock number must be ≥ 1']);
            exit;
        }
        // Pull subjects for department
        $subjects = $conn->prepare("SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name");
        $subjects->bind_param("i", $department_id);
        $subjects->execute();
        $subj_res = $subjects->get_result();
        $subject_list = [];
        while ($s = $subj_res->fetch_assoc()) $subject_list[] = $s;
        $subjects->close();
        // Pull students in class (current year)
        $students = $conn->prepare(
            "SELECT u.id, u.name
             FROM users u
             JOIN student_classes sc ON u.id = sc.user_id
             WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
             ORDER BY u.name"
        );
        $students->bind_param("is", $majesty_id, $current_academic_year);
        $students->execute();
        $stud_res = $students->get_result();
        $student_list = [];
        while ($s = $stud_res->fetch_assoc()) $student_list[] = $s;
        $students->close();
        // Pull existing scores for this mock
        $existing = [];
        $q = $conn->prepare(
            "SELECT student_id, subject_id, mock_raw AS raw_score, mock_grade AS grade, mock_comments AS comments
             FROM mock_entries
             WHERE class_id = ? AND term_id = ? AND academic_year = ? AND mock_number = ?
             ORDER BY student_id, subject_id"
        );
        $q->bind_param("iisi", $majesty_id, $term_id, $current_academic_year, $mock_number);
        $q->execute();
        $res = $q->get_result();
        while ($row = $res->fetch_assoc()) {
            $existing[$row['student_id']][$row['subject_id']] = $row;
        }
        $q->close();
        // Build HTML table
        $html = '<div class="print-section">';
        $html .= '<h3 class="text-xl font-semibold mb-4">Mock Exam #' . $mock_number . ' Report - Majesty Class</h3>';
        $html .= '<table class="w-full table-auto border-collapse print:table"><thead><tr class="bg-primary-700 text-left"><th class="p-3">Student</th>';
        foreach ($subject_list as $sub) {
            $html .= '<th class="p-3">' . htmlspecialchars($sub['subject_name']) . '</th>';
        }
        $html .= '<th class="p-3">Overall Average</th><th class="p-3">Comments</th></tr></thead><tbody>';
        $class_averages = [];
        foreach ($student_list as $stu) {
            $stu_total = 0;
            $stu_count = 0;
            $html .= '<tr class="border-b border-gray-700 hover:bg-gray-800 transition print:border-gray-500"><td class="p-3 font-medium">' . htmlspecialchars($stu['name']) . '</td>';
            foreach ($subject_list as $sub) {
                $sid = $sub['id'];
                $key = $existing[$stu['id']][$sid] ?? null;
                $score = $key['raw_score'] ?? 'N/A';
                $grade = $key['grade'] ?? '';
                $stu_total += is_numeric($score) ? (float)$score : 0;
                $stu_count++;
                $html .= '<td class="p-2 text-center"><span class="font-medium">' . htmlspecialchars($score) . '</span><br><small class="text-xs text-gray-400">' . htmlspecialchars($grade) . '</small></td>';
                if (!isset($class_averages[$sid])) $class_averages[$sid] = ['sum' => 0, 'count' => 0];
                if (is_numeric($score)) {
                    $class_averages[$sid]['sum'] += (float)$score;
                    $class_averages[$sid]['count']++;
                }
            }
            $stu_avg = $stu_count > 0 ? round($stu_total / $stu_count, 2) : 0;
            $stu_grade = calculateGrade($stu_avg);
            $html .= '<td class="p-2 text-center"><span class="font-semibold">' . $stu_avg . '%</span><br><small class="text-xs text-gray-400">' . $stu_grade . '</small></td>';
            $comments = '';
            foreach ($subject_list as $sub) {
                $key = $existing[$stu['id']][$sub['id']] ?? null;
                if ($key['comments']) $comments .= ($comments ? '; ' : '') . htmlspecialchars($key['comments']);
            }
            $html .= '<td class="p-2"><small class="text-gray-300">' . $comments . '</small></td></tr>';
        }
        $html .= '</tbody></table>';
        // Add summary row if data exists
        if (!empty($existing)) {
            $html .= '<div class="mt-4 p-4 bg-gray-800 rounded-lg print:bg-gray-700 print:border">';
            $html .= '<h4 class="font-semibold mb-2">Class Averages:</h4><table class="w-full table-auto print:table"><thead><tr><th class="p-2">Subject</th><th class="p-2">Average Score</th></tr></thead><tbody>';
            foreach ($subject_list as $sub) {
                $sid = $sub['id'];
                $avg = isset($class_averages[$sid]) && $class_averages[$sid]['count'] > 0 ? round($class_averages[$sid]['sum'] / $class_averages[$sid]['count'], 2) : 'N/A';
                $html .= '<tr><td class="p-2">' . htmlspecialchars($sub['subject_name']) . '</td><td class="p-2 text-center">' . $avg . '%</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div>';
        echo json_encode(['success' => true, 'table' => $html]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Mock Reports – Admin</title>
    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], orbitron: ['Orbitron', 'sans-serif'] },
                    colors: { primary: { 500: '#f97316' }, accent: { 500: '#1e3a8a' } },
                    animation: { 'fade-in': 'fadeIn .6s ease-out' },
                    keyframes: { fadeIn: { from: { opacity: 0 }, to: { opacity: 1 } } }
                }
            }
        }
    </script>
    <style>
        .glass { @apply bg-white bg-opacity-10 backdrop-blur-md border border-white border-opacity-20 shadow-lg; }
        .dark .glass { @apply bg-gray-800 bg-opacity-30 border-gray-600; }
        @media print {
            body * { visibility: hidden; }
            .print-section, .print-section * { visibility: visible; }
            .print-section { position: absolute; left: 0; top: 0; width: 100%; }
            table.print { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #000; padding: 8px; }
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-orange-900 text-gray-100">
<?php
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
if (file_exists($header_path)) include $header_path;
else echo '<header class="bg-primary-600 p-4 text-center text-white"><h1 class="text-2xl font-orbitron">Admin Dashboard</h1></header>';
?>
<main class="container mx-auto p-6 max-w-7xl">
    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="text-2xl font-orbitron mb-2">Mock Exam Reports – Majesty Class</h2>
        <p class="text-sm opacity-80">
            Term: <?= htmlspecialchars($current_term['term_name'] ?? 'Current') ?> |
            Academic Year: <?= htmlspecialchars($current_academic_year) ?>
        </p>
    </div>
    <!-- Filters -->
    <div class="glass rounded-xl p-6 mb-6">
        <form id="report-filter" class="grid md:grid-cols-2 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium mb-1">Term</label>
                <select id="term-filter" class="w-full px-3 py-2 bg-gray-800 text-white rounded">
                    <option value="<?= $current_term_id ?>">Current Term</option>
                    <?php
                    $terms = $conn->query("SELECT id, term_name FROM academic_calendar ORDER BY year DESC")->fetch_all(MYSQLI_ASSOC);
                    foreach ($terms as $t): if ($t['id'] == $current_term_id) continue; ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['term_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Mock Number</label>
                <input type="number" id="mock-filter" min="1" class="w-full px-3 py-2 bg-gray-800 text-white rounded" placeholder="e.g. 1">
            </div>
            <button type="button" id="load-report" class="md:col-span-2 px-6 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg font-medium transition">
                Load Report
            </button>
        </form>
    </div>
    <!-- Print button (appears after report loads) -->
    <div id="print-wrapper" class="mb-6 text-center hidden">
        <button id="print-report" class="px-6 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium transition">
            <i class="fas fa-print mr-2"></i> Print Report
        </button>
    </div>
    <!-- Report table (filled by AJAX) -->
    <div id="report-table" class="glass rounded-xl p-6 overflow-x-auto hidden"></div>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.id = 'csrf_holder';
    // ---- Get fresh CSRF token (once on page load) ----
    fetch('', {method:'POST', body: new URLSearchParams({action:'get_csrf'})})
        .then(r=>r.json())
        .then(j=> csrfInput.value = j.csrf_token);
    // ---- Load report ----
    document.getElementById('load-report').addEventListener('click', async () => {
        const mockNum = parseInt(document.getElementById('mock-filter').value);
        const termId = document.getElementById('term-filter').value || '<?= $current_term_id ?>';
        if (isNaN(mockNum) || mockNum < 1) { alert('Enter a valid mock number'); return; }
        const fd = new FormData();
        fd.append('action', 'load_report');
        fd.append('mock_number', mockNum);
        fd.append('term_id', termId);
        fd.append('csrf_token', csrfInput.value);
        const res = await fetch('', {method:'POST', body:fd});
        const json = await res.json();
        if (!json.success) { alert(json.message || 'Error loading report'); return; }
        const tableDiv = document.getElementById('report-table');
        tableDiv.innerHTML = json.table;
        tableDiv.classList.remove('hidden');
        document.getElementById('print-wrapper').classList.remove('hidden');
    });
    // ---- Print button ----
    document.getElementById('print-report').addEventListener('click', () => {
        window.print();
    });
});
</script>
</body>
</html>
<?php
ob_end_flush();
?>