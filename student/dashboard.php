<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Verify user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: /login.php?error=unauthorized");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Helper function to get current academic year and term based on current date
function getCurrentAcademicPeriod($conn) {
    $current_date = date('Y-m-d');
    
    // Find the academic calendar entry where current date falls between start_date and end_date
    $stmt = $conn->prepare("
        SELECT id, year, term_name, start_date, end_date 
        FROM academic_calendar 
        WHERE start_date <= ? AND end_date >= ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $current_date, $current_date);
    $stmt->execute();
    $current_period = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($current_period) {
        return [
            'academic_year' => $current_period['year'],
            'term_name' => $current_period['term_name'],
            'term_id' => $current_period['id'],
            'start_date' => $current_period['start_date'],
            'end_date' => $current_period['end_date']
        ];
    }
    
    // If no current period found, get the most recent or upcoming period
    $stmt = $conn->prepare("
        SELECT id, year, term_name, start_date, end_date 
        FROM academic_calendar 
        ORDER BY start_date DESC
        LIMIT 1
    ");
    $stmt->execute();
    $latest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($latest) {
        return [
            'academic_year' => $latest['year'],
            'term_name' => $latest['term_name'],
            'term_id' => $latest['id'],
            'start_date' => $latest['start_date'],
            'end_date' => $latest['end_date']
        ];
    }
    
    // Fallback values
    return [
        'academic_year' => '2025/2026',
        'term_name' => 'Term 1',
        'term_id' => null,
        'start_date' => null,
        'end_date' => null
    ];
}

// Get current academic period
$current_period = getCurrentAcademicPeriod($conn);
$academic_year = $current_period['academic_year'];
$current_term_name = $current_period['term_name'];
$current_term_id = $current_period['term_id'];

// Also get all available academic years from academic_calendar for the dropdown
$stmt = $conn->prepare("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
$stmt->execute();
$academic_years = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle AJAX requests
$ajax_action = $_POST['ajax_action'] ?? '';
if ($ajax_action) {
    header('Content-Type: application/json');
    $csrf_token = $_POST['csrf_token'] ?? '';
    // Validate CSRF token
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $csrf_token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $stmt->close();

    // Helper function for Gemini API
    function callGemini($prompt) {
        $apiKey = $apiKey = getenv('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ]
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return $result['candidates'][0]['content']['parts'][0]['text'] ?? 'No feedback generated.';
        } else {
            return "AI service temporarily unavailable. Please try again later.";
        }
    }

    if ($ajax_action === 'get_assignment') {
        $assignment_id = (int)$_POST['assignment_id'];
        $stmt = $conn->prepare("
            SELECT a.id, a.title, a.description, a.due_date, a.max_points, s.subject_name, a.type, a.is_group_assignment
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            WHERE a.id = ? AND a.status = 'published'
        ");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$assignment) {
            echo json_encode(['success' => false, 'error' => 'Assignment not found']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, question_text, question_number FROM assignment_questions WHERE assignment_id = ? ORDER BY question_number");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['success' => true, 'assignment' => $assignment, 'questions' => $questions]);
        exit;
    }

    if ($ajax_action === 'get_feedback') {
        $question_text = $_POST['question_text'] ?? '';
        $student_answer = $_POST['student_answer'] ?? '';
        if (empty($question_text) || empty($student_answer)) {
            echo json_encode(['success' => false, 'error' => 'Question and answer required']);
            exit;
        }
        $prompt = "You are a helpful teacher. Provide constructive feedback on the student's answer to the question:\n\nQuestion: $question_text\n\nStudent's Answer: $student_answer\n\nFeedback:";
        $feedback = callGemini($prompt);
        echo json_encode(['success' => true, 'feedback' => $feedback]);
        exit;
    }

    if ($ajax_action === 'submit_assignment') {
        $assignment_id = (int)$_POST['assignment_id'];
        $answers_json = $_POST['answers'] ?? '{}';
        $overall_feedback = $_POST['overall_feedback'] ?? '';
        $answers = json_decode($answers_json, true);
        if (!is_array($answers)) {
            echo json_encode(['success' => false, 'error' => 'Invalid answers format']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $assignment_id, $user_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $submission_date = date('Y-m-d H:i:s');
        if ($existing) {
            $stmt = $conn->prepare("UPDATE assignment_submissions SET answers = ?, feedback = ?, submission_date = ?, status = 'submitted' WHERE id = ?");
            $stmt->bind_param("sssi", $answers_json, $overall_feedback, $submission_date, $existing['id']);
        } else {
            $stmt = $conn->prepare("INSERT INTO assignment_submissions (assignment_id, user_id, answers, feedback, submission_date, status) VALUES (?, ?, ?, ?, ?, 'submitted')");
            $stmt->bind_param("iisss", $assignment_id, $user_id, $answers_json, $overall_feedback, $submission_date);
        }
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Assignment submitted successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to submit assignment']);
        }
        exit;
    }

    if ($ajax_action === 'get_quiz_question') {
        $quiz_id = (int)$_POST['quiz_id'];
        $question_index = (int)$_POST['question_index'];
        
        $stmt = $conn->prepare("
            SELECT id, title, question, question_type, options, created_at
            FROM quizzes 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        $quiz = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$quiz) {
            echo json_encode(['success' => false, 'error' => 'Quiz not found']);
            exit;
        }
        
        $questions = json_decode($quiz['question'], true);
        $options = json_decode($quiz['options'], true);
        
        if (!$questions || !isset($questions[$question_index])) {
            echo json_encode(['success' => false, 'error' => 'Question not found']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'quiz_title' => $quiz['title'],
            'question' => $questions[$question_index],
            'question_type' => $quiz['question_type'],
            'options' => $options[$question_index] ?? [],
            'total_questions' => count($questions),
            'current_index' => $question_index
        ]);
        exit;
    }
}

// Fetch student details
$stmt = $conn->prepare("SELECT name, profile_picture, email, phone, status FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: ['name' => 'Student', 'profile_picture' => null, 'email' => '', 'phone' => '', 'status' => 'active'];
$stmt->close();

// Check if student is active
if ($user['status'] !== 'active') {
    echo "<div class='min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-900 to-orange-900'>
            <div class='text-center p-8 glass-card'>
                <i class='fas fa-ban text-6xl text-red-500 mb-4'></i>
                <h1 class='text-2xl font-bold mb-2'>Account Inactive</h1>
                <p class='text-gray-300'>Your account is currently inactive. Please contact the administrator.</p>
            </div>
          </div>";
    exit;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 5;

// Fetch student classes and department(s)
$stmt = $conn->prepare("
    SELECT c.id, c.class_name, c.department_id, d.name as department_name
    FROM student_classes sc
    JOIN classes c ON sc.class_id = c.id
    LEFT JOIN departments d ON c.department_id = d.id
    WHERE sc.user_id = ? AND sc.academic_year = ?
");
$stmt->bind_param("is", $user_id, $academic_year);
$stmt->execute();
$classes_result = $stmt->get_result();
$student_classes = [];
$class_ids = [];
$department_ids = [];
while ($row = $classes_result->fetch_assoc()) {
    $student_classes[] = $row['class_name'];
    $class_ids[] = $row['id'];
    $department_ids[] = $row['department_id'];
}
$stmt->close();
$student_class_display = !empty($student_classes) ? implode(', ', $student_classes) : 'No class assigned';
$class_ids_str = empty($class_ids) ? '0' : implode(',', $class_ids);
$department_ids_str = empty($department_ids) ? '0' : implode(',', array_unique($department_ids));

// Cache metrics in session (5 minutes)
if (!isset($_SESSION['metrics']) || $_SESSION['metrics']['timestamp'] < time() - 300 || $_SESSION['metrics']['academic_year'] !== $academic_year) {
    $metrics = [
        'assignments'      => 0,
        'new_assignments'  => 0,
        'attendance'       => 0,
        'avg_grade'        => 0,
        'badges'           => 0,
        'library_loans'    => 0,
        'clubs'            => 0,
        'quizzes'          => 0,
        'group_projects'   => 0,
    ];

    // Total Assignments
    if (!empty($class_ids)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments a WHERE a.class_id IN ($class_ids_str) AND a.status = 'published'");
        $stmt->execute();
        $metrics['assignments'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();

        // New Assignments (due within 7 days)
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments a WHERE a.class_id IN ($class_ids_str) AND a.status = 'published' AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $metrics['new_assignments'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
        
        // Active Quizzes
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM quizzes q WHERE q.class_id IN ($class_ids_str) AND q.status = 'active'");
        $stmt->execute();
        $metrics['quizzes'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
        
        // Group Projects
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM groups g WHERE g.class_id IN ($class_ids_str) AND g.status = 'active'");
        $stmt->execute();
        $metrics['group_projects'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }

    // Attendance
    if (!empty($class_ids)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present FROM attendance WHERE user_id = ? AND class_id IN ($class_ids_str)");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $attendance = $stmt->get_result()->fetch_assoc();
        $metrics['attendance'] = ($attendance['total'] > 0) ? round(($attendance['present'] / $attendance['total']) * 100, 1) : 0;
        $stmt->close();
    }

    // Average Grade from grades table using current term
    if (!empty($class_ids) && $current_term_id) {
        $stmt = $conn->prepare("
            SELECT AVG(g.total) as avg_grade
            FROM grades g
            WHERE g.user_id = ?
              AND g.class_id IN ($class_ids_str)
              AND g.term_id = ?
              AND g.total IS NOT NULL
        ");
        $stmt->bind_param("ii", $user_id, $current_term_id);
        $stmt->execute();
        $avg_grade_result = $stmt->get_result()->fetch_assoc()['avg_grade'];
        $metrics['avg_grade'] = $avg_grade_result !== null ? round($avg_grade_result, 1) : 0;
        $stmt->close();
    }

    // Badges from student_progress
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT badge_id) as count FROM student_progress WHERE user_id = ? AND badge_id IS NOT NULL");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $metrics['badges'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Library Loans (active)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM library_loans WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $metrics['library_loans'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Clubs
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM club_memberships WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $metrics['clubs'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $_SESSION['metrics'] = ['data' => $metrics, 'timestamp' => time(), 'academic_year' => $academic_year];
} else {
    $metrics = $_SESSION['metrics']['data'];
}

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Log activity
logActivity($conn, $user_id, "Accessed Student Dashboard", "Student accessed dashboard for academic year $academic_year, term $current_term_name");

// Get inspirational quote
function getRandomQuote($conn) {
    $fallbacks = [
        "Keep pushing forward! Your hard work will pay off!",
        "Every question is a step to success!",
        "Education is the most powerful weapon you can use to change the world.",
        "The future belongs to those who believe in the beauty of their dreams.",
        "Success is not final, failure is not fatal: it is the courage to continue that counts."
    ];
    return $fallbacks[array_rand($fallbacks)];
}
$quote = getRandomQuote($conn);

// Fetch upcoming exams
$upcoming_exams = [];
if (!empty($class_ids) && $current_term_id) {
    $stmt = $conn->prepare("
        SELECT es.*, s.subject_name 
        FROM exam_schedules es
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.class_id IN ($class_ids_str) 
          AND es.term_id = ?
          AND es.exam_date >= CURDATE()
        ORDER BY es.exam_date ASC
        LIMIT 5
    ");
    $stmt->bind_param("i", $current_term_id);
    $stmt->execute();
    $upcoming_exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch recent feedback
$recent_feedback = [];
$stmt = $conn->prepare("
    SELECT sf.*, c.class_name 
    FROM student_feedback sf
    LEFT JOIN classes c ON sf.class_id = c.id
    WHERE sf.user_id = ?
    ORDER BY sf.created_at DESC
    LIMIT 3
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_feedback = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Student Dashboard - QueensPa School</title>
    <!-- Tailwind + Fonts + Icons + Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-confetti@0.12.0/dist/js-confetti.browser.js"></script>
    <style>
        :root {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --primary-light: #ffedd5;
        }
        body {
            font-family: 'Inter', sans-serif;
            transition: background-color 0.3s ease, color 0.2s ease;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .dark .glass-card {
            background: rgba(0, 0, 0, 0.25);
            border-color: rgba(255, 255, 255, 0.08);
        }
        .glass-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.2);
        }
        .btn-primary {
            background: linear-gradient(135deg, #f97316, #ea580c);
            transition: all 0.2s;
        }
        .btn-primary:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 20px rgba(249, 115, 22, 0.4);
        }
        .profile-pic {
            border: 2px solid #f97316;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .profile-pic:hover {
            transform: scale(1.05);
            box-shadow: 0 0 15px rgba(249, 115, 22, 0.5);
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 10px; }
        .animate-pulse-slow {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        .progress-ring {
            transition: stroke-dashoffset 0.5s ease;
        }
        .current-term-badge {
            background: linear-gradient(135deg, #f97316, #ea580c);
        }
    </style>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        };
    </script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-gray-800 to-orange-900 text-gray-200 transition-colors">

    <!-- Header / Navbar -->
    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    if (file_exists($header_path)) include $header_path;
    else echo '<div class="bg-red-500/80 p-4 text-center">Header missing</div>';
    ?>

    <main class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Welcome Section with Current Term Badge -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 mb-12 fade-in">
            <div class="flex items-center gap-5">
                <img src="<?= htmlspecialchars($user['profile_picture'] ?: '/images/default-avatar.png') ?>" 
                     alt="Profile" class="profile-pic w-20 h-20 rounded-full object-cover shadow-lg">
                <div>
                    <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight">Welcome, <?= htmlspecialchars($user['name']) ?>!</h1>
                    <div class="flex flex-wrap items-center gap-3 mt-2">
                        <p class="text-orange-400 font-medium"><?= htmlspecialchars($student_class_display) ?></p>
                        <span class="current-term-badge px-3 py-1 rounded-full text-xs font-semibold text-white">
                            <i class="fas fa-calendar-alt mr-1"></i> <?= htmlspecialchars($academic_year) ?> | <?= htmlspecialchars($current_term_name) ?>
                        </span>
                    </div>
                    <p class="text-sm text-gray-300 mt-2 italic">✨ “<?= htmlspecialchars($quote) ?>”</p>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <button id="darkModeToggle" class="bg-white/10 backdrop-blur-sm p-2 rounded-full hover:bg-white/20 transition">
                    <i class="fas fa-moon text-orange-400"></i>
                </button>
            </div>
        </div>

        <!-- Key Metrics Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-5 mb-12 fade-in">
            <?php
            $metric_items = [
                ['icon' => 'fa-tasks', 'label' => 'Assignments', 'value' => $metrics['assignments'], 'color' => 'text-blue-400'],
                ['icon' => 'fa-clock', 'label' => 'Due Soon', 'value' => $metrics['new_assignments'], 'color' => 'text-yellow-400'],
                ['icon' => 'fa-calendar-check', 'label' => 'Attendance', 'value' => $metrics['attendance'] . '%', 'color' => 'text-green-400'],
                ['icon' => 'fa-chart-line', 'label' => 'Avg Grade', 'value' => $metrics['avg_grade'] . '%', 'color' => 'text-purple-400'],
                ['icon' => 'fa-medal', 'label' => 'Badges', 'value' => $metrics['badges'], 'color' => 'text-yellow-500'],
                ['icon' => 'fa-book', 'label' => 'Library Loans', 'value' => $metrics['library_loans'], 'color' => 'text-indigo-400'],
                ['icon' => 'fa-users', 'label' => 'Clubs', 'value' => $metrics['clubs'], 'color' => 'text-pink-400'],
                ['icon' => 'fa-puzzle-piece', 'label' => 'Quizzes', 'value' => $metrics['quizzes'], 'color' => 'text-cyan-400'],
                ['icon' => 'fa-users-between', 'label' => 'Group Projects', 'value' => $metrics['group_projects'], 'color' => 'text-emerald-400'],
            ];
            foreach ($metric_items as $item): ?>
                <div class="glass-card p-5 text-center hover:scale-[1.02] transition-all cursor-default group">
                    <i class="fas <?= $item['icon'] ?> text-3xl <?= $item['color'] ?> mb-2 group-hover:scale-110 transition-transform"></i>
                    <h3 class="text-sm uppercase tracking-wider font-semibold text-gray-300"><?= $item['label'] ?></h3>
                    <p class="text-2xl font-bold mt-1"><?= $item['value'] ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Two columns: Calendar + Subject Performance -->
        <div class="grid lg:grid-cols-2 gap-8 mb-12">
            <div class="glass-card p-5">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2"><i class="fas fa-calendar-alt text-orange-400"></i> Your Schedule</h2>
                <div id="calendar" class="min-h-[400px]"></div>
            </div>
            <div class="glass-card p-5">
                <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                    <h2 class="text-xl font-bold flex items-center gap-2"><i class="fas fa-chart-pie text-orange-400"></i> Subject Performance</h2>
                    <select id="termFilter" class="bg-white/10 border border-white/20 rounded-lg px-3 py-1 text-sm">
                        <option value="current" selected>Current Term (<?= htmlspecialchars($current_term_name) ?>)</option>
                        <option value="all">All Terms</option>
                        <?php
                        // Get all terms from academic_calendar for the current academic year
                        $stmt = $conn->prepare("SELECT id, term_name FROM academic_calendar WHERE year = ? ORDER BY start_date");
                        $stmt->bind_param("s", $academic_year);
                        $stmt->execute();
                        $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        foreach ($terms as $term): ?>
                            <option value="<?= $term['id'] ?>" <?= ($term['term_name'] === $current_term_name) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($term['term_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <canvas id="gradesChart" class="w-full h-72"></canvas>
            </div>
        </div>

        <!-- Upcoming Exams Section -->
        <?php if (!empty($upcoming_exams)): ?>
        <div class="glass-card p-5 mb-12">
            <h2 class="text-xl font-bold mb-4 flex items-center gap-2"><i class="fas fa-calendar-week text-orange-400"></i> Upcoming Exams (<?= htmlspecialchars($current_term_name) ?>)</h2>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($upcoming_exams as $exam): ?>
                <div class="bg-white/5 rounded-lg p-4 border-l-4 border-orange-500">
                    <h3 class="font-semibold text-lg"><?= htmlspecialchars($exam['subject_name']) ?></h3>
                    <p class="text-sm text-gray-400 mt-1">
                        <i class="fas fa-calendar-day mr-1"></i> <?= date('M d, Y', strtotime($exam['exam_date'])) ?>
                        <?php if ($exam['start_time']): ?>
                            <br><i class="fas fa-clock mr-1"></i> <?= date('h:i A', strtotime($exam['start_time'])) ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($exam['room']): ?>
                        <p class="text-sm mt-1"><i class="fas fa-door-open mr-1"></i> Room: <?= htmlspecialchars($exam['room']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Assignments Table -->
        <div class="glass-card p-5 mb-12">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
                <h2 class="text-xl font-bold flex items-center gap-2"><i class="fas fa-exclamation-circle text-orange-400"></i> New Assignments (Due This Week)</h2>
                <div class="flex gap-3 w-full md:w-auto">
                    <input type="text" id="newAssignmentSearch" placeholder="Search..." class="bg-white/10 border border-white/20 rounded-lg px-3 py-1.5 text-sm flex-1">
                    <select id="newAssignmentFilter" class="bg-white/10 border border-white/20 rounded-lg px-3 py-1.5 text-sm">
                        <option value="all">All</option>
                        <option value="pending">Pending</option>
                        <option value="submitted">Submitted</option>
                        <option value="graded">Graded</option>
                    </select>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="newAssignmentTable">
                    <thead class="border-b border-white/10">
                        <tr class="text-left text-gray-300">
                            <th class="pb-2">Title</th><th>Subject</th><th>Due Date</th><th>Type</th><th>Progress</th><th class="text-right">Action</th>
                         </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (!empty($class_ids)) {
                            $stmt = $conn->prepare("
                                SELECT a.id, a.title, s.subject_name, a.due_date, a.max_points, a.type,
                                       asub.status, asub.score, asub.submission_date
                                FROM assignments a
                                JOIN subjects s ON a.subject_id = s.id
                                LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.user_id = ?
                                WHERE a.class_id IN ($class_ids_str)
                                  AND a.status = 'published'
                                  AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                                ORDER BY a.due_date ASC
                                LIMIT ? OFFSET ?
                            ");
                            $offset = ($page - 1) * $per_page;
                            $stmt->bind_param("iii", $user_id, $per_page, $offset);
                            $stmt->execute();
                            $new_assignments = $stmt->get_result();
                            while ($row = $new_assignments->fetch_assoc()):
                                $status = $row['status'] ?? 'pending';
                                $score = (float)($row['score'] ?? 0);
                                $max = (float)$row['max_points'];
                                $progress = ($max > 0) ? ($score / $max) * 100 : 0;
                                $progress_width = min(100, $progress);
                                $progress_color = $status === 'graded' ? 'bg-green-500' : ($status === 'submitted' ? 'bg-blue-500' : 'bg-orange-500');
                                $type_icon = [
                                    'quiz' => 'fa-puzzle-piece',
                                    'test' => 'fa-file-alt',
                                    'homework' => 'fa-home',
                                    'classwork' => 'fa-chalkboard',
                                    'group_work' => 'fa-users'
                                ][$row['type']] ?? 'fa-tasks';
                                ?>
                                <tr class="border-b border-white/5 hover:bg-white/5 transition fade-in">
                                    <td class="py-3 font-medium"><?= htmlspecialchars($row['title']) ?></td>
                                    <td><?= htmlspecialchars($row['subject_name']) ?></td>
                                    <td><?= date('M d, Y', strtotime($row['due_date'])) ?> 
                                        <?php if (strtotime($row['due_date']) < time() && $status === 'pending'): ?>
                                            <span class="ml-1 text-xs text-red-400">(Late)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><i class="fas <?= $type_icon ?> text-orange-400 mr-1"></i> <?= ucfirst($row['type']) ?></td>
                                    <td class="w-1/5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs capitalize"><?= $status ?></span>
                                            <div class="flex-1 h-1.5 bg-gray-600 rounded-full overflow-hidden">
                                                <div class="<?= $progress_color ?> h-full rounded-full transition-all" style="width: <?= $progress_width ?>%"></div>
                                            </div>
                                            <?php if ($status === 'graded'): ?>
                                                <span class="text-xs"><?= $score ?>/<?= $max ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($status === 'pending'): ?>
                                            <button class="text-orange-400 hover:text-orange-300 text-sm font-medium take-assignment-btn transition-all hover:scale-105" data-id="<?= $row['id'] ?>">
                                                Take Assignment →
                                            </button>
                                        <?php elseif ($status === 'submitted'): ?>
                                            <span class="text-blue-400 text-sm"><i class="fas fa-clock mr-1"></i> Awaiting Grading</span>
                                        <?php else: ?>
                                            <span class="text-green-400 text-sm"><i class="fas fa-check-circle mr-1"></i> Graded</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; $stmt->close();
                        } else { echo '<tr><td colspan="6" class="py-6 text-center text-gray-400">No classes assigned yet.</td></tr>'; } ?>
                    </tbody>
                </table>
            </div>
            <?php
            $total_new = 0;
            if (!empty($class_ids)) {
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM assignments a WHERE a.class_id IN ($class_ids_str) AND a.status = 'published' AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)");
                $stmt->execute();
                $total_new = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
                $stmt->close();
            }
            $pages = ceil($total_new / $per_page);
            if ($pages > 1): ?>
                <div class="flex justify-center gap-2 mt-5">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <a href="?page=<?= $i ?>" class="px-3 py-1 rounded-lg transition-all <?= $i == $page ? 'bg-orange-500 text-white' : 'bg-white/10 hover:bg-white/20' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Group Projects Section -->
        <div class="grid lg:grid-cols-2 gap-8 mb-12">
            <div class="glass-card p-5">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2"><i class="fas fa-users-between text-orange-400"></i> Active Group Projects</h2>
                <?php
                if (!empty($class_ids)) {
                    $stmt = $conn->prepare("
                        SELECT g.id, g.project_title, g.description, g.deadline, g.max_points,
                               gm.role, COUNT(gm2.id) as member_count
                        FROM groups g
                        JOIN group_members gm ON g.id = gm.group_id AND gm.user_id = ?
                        LEFT JOIN group_members gm2 ON g.id = gm2.group_id AND gm2.status = 'active'
                        WHERE g.class_id IN ($class_ids_str) AND g.status = 'active'
                        GROUP BY g.id
                        ORDER BY g.deadline ASC
                        LIMIT 5
                    ");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $group_projects = $stmt->get_result();
                    
                    if ($group_projects->num_rows > 0):
                        while ($project = $group_projects->fetch_assoc()):
                ?>
                    <div class="bg-white/5 rounded-lg p-4 mb-3 hover:bg-white/10 transition">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="font-semibold text-lg"><?= htmlspecialchars($project['project_title']) ?></h3>
                                <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(substr($project['description'], 0, 100)) ?>...</p>
                                <div class="flex gap-4 mt-2 text-sm">
                                    <span><i class="fas fa-calendar-alt mr-1 text-orange-400"></i> Due: <?= date('M d, Y', strtotime($project['deadline'])) ?></span>
                                    <span><i class="fas fa-users mr-1 text-orange-400"></i> <?= $project['member_count'] ?> members</span>
                                    <span><i class="fas fa-star mr-1 text-yellow-500"></i> Max: <?= $project['max_points'] ?> pts</span>
                                </div>
                            </div>
                            <span class="text-xs px-2 py-1 rounded-full <?= $project['role'] === 'leader' ? 'bg-orange-500/20 text-orange-400' : 'bg-blue-500/20 text-blue-400' ?>">
                                <?= ucfirst($project['role']) ?>
                            </span>
                        </div>
                    </div>
                <?php 
                        endwhile;
                    else:
                        echo '<p class="text-gray-400 text-center py-4">No active group projects assigned.</p>';
                    endif;
                    $stmt->close();
                } else {
                    echo '<p class="text-gray-400 text-center py-4">No classes assigned yet.</p>';
                }
                ?>
            </div>

            <!-- My Clubs Section -->
            <div class="glass-card p-5">
                <h2 class="text-xl font-bold mb-4 flex items-center gap-2"><i class="fas fa-users text-orange-400"></i> My Clubs & Activities</h2>
                <?php
                $stmt = $conn->prepare("
                    SELECT c.id, c.name, c.description, cm.role, cm.joined_at
                    FROM club_memberships cm
                    JOIN clubs c ON cm.club_id = c.id
                    WHERE cm.user_id = ? AND cm.status = 'active'
                    ORDER BY cm.joined_at DESC
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $my_clubs = $stmt->get_result();
                
                if ($my_clubs->num_rows > 0):
                    while ($club = $my_clubs->fetch_assoc()):
                ?>
                    <div class="bg-white/5 rounded-lg p-4 mb-3 hover:bg-white/10 transition">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="font-semibold text-lg"><?= htmlspecialchars($club['name']) ?></h3>
                                <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(substr($club['description'], 0, 100)) ?></p>
                                <p class="text-xs text-gray-500 mt-2">Joined: <?= date('M d, Y', strtotime($club['joined_at'])) ?></p>
                            </div>
                            <span class="text-xs px-2 py-1 rounded-full bg-orange-500/20 text-orange-400">
                                <?= ucfirst($club['role']) ?>
                            </span>
                        </div>
                    </div>
                <?php 
                    endwhile;
                else:
                    echo '<p class="text-gray-400 text-center py-4">You haven\'t joined any clubs yet.</p>';
                endif;
                $stmt->close();
                ?>
            </div>
        </div>

        <!-- Recent Feedback Section -->
        <?php if (!empty($recent_feedback)): ?>
        <div class="glass-card p-5 mb-12">
            <h2 class="text-xl font-bold mb-4 flex items-center gap-2"><i class="fas fa-comment-dots text-orange-400"></i> Recent Feedback</h2>
            <div class="space-y-3">
                <?php foreach ($recent_feedback as $feedback): ?>
                <div class="bg-white/5 rounded-lg p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm"><?= htmlspecialchars($feedback['feedback']) ?></p>
                            <div class="flex gap-3 mt-2 text-xs text-gray-400">
                                <span><i class="fas fa-school mr-1"></i> <?= htmlspecialchars($feedback['class_name'] ?? 'General') ?></span>
                                <span><i class="fas fa-star mr-1 text-yellow-500"></i> Rating: <?= str_repeat('★', (int)$feedback['rating']) ?><?= str_repeat('☆', 5 - (int)$feedback['rating']) ?></span>
                                <span><i class="fas fa-clock mr-1"></i> <?= date('M d, Y', strtotime($feedback['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Learning Resources Section -->
        <div class="glass-card p-5">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold flex items-center gap-2"><i class="fas fa-book-open text-orange-400"></i> Learning Resources</h2>
                <a href="/student/resources.php" class="text-orange-400 hover:text-orange-300 text-sm">View All →</a>
            </div>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                $stmt = $conn->prepare("
                    SELECT lr.id, lr.title, lr.description, lr.resource_type, lr.views_count, lr.rating
                    FROM learning_resources lr
                    WHERE (lr.class_id IN ($class_ids_str) OR lr.access_level = 'public')
                      AND lr.is_approved = 1
                    ORDER BY lr.created_at DESC
                    LIMIT 6
                ");
                $stmt->execute();
                $resources = $stmt->get_result();
                
                if ($resources->num_rows > 0):
                    while ($resource = $resources->fetch_assoc()):
                        $type_icons = [
                            'document' => 'fa-file-alt',
                            'video' => 'fa-video',
                            'audio' => 'fa-headphones',
                            'link' => 'fa-link',
                            'interactive' => 'fa-gamepad'
                        ];
                        $icon = $type_icons[$resource['resource_type']] ?? 'fa-book';
                ?>
                    <div class="bg-white/5 rounded-lg p-4 hover:bg-white/10 transition group">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-full bg-orange-500/20 flex items-center justify-center">
                                <i class="fas <?= $icon ?> text-orange-400"></i>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-semibold group-hover:text-orange-400 transition"><?= htmlspecialchars($resource['title']) ?></h3>
                                <p class="text-xs text-gray-400 mt-1 line-clamp-2"><?= htmlspecialchars(substr($resource['description'], 0, 80)) ?>...</p>
                                <div class="flex gap-3 mt-2 text-xs text-gray-500">
                                    <span><i class="fas fa-eye mr-1"></i> <?= $resource['views_count'] ?></span>
                                    <?php if ($resource['rating'] > 0): ?>
                                        <span><i class="fas fa-star mr-1 text-yellow-500"></i> <?= number_format($resource['rating'], 1) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php 
                    endwhile;
                else:
                    echo '<p class="text-gray-400 text-center py-8 col-span-3">No learning resources available yet.</p>';
                endif;
                $stmt->close();
                ?>
            </div>
        </div>
    </main>

    <!-- Assignment Modal -->
    <div id="assignmentModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center z-50 hidden overflow-y-auto">
        <div class="glass-card w-full max-w-4xl p-6 relative max-h-[90vh] overflow-y-auto">
            <button class="absolute top-3 right-3 text-gray-400 hover:text-white transition" id="closeModalBtn"><i class="fas fa-times text-xl"></i></button>
            <div id="modalContent">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-3xl text-orange-400"></i>
                    <p class="mt-2">Loading assignment...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Quiz Modal -->
    <div id="quizModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center z-50 hidden overflow-y-auto">
        <div class="glass-card w-full max-w-2xl p-6 relative max-h-[90vh] overflow-y-auto">
            <button class="absolute top-3 right-3 text-gray-400 hover:text-white transition" id="closeQuizModalBtn"><i class="fas fa-times text-xl"></i></button>
            <div id="quizModalContent">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-3xl text-orange-400"></i>
                    <p class="mt-2">Loading quiz...</p>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" id="currentAcademicYear" value="<?= htmlspecialchars($academic_year) ?>">
    <input type="hidden" id="currentTermId" value="<?= htmlspecialchars($current_term_id) ?>">
    
    <script>
        // Global variables
        const confetti = new JSConfetti();
        const userId = <?= $user_id ?>;
        const csrfToken = document.getElementById('csrfToken').value;
        const currentAcademicYear = document.getElementById('currentAcademicYear').value;
        const currentTermId = document.getElementById('currentTermId').value;

        // Dark mode toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        const htmlElement = document.documentElement;
        if (localStorage.getItem('darkMode') === 'true') htmlElement.classList.add('dark');
        darkModeToggle.addEventListener('click', () => {
            htmlElement.classList.toggle('dark');
            localStorage.setItem('darkMode', htmlElement.classList.contains('dark'));
            darkModeToggle.innerHTML = htmlElement.classList.contains('dark') ? '<i class="fas fa-sun text-orange-400"></i>' : '<i class="fas fa-moon text-orange-400"></i>';
        });

        // FullCalendar
        const calendarEl = document.getElementById('calendar');
        if (calendarEl) {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: window.innerWidth < 640 ? 'listWeek' : 'dayGridMonth',
                events: `/api/events.php?user_id=${userId}&academic_year=${encodeURIComponent(currentAcademicYear)}`,
                eventColor: '#f97316',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listWeek'
                },
                buttonText: {
                    today: 'Today',
                    month: 'Month',
                    listWeek: 'Week'
                }
            });
            calendar.render();
        }

        // Subject Performance Chart
        let gradesChart = null;
        function loadGradesChart(termId = 'current') {
            let termParam = termId;
            if (termId === 'current') termParam = currentTermId;
            
            fetch(`/api/grades.php?user_id=${userId}&academic_year=${encodeURIComponent(currentAcademicYear)}&term_id=${termParam}&department_ids=<?= urlencode($department_ids_str) ?>`)
                .then(res => res.json())
                .then(data => {
                    const ctx = document.getElementById('gradesChart').getContext('2d');
                    if (gradesChart) gradesChart.destroy();
                    gradesChart = new Chart(ctx, {
                        type: 'radar',
                        data: {
                            labels: data.subjects || [],
                            datasets: [{
                                label: 'Your Score (%)',
                                data: data.grades || [],
                                backgroundColor: 'rgba(249, 115, 22, 0.2)',
                                borderColor: '#f97316',
                                borderWidth: 2,
                                pointBackgroundColor: '#f97316',
                                pointBorderColor: '#fff',
                                pointHoverBackgroundColor: '#fff',
                                pointHoverBorderColor: '#f97316'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            scales: { 
                                r: { 
                                    beginAtZero: true, 
                                    max: 100,
                                    ticks: { color: '#e2e8f0' },
                                    grid: { color: 'rgba(255,255,255,0.1)' }
                                } 
                            },
                            plugins: { 
                                legend: { 
                                    labels: { color: '#e2e8f0' } 
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return `${context.dataset.label}: ${context.raw}%`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                })
                .catch(error => console.error('Error loading grades:', error));
        }
        loadGradesChart('current');
        
        const termFilter = document.getElementById('termFilter');
        if (termFilter) {
            termFilter.addEventListener('change', e => loadGradesChart(e.target.value));
        }

        // Assignment modal handling
        const modal = document.getElementById('assignmentModal');
        const modalContent = document.getElementById('modalContent');
        let currentAssignmentId = null;

        async function loadAssignment(assignmentId) {
            modalContent.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-3xl text-orange-400"></i><p class="mt-2">Loading assignment...</p></div>';
            modal.classList.remove('hidden');
            const formData = new FormData();
            formData.append('ajax_action', 'get_assignment');
            formData.append('assignment_id', assignmentId);
            formData.append('csrf_token', csrfToken);
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            if (!data.success) {
                modalContent.innerHTML = `<div class="text-center py-8 text-red-400"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error: ${data.error}</p></div>`;
                return;
            }
            currentAssignmentId = assignmentId;
            const assignment = data.assignment;
            const questions = data.questions;
            let html = `
                <h3 class="text-2xl font-bold mb-2">${escapeHtml(assignment.title)}</h3>
                <div class="grid grid-cols-2 gap-2 mb-4 text-sm">
                    <p><strong><i class="fas fa-book mr-1"></i> Subject:</strong> ${escapeHtml(assignment.subject_name)}</p>
                    <p><strong><i class="fas fa-calendar-alt mr-1"></i> Due:</strong> ${new Date(assignment.due_date).toLocaleDateString()}</p>
                    <p><strong><i class="fas fa-star mr-1"></i> Max Points:</strong> ${assignment.max_points}</p>
                    <p><strong><i class="fas fa-tag mr-1"></i> Type:</strong> ${assignment.type}</p>
                </div>
                <div class="bg-white/5 rounded-lg p-3 mb-4">
                    <p class="text-gray-300">${escapeHtml(assignment.description || 'No description provided.')}</p>
                </div>
                <hr class="my-4 border-white/20">
                <form id="assignmentForm">
            `;
            if (questions.length === 0) {
                html += `
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-2">Your Answer:</label>
                        <textarea name="answers[0]" rows="6" class="w-full bg-white/10 border border-white/20 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-orange-500" placeholder="Type your answer here..." required></textarea>
                    </div>
                    <button type="button" id="getFeedbackBtn" class="bg-orange-600 hover:bg-orange-700 px-4 py-2 rounded-lg mb-4 transition">
                        <i class="fas fa-robot mr-2"></i>Get AI Feedback
                    </button>
                    <div id="feedbackDisplay" class="mb-4 p-3 bg-gray-800/50 rounded-lg hidden"></div>
                `;
            } else {
                for (let q of questions) {
                    html += `
                        <div class="mb-5 border-b border-white/10 pb-4">
                            <label class="block text-sm font-medium mb-2">Question ${q.question_number}: ${escapeHtml(q.question_text)}</label>
                            <textarea name="answers[${q.id}]" rows="3" class="question-answer w-full bg-white/10 border border-white/20 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-orange-500" data-question-id="${q.id}" data-question-text="${escapeHtml(q.question_text)}" placeholder="Type your answer here..." required></textarea>
                            <button type="button" class="feedback-individual-btn text-orange-400 text-sm mt-2 hover:text-orange-300 transition" data-question-id="${q.id}">
                                <i class="fas fa-robot mr-1"></i>Get Feedback
                            </button>
                            <div id="feedback-${q.id}" class="mt-2 p-3 bg-gray-800/50 rounded-lg text-sm hidden"></div>
                        </div>
                    `;
                }
            }
            html += `
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" id="closeModalBtn2" class="px-4 py-2 bg-gray-600 rounded-lg hover:bg-gray-500 transition">Cancel</button>
                        <button type="submit" class="btn-primary px-5 py-2 rounded-lg text-white font-medium">
                            <i class="fas fa-paper-plane mr-2"></i>Submit Assignment
                        </button>
                    </div>
                </form>
            `;
            modalContent.innerHTML = html;

            // Attach event listeners
            if (questions.length === 0) {
                document.getElementById('getFeedbackBtn')?.addEventListener('click', async () => {
                    const answer = document.querySelector('textarea[name="answers[0]"]').value;
                    if (!answer) {
                        alert('Please enter your answer first.');
                        return;
                    }
                    const feedbackDiv = document.getElementById('feedbackDisplay');
                    feedbackDiv.classList.remove('hidden');
                    feedbackDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting AI feedback...';
                    const formData = new FormData();
                    formData.append('ajax_action', 'get_feedback');
                    formData.append('question_text', 'General assignment submission');
                    formData.append('student_answer', answer);
                    formData.append('csrf_token', csrfToken);
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        feedbackDiv.innerHTML = `<i class="fas fa-comment-dots mr-2 text-orange-400"></i> ${escapeHtml(data.feedback)}`;
                    } else {
                        feedbackDiv.innerHTML = `<span class="text-red-400"><i class="fas fa-exclamation-circle mr-1"></i> Error: ${data.error}</span>`;
                    }
                });
            } else {
                document.querySelectorAll('.feedback-individual-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        const questionId = btn.dataset.questionId;
                        const textarea = document.querySelector(`textarea[name="answers[${questionId}"]`);
                        const answer = textarea.value;
                        if (!answer) {
                            alert('Please answer the question first.');
                            return;
                        }
                        const questionText = textarea.dataset.questionText;
                        const feedbackDiv = document.getElementById(`feedback-${questionId}`);
                        feedbackDiv.classList.remove('hidden');
                        feedbackDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting AI feedback...';
                        const formData = new FormData();
                        formData.append('ajax_action', 'get_feedback');
                        formData.append('question_text', questionText);
                        formData.append('student_answer', answer);
                        formData.append('csrf_token', csrfToken);
                        const res = await fetch(window.location.href, { method: 'POST', body: formData });
                        const data = await res.json();
                        if (data.success) {
                            feedbackDiv.innerHTML = `<i class="fas fa-comment-dots mr-2 text-orange-400"></i> ${escapeHtml(data.feedback)}`;
                        } else {
                            feedbackDiv.innerHTML = `<span class="text-red-400"><i class="fas fa-exclamation-circle mr-1"></i> Error: ${data.error}</span>`;
                        }
                    });
                });
            }

            document.getElementById('assignmentForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const answers = {};
                if (questions.length === 0) {
                    answers['0'] = document.querySelector('textarea[name="answers[0]"]').value;
                } else {
                    document.querySelectorAll('.question-answer').forEach(textarea => {
                        answers[textarea.dataset.questionId] = textarea.value;
                    });
                }
                let overallFeedback = '';
                if (questions.length === 0) {
                    overallFeedback = document.getElementById('feedbackDisplay')?.innerText || '';
                } else {
                    const feedbackDivs = document.querySelectorAll('[id^="feedback-"]');
                    feedbackDivs.forEach(div => {
                        if (div.innerText) overallFeedback += div.innerText + '\n';
                    });
                }
                const submitBtn = document.querySelector('#assignmentForm button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
                submitBtn.disabled = true;
                
                const formData = new FormData();
                formData.append('ajax_action', 'submit_assignment');
                formData.append('assignment_id', currentAssignmentId);
                formData.append('answers', JSON.stringify(answers));
                formData.append('overall_feedback', overallFeedback);
                formData.append('csrf_token', csrfToken);
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const result = await res.json();
                
                if (result.success) {
                    confetti.addConfetti({ confettiNumber: 150 });
                    alert('✓ Assignment submitted successfully!');
                    modal.classList.add('hidden');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            });

            document.getElementById('closeModalBtn2')?.addEventListener('click', () => modal.classList.add('hidden'));
        }

        document.querySelectorAll('.take-assignment-btn').forEach(btn => {
            btn.addEventListener('click', () => loadAssignment(btn.dataset.id));
        });
        document.getElementById('closeModalBtn')?.addEventListener('click', () => modal.classList.add('hidden'));
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // Table search/filter
        function filterTable(searchId, filterId, tableId, statusColIndex = 4) {
            const search = document.getElementById(searchId);
            const filter = document.getElementById(filterId);
            const rows = document.querySelectorAll(`#${tableId} tbody tr`);
            const filterTable = () => {
                const term = search.value.toLowerCase();
                const filterVal = filter.value;
                rows.forEach(row => {
                    const title = row.cells[0]?.textContent.toLowerCase() || '';
                    const status = row.cells[statusColIndex]?.textContent.trim().toLowerCase() || '';
                    const matchSearch = title.includes(term);
                    const matchFilter = filterVal === 'all' || status.includes(filterVal);
                    row.style.display = matchSearch && matchFilter ? '' : 'none';
                });
            };
            if (search) search.addEventListener('input', filterTable);
            if (filter) filter.addEventListener('change', filterTable);
        }
        filterTable('newAssignmentSearch', 'newAssignmentFilter', 'newAssignmentTable', 4);
    </script>
    <?php mysqli_close($conn); ?>
</body>
</html>