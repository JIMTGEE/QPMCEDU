<?php
// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');

// Start output buffering and session
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Restrict to admins only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an admin.";
    header("Location: /login.php");
    exit();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate user_id
$admin_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin'";
$user_check_stmt = $conn->prepare($user_check_sql);
if (!$user_check_stmt) {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->bind_param("i", $admin_id);
$user_check_stmt->execute();
if ($user_check_stmt->get_result()->num_rows === 0) {
    error_log("Invalid admin_id: $admin_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->close();

// CSRF Token Management
function generateCsrfToken($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
    $stmt = $conn->prepare($insert_sql);
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $token, $expires_at);
        if ($stmt->execute()) {
            $stmt->close();
            return $token;
        }
        $stmt->close();
    }
    error_log("Failed to generate CSRF token for user_id $user_id");
    return bin2hex(random_bytes(32)); // Fallback
}

function validateCsrfToken($conn, $user_id, $token) {
    if (empty($token)) return false;
    $sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = $result->num_rows > 0;
        if ($valid) {
            $delete_sql = "DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param("is", $user_id, $token);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        $stmt->close();
        return $valid;
    }
    error_log("CSRF validation failed for user_id $user_id");
    return false;
}

// Generate CSRF token for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['csrf_token'] = generateCsrfToken($conn, $admin_id);
}

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Handle AJAX requests
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'];

    if ($action === 'get_subjects') {
        $subjects_query = "SELECT DISTINCT subject_name FROM subjects ORDER BY subject_name ASC";
        $result = $conn->query($subjects_query);
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = ['subject_name' => $row['subject_name']];
        }
        echo json_encode(['success' => true, 'subjects' => $subjects]);
        exit();
    } elseif ($action === 'get_classes') {
        $classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name ASC";
        $result = $conn->query($classes_sql);
        $classes = [];
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
        echo json_encode(['success' => true, 'classes' => $classes]);
        exit();
    } elseif ($action === 'get_teachers') {
        $teachers_sql = "SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name ASC";
        $result = $conn->query($teachers_sql);
        $teachers = [];
        while ($row = $result->fetch_assoc()) {
            $teachers[] = $row;
        }
        echo json_encode(['success' => true, 'teachers' => $teachers]);
        exit();
    } elseif ($action === 'get_terms') {
        $terms_sql = "SELECT id, term_name, year, start_date, end_date FROM academic_calendar ORDER BY year DESC, start_date DESC";
        $result = $conn->query($terms_sql);
        $terms = [];
        while ($row = $result->fetch_assoc()) {
            $terms[] = $row;
        }
        echo json_encode(['success' => true, 'terms' => $terms]);
        exit();
    } elseif ($action === 'get_periods' && isset($_GET['term_id']) && isset($_GET['entry_period'])) {
        $term_id = intval($_GET['term_id']);
        $entry_period = $_GET['entry_period'];
        $periods_query = "SELECT DISTINCT period_start_date, period_end_date 
                          FROM academic_progress_entries 
                          WHERE term_id = ? AND entry_period = ? 
                          ORDER BY period_start_date";
        $stmt = $conn->prepare($periods_query);
        $stmt->bind_param("is", $term_id, $entry_period);
        $stmt->execute();
        $result = $stmt->get_result();
        $periods = [];
        $index = 1;
        while ($row = $result->fetch_assoc()) {
            $periods[] = [
                'start' => $row['period_start_date'],
                'end' => $row['period_end_date'],
                'label' => $entry_period === 'weekly' ? "Week $index" : "Bi-Week $index"
            ];
            $index++;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'periods' => $periods]);
        exit();
    } elseif ($action === 'get_subject_progress' && isset($_GET['subject_name'])) {
        $subject_name = $_GET['subject_name'];
        $entry_period = 'weekly'; // Default to weekly for line graph
        $query = "SELECT u.name AS student_name, ape.total_scaled, ape.period_start_date 
                  FROM academic_progress_entries ape 
                  JOIN users u ON ape.student_id = u.id 
                  JOIN subjects s ON ape.subject_id = s.id 
                  WHERE s.subject_name = ? AND ape.entry_period = ? 
                  ORDER BY ape.period_start_date, u.name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $subject_name, $entry_period);
        $stmt->execute();
        $result = $stmt->get_result();
        $progress = [];
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'progress' => $progress]);
        exit();
    } elseif ($action === 'get_filtered_progress') {
        $class_id = intval($_POST['class_id']);
        $term_id = intval($_POST['term_id']);
        $entry_period = $_POST['entry_period'];
        $period_start = $_POST['period_start'];
        $period_end = $_POST['period_end'];
        $teacher_id = intval($_POST['teacher_id'] ?? 0);
        // Validate CSRF token for POST
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($conn, $admin_id, $csrf_token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit();
        }
        $query = "SELECT u.name AS student_name, s.subject_name, ape.total_scaled, t.name AS teacher_name 
                  FROM academic_progress_entries ape 
                  JOIN users u ON ape.student_id = u.id 
                  JOIN subjects s ON ape.subject_id = s.id 
                  JOIN users t ON ape.teacher_id = t.id 
                  WHERE ape.class_id = ? AND ape.term_id = ? 
                  AND ape.entry_period = ? AND ape.period_start_date = ? AND ape.period_end_date = ?";
        $params = "iisss";
        $values = [$class_id, $term_id, $entry_period, $period_start, $period_end];
        if ($teacher_id > 0) {
            $query .= " AND ape.teacher_id = ?";
            $params .= "i";
            $values[] = $teacher_id;
        }
        $query .= " ORDER BY u.name, s.subject_name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($params, ...$values);
        $stmt->execute();
        $result = $stmt->get_result();
        $progress = [];
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'progress' => $progress]);
        exit();
    }
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit();
}

// Fetch initial data
$subjects_sql = "SELECT DISTINCT subject_name FROM subjects ORDER BY subject_name ASC";
$subjects_result = $conn->query($subjects_sql);
$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = ['subject_name' => $row['subject_name']];
}

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin progress view for QueensPa School Management System">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Admin Progress Monitoring - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Orbitron:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        /* ===== UPDATED UI/UX: HIGH CONTRAST & READABILITY ===== */
        * {
            box-sizing: border-box;
        }
        html {
            font-size: 16px;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #0f172a; /* Solid dark navy – no gradient distraction */
            color: #f1f5f9;
            min-height: 100vh;
            line-height: 1.6;
        }
        h1, h2, h3 {
            font-family: 'Orbitron', sans-serif;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        /* Solid card style with high opacity */
        .glassmorphism {
            background: rgba(30, 41, 59, 0.9);  /* almost opaque slate */
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            border-radius: 1.25rem;
            padding: 1.5rem;
        }
        .dark .glassmorphism {
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        /* Form labels – bright and clear */
        label {
            font-weight: 600;
            font-size: 0.95rem;
            color: #e2e8f0;
            margin-bottom: 0.25rem;
        }
        /* Select inputs – fully opaque, strong border */
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: #1e293b;
            border: 2px solid #475569;
            color: #f8fafc;
            border-radius: 0.75rem;
            font-size: 0.95rem;
            font-weight: 500;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23f97316' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 0.8rem;
            cursor: pointer;
        }
        .form-select:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.4);
            background-color: #0f172a;
        }
        .form-select:disabled {
            opacity: 0.5;
            background-color: #1e293b;
            cursor: not-allowed;
        }
        /* Option styling for dropdowns */
        .form-select option {
            background-color: #1e293b;
            color: #f1f5f9;
            padding: 0.5rem;
        }
        /* Error messages – bright red, visible */
        .error-message {
            color: #f87171;
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.25rem;
        }
        /* Charts container */
        .chart-container {
            position: relative;
            height: 320px;
            width: 100%;
            margin-top: 1rem;
        }
        /* Scrollbar styling (retained) */
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 6px; }
        ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        /* Theme toggle button – consistent with new design */
        #theme-toggle {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            border-radius: 0.75rem;
            transition: all 0.2s;
        }
        #theme-toggle:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        /* Graph section heading */
        section h3 {
            font-size: 1.4rem;
            color: #fff;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        /* Responsive grid for filters */
        @media (max-width: 768px) {
            .grid.md\\:grid-cols-4 {
                grid-template-columns: 1fr;
            }
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
    </style>
</head>
<body class="antialiased">
    <div class="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <div class="flex flex-wrap justify-between items-center mb-8 gap-4">
                <h1 class="text-3xl md:text-4xl font-bold text-white flex items-center gap-3">
                    <i class="fas fa-chart-line text-orange-500"></i> Admin Progress Monitoring
                </h1>
                <button id="theme-toggle" class="flex items-center gap-2 text-white">
                    <i class="fas fa-moon"></i> <span>Toggle Theme</span>
                </button>
            </div>

            <!-- Filter Section -->
            <section class="glassmorphism mb-8" aria-labelledby="filter-heading">
                <h3 id="filter-heading"><i class="fas fa-sliders-h text-orange-400"></i> Filters</h3>
                <form id="filter-form" class="grid md:grid-cols-4 gap-5">
                    <div>
                        <label for="subject_name_line" class="block">Subject (Line Graph)</label>
                        <select id="subject_name_line" class="form-select" aria-describedby="subject_name_line_error">
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject['subject_name']) ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="subject_name_line_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="class_id" class="block">Class</label>
                        <select id="class_id" class="form-select" aria-describedby="class_id_error">
                            <option value="">Select Class</option>
                        </select>
                        <p id="class_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="teacher_id" class="block">Teacher</label>
                        <select id="teacher_id" class="form-select" aria-describedby="teacher_id_error">
                            <option value="0">All Teachers</option>
                        </select>
                        <p id="teacher_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="term_id" class="block">Term</label>
                        <select id="term_id" class="form-select" aria-describedby="term_id_error">
                            <option value="">Select Term</option>
                        </select>
                        <p id="term_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="entry_period" class="block">Entry Period</label>
                        <select id="entry_period" class="form-select" disabled aria-describedby="entry_period_error">
                            <option value="">Select Entry Period</option>
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-Weekly</option>
                        </select>
                        <p id="entry_period_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="period" class="block">Exact Period</label>
                        <select id="period" class="form-select" disabled aria-describedby="period_error">
                            <option value="">Select Exact Period</option>
                        </select>
                        <p id="period_error" class="error-message hidden"></p>
                    </div>
                    <input type="hidden" id="csrf_token" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                </form>
            </section>

            <!-- Graphs Section -->
            <section class="glassmorphism mb-8" aria-labelledby="graphs-heading">
                <h3 id="graphs-heading"><i class="fas fa-chart-bar text-orange-400"></i> Progress Graphs</h3>
                <div class="chart-container" id="line-graph-container">
                    <canvas id="line-chart" aria-label="Subject progress over time"></canvas>
                </div>
                <div class="chart-container hidden" id="bar-graph-container">
                    <canvas id="bar-chart" aria-label="Filtered progress"></canvas>
                </div>
                <div id="progress-message" class="text-center text-slate-300 font-medium mt-4"></div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <?php mysqli_close($conn); ob_end_flush(); ?>
    <script>
        // Theme toggle
        const themeToggle = document.getElementById('theme-toggle');
        const body = document.body;
        const icon = themeToggle.querySelector('i');
        const textSpan = themeToggle.querySelector('span');

        function setTheme(isDark) {
            if (isDark) {
                body.classList.add('dark');
                icon.className = 'fas fa-sun';
                textSpan.textContent = 'Light Mode';
                localStorage.setItem('theme', 'dark');
            } else {
                body.classList.remove('dark');
                icon.className = 'fas fa-moon';
                textSpan.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            }
        }

        // Initialize
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            setTheme(true);
        } else {
            setTheme(false); // default light
        }

        themeToggle.addEventListener('click', () => {
            setTheme(!body.classList.contains('dark'));
        });

        // Form elements
        const subjectNameLineInput = document.getElementById('subject_name_line');
        const classIdInput = document.getElementById('class_id');
        const teacherIdInput = document.getElementById('teacher_id');
        const termIdInput = document.getElementById('term_id');
        const entryPeriodInput = document.getElementById('entry_period');
        const periodInput = document.getElementById('period');
        const csrfTokenInput = document.getElementById('csrf_token');
        const lineGraphContainer = document.getElementById('line-graph-container');
        const barGraphContainer = document.getElementById('bar-graph-container');
        const progressMessage = document.getElementById('progress-message');
        let lineChart = null;
        let barChart = null;

        // Load initial classes, teachers, and terms
        Promise.all([
            fetch('?action=get_classes').then(res => res.json()),
            fetch('?action=get_teachers').then(res => res.json()),
            fetch('?action=get_terms').then(res => res.json())
        ]).then(([classesData, teachersData, termsData]) => {
            if (classesData.success) {
                classesData.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.id;
                    option.textContent = cls.class_name;
                    classIdInput.appendChild(option);
                });
            }
            if (teachersData.success) {
                teachersData.teachers.forEach(teacher => {
                    const option = document.createElement('option');
                    option.value = teacher.id;
                    option.textContent = teacher.name;
                    teacherIdInput.appendChild(option);
                });
            }
            if (termsData.success) {
                termsData.terms.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.id;
                    option.textContent = `${term.term_name} (${term.year})`;
                    termIdInput.appendChild(option);
                });
            }
            // Automatically select first subject and load line chart
            if (subjectNameLineInput.options.length > 1) {
                subjectNameLineInput.value = subjectNameLineInput.options[1].value;
                updateLineChart();
            }
        }).catch(() => showError('progress-message', 'Error loading initial data'));

        // Enable entry period on term change
        termIdInput.addEventListener('change', () => {
            hideError('term_id_error');
            entryPeriodInput.disabled = !termIdInput.value;
            periodInput.innerHTML = '<option value="">Select Exact Period</option>';
            periodInput.disabled = true;
            updateBarChart();
        });

        // Load periods on entry period change
        entryPeriodInput.addEventListener('change', () => {
            hideError('entry_period_error');
            periodInput.innerHTML = '<option value="">Select Exact Period</option>';
            periodInput.disabled = true;
            if (termIdInput.value && entryPeriodInput.value) {
                showLoading('progress-message');
                fetch(`?action=get_periods&term_id=${encodeURIComponent(termIdInput.value)}&entry_period=${encodeURIComponent(entryPeriodInput.value)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            data.periods.forEach(period => {
                                const option = document.createElement('option');
                                option.value = JSON.stringify(period);
                                option.textContent = `${period.label}: ${period.start} to ${period.end}`;
                                periodInput.appendChild(option);
                            });
                            periodInput.disabled = data.periods.length === 0;
                        } else {
                            showError('period_error', data.error || 'Failed to load periods');
                        }
                    })
                    .catch(() => showError('period_error', 'Error loading periods'))
                    .finally(() => hideLoading('progress-message'));
            }
            updateBarChart();
        });

        // Update bar chart on period or teacher change
        periodInput.addEventListener('change', () => {
            hideError('period_error');
            updateBarChart();
        });
        teacherIdInput.addEventListener('change', () => {
            hideError('teacher_id_error');
            updateBarChart();
        });

        // Update line chart on subject selection
        subjectNameLineInput.addEventListener('change', () => {
            hideError('subject_name_line_error');
            updateLineChart();
        });

        // Update line chart
        function updateLineChart() {
            const subjectName = subjectNameLineInput.value;
            if (!subjectName) {
                lineGraphContainer.classList.add('hidden');
                progressMessage.textContent = 'Select a subject to view line graph';
                if (lineChart) lineChart.destroy();
                return;
            }
            showLoading('progress-message');
            fetch(`?action=get_subject_progress&subject_name=${encodeURIComponent(subjectName)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.progress.length > 0) {
                        lineGraphContainer.classList.remove('hidden');
                        renderLineChart(data.progress);
                        progressMessage.textContent = '';
                    } else {
                        lineGraphContainer.classList.add('hidden');
                        progressMessage.textContent = 'No progress data for selected subject';
                        if (lineChart) lineChart.destroy();
                    }
                })
                .catch(() => {
                    showError('progress-message', 'Error loading line chart data');
                    lineGraphContainer.classList.add('hidden');
                })
                .finally(() => hideLoading('progress-message'));
        }

        // Render line chart
        function renderLineChart(progress) {
            const ctx = document.getElementById('line-chart').getContext('2d');
            if (lineChart) lineChart.destroy();
            const students = [...new Set(progress.map(p => p.student_name))];
            const periods = [...new Set(progress.map(p => p.period_start_date))].sort();
            const datasets = students.map((student, index) => ({
                label: student,
                data: periods.map(period => {
                    const entry = progress.find(p => p.student_name === student && p.period_start_date === period);
                    return entry ? parseFloat(entry.total_scaled) : 0;
                }),
                borderColor: getRandomColor(index),
                backgroundColor: getRandomColor(index) + '33',
                fill: false,
                tension: 0.4
            }));
            // Class average
            datasets.push({
                label: 'Class Average',
                data: periods.map(period => {
                    const entries = progress.filter(p => p.period_start_date === period);
                    const sum = entries.reduce((acc, p) => acc + parseFloat(p.total_scaled), 0);
                    return entries.length ? sum / entries.length : 0;
                }),
                borderColor: '#ffffff',
                backgroundColor: '#ffffff33',
                borderDash: [5, 5],
                fill: false
            });
            // Class highest
            datasets.push({
                label: 'Class Highest',
                data: periods.map(period => {
                    const scores = progress.filter(p => p.period_start_date === period).map(p => parseFloat(p.total_scaled));
                    return scores.length ? Math.max(...scores) : 0;
                }),
                borderColor: '#ffd700',
                backgroundColor: '#ffd70033',
                fill: false
            });
            lineChart = new Chart(ctx, {
                type: 'line',
                data: { labels: periods.map(d => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })), datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { color: '#e2e8f0' } },
                        title: { display: true, text: 'Subject Progress Over Time', color: '#ffffff', font: { size: 16 } }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100, title: { display: true, text: 'Scaled Score (%)', color: '#e2e8f0' }, ticks: { color: '#cbd5e1' } },
                        x: { title: { display: true, text: 'Period', color: '#e2e8f0' }, ticks: { color: '#cbd5e1' } }
                    }
                }
            });
        }

        // Update bar chart
        function updateBarChart() {
            const classId = classIdInput.value;
            const teacherId = teacherIdInput.value;
            const termId = termIdInput.value;
            const entryPeriod = entryPeriodInput.value;
            const periodJson = periodInput.value;
            if (!classId || !termId || !entryPeriod || !periodJson) {
                barGraphContainer.classList.add('hidden');
                if (!subjectNameLineInput.value) {
                    progressMessage.textContent = 'Select filters to view bar graph';
                }
                if (barChart) barChart.destroy();
                return;
            }
            const period = JSON.parse(periodJson);
            showLoading('progress-message');
            const formData = new FormData();
            formData.append('action', 'get_filtered_progress');
            formData.append('class_id', classId);
            formData.append('teacher_id', teacherId);
            formData.append('term_id', termId);
            formData.append('entry_period', entryPeriod);
            formData.append('period_start', period.start);
            formData.append('period_end', period.end);
            formData.append('csrf_token', csrfTokenInput.value);
            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.progress.length > 0) {
                        barGraphContainer.classList.remove('hidden');
                        renderBarChart(data.progress);
                        progressMessage.textContent = '';
                    } else {
                        barGraphContainer.classList.add('hidden');
                        progressMessage.textContent = 'No progress data for selected filters';
                        if (barChart) barChart.destroy();
                    }
                })
                .catch(() => {
                    showError('progress-message', 'Error loading bar chart data');
                    barGraphContainer.classList.add('hidden');
                })
                .finally(() => hideLoading('progress-message'));
        }

        // Render bar chart
        function renderBarChart(progress) {
            const ctx = document.getElementById('bar-chart').getContext('2d');
            if (barChart) barChart.destroy();
            const subjects = [...new Set(progress.map(p => p.subject_name))];
            const students = [...new Set(progress.map(p => p.student_name))];
            const labels = students;
            const datasets = subjects.map((subject, index) => ({
                label: subject,
                data: students.map(student => {
                    const entry = progress.find(p => p.student_name === student && p.subject_name === subject);
                    return entry ? parseFloat(entry.total_scaled) : 0;
                }),
                backgroundColor: getRandomColor(index) + '99',
                borderColor: getRandomColor(index),
                borderWidth: 1,
                barThickness: 30
            }));
            barChart = new Chart(ctx, {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { color: '#e2e8f0' } },
                        title: { 
                            display: true, 
                            text: 'Class Progress by Learner', 
                            color: '#ffffff',
                            font: { size: 16 }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100, title: { display: true, text: 'Scaled Score (%)', color: '#e2e8f0' }, ticks: { color: '#cbd5e1' } },
                        x: { title: { display: true, text: 'Learner', color: '#e2e8f0' }, ticks: { color: '#cbd5e1' } }
                    }
                }
            });
        }

        // Get random color
        function getRandomColor(index) {
            const colors = ['#f97316', '#3b82f6', '#ef4444', '#22c55e', '#a855f7', '#eab308', '#06b6d4'];
            return colors[index % colors.length];
        }

        // Loading and error handling
        function showLoading(elementId) {
            const element = document.getElementById(elementId);
            element.innerHTML = '<div class="flex justify-center"><span class="inline-block w-8 h-8 border-4 border-slate-400 border-t-orange-500 rounded-full animate-spin"></span></div>';
        }
        function hideLoading(elementId) {
            const element = document.getElementById(elementId);
            if (element.innerHTML.includes('animate-spin')) {
                element.innerHTML = '';
            }
        }
        function showError(elementId, message) {
            const element = document.getElementById(elementId);
            element.innerHTML = `<p class="error-message"><i class="fas fa-exclamation-circle mr-1"></i>${message}</p>`;
            element.focus();
        }
        function hideError(elementId) {
            const element = document.getElementById(elementId);
            element.classList.add('hidden');
            element.innerHTML = '';
        }
    </script>
</body>
</html>