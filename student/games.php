<?php
ob_start(); // Start output buffering
session_start();

// Enable error logging, disable display
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');

// Restrict to students only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt to games.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
    header("Location: /login.php");
    exit();
}

// Include dependencies
$include_success = true;
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php')) {
    error_log("Failed to include db.php");
    $include_success = false;
}
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php')) {
    error_log("Failed to include log_activity.php");
    $include_success = false;
}

// Fallback for log_activity
if (!function_exists('log_activity')) {
    function log_activity($conn, $user_id, $action, $description) {
        try {
            $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $action, $description);
                $stmt->execute();
                $stmt->close();
            } else {
                error_log("log_activity fallback failed to prepare statement: " . $conn->error);
            }
        } catch (Exception $e) {
            error_log("log_activity fallback error: " . $e->getMessage());
        }
    }
}

// Verify database connection
if (!isset($conn) || !$conn || !$include_success) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error or missing db.php"));
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}

$user_id = (int)$_SESSION['user_id'];
$success_msg = $error_msg = "";
$session_id = session_id();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Check if games table exists
try {
    $result = $conn->query("SHOW TABLES LIKE 'games'");
    if ($result->num_rows === 0) {
        $create_table = "CREATE TABLE games (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            image_url VARCHAR(255) NOT NULL,
            game_url VARCHAR(255) NOT NULL,
            category VARCHAR(50) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB";
        $conn->query($create_table) or throw new Exception("Failed to create games table: " . $conn->error);
        log_activity($conn, $user_id, 'Table Creation', 'Created games table');
    }
} catch (Exception $e) {
    error_log("Error checking/creating games table: " . $e->getMessage());
    die(json_encode(['error' => 'Failed to initialize database. Please contact support.']));
}

// Sync games from /student/games directory with database
$games_dir = $_SERVER['DOCUMENT_ROOT'] . '/student/games';

// Define modern game definitions with gradient colors and emoji icons
// Added Virtual Lab Simulator and Code & Conquer games
$default_games = [
    [
        'title' => 'Code & Conquer',
        'description' => 'Learn Python programming through block-based puzzles! Build code by dragging blocks, then watch your hero navigate mazes, collect treasures, and defeat enemies.',
        'image_url' => '/images/code_conquer.jpg',
        'game_url' => '/student/games/code-conquer.html',
        'category' => 'Coding',
        'gradient' => 'linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%)',
        'emoji' => '💻',
        'badge_color' => '#00d4ff',
        'stats' => ['levels' => '6', 'worlds' => '6', 'difficulty' => 'Beginner-Expert', 'concepts' => 'Python, Logic, Loops']
    ],
    [
        'title' => 'Virtual Lab Simulator',
        'description' => 'Step into a real science laboratory! Mix chemicals, observe reactions, test acids and bases, and discover the scientist in you through hands-on virtual experiments.',
        'image_url' => '/images/lab_simulator.jpg',
        'game_url' => '/student/games/lab-simulator.html',
        'category' => 'Science',
        'gradient' => 'linear-gradient(135deg, #00d4a8 0%, #0099ff 100%)',
        'emoji' => '🧪',
        'badge_color' => '#00d4a8',
        'stats' => ['experiments' => '5', 'reactions' => '30+', 'difficulty' => 'Easy-Medium']
    ],
    [
        'title' => 'Solar System Explorer',
        'description' => 'Journey through the cosmos! Explore planets, learn astronomy facts, and complete space challenges.',
        'image_url' => '/images/solar_system_quest.jpg',
        'game_url' => '/student/games/solar-system-quest.html',
        'category' => 'Science',
        'gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        'emoji' => '🚀',
        'badge_color' => '#8B5CF6',
        'stats' => ['planets' => '8', 'quizzes' => '20+', 'difficulty' => 'Medium']
    ],
    [
        'title' => 'Math Quest Ghana',
        'description' => 'Master mathematics through Ghanaian market adventures! Real-world problems with local context.',
        'image_url' => '/images/math_quiz.jpg',
        'game_url' => '/student/games/math-quest.html',
        'category' => 'Math',
        'gradient' => 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
        'emoji' => '🧮',
        'badge_color' => '#EC4899',
        'stats' => ['levels' => '4', 'topics' => '15+', 'difficulty' => 'Adaptive']
    ],
    [
        'title' => 'Word Search 3D',
        'description' => 'Discover hidden words in an immersive 3D cube! Ghanaian-themed vocabulary challenges await.',
        'image_url' => '/images/word_search.jpg',
        'game_url' => '/student/games/word-search.html',
        'category' => 'Language',
        'gradient' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
        'emoji' => '🔤',
        'badge_color' => '#06B6D4',
        'stats' => ['words' => '100+', 'themes' => '5', 'difficulty' => 'Easy-Medium']
    ],
    [
        'title' => 'Memory Match',
        'description' => 'Test your memory with beautifully designed card matching games featuring educational themes.',
        'image_url' => '/images/memory_match.jpg',
        'game_url' => '/student/games/memory-match.html',
        'category' => 'Memory',
        'gradient' => 'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)',
        'emoji' => '🧠',
        'badge_color' => '#10B981',
        'stats' => ['cards' => '24', 'themes' => '6', 'difficulty' => 'Progressive']
    ],
    [
        'title' => 'Language Quest',
        'description' => 'Embark on an epic language adventure! Build vocabulary through Ghanaian cultural stories and challenges.',
        'image_url' => '/images/language_quest.jpg',
        'game_url' => '/student/games/language-quest.html',
        'category' => 'Language',
        'gradient' => 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'emoji' => '📚',
        'badge_color' => '#F59E0B',
        'stats' => ['words' => '200+', 'levels' => '10', 'difficulty' => 'Adaptive']
    ],
    [
        'title' => 'Français Quest',
        'description' => 'Bonjour! Learn French through interactive adventures, from Paris to Francophone Africa.',
        'image_url' => '/images/french_quest.jpg',
        'game_url' => '/student/games/french-quest.html',
        'category' => 'Language',
        'gradient' => 'linear-gradient(135deg, #002395 0%, #ED2939 100%)',
        'emoji' => '🇫🇷',
        'badge_color' => '#DC2626',
        'stats' => ['phrases' => '150+', 'regions' => '4', 'difficulty' => 'A1-C2']
    ]
];

try {
    // Scan games directory for .html files
    $game_files = glob($games_dir . '/*.html');
    $existing_game_urls = array_column($default_games, 'game_url');

    // Add any new games from directory to default_games (excluding duplicates)
    foreach ($game_files as $file) {
        $relative_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $file);
        if (!in_array($relative_path, $existing_game_urls)) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $title = ucwords(str_replace('-', ' ', $filename));
            $default_games[] = [
                'title' => $title,
                'description' => "Enjoy the exciting $title game to enhance your learning!",
                'image_url' => '/images/' . $filename . '.jpg',
                'game_url' => $relative_path,
                'category' => 'General',
                'gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'emoji' => '🎮',
                'badge_color' => '#8B5CF6',
                'stats' => ['type' => 'Interactive', 'difficulty' => 'Medium']
            ];
        }
    }

    // Sync default games with database
    foreach ($default_games as $game) {
        $stmt = $conn->prepare("SELECT id FROM games WHERE game_url = ?");
        $stmt->bind_param("s", $game['game_url']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO games (title, description, image_url, game_url, category) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $game['title'], $game['description'], $game['image_url'], $game['game_url'], $game['category']);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, $user_id, 'Game Added', "Added game: {$game['title']}");
        } else {
            $stmt->close();
        }
    }
} catch (Exception $e) {
    error_log("Error syncing games from directory: " . $e->getMessage());
    $error_msg = "Error syncing games. Please contact support.";
}

// Handle game play tracking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'play_game') {
    header('Content-Type: application/json');
    ob_clean();

    // Verify CSRF token
    $received_token = $_POST['csrf_token'] ?? '';
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $received_token);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        error_log("Invalid CSRF token for user_id: $user_id");
        echo json_encode(['error' => 'Invalid CSRF token. Please refresh the page.']);
        exit();
    }
    $stmt->close();

    $game_id = (int)($_POST['game_id'] ?? 0);
    try {
        $sql = "INSERT INTO game_plays (user_id, game_id, played_at) VALUES (?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("ii", $user_id, $game_id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, $user_id, 'Game Play', "Played game ID: $game_id");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("Error tracking game play for user_id: $user_id, game_id: $game_id, Error: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to track game play. Please try again.']);
    }
    exit();
}

// Fetch games
$games = [];
try {
    $sql = "SELECT id, title, description, image_url, game_url, category FROM games ORDER BY created_at DESC";
    $result = $conn->query($sql);
    if (!$result) throw new Exception("Query failed: " . $conn->error);
    while ($row = $result->fetch_assoc()) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . $row['game_url'];
        if (file_exists($file_path)) {
            // Merge with default game data for styling
            $default_game = array_filter($default_games, function($g) use ($row) {
                return $g['game_url'] === $row['game_url'];
            });
            $row = array_merge($row, !empty($default_game) ? reset($default_game) : [
                'gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'emoji' => '🎮',
                'badge_color' => '#8B5CF6',
                'stats' => ['type' => 'Interactive']
            ]);
            $games[] = $row;
        } else {
            error_log("Game file not found: " . $row['game_url']);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching games for user_id: $user_id, Error: " . $e->getMessage());
    $error_msg = "Error loading games: " . htmlspecialchars($e->getMessage());
}

// ---------------------------------------------------------------
// Fetch student name — matches dashboard.php exactly:
// single query to users table, name column, with safe fallback.
// ---------------------------------------------------------------
$student_name = 'Student';
try {
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: ['name' => 'Student'];
    $student_name = !empty($user['name']) ? $user['name'] : 'Student';
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching student name for user_id: $user_id, Error: " . $e->getMessage());
}

// Category metadata
$category_meta = [
    'Science'  => ['icon' => '🔬', 'color' => '#8B5CF6', 'light' => '#f5f3ff', 'gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'],
    'Math'     => ['icon' => '🔢', 'color' => '#EC4899', 'light' => '#fdf2f8', 'gradient' => 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)'],
    'Language' => ['icon' => '📖', 'color' => '#06B6D4', 'light' => '#ecfeff', 'gradient' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)'],
    'Memory'   => ['icon' => '🧠', 'color' => '#10B981', 'light' => '#ecfdf5', 'gradient' => 'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)'],
    'Coding'   => ['icon' => '💻', 'color' => '#00d4ff', 'light' => '#e0f7fa', 'gradient' => 'linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%)'],
    'General'  => ['icon' => '🎮', 'color' => '#F59E0B', 'light' => '#fffbeb', 'gradient' => 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)'],
];

// Group games by category
$games_by_category = [];
foreach ($games as $game) {
    $games_by_category[$game['category']][] = $game;
}

// Include header
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php')) {
    error_log("Failed to include student_header.php");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Learning Games for QueensPa School students.">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Learning Games">
    <meta property="og:description" content="Play educational games to enhance your learning experience.">
    <meta property="og:type" content="website">
    <title>🎮 Learning Games · QueensPa School</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Baloo+2:wght@700;800&family=Fredoka+One&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        :root {
            --brand: #FF6B35;
            --brand-dark: #E55A2B;
            --bg: #0F172A;
            --surface: #1E293B;
            --surface-light: #334155;
            --text: #F1F5F9;
            --text-muted: #94A3B8;
            --radius: 20px;
            --radius-sm: 12px;
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.3);
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: var(--bg);
            font-family: 'Nunito', sans-serif;
            color: var(--text);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(139, 92, 246, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(236, 72, 153, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 50% 80%, rgba(6, 182, 212, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .games-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        /* ── Hero Section ── */
        .hero-section {
            background: linear-gradient(135deg, #1E293B 0%, #334155 100%);
            border-radius: 30px;
            padding: 3rem;
            margin-bottom: 3rem;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: var(--shadow-lg);
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255,107,53,0.15) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulse 4s ease-in-out infinite;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(139,92,246,0.1) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulse 5s ease-in-out infinite alternate;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .hero-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 3rem;
        }

        .hero-icon-wrapper {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #FF6B35, #FF8C42);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            box-shadow: 0 10px 40px rgba(255,107,53,0.4);
            animation: float 3s ease-in-out infinite;
            flex-shrink: 0;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(-5deg); }
            50% { transform: translateY(-15px) rotate(5deg); }
        }

        .hero-text h1 {
            font-family: 'Fredoka One', cursive;
            font-size: clamp(2rem, 4vw, 3rem);
            background: linear-gradient(135deg, #FF6B35, #FF8C42, #F59E0B);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .hero-text p {
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .hero-stats {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .hero-stat {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 15px;
            padding: 0.75rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
        }

        .hero-stat .stat-icon {
            font-size: 1.5rem;
        }

        /* ── Search & Filters ── */
        .controls-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-wrapper {
            position: relative;
            flex: 1;
            min-width: 280px;
        }

        .search-icon {
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        .search-input {
            width: 100%;
            padding: 1rem 1.25rem 1rem 3rem;
            background: var(--surface);
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            color: var(--text);
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            outline: none;
            transition: var(--transition);
        }

        .search-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 4px rgba(255,107,53,0.15);
        }

        .search-input::placeholder {
            color: var(--text-muted);
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-btn {
            background: var(--surface);
            border: 2px solid transparent;
            border-radius: 50px;
            padding: 0.7rem 1.5rem;
            color: var(--text-muted);
            font-family: 'Nunito', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }

        .filter-btn:hover {
            border-color: rgba(255,107,53,0.5);
            color: var(--text);
            transform: translateY(-2px);
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #FF6B35, #FF8C42);
            border-color: transparent;
            color: white;
            box-shadow: 0 5px 20px rgba(255,107,53,0.3);
        }

        /* ── Category Section ── */
        .category-section {
            margin-bottom: 3rem;
        }

        .category-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .category-icon {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .category-title {
            font-family: 'Fredoka One', cursive;
            font-size: 1.5rem;
            color: var(--text);
        }

        .category-count {
            background: var(--surface-light);
            color: var(--text-muted);
            padding: 0.2rem 0.8rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
        }

        /* ── Games Grid ── */
        .games-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }

        /* ── Game Card ── */
        .game-card {
            background: var(--surface);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
            transition: all var(--transition);
            animation: cardFadeIn 0.5s ease both;
            cursor: pointer;
            position: relative;
        }

        .game-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            border-color: rgba(255,107,53,0.3);
        }

        @keyframes cardFadeIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .game-card-preview {
            height: 200px;
            position: relative;
            overflow: hidden;
        }

        .game-card-gradient {
            position: absolute;
            inset: 0;
            opacity: 0.9;
        }

        .game-card-emoji {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 5rem;
            filter: drop-shadow(0 5px 15px rgba(0,0,0,0.3));
            transition: transform var(--transition);
        }

        .game-card:hover .game-card-emoji {
            transform: translate(-50%, -50%) scale(1.1) rotate(5deg);
        }

        .game-card-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .game-card-body {
            padding: 1.5rem;
        }

        .game-card-category {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            opacity: 0.8;
        }

        .game-card-title {
            font-family: 'Fredoka One', cursive;
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            color: var(--text);
        }

        .game-card-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .game-card-stats {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }

        .game-stat-pill {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            padding: 0.3rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
        }

        .play-button {
            width: 100%;
            padding: 0.9rem;
            background: linear-gradient(135deg, #FF6B35, #FF8C42);
            border: none;
            border-radius: 15px;
            color: white;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .play-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255,107,53,0.4);
            color: white;
        }

        .play-button i {
            transition: transform var(--transition);
        }

        .play-button:hover i {
            transform: translateX(3px);
        }

        /* Special style for Code & Conquer card */
        .game-card[data-title*="code & conquer"] .game-card-gradient {
            background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%) !important;
        }

        /* ── Empty State ── */
        .empty-state {
            grid-column: 1/-1;
            text-align: center;
            padding: 4rem 1rem;
        }

        .empty-state-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            animation: float 3s ease-in-out infinite;
        }

        .empty-state h3 {
            font-family: 'Fredoka One', cursive;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-muted);
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .games-container { padding: 1rem; }
            .hero-section { padding: 2rem 1.5rem; }
            .hero-content { flex-direction: column; text-align: center; gap: 1.5rem; }
            .hero-stats { justify-content: center; }
            .games-grid { grid-template-columns: 1fr; }
            .controls-section { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="games-container">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="hero-content">
                <div class="hero-icon-wrapper">🎮</div>
                <div class="hero-text">
                    <h1>Welcome, <?php echo htmlspecialchars($student_name); ?>!</h1>
                    <p>Ready to level up your learning? Choose a game and start your adventure!</p>
                    <div class="hero-stats">
                        <?php
                        $total_games = count($games);
                        $total_categories = count(array_unique(array_column($games, 'category')));
                        ?>
                        <div class="hero-stat">
                            <span class="stat-icon">🕹️</span>
                            <span><?php echo $total_games; ?> Games Available</span>
                        </div>
                        <div class="hero-stat">
                            <span class="stat-icon">📚</span>
                            <span><?php echo $total_categories; ?> Subjects</span>
                        </div>
                        <div class="hero-stat">
                            <span class="stat-icon">🇬🇭</span>
                            <span>Ghana-Inspired</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error Message -->
        <?php if ($error_msg): ?>
            <div style="background: #991B1B20; border: 1px solid #991B1B; border-radius: 15px; padding: 1rem; margin-bottom: 2rem; color: #FCA5A5; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <!-- Search & Filters -->
        <div class="controls-section">
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" id="gameSearch" placeholder="Search for games..." autocomplete="off">
            </div>
            <div class="filter-group" id="filterGroup">
                <button class="filter-btn active" data-filter="all">🎯 All Games</button>
                <?php foreach (array_keys($games_by_category) as $cat): ?>
                    <?php $meta = $category_meta[$cat] ?? $category_meta['General']; ?>
                    <button class="filter-btn" data-filter="<?php echo htmlspecialchars($cat); ?>">
                        <?php echo $meta['icon']; ?> <?php echo htmlspecialchars($cat); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Games Display -->
        <?php if (empty($games)): ?>
            <div class="games-grid">
                <div class="empty-state">
                    <div class="empty-state-icon">🎲</div>
                    <h3>No Games Yet</h3>
                    <p>Exciting learning games are coming soon! Check back later.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($games_by_category as $category => $cat_games): ?>
                <?php $meta = $category_meta[$category] ?? $category_meta['General']; ?>
                <div class="category-section" data-category="<?php echo htmlspecialchars($category); ?>">
                    <div class="category-header">
                        <div class="category-icon" style="background: <?php echo $meta['light']; ?>">
                            <?php echo $meta['icon']; ?>
                        </div>
                        <span class="category-title"><?php echo htmlspecialchars($category); ?></span>
                        <span class="category-count"><?php echo count($cat_games); ?> games</span>
                    </div>

                    <div class="games-grid">
                        <?php foreach ($cat_games as $index => $game): ?>
                            <?php 
                            $delay = $index * 100;
                            $gradient = $game['gradient'] ?? $meta['gradient'];
                            $emoji = $game['emoji'] ?? $meta['icon'];
                            $badge_color = $game['badge_color'] ?? $meta['color'];
                            ?>
                            <div class="game-card" 
                                 data-title="<?php echo strtolower(htmlspecialchars($game['title'])); ?>"
                                 style="animation-delay: <?php echo $delay; ?>ms;">
                                
                                <!-- Preview Area -->
                                <div class="game-card-preview">
                                    <div class="game-card-gradient" style="background: <?php echo $gradient; ?>;"></div>
                                    <div class="game-card-emoji"><?php echo $emoji; ?></div>
                                    <div class="game-card-badge" style="background: <?php echo $badge_color; ?>">
                                        <?php echo htmlspecialchars($category); ?>
                                    </div>
                                </div>
                                
                                <!-- Card Body -->
                                <div class="game-card-body">
                                    <div class="game-card-category" style="color: <?php echo $badge_color; ?>">
                                        <?php echo $meta['icon']; ?> <?php echo htmlspecialchars($category); ?>
                                    </div>
                                    
                                    <h3 class="game-card-title">
                                        <?php echo htmlspecialchars($game['title']); ?>
                                    </h3>
                                    
                                    <p class="game-card-desc">
                                        <?php echo htmlspecialchars($game['description']); ?>
                                    </p>
                                    
                                    <!-- Game Stats -->
                                    <?php if (isset($game['stats']) && !empty($game['stats'])): ?>
                                    <div class="game-card-stats">
                                        <?php foreach ($game['stats'] as $stat_key => $stat_value): ?>
                                            <span class="game-stat-pill">
                                                <?php echo htmlspecialchars(ucfirst($stat_key)); ?>: <?php echo htmlspecialchars($stat_value); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Play Button -->
                                    <a href="<?php echo htmlspecialchars($game['game_url']); ?>"
                                       class="play-button"
                                       data-game-id="<?php echo $game['id']; ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       onclick="trackGamePlay(event, <?php echo $game['id']; ?>)">
                                        <i class="fas fa-play"></i> Play Now
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php
    if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php')) {
        error_log("Failed to include student_footer.php");
    }
    $conn->close();
    ob_end_flush();
    ?>

    <script>
    $(function() {
        // Track game plays
        window.trackGamePlay = function(e, gameId) {
            $.ajax({
                url: '/student/games.php',
                type: 'POST',
                data: {
                    action: 'play_game',
                    game_id: gameId,
                    csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                },
                contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
                dataType: 'json',
                error: function(xhr) { 
                    console.log('Game tracking:', xhr.responseText); 
                }
            });
        };

        // Category filtering
        $('.filter-btn').on('click', function() {
            const filter = $(this).data('filter');
            
            // Update active state
            $('.filter-btn').removeClass('active');
            $(this).addClass('active');
            
            // Filter sections
            if (filter === 'all') {
                $('.category-section').show();
                $('.game-card').show();
            } else {
                $('.category-section').each(function() {
                    if ($(this).data('category') === filter) {
                        $(this).show();
                        $(this).find('.game-card').show();
                    } else {
                        $(this).hide();
                    }
                });
            }
            
            // Apply search if any
            applySearch();
        });

        // Live search
        function applySearch() {
            const query = $('#gameSearch').val().toLowerCase().trim();
            
            if (!query) {
                // Reset to filter view
                const activeFilter = $('.filter-btn.active').data('filter');
                if (activeFilter === 'all') {
                    $('.category-section').show();
                    $('.game-card').show();
                } else {
                    $('.filter-btn.active').trigger('click');
                }
                return;
            }
            
            // Search through cards
            $('.category-section').show();
            $('.game-card').each(function() {
                const title = $(this).data('title') || '';
                const desc = $(this).find('.game-card-desc').text().toLowerCase() || '';
                const matches = title.includes(query) || desc.includes(query);
                $(this).toggle(matches);
            });
            
            // Hide empty sections
            $('.category-section').each(function() {
                const visibleCards = $(this).find('.game-card:visible').length;
                $(this).toggle(visibleCards > 0);
            });
        }

        // Debounced search
        let searchTimeout;
        $('#gameSearch').on('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applySearch, 300);
        });

        // Card hover effects
        $('.game-card').on('mouseenter', function() {
            $(this).find('.game-card-emoji').css({
                'transform': 'translate(-50%, -50%) scale(1.1) rotate(5deg)'
            });
        }).on('mouseleave', function() {
            $(this).find('.game-card-emoji').css({
                'transform': 'translate(-50%, -50%) scale(1) rotate(0deg)'
            });
        });
    });
    </script>
</body>
</html>