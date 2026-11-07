<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher exists and fetch department
$teacher_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Teacher not found for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}
$teacher_department_id = $teacher['department_id'];

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed Lesson Plan Curriculum Selection', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed lesson plan curriculum selection page";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log page access: " . $log_stmt->error);
}
$log_stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
?>

<!DOCTYPE html>
<html lang="en" class="dark" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Lesson plan curriculum selection for QueensPa School teachers. Choose between Oxford and GES curriculums.">
    <meta name="keywords" content="QueensPa School, lesson plan, teacher portal, Oxford curriculum, GES curriculum">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Lesson Plan Format Selection">
    <meta property="og:description" content="Teacher interface for selecting lesson plan curriculum (Oxford or GES) at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/lesson_plan.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Lesson Plan Curriculum Selection">
    <meta name="twitter:description" content="Teacher interface for selecting lesson plan curriculum (Oxford or GES) at QueensPa School.">
    <title>Lesson Plan Curriculum Selection - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(to bottom right, #111827, #000000);
            color: white;
            min-height: 100vh;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dark {
            --bg-color: #111827;
            --text-color: #ffffff;
            --card-bg: rgba(31, 41, 55, 0.8);
        }
        .light {
            --bg-color: #f3f4f6;
            --text-color: #000000;
            --card-bg: rgba(255, 255, 255, 0.9);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-900 to-black text-white min-h-screen">
    <div id="loading-overlay" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
        <i class="fas fa-spinner fa-spin text-orange-500 text-4xl"></i>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <main class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Header Section -->
        <header class="mb-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center space-y-4 md:space-y-0">
                <div>
                    <h1 class="text-3xl font-bold font-orbitron">Select Lesson Plan Curriculum</h1>
                    <p class="text-gray-400 mt-1">Choose the appropriate lesson plan curriculum for your teaching needs</p>
                </div>
                <div class="flex items-center space-x-4">
                    <button id="theme-toggle" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-xl flex items-center transition-colors duration-300">
                        <i class="fas fa-moon mr-2"></i> Toggle Theme
                    </button>
                </div>
            </div>
        </header>

        <!-- Format Selection Section -->
        <section class="mb-8 animate-fade-in-up">
            <div class="glassmorphism rounded-2xl p-6">
                <h2 class="text-2xl font-semibold font-orbitron mb-4">Choose Your Lesson Plan Curriculum</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white p-6 rounded-xl transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                        <h3 class="text-xl font-semibold font-orbitron mb-2">Oxford Curriculum</h3>
                        <p class="text-gray-200 mb-4">Designed for Pre-School and Primary levels, the Oxford curriculum organizes lesson plans into Units, Topics, and Lessons, emphasizing a structured and thematic approach to teaching.</p>
                        <p class="text-gray-300 text-sm mb-4">
                            <strong>Best for:</strong> Teachers in Pre-School and Primary (Basic 1-6) levels.<br>
                            <strong>Structure:</strong> Annual plans cover yearly goals, termly plans break down units per term, and weekly plans detail daily lessons with specific topics and activities.
                        </p>
                        <a href="/teacher/lesson_plan_oxford.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg font-medium inline-block transition-all duration-300">
                            <i class="fas fa-book mr-2"></i> Use Oxford Curriculum
                        </a>
                    </div>
                    <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white p-6 rounded-xl transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism">
                        <h3 class="text-xl font-semibold font-orbitron mb-2">GES Curriculum</h3>
                        <p class="text-gray-200 mb-4">Tailored for Junior High School (JHS), the GES format aligns with the Ghana Education Service curriculum, using Strands, Sub-strands, and Content Standards for structured learning.</p>
                        <p class="text-gray-300 text-sm mb-4">
                            <strong>Best for:</strong> Teachers in Junior High School (Basic 7-9) levels.<br>
                            <strong>Structure:</strong> Annual plans outline curriculum strands, termly plans focus on sub-strands, and weekly plans detail specific content standards and indicators for lessons.
                        </p>
                        <a href="/teacher/lesson_plan_ges.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg font-medium inline-block transition-all duration-300">
                            <i class="fas fa-book-open mr-2"></i> Use GES Curriculum
                        </a>
                    </div>
                </div>
                <p class="text-gray-300 text-sm text-center">Select the format that aligns with your teaching level. Oxford is ideal for younger learners with a focus on thematic units, while GES is suited for JHS with a curriculum-driven approach.</p>
            </div>
        </section>
    </main>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loadingOverlay = document.getElementById('loading-overlay');
            loadingOverlay.classList.add('hidden');

            // Dark mode toggle
            const themeToggle = document.getElementById('theme-toggle');
            if (localStorage.getItem('theme') === 'light') {
                document.documentElement.classList.remove('dark');
                document.body.classList.remove('bg-gradient-to-br', 'from-gray-900', 'to-black', 'text-white');
                document.body.classList.add('bg-gradient-to-br', 'from-gray-100', 'to-white', 'text-black');
                themeToggle.innerHTML = '<i class="fas fa-moon mr-2"></i> Toggle Theme';
            }
            themeToggle.addEventListener('click', () => {
                const html = document.documentElement;
                html.classList.toggle('dark');
                const isDark = html.classList.contains('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                if (isDark) {
                    document.body.classList.add('bg-gradient-to-br', 'from-gray-900', 'to-black', 'text-white');
                    document.body.classList.remove('bg-gradient-to-br', 'from-gray-100', 'to-white', 'text-black');
                    themeToggle.innerHTML = '<i class="fas fa-sun mr-2"></i> Toggle Theme';
                } else {
                    document.body.classList.add('bg-gradient-to-br', 'from-gray-100', 'to-white', 'text-black');
                    document.body.classList.remove('bg-gradient-to-br', 'from-gray-900', 'to-black', 'text-white');
                    themeToggle.innerHTML = '<i class="fas fa-moon mr-2"></i> Toggle Theme';
                }
            });
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>