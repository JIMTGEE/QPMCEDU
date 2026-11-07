<?php
/**
 * EduBot Pro v2.1 - Full-Scale AI Learning Assistant
 * QueensPa School | PHP 7.4+ Compatible | Production Ready
 * 
 * FEATURES:
 * - Secure API key handling (environment variables only)
 * - Auto-migrates all DB tables on first run
 * - Rate limiting (60 requests/hour per student)
 * - Multimodal file uploads (images -> Gemini vision)
 * - Markdown rendering, code highlighting, math equations
 * - Voice input, dark mode, chat export, typing indicators
 * - Conversation memory & context awareness
 * - Session management with sidebar history
 * - CSRF protection, input sanitization, error logging
 */

ob_start();
session_start();

// === SECURITY HEADERS ===
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// === CONFIGURATION ===
define('EDUBOT_VERSION', '2.1.0');
define('EDUBOT_MAX_PROMPT_LENGTH', 4000);
define('EDUBOT_RATE_LIMIT_HOURLY', 60);
define('EDUBOT_MAX_FILE_SIZE', 5 * 1024 * 1024);
define('EDUBOT_ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'
]);

// === SECURE API KEY CONFIGURATION ===
// CRITICAL: Set GEMINI_API_KEY as environment variable on your server
// Apache: SetEnv GEMINI_API_KEY your_key_here
// Nginx: fastcgi_param GEMINI_API_KEY your_key_here
// PHP-FPM: env[GEMINI_API_KEY] = your_key_here
// Shell: export GEMINI_API_KEY=your_key_here
//
// NEVER hardcode keys in this file. If env vars unavailable,
// use a file OUTSIDE web root (e.g., /etc/secrets/gemini_key.txt)

$api_key = getenv('GEMINI_API_KEY');

// Fallback: external file outside web root (comment out if using env var)
if (empty($api_key)) {
    $secret_path = '/etc/secrets/gemini_key.txt'; // CHANGE THIS PATH
    if (file_exists($secret_path)) {
        $api_key = trim(file_get_contents($secret_path));
    }
}

if (empty($api_key)) {
    error_log("Edubot: GEMINI_API_KEY not configured");
    if (is_ajax_request()) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'AI service not configured. Contact administrator.']));
    }
    die("<h2>Configuration Error</h2><p>AI service not configured. Please set GEMINI_API_KEY environment variable.</p>");
}

define('EDUBOT_API_KEY', $api_key);

// Error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
error_reporting(E_ALL);

// === AUTH CHECK ===
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    if (is_ajax_request()) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Unauthorized. Please log in again.']));
    }
    header("Location: /login.php");
    exit();
}

// === DEPENDENCIES ===
$include_success = true;
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php')) {
    error_log("Edubot: Failed to include db.php");
    $include_success = false;
}
if (!@include_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php')) {
    error_log("Edubot: Failed to include log_activity.php");
}

if (!function_exists('log_activity')) {
    function log_activity($conn, $user_id, $action, $description) {
        if (!$conn) return;
        try {
            $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $action, $description);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("log_activity fallback: " . $e->getMessage());
        }
    }
}

// Verify DB
if (!$include_success || !isset($conn) || !$conn || $conn->connect_error) {
    $db_err = (isset($conn) && $conn) ? $conn->connect_error : (mysqli_connect_error() ?: "Unknown DB error");
    error_log("Edubot: DB connection failed: " . $db_err);
    if (is_ajax_request()) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Database connection failed. Please try again later.']));
    }
    die("<h2>System Error</h2><p>Unable to connect to database. Contact support.</p>");
}

$user_id = (int)$_SESSION['user_id'];
$session_id = session_id();

// === AUTO-MIGRATE ALL TABLES ===
edubot_init_tables($conn);

// === CSRF TOKEN ===
$csrf_token = edubot_get_csrf_token($conn, $user_id);

// === AJAX ROUTING (Early Exit) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $state_actions = ['send_message', 'clear_history', 'delete_session', 'rename_session', 'upload_file'];
    if (in_array($action, $state_actions, true)) {
        $received_token = $_POST['csrf_token'] ?? '';
        if (!edubot_verify_csrf($conn, $user_id, $received_token)) {
            error_log("Edubot: Invalid CSRF for user_id=$user_id action=$action");
            json_response(['error' => 'Invalid security token. Please refresh the page.'], 403);
        }
    }

    switch ($action) {
        case 'send_message':
            edubot_handle_send_message($conn, $user_id, $session_id);
            break;
        case 'clear_history':
            edubot_handle_clear_history($conn, $user_id, $session_id);
            break;
        case 'get_sessions':
            edubot_handle_get_sessions($conn, $user_id);
            break;
        case 'get_session_messages':
            edubot_handle_get_session_messages($conn, $user_id);
            break;
        case 'delete_session':
            edubot_handle_delete_session($conn, $user_id);
            break;
        case 'rename_session':
            edubot_handle_rename_session($conn, $user_id);
            break;
        case 'upload_file':
            edubot_handle_upload_file($conn, $user_id);
            break;
        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
    exit;
}

// Export handler (GET)
if (isset($_GET['action']) && $_GET['action'] === 'export_chat') {
    edubot_handle_export_chat($conn, $user_id);
    exit;
}

// === LOAD PAGE DATA (GET ONLY) ===
$page_data = edubot_load_page_data($conn, $user_id, $session_id);
extract($page_data);

// === HTML OUTPUT ===
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
if (file_exists($header_path)) include $header_path;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="EduBot Pro - AI Learning Assistant for QueensPa School students">
    <meta name="robots" content="noindex, nofollow">
    <title>EduBot Pro - AI Learning Assistant | QueensPa School</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/github-dark.min.css" id="hljs-theme">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.5/purify.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <style>
        :root {
            --primary: #ff4500;
            --primary-hover: #e63e00;
            --secondary: #ff6b00;
            --bg-light: #f8f9fa;
            --bg-dark: #0f172a;
            --text-light: #1f2937;
            --text-dark: #e2e8f0;
            --sidebar-width: 280px;
        }
        * { scrollbar-width: thin; scrollbar-color: var(--primary) transparent; }
        *::-webkit-scrollbar { width: 6px; }
        *::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg-light);
            transition: background 0.3s, color 0.3s;
            margin: 0;
            height: 100vh;
            overflow: hidden;
        }
        body.dark-mode { background: var(--bg-dark); color: var(--text-dark); }
        .chat-wrapper {
            display: flex;
            height: calc(100vh - 60px);
            max-width: 1400px;
            margin: 0 auto;
            gap: 1rem;
            padding: 1rem;
        }
        .sidebar {
            width: var(--sidebar-width);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        body.dark-mode .sidebar { background: #1e293b; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .main-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            min-width: 0;
        }
        body.dark-mode .main-chat { background: #1e293b; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .chat-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            scroll-behavior: smooth;
            min-height: 0;
        }
        .message {
            margin-bottom: 1.25rem;
            max-width: 85%;
            animation: msgSlide 0.3s ease;
        }
        @keyframes msgSlide {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message.user { margin-left: auto; }
        .message.ai { margin-right: auto; }
        .message-bubble {
            padding: 1rem 1.25rem;
            border-radius: 1rem;
            line-height: 1.6;
            position: relative;
            word-wrap: break-word;
        }
        .message.user .message-bubble {
            background: linear-gradient(135deg, #ff4500, #ff6b00);
            color: #fff;
            border-bottom-right-radius: 0.25rem;
        }
        .message.ai .message-bubble {
            background: #f1f5f9;
            color: #1f2937;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 0.25rem;
        }
        body.dark-mode .message.ai .message-bubble { background: #334155; color: #e2e8f0; border-color: #475569; }
        .message-meta {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .typing-indicator {
            display: flex;
            gap: 0.25rem;
            padding: 1rem;
        }
        .typing-indicator span {
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
        }
        .typing-indicator span:nth-child(1) { animation-delay: -0.32s; }
        .typing-indicator span:nth-child(2) { animation-delay: -0.16s; }
        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1); }
        }
        .input-area {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            flex-shrink: 0;
        }
        body.dark-mode .input-area { background: #0f172a; border-color: #334155; }
        .input-container {
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 1rem;
            padding: 0.5rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        body.dark-mode .input-container { background: #1e293b; border-color: #475569; }
        .input-container:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.1);
        }
        #prompt-input {
            flex: 1;
            border: none;
            background: transparent;
            resize: none;
            max-height: 150px;
            padding: 0.5rem;
            font-size: 1rem;
            outline: none;
            font-family: inherit;
        }
        body.dark-mode #prompt-input { color: #e2e8f0; }
        .input-actions button {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .input-actions button:hover { background: #f3f4f6; color: var(--primary); }
        body.dark-mode .input-actions button:hover { background: #334155; }
        .send-btn {
            background: var(--primary) !important;
            color: #fff !important;
        }
        .send-btn:hover { background: var(--primary-hover) !important; transform: scale(1.05); }
        .send-btn:disabled { background: #9ca3af !important; cursor: not-allowed; transform: none; }
        .file-preview {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 0.5rem;
        }
        .file-chip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #fef3c7;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.875rem;
            color: #92400e;
        }
        .file-chip img {
            max-width: 40px;
            max-height: 40px;
            border-radius: 4px;
            object-fit: cover;
        }
        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        body.dark-mode .sidebar-header { border-color: #334155; }
        .new-chat-btn {
            width: 100%;
            padding: 0.75rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .new-chat-btn:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .session-list {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }
        .session-item {
            padding: 0.75rem;
            border-radius: 0.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
            margin-bottom: 0.25rem;
            position: relative;
        }
        .session-item:hover, .session-item.active { background: #f3f4f6; }
        body.dark-mode .session-item:hover, body.dark-mode .session-item.active { background: #334155; }
        .session-title {
            font-size: 0.875rem;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1;
            margin-right: 0.5rem;
        }
        .session-time { font-size: 0.7rem; color: #9ca3af; }
        .session-menu {
            opacity: 0;
            transition: opacity 0.2s;
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
        }
        .session-item:hover .session-menu { opacity: 1; }
        .toolbar {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem;
            border-top: 1px solid #e5e7eb;
        }
        body.dark-mode .toolbar { border-color: #334155; }
        .toolbar button {
            flex: 1;
            padding: 0.5rem;
            border: none;
            background: transparent;
            border-radius: 0.375rem;
            cursor: pointer;
            color: #6b7280;
            font-size: 0.875rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toolbar button:hover { background: #f3f4f6; color: var(--primary); }
        body.dark-mode .toolbar button:hover { background: #334155; }
        .markdown-content h1, .markdown-content h2, .markdown-content h3 {
            margin-top: 1rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: inherit;
        }
        .markdown-content h1 { font-size: 1.5rem; }
        .markdown-content h2 { font-size: 1.25rem; }
        .markdown-content h3 { font-size: 1.1rem; }
        .markdown-content p { margin-bottom: 0.75rem; line-height: 1.6; }
        .markdown-content ul, .markdown-content ol { margin-left: 1.5rem; margin-bottom: 0.75rem; }
        .markdown-content li { margin-bottom: 0.25rem; }
        .markdown-content code {
            background: rgba(0,0,0,0.05);
            padding: 0.2rem 0.4rem;
            border-radius: 0.25rem;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.9em;
        }
        body.dark-mode .markdown-content code { background: rgba(255,255,255,0.1); }
        .markdown-content pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 0.5rem;
            overflow-x: auto;
            margin-bottom: 0.75rem;
            position: relative;
        }
        .markdown-content pre code { background: transparent; padding: 0; font-size: 0.875rem; }
        .copy-code-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(255,255,255,0.1);
            border: none;
            color: #fff;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.75rem;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .markdown-content pre:hover .copy-code-btn { opacity: 1; }
        .markdown-content blockquote {
            border-left: 4px solid var(--primary);
            padding-left: 1rem;
            margin-left: 0;
            color: #6b7280;
            font-style: italic;
            background: rgba(255,69,0,0.03);
            padding: 0.5rem 1rem;
            border-radius: 0 0.5rem 0.5rem 0;
        }
        .markdown-content table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0.75rem;
        }
        .markdown-content th, .markdown-content td {
            border: 1px solid #e2e8f0;
            padding: 0.5rem;
            text-align: left;
        }
        .markdown-content th { background: #f8fafc; font-weight: 600; }
        body.dark-mode .markdown-content th { background: #334155; }
        body.dark-mode .markdown-content th, body.dark-mode .markdown-content td { border-color: #475569; }
        .welcome-banner {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, rgba(255,69,0,0.05), rgba(255,107,0,0.05));
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border: 1px dashed rgba(255,69,0,0.2);
        }
        .suggestion-chips {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 1rem;
        }
        .suggestion-chip {
            padding: 0.5rem 1rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 2rem;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        .suggestion-chip:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255,69,0,0.15);
        }
        body.dark-mode .suggestion-chip { background: #334155; border-color: #475569; color: #e2e8f0; }
        .alert-toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            color: #fff;
            font-weight: 500;
            z-index: 1000;
            animation: slideIn 0.3s ease;
            max-width: 400px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .alert-toast.error { background: #ef4444; }
        .alert-toast.success { background: #10b981; }
        .alert-toast.warning { background: #f59e0b; }
        .alert-toast.info { background: #3b82f6; }
        .chat-image {
            max-width: 300px;
            max-height: 200px;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .chat-image:hover { transform: scale(1.02); }
        @media (max-width: 768px) {
            .chat-wrapper {
                flex-direction: column;
                height: auto;
                padding: 0.5rem;
                gap: 0.5rem;
            }
            .sidebar {
                width: 100%;
                height: auto;
                max-height: 200px;
            }
            .main-chat { height: calc(100vh - 220px); }
            .message { max-width: 95%; }
            .chat-header { padding: 0.75rem 1rem; }
            .chat-header h1 { font-size: 1rem; }
            .chat-messages { padding: 1rem; }
            .input-area { padding: 0.75rem 1rem; }
        }
        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div id="alert-container"></div>

    <div class="chat-wrapper">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <button class="new-chat-btn" onclick="startNewChat()">
                    <i class="fas fa-plus"></i> New Chat
                </button>
            </div>
            <div class="session-list" id="session-list">
                <div class="text-center text-gray-500 text-sm mt-4 p-4">
                    <div class="spinner mb-2"></div>
                    Loading sessions...
                </div>
            </div>
            <div class="toolbar">
                <button onclick="toggleDarkMode()" title="Toggle Dark Mode"><i class="fas fa-moon"></i></button>
                <button onclick="exportChat()" title="Export Chat"><i class="fas fa-download"></i></button>
                <button onclick="clearCurrentSession()" title="Clear Current Session"><i class="fas fa-trash"></i></button>
                <button onclick="location.href='/student/dashboard.php'" title="Dashboard"><i class="fas fa-home"></i></button>
            </div>
        </aside>

        <main class="main-chat">
            <div class="chat-header">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-robot text-xl"></i>
                    </div>
                    <div>
                        <h1 class="font-bold text-lg">EduBot Pro</h1>
                        <p class="text-xs opacity-90">
                            AI Assistant &bull; <?php echo htmlspecialchars($student_name ?? 'Student'); ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs bg-white/20 px-2 py-1 rounded-full" id="status-indicator">
                        <i class="fas fa-circle text-green-400 text-[8px] mr-1"></i> Online
                    </span>
                    <button class="md:hidden text-white" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages">
                <?php if (empty($current_session_messages)): ?>
                <div class="welcome-banner">
                    <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-red-600 rounded-2xl mx-auto mb-4 flex items-center justify-center text-white text-3xl">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h2 class="text-2xl font-bold mb-2">
                        Welcome back, <?php echo htmlspecialchars($student_name ?? 'Student'); ?>!
                    </h2>
                    <p class="text-gray-600 mb-4 dark:text-gray-300">
                        I'm EduBot, your personal AI tutor. Ask me anything academic.
                    </p>
                    <div class="suggestion-chips">
                        <div class="suggestion-chip" onclick="setPrompt('Explain photosynthesis in simple terms')">Explain photosynthesis</div>
                        <div class="suggestion-chip" onclick="setPrompt('Help me solve 2x + 5 = 15 step by step')">Solve math</div>
                        <div class="suggestion-chip" onclick="setPrompt('Quiz me on World War II causes')">Quiz me</div>
                        <div class="suggestion-chip" onclick="setPrompt('Analyze my recent grades and suggest improvements')">Grade analysis</div>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($current_session_messages as $msg): ?>
                    <div class="message user">
                        <div class="message-bubble">
                            <div class="font-semibold mb-1 text-sm opacity-90">You</div>
                            <?php echo nl2br(htmlspecialchars($msg['prompt'])); ?>
                        </div>
                        <div class="message-meta justify-end">
                            <span><?php echo date('M j, g:i a', strtotime($msg['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="message ai">
                        <div class="message-bubble markdown-content" id="msg-<?php echo $msg['id']; ?>">
                            <div class="font-semibold mb-1 text-sm text-orange-600">EduBot</div>
                            <div class="response-text"><?php echo htmlspecialchars($msg['response']); ?></div>
                        </div>
                        <div class="message-meta">
                            <button onclick="copyMessage('msg-<?php echo $msg['id']; ?>')" class="text-xs hover:text-orange-600 transition">
                                <i class="fas fa-copy mr-1"></i> Copy
                            </button>
                            <span><?php echo date('M j, g:i a', strtotime($msg['created_at'])); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="input-area">
                <div id="file-preview" class="file-preview"></div>
                <form id="chat-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($session_id); ?>">

                    <div class="input-container">
                        <button type="button" class="text-gray-400 hover:text-orange-500 transition flex-shrink-0" 
                                onclick="document.getElementById('file-input').click()" title="Attach image or PDF">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <input type="file" id="file-input" name="attachment" style="display:none" 
                               accept="image/*,.pdf" onchange="handleFileSelect(this)" multiple>

                        <textarea id="prompt-input" name="prompt" rows="1" 
                                  placeholder="Ask EduBot anything academic..." 
                                  onkeydown="handleKeyDown(event)"></textarea>

                        <div class="input-actions">
                            <button type="button" id="voice-btn" onclick="toggleVoiceInput()" title="Voice input">
                                <i class="fas fa-microphone"></i>
                            </button>
                            <button type="submit" class="send-btn" id="send-btn" title="Send message">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </form>
                <div class="text-xs text-gray-400 mt-2 text-center">
                    EduBot may make mistakes. Please verify important information. 
                    <span id="rate-limit-info"></span>
                </div>
            </div>
        </main>
    </div>

    <script>
        const CONFIG = {
            csrfToken: '<?php echo htmlspecialchars($csrf_token); ?>',
            sessionId: '<?php echo htmlspecialchars($session_id); ?>',
            userId: <?php echo $user_id; ?>,
            apiEndpoint: '/student/learn.php'
        };
        let isTyping = false, voiceRecognition = null, currentFiles = [], currentSessionId = CONFIG.sessionId;

        $(document).ready(function() {
            loadSessions();
            scrollToBottom();
            initMarkdown();
            restoreDraft();
            $('#prompt-input').on('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 150) + 'px';
                saveDraft();
            });
            $('#chat-form').submit(function(e) {
                e.preventDefault();
                sendMessage();
            });
            if (localStorage.getItem('edubot_dark_mode') === 'true') {
                document.body.classList.add('dark-mode');
                $('#hljs-theme').attr('href', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/github-dark.min.css');
            }
        });

        function sendMessage() {
            const prompt = $('#prompt-input').val().trim();
            if ((!prompt && currentFiles.length === 0) || isTyping) return;
            if (prompt) appendUserMessage(prompt);
            $('#prompt-input').val('').css('height', 'auto');
            clearFiles();
            saveDraft();
            showTypingIndicator();
            isTyping = true;
            $('#send-btn').prop('disabled', true);
            $('#status-indicator').html('<i class="fas fa-circle text-yellow-400 text-[8px] mr-1 animate-pulse"></i> Thinking...');

            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('csrf_token', CONFIG.csrfToken);
            formData.append('session_id', currentSessionId);
            formData.append('prompt', prompt);
            currentFiles.forEach(file => formData.append('attachments[]', file));

            $.ajax({
                url: CONFIG.apiEndpoint,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 60000,
                success: function(response) {
                    hideTypingIndicator();
                    if (response.success) {
                        appendAIMessage(response.response, response.id);
                        if (response.rate_limit) updateRateLimit(response.rate_limit);
                        loadSessions();
                    } else {
                        showAlert(response.error || 'Failed to get response', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    hideTypingIndicator();
                    console.error('AJAX Error:', status, xhr.status, xhr.responseText);
                    let msg = 'Error communicating with EduBot.';
                    try {
                        const resp = JSON.parse(xhr.responseText);
                        if (resp.error) msg = resp.error;
                    } catch(e) {
                        if (xhr.status === 500) msg = 'Server error (500). Check error_log.txt.';
                        else if (xhr.status === 403) msg = 'Session expired. Please refresh.';
                        else if (xhr.status === 429) msg = 'Too many requests. Please slow down.';
                        else if (xhr.status === 401) msg = 'Unauthorized. Please log in again.';
                        else if (status === 'timeout') msg = 'Request timed out. Please try again.';
                    }
                    showAlert(msg + ' <a href="javascript:location.reload()" class="underline">Refresh</a>', 'error');
                },
                complete: function() {
                    isTyping = false;
                    $('#send-btn').prop('disabled', false);
                    $('#status-indicator').html('<i class="fas fa-circle text-green-400 text-[8px] mr-1"></i> Online');
                }
            });
        }

        function appendUserMessage(text) {
            const time = new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            $('#chat-messages').append(`
                <div class="message user">
                    <div class="message-bubble">
                        <div class="font-semibold mb-1 text-sm opacity-90">You</div>
                        ${escapeHtml(text)}
                    </div>
                    <div class="message-meta justify-end"><span>${time}</span></div>
                </div>
            `);
            scrollToBottom();
        }

        function appendAIMessage(text, id) {
            const safeId = id || 'temp-' + Date.now();
            const time = new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            $('#chat-messages').append(`
                <div class="message ai">
                    <div class="message-bubble markdown-content" id="msg-${safeId}">
                        <div class="font-semibold mb-1 text-sm text-orange-600">EduBot</div>
                        <div class="response-text"></div>
                    </div>
                    <div class="message-meta">
                        <button onclick="copyMessage('msg-${safeId}')" class="text-xs hover:text-orange-600 transition">
                            <i class="fas fa-copy mr-1"></i> Copy
                        </button>
                        <span>${time}</span>
                    </div>
                </div>
            `);
            scrollToBottom();
            const container = $(`#msg-${safeId}`);
            container.find('.response-text').text(text);
            renderMarkdown(container);
        }

        function showTypingIndicator() {
            $('#chat-messages').append(`
                <div class="message ai" id="typing-indicator">
                    <div class="message-bubble">
                        <div class="typing-indicator"><span></span><span></span><span></span></div>
                    </div>
                </div>
            `);
            scrollToBottom();
        }

        function hideTypingIndicator() { $('#typing-indicator').remove(); }

        function renderMarkdown(container) {
            const rawText = container.find('.response-text').text();
            marked.setOptions({ breaks: true, gfm: true, headerIds: false, sanitize: false });
            const html = marked.parse(rawText);
            const clean = DOMPurify.sanitize(html, {
                ALLOWED_TAGS: ['h1','h2','h3','h4','h5','h6','p','br','hr','ul','ol','li','strong','em','b','i','u','s','code','pre','blockquote','a','img','table','thead','tbody','tr','th','td','span','div'],
                ALLOWED_ATTR: ['href','title','src','alt','class','id','target']
            });
            container.find('.response-text').html(clean);
            container.find('pre code').each(function(i, block) { hljs.highlightElement(block); });
            container.find('pre').each(function() {
                if (!$(this).find('.copy-code-btn').length) {
                    $(this).append('<button class="copy-code-btn" onclick="copyCode(this)"><i class="fas fa-copy"></i> Copy</button>');
                }
            });
            if (window.renderMathInElement) {
                renderMathInElement(container[0], {
                    delimiters: [{left: '$$', right: '$$', display: true}, {left: '$', right: '$', display: false}],
                    throwOnError: false
                });
            }
            container.find('a').attr('target', '_blank').attr('rel', 'noopener noreferrer');
        }

        function initMarkdown() {
            $('.markdown-content').each(function() { renderMarkdown($(this)); });
        }

        function handleFileSelect(input) {
            Array.from(input.files).forEach(file => {
                if (file.size > 5 * 1024 * 1024) {
                    showAlert('File too large (max 5MB): ' + escapeHtml(file.name), 'warning');
                    return;
                }
                currentFiles.push(file);
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#file-preview').append(`
                            <div class="file-chip" data-filename="${escapeHtml(file.name)}">
                                <img src="${e.target.result}" alt="preview">
                                <span>${escapeHtml(file.name)}</span>
                                <i class="fas fa-times cursor-pointer ml-1" onclick="removeFile('${escapeHtml(file.name)}')"></i>
                            </div>
                        `);
                    };
                    reader.readAsDataURL(file);
                } else {
                    $('#file-preview').append(`
                        <div class="file-chip" data-filename="${escapeHtml(file.name)}">
                            <i class="fas fa-file-pdf text-red-500"></i>
                            <span>${escapeHtml(file.name)}</span>
                            <i class="fas fa-times cursor-pointer ml-1" onclick="removeFile('${escapeHtml(file.name)}')"></i>
                        </div>
                    `);
                }
            });
            input.value = '';
        }

        function removeFile(name) {
            currentFiles = currentFiles.filter(f => f.name !== name);
            $(`.file-chip[data-filename="${CSS.escape(name)}"]`).remove();
        }

        function clearFiles() { currentFiles = []; $('#file-preview').empty(); }

        function toggleVoiceInput() {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) { showAlert('Voice input not supported. Try Chrome.', 'warning'); return; }
            if (voiceRecognition && voiceRecognition.recognizing) { voiceRecognition.stop(); return; }
            voiceRecognition = new SpeechRecognition();
            voiceRecognition.continuous = false;
            voiceRecognition.interimResults = true;
            voiceRecognition.lang = 'en-US';
            voiceRecognition.onstart = function() {
                voiceRecognition.recognizing = true;
                $('#voice-btn').html('<i class="fas fa-stop text-red-500"></i>');
                showAlert('Listening... Speak now', 'info');
            };
            voiceRecognition.onresult = function(event) {
                let final = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) final += event.results[i][0].transcript;
                }
                if (final) {
                    const current = $('#prompt-input').val();
                    $('#prompt-input').val(current + (current ? ' ' : '') + final).trigger('input');
                }
            };
            voiceRecognition.onerror = function(event) {
                if (event.error !== 'aborted') showAlert('Voice error: ' + event.error, 'error');
            };
            voiceRecognition.onend = function() {
                voiceRecognition.recognizing = false;
                $('#voice-btn').html('<i class="fas fa-microphone"></i>');
            };
            voiceRecognition.start();
        }

        function loadSessions() {
            $.post(CONFIG.apiEndpoint, {
                action: 'get_sessions',
                csrf_token: CONFIG.csrfToken
            }, function(data) {
                if (data.success) renderSessions(data.sessions);
            }, 'json').fail(function() {
                $('#session-list').html('<div class="text-center text-gray-500 text-sm mt-4 p-4">Failed to load sessions</div>');
            });
        }

        function renderSessions(sessions) {
            let html = '';
            if (!sessions || sessions.length === 0) {
                html = '<div class="text-center text-gray-500 text-sm mt-4 p-4">No previous chats.<br>Start a new conversation!</div>';
            } else {
                sessions.forEach(s => {
                    const isActive = s.session_id === currentSessionId;
                    const date = new Date(s.updated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    html += `
                        <div class="session-item ${isActive ? 'active' : ''}" data-session="${escapeHtml(s.session_id)}">
                            <div class="session-title">${escapeHtml(s.title || 'New Chat')}</div>
                            <div class="session-time">${date}</div>
                            <div class="session-menu">
                                <button onclick="event.stopPropagation(); deleteSession('${escapeHtml(s.session_id)}')" class="text-red-400 hover:text-red-600 p-1">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
            }
            $('#session-list').html(html);
            $('.session-item').click(function() {
                const sid = $(this).data('session');
                if (sid !== currentSessionId) switchSession(sid);
            });
        }

        function switchSession(sid) {
            currentSessionId = sid;
            const url = new URL(window.location);
            url.searchParams.set('session', sid);
            window.history.pushState({}, '', url);
            $.post(CONFIG.apiEndpoint, {
                action: 'get_session_messages',
                csrf_token: CONFIG.csrfToken,
                session_id: sid
            }, function(data) {
                if (data.success) {
                    renderMessages(data.messages);
                    loadSessions();
                }
            }, 'json');
        }

        function renderMessages(messages) {
            let html = '';
            if (!messages || messages.length === 0) {
                html = `
                    <div class="welcome-banner">
                        <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-red-600 rounded-2xl mx-auto mb-4 flex items-center justify-center text-white text-3xl">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h2 class="text-2xl font-bold mb-2">New Chat</h2>
                        <p class="text-gray-600 mb-4">Start a new conversation with EduBot.</p>
                    </div>
                `;
            } else {
                messages.forEach(msg => {
                    const time = new Date(msg.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
                    html += `
                        <div class="message user">
                            <div class="message-bubble">
                                <div class="font-semibold mb-1 text-sm opacity-90">You</div>
                                ${escapeHtml(msg.prompt)}
                            </div>
                            <div class="message-meta justify-end"><span>${time}</span></div>
                        </div>
                        <div class="message ai">
                            <div class="message-bubble markdown-content" id="msg-${msg.id}">
                                <div class="font-semibold mb-1 text-sm text-orange-600">EduBot</div>
                                <div class="response-text">${escapeHtml(msg.response)}</div>
                            </div>
                            <div class="message-meta">
                                <button onclick="copyMessage('msg-${msg.id}')" class="text-xs hover:text-orange-600 transition">
                                    <i class="fas fa-copy mr-1"></i> Copy
                                </button>
                                <span>${time}</span>
                            </div>
                        </div>
                    `;
                });
            }
            $('#chat-messages').html(html);
            initMarkdown();
            scrollToBottom();
        }

        function startNewChat() {
            $.post(CONFIG.apiEndpoint, {
                action: 'clear_history',
                csrf_token: CONFIG.csrfToken,
                session_id: currentSessionId
            }, function(data) {
                if (data.success) {
                    currentSessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    $('#chat-messages').html(`
                        <div class="welcome-banner">
                            <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-red-600 rounded-2xl mx-auto mb-4 flex items-center justify-center text-white text-3xl">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <h2 class="text-2xl font-bold mb-2">New Chat Started</h2>
                            <p class="text-gray-600 mb-4">Ask me anything!</p>
                            <div class="suggestion-chips">
                                <div class="suggestion-chip" onclick="setPrompt('Explain photosynthesis')">Explain photosynthesis</div>
                                <div class="suggestion-chip" onclick="setPrompt('Solve 2x + 5 = 15')">Solve math</div>
                                <div class="suggestion-chip" onclick="setPrompt('Quiz me on WWII')">Quiz me</div>
                            </div>
                        </div>
                    `);
                    loadSessions();
                    showAlert('New chat started', 'success');
                }
            }, 'json');
        }

        function clearCurrentSession() {
            if (confirm('Clear all messages in this session? This cannot be undone.')) startNewChat();
        }

        function deleteSession(sid) {
            if (!confirm('Delete this chat session permanently?')) return;
            $.post(CONFIG.apiEndpoint, {
                action: 'delete_session',
                csrf_token: CONFIG.csrfToken,
                session_id: sid
            }, function(data) {
                if (data.success) {
                    if (sid === currentSessionId) startNewChat();
                    else loadSessions();
                    showAlert('Session deleted', 'success');
                }
            }, 'json');
        }

        function handleKeyDown(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }

        function scrollToBottom() {
            const el = document.getElementById('chat-messages');
            el.scrollTop = el.scrollHeight;
        }

        function setPrompt(text) { $('#prompt-input').val(text).focus().trigger('input'); }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function copyMessage(id) {
            const text = $('#' + id).text();
            navigator.clipboard.writeText(text).then(() => {
                showAlert('Copied to clipboard!', 'success');
            }).catch(() => {
                showAlert('Failed to copy', 'error');
            });
        }

        function copyCode(btn) {
            const code = $(btn).siblings('code').text();
            navigator.clipboard.writeText(code).then(() => {
                const original = $(btn).html();
                $(btn).html('<i class="fas fa-check"></i> Copied');
                setTimeout(() => $(btn).html(original), 2000);
            });
        }

        function showAlert(message, type) {
            const id = 'alert-' + Date.now();
            const icon = type === 'success' ? 'fa-check-circle' : type === 'warning' ? 'fa-exclamation-triangle' : type === 'info' ? 'fa-info-circle' : 'fa-times-circle';
            $('#alert-container').append(`
                <div class="alert-toast ${type}" id="${id}">
                    <div class="flex items-center gap-2">
                        <i class="fas ${icon}"></i>
                        <span>${message}</span>
                        <button onclick="$('#${id}').remove()" class="ml-2 opacity-75 hover:opacity-100">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `);
            setTimeout(() => {
                $('#' + id).fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        }

        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('edubot_dark_mode', isDark);
            $('#hljs-theme').attr('href', isDark 
                ? 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/github-dark.min.css'
                : 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/github.min.css'
            );
        }

        function exportChat() {
            window.open(`${CONFIG.apiEndpoint}?action=export_chat&csrf_token=${CONFIG.csrfToken}`, '_blank');
        }

        function updateRateLimit(info) {
            $('#rate-limit-info').text(`• ${info.remaining}/${EDUBOT_RATE_LIMIT_HOURLY} left this hour`);
        }

        function saveDraft() {
            localStorage.setItem('edubot_draft_' + currentSessionId, $('#prompt-input').val());
        }

        function restoreDraft() {
            const draft = localStorage.getItem('edubot_draft_' + currentSessionId);
            if (draft) $('#prompt-input').val(draft).trigger('input');
        }

        function toggleSidebar() {
            $('#sidebar').toggleClass('mobile-open');
        }
    </script>
</body>
</html>
<?php
if (isset($conn)) $conn->close();
ob_end_flush();

// ==================== BACKEND FUNCTIONS ====================

function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode($data);
    exit;
}

function edubot_init_tables($conn) {
    $tables = [
        'csrf_tokens' => "CREATE TABLE IF NOT EXISTS csrf_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_user_token (user_id, token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'learning_history' => "CREATE TABLE IF NOT EXISTS learning_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            prompt TEXT,
            response LONGTEXT,
            subject_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            session_id VARCHAR(64),
            model VARCHAR(50) DEFAULT 'gemini-1.5-flash',
            tokens_used INT DEFAULT 0,
            INDEX idx_user_session (user_id, session_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'ai_chat_sessions' => "CREATE TABLE IF NOT EXISTS ai_chat_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            title VARCHAR(255) DEFAULT 'New Chat',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            message_count INT DEFAULT 0,
            UNIQUE KEY uk_session (user_id, session_id),
            INDEX idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'ai_file_attachments' => "CREATE TABLE IF NOT EXISTS ai_file_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            chat_id INT NOT NULL,
            filename VARCHAR(255),
            filepath VARCHAR(500),
            mime_type VARCHAR(100),
            file_size INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_chat (chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'ai_usage_limits' => "CREATE TABLE IF NOT EXISTS ai_usage_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_count INT DEFAULT 0,
            window_start DATETIME NOT NULL,
            UNIQUE KEY uk_user_window (user_id, window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($tables as $name => $sql) {
        if (!$conn->query($sql)) {
            error_log("Edubot: Failed to create table $name: " . $conn->error);
        }
    }

    // Cleanup old tokens
    $conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW() - INTERVAL 24 HOUR");
}

function edubot_get_csrf_token($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $token, $expires);
        $stmt->execute();
        $stmt->close();
    }
    return $token;
}

function edubot_verify_csrf($conn, $user_id, $token) {
    if (empty($token)) return false;
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    if (!$stmt) return false;
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result->num_rows > 0;
    $stmt->close();
    return $valid;
}

function edubot_check_rate_limit($conn, $user_id) {
    $window = date('Y-m-d H:00:00');
    $stmt = $conn->prepare("SELECT request_count FROM ai_usage_limits WHERE user_id = ? AND window_start = ?");
    $stmt->bind_param("is", $user_id, $window);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $count = $row ? (int)$row['request_count'] : 0;
    $limit = EDUBOT_RATE_LIMIT_HOURLY;

    if ($count >= $limit) {
        return ['allowed' => false, 'remaining' => 0, 'reset' => date('H:i:s', strtotime('+1 hour'))];
    }

    $stmt = $conn->prepare("INSERT INTO ai_usage_limits (user_id, request_count, window_start) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE request_count = request_count + 1");
    $stmt->bind_param("is", $user_id, $window);
    $stmt->execute();
    $stmt->close();

    return ['allowed' => true, 'remaining' => $limit - $count - 1, 'reset' => date('H:i:s', strtotime('+1 hour'))];
}

function edubot_build_context($conn, $user_id) {
    $context = "";
    $name = 'Student';

    $stmt = $conn->prepare("SELECT student_name FROM students WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) $name = $row['student_name'] ?: 'Student';
        $stmt->close();
    }
    if ($name === 'Student') {
        $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) $name = $row['name'] ?: 'Student';
            $stmt->close();
        }
    }

    $context .= "Student Name: $name\nToday: " . date('Y-m-d') . "\n\n";

    // Grades
    $stmt = $conn->prepare("SELECT g.score, g.total, s.subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.user_id = ? ORDER BY g.created_at DESC LIMIT 5");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $context .= "Recent Grades:\n";
            while ($row = $result->fetch_assoc()) {
                $pct = $row['total'] ? round(($row['score']/$row['total'])*100, 1) : 0;
                $context .= "- {$row['subject_name']}: {$row['score']}/{$row['total']} ($pct%)\n";
            }
            $context .= "\n";
        }
        $stmt->close();
    }

    // Quizzes
    $stmt = $conn->prepare("SELECT q.title, qs.score, qs.total_score, s.subject_name FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id = q.id JOIN subjects s ON q.subject_id = s.id WHERE qs.user_id = ? ORDER BY qs.submitted_at DESC LIMIT 5");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $context .= "Recent Quizzes:\n";
            while ($row = $result->fetch_assoc()) {
                $pct = $row['total_score'] ? round(($row['score']/$row['total_score'])*100, 1) : 0;
                $context .= "- {$row['title']} ({$row['subject_name']}): {$row['score']}/{$row['total_score']} ($pct%)\n";
            }
            $context .= "\n";
        }
        $stmt->close();
    }

    // Assignments
    $stmt = $conn->prepare("SELECT a.title, a.due_date, a_s.score, a_s.feedback, s.subject_name FROM assignment_submissions a_s JOIN assignments a ON a_s.assignment_id = a.id JOIN subjects s ON a.subject_id = s.id WHERE a_s.user_id = ? ORDER BY a_s.submission_date DESC LIMIT 5");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $context .= "Recent Assignments:\n";
            while ($row = $result->fetch_assoc()) {
                $status = $row['score'] !== null ? "Graded: {$row['score']}" : "Pending";
                $fb = $row['feedback'] ? "Note: {$row['feedback']}" : "";
                $context .= "- {$row['title']} ({$row['subject_name']}): $status $fb\n";
            }
            $context .= "\n";
        }
        $stmt->close();
    }

    // Badges
    $stmt = $conn->prepare("SELECT sp.points_earned, b.name AS badge_name FROM student_progress sp LEFT JOIN badges b ON sp.badge_id = b.id WHERE sp.user_id = ? ORDER BY sp.created_at DESC LIMIT 5");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $context .= "Achievements:\n";
            while ($row = $result->fetch_assoc()) {
                $parts = [];
                if ($row['badge_name']) $parts[] = "Badge: {$row['badge_name']}";
                if ($row['points_earned']) $parts[] = "+{$row['points_earned']} pts";
                if ($parts) $context .= "- " . implode(", ", $parts) . "\n";
            }
            $context .= "\n";
        }
        $stmt->close();
    }

    return [$context, $name];
}

function edubot_get_conversation_history($conn, $user_id, $session_id, $limit = 6) {
    $messages = [];
    $stmt = $conn->prepare("SELECT prompt, response FROM learning_history WHERE user_id = ? AND session_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("isi", $user_id, $session_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $messages[] = $row;
    $stmt->close();
    return array_reverse($messages);
}

function edubot_handle_send_message($conn, $user_id, $session_id) {
    header('Content-Type: application/json');
    ob_clean();

    try {
        // Rate limit
        $rate = edubot_check_rate_limit($conn, $user_id);
        if (!$rate['allowed']) {
            json_response([
                'error' => 'Rate limit reached (' . EDUBOT_RATE_LIMIT_HOURLY . '/hour). Resets at ' . $rate['reset'] . '.',
                'rate_limit' => $rate
            ], 429);
        }

        // Validate prompt
        $prompt = isset($_POST['prompt']) ? trim($_POST['prompt']) : '';
        if (empty($prompt) && empty($_FILES['attachments'])) {
            json_response(['error' => 'Please enter a question or attach a file.'], 400);
        }
        if (strlen($prompt) > EDUBOT_MAX_PROMPT_LENGTH) {
            json_response(['error' => 'Question too long (max ' . EDUBOT_MAX_PROMPT_LENGTH . ' chars).'], 400);
        }
        $prompt = strip_tags($prompt);

        // Context
        list($context, $student_name) = edubot_build_context($conn, $user_id);

        // History
        $history = edubot_get_conversation_history($conn, $user_id, $session_id, 6);
        $history_str = "";
        if ($history) {
            $history_str = "Recent Conversation:\n";
            foreach ($history as $h) {
                $history_str .= "Student: {$h['prompt']}\nEduBot: {$h['response']}\n\n";
            }
        }

        // File attachments
        $attachment_context = "";
        $uploaded_files = [];
        if (!empty($_FILES['attachments'])) {
            $files = $_FILES['attachments'];
            $file_count = is_array($files['name']) ? count($files['name']) : 1;
            for ($i = 0; $i < $file_count; $i++) {
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                if ($error !== UPLOAD_ERR_OK) continue;

                $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $type = is_array($files['type']) ? $files['type'][$i] : $files['type'];
                $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];

                if ($size > EDUBOT_MAX_FILE_SIZE) continue;
                if (!in_array($type, EDUBOT_ALLOWED_MIME_TYPES)) continue;

                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/edubot/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
                $dest = $upload_dir . $filename;

                if (move_uploaded_file($tmp, $dest)) {
                    $uploaded_files[] = ['path' => $dest, 'name' => $name, 'type' => $type];
                    $attachment_context .= "[Attached: $name ($type)]\n";
                }
            }
        }

        // Build prompt
        $system = "You are EduBot, an expert AI tutor for QueensPa School. Help students with clear, accurate, step-by-step explanations. Use markdown. Be encouraging but concise. If unsure, say so. Always suggest a follow-up question.";
        $full_prompt = "$system\n\n$context\n$history_str";
        if ($attachment_context) $full_prompt .= "Attachments:\n$attachment_context\n";
        $full_prompt .= "Student Question: $prompt\n\nProvide a helpful educational response.";

        // API Key check
        $api_key = EDUBOT_API_KEY;
        if (empty($api_key) || strpos($api_key, 'YOUR_') !== false) {
            json_response(['error' => 'AI service not configured. Contact administrator.'], 500);
        }

        // Call Gemini
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $api_key;
        $parts = [['text' => $full_prompt]];

        // Add images for multimodal
        foreach ($uploaded_files as $file) {
            if (strpos($file['type'], 'image/') === 0) {
                $img_data = base64_encode(file_get_contents($file['path']));
                $parts[] = ['inlineData' => ['mimeType' => $file['type'], 'data' => $img_data]];
            }
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048,
                'topP' => 0.9,
                'topK' => 40
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE']
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            error_log("Edubot: CURL error: $curl_err");
            json_response(['error' => 'Network error connecting to AI service.'], 502);
        }
        if ($http_code !== 200) {
            error_log("Edubot: Gemini HTTP $http_code: $response");
            json_response(['error' => 'AI service error. Please try again.'], 502);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Edubot: JSON error: " . json_last_error_msg());
            json_response(['error' => 'Invalid AI response format.'], 502);
        }

        if (isset($data['promptFeedback']['blockReason'])) {
            json_response(['error' => 'Blocked by safety filters: ' . $data['promptFeedback']['blockReason']], 400);
        }
        if (empty($data['candidates'][0]['content']['parts'][0]['text'])) {
            error_log("Edubot: Empty AI response");
            json_response(['error' => 'AI returned empty response. Please rephrase.'], 502);
        }

        $ai_text = trim($data['candidates'][0]['content']['parts'][0]['text']);

        // Save to DB
        $stmt = $conn->prepare("INSERT INTO learning_history (user_id, prompt, response, session_id, model) VALUES (?, ?, ?, ?, 'gemini-1.5-flash')");
        $stmt->bind_param("isss", $user_id, $prompt, $ai_text, $session_id);
        $stmt->execute();
        $insert_id = $conn->insert_id;
        $stmt->close();

        // Update session metadata
        $stmt = $conn->prepare("INSERT INTO ai_chat_sessions (user_id, session_id, title, message_count) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE updated_at = NOW(), message_count = message_count + 1");
        $title = substr($prompt, 0, 50) . (strlen($prompt) > 50 ? '...' : '');
        $stmt->bind_param("iss", $user_id, $session_id, $title);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'AI Chat', 'Sent message to EduBot');

        // Save attachment metadata
        foreach ($uploaded_files as $file) {
            $stmt = $conn->prepare("INSERT INTO ai_file_attachments (user_id, chat_id, filename, filepath, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)");
            $fsize = filesize($file['path']);
            $stmt->bind_param("iisssi", $user_id, $insert_id, $file['name'], $file['path'], $file['type'], $fsize);
            $stmt->execute();
            $stmt->close();
        }

        json_response([
            'success' => true,
            'id' => $insert_id,
            'prompt' => $prompt,
            'response' => $ai_text,
            'created_at' => date('Y-m-d H:i:s'),
            'rate_limit' => $rate
        ]);

    } catch (Exception $e) {
        error_log("Edubot send_message exception: " . $e->getMessage());
        json_response(['error' => 'Internal error: ' . $e->getMessage()], 500);
    }
}

function edubot_handle_clear_history($conn, $user_id, $session_id) {
    header('Content-Type: application/json');
    ob_clean();
    try {
        $stmt = $conn->prepare("DELETE FROM learning_history WHERE user_id = ? AND session_id = ?");
        $stmt->bind_param("is", $user_id, $session_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM ai_chat_sessions WHERE user_id = ? AND session_id = ?");
        $stmt->bind_param("is", $user_id, $session_id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $user_id, 'Clear AI History', "Deleted session $session_id ($deleted rows)");
        json_response(['success' => true, 'message' => "Cleared $deleted messages.", 'deleted' => $deleted]);
    } catch (Exception $e) {
        error_log("Edubot clear_history: " . $e->getMessage());
        json_response(['error' => 'Failed to clear history.'], 500);
    }
}

function edubot_handle_get_sessions($conn, $user_id) {
    header('Content-Type: application/json');
    ob_clean();
    $sessions = [];
    $stmt = $conn->prepare("SELECT session_id, title, message_count, updated_at FROM ai_chat_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $sessions[] = $row;
    $stmt->close();
    json_response(['success' => true, 'sessions' => $sessions]);
}

function edubot_handle_get_session_messages($conn, $user_id) {
    header('Content-Type: application/json');
    ob_clean();
    $target = $_POST['session_id'] ?? '';
    if (empty($target)) json_response(['error' => 'Session ID required'], 400);

    $messages = [];
    $stmt = $conn->prepare("SELECT id, prompt, response, created_at FROM learning_history WHERE user_id = ? AND session_id = ? ORDER BY created_at ASC LIMIT 100");
    $stmt->bind_param("is", $user_id, $target);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $messages[] = $row;
    $stmt->close();
    json_response(['success' => true, 'messages' => $messages]);
}

function edubot_handle_delete_session($conn, $user_id) {
    header('Content-Type: application/json');
    ob_clean();
    $target = $_POST['session_id'] ?? '';
    if (empty($target)) json_response(['error' => 'Session ID required'], 400);

    $stmt = $conn->prepare("DELETE FROM learning_history WHERE user_id = ? AND session_id = ?");
    $stmt->bind_param("is", $user_id, $target);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM ai_chat_sessions WHERE user_id = ? AND session_id = ?");
    $stmt->bind_param("is", $user_id, $target);
    $stmt->execute();
    $stmt->close();

    json_response(['success' => true]);
}

function edubot_handle_rename_session($conn, $user_id) {
    header('Content-Type: application/json');
    ob_clean();
    $target = $_POST['session_id'] ?? '';
    $title = $_POST['title'] ?? '';
    if (empty($target) || empty($title)) json_response(['error' => 'Missing data'], 400);

    $stmt = $conn->prepare("UPDATE ai_chat_sessions SET title = ? WHERE user_id = ? AND session_id = ?");
    $stmt->bind_param("sis", $title, $user_id, $target);
    $stmt->execute();
    $stmt->close();
    json_response(['success' => true]);
}

function edubot_handle_upload_file($conn, $user_id) {
    header('Content-Type: application/json');
    ob_clean();
    if (empty($_FILES['file'])) json_response(['error' => 'No file uploaded'], 400);

    $file = $_FILES['file'];
    if ($file['size'] > EDUBOT_MAX_FILE_SIZE) json_response(['error' => 'File too large (max 5MB)'], 400);
    if (!in_array($file['type'], EDUBOT_ALLOWED_MIME_TYPES)) json_response(['error' => 'Invalid file type'], 400);

    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/edubot/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
    $dest = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_response(['error' => 'Failed to save file'], 500);
    }

    json_response(['success' => true, 'filename' => $filename, 'url' => '/uploads/edubot/' . $filename]);
}

function edubot_handle_export_chat($conn, $user_id) {
    $format = $_GET['format'] ?? 'txt';
    $stmt = $conn->prepare("SELECT lh.prompt, lh.response, lh.created_at, s.title FROM learning_history lh LEFT JOIN ai_chat_sessions s ON lh.session_id = s.session_id WHERE lh.user_id = ? ORDER BY lh.created_at ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="edubot_export.json"');
        $data = [];
        while ($row = $result->fetch_assoc()) $data[] = $row;
        echo json_encode($data, JSON_PRETTY_PRINT);
    } else {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="edubot_export.txt"');
        echo "EduBot Chat Export - QueensPa School\nGenerated: " . date('Y-m-d H:i:s') . "\nUser: $user_id\n" . str_repeat("=", 60) . "\n\n";
        while ($row = $result->fetch_assoc()) {
            echo "[" . $row['created_at'] . "] " . ($row['title'] ?: 'Chat') . "\nStudent: " . $row['prompt'] . "\nEduBot: " . $row['response'] . "\n\n";
        }
    }
    $stmt->close();
    exit;
}

function edubot_load_page_data($conn, $user_id, $session_id) {
    $data = ['student_name' => 'Student', 'achievements' => [], 'current_session_messages' => []];

    $stmt = $conn->prepare("SELECT student_name FROM students WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) $data['student_name'] = $row['student_name'] ?: 'Student';
        $stmt->close();
    }
    if ($data['student_name'] === 'Student') {
        $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) $data['student_name'] = $row['name'] ?: 'Student';
            $stmt->close();
        }
    }

    $stmt = $conn->prepare("SELECT sp.points_earned, b.name AS badge_name FROM student_progress sp LEFT JOIN badges b ON sp.badge_id = b.id WHERE sp.user_id = ? ORDER BY sp.created_at DESC LIMIT 5");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $data['achievements'][] = $row;
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT id, prompt, response, created_at FROM learning_history WHERE user_id = ? AND session_id = ? ORDER BY created_at ASC LIMIT 50");
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $data['current_session_messages'][] = $row;
        $stmt->close();
    }

    return $data;
}