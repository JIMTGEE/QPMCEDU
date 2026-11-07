<?php
ob_start();
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Ensure logging directory exists
$log_dir = $_SERVER['DOCUMENT_ROOT'] . '/logs/';
$log_file = $log_dir . 'auth_errors.log';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
if (!is_writable($log_dir)) {
    $log_file = sys_get_temp_dir() . '/qpmseduc_auth_errors.log';
}

// Check user authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Authentication failed: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset') . "\n", FILE_APPEND);
    header("Location: /login.php?error=unauthenticated");
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Generate or reuse CSRF token
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Custom sanitization function
function sanitize_string($input) {
    if (!is_string($input)) {
        return '';
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Fetch current academic year and term
$academic_year = '2024/2025';
$term_query = $conn->prepare("SELECT id, term_name, start_date, end_date FROM academic_calendar WHERE year = ? AND start_date <= CURDATE() AND end_date >= CURDATE()");
$term_query->bind_param("s", $academic_year);
$term_query->execute();
$term_result = $term_query->get_result();
$current_term = $term_result->fetch_assoc() ?: ['id' => null, 'term_name' => 'No Active Term'];
$term_query->close();

// Handle join session via token
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['join_session'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    
    $session_token = sanitize_string($_POST['session_token'] ?? '');
    if (!$session_token || strlen($session_token) > 64) {
        echo json_encode(['error' => 'Invalid session token']);
        exit;
    }
    
    // Check for class chat sessions
    $stmt = $conn->prepare("SELECT cs.id, cs.status, cs.class_id, c.class_name 
                            FROM chat_sessions cs 
                            JOIN classes c ON cs.class_id = c.id
                            WHERE cs.session_token = ? AND cs.status = 'active'");
    $stmt->bind_param("s", $session_token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if student is enrolled in this class
        $class_check = $conn->prepare("SELECT id FROM student_classes WHERE user_id = ? AND class_id = ?");
        $class_check->bind_param("ii", $user_id, $row['class_id']);
        $class_check->execute();
        $enrolled = $class_check->get_result()->fetch_assoc();
        $class_check->close();
        
        if ($enrolled) {
            echo json_encode(['success' => true, 'session_id' => $row['id'], 'session_type' => 'class']);
            exit;
        } else {
            echo json_encode(['error' => 'You are not enrolled in this class']);
            exit;
        }
    }
    $stmt->close();
    
    // Check for discussion groups
    $stmt = $conn->prepare("SELECT cs.id, cs.status 
                            FROM chat_sessions cs 
                            WHERE cs.session_token = ? AND cs.class_id IS NULL AND cs.parent_id IS NULL AND cs.recipient_id IS NULL AND cs.status = 'active'");
    $stmt->bind_param("s", $session_token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if student is a participant in this group
        $participant_check = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
        $participant_check->bind_param("ii", $row['id'], $user_id);
        $participant_check->execute();
        $is_participant = $participant_check->get_result()->fetch_assoc();
        $participant_check->close();
        
        if ($is_participant) {
            echo json_encode(['success' => true, 'session_id' => $row['id'], 'session_type' => 'discussion']);
            exit;
        } else {
            echo json_encode(['error' => 'You are not a member of this group']);
            exit;
        }
    }
    $stmt->close();
    
    echo json_encode(['error' => 'Invalid or inaccessible session token']);
    exit;
}

// Handle private chat access via token
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['access_private_chat'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    
    $session_token = sanitize_string($_POST['session_token'] ?? '');
    if (!$session_token || strlen($session_token) > 64) {
        echo json_encode(['error' => 'Invalid session token']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT id, status FROM private_chat_sessions WHERE session_token = ? AND (sender_id = ? OR recipient_id = ?) AND status = 'active'");
    $stmt->bind_param("sii", $session_token, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'session_id' => $row['id'], 'session_type' => 'private']);
        exit;
    }
    $stmt->close();
    
    echo json_encode(['error' => 'Invalid private chat token']);
    exit;
}

// Handle message submission via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    
    $session_id = filter_input(INPUT_POST, 'chat_session_id', FILTER_VALIDATE_INT);
    $is_private = isset($_POST['is_private']) ? (int)$_POST['is_private'] : 0;
    $message = sanitize_string($_POST['message'] ?? '');
    
    if (!$session_id) {
        echo json_encode(['error' => 'Invalid session']);
        exit;
    }
    
    // Verify student access based on session type
    $has_access = false;
    
    if ($is_private) {
        // Check private chat access
        $stmt = $conn->prepare("SELECT id FROM private_chat_sessions WHERE id = ? AND (sender_id = ? OR recipient_id = ?) AND status = 'active'");
        $stmt->bind_param("iii", $session_id, $user_id, $user_id);
        $stmt->execute();
        $has_access = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        // Check group chat access (class or discussion)
        $stmt = $conn->prepare("SELECT cs.id, cs.status, cs.class_id 
                                FROM chat_sessions cs 
                                WHERE cs.id = ? AND cs.status = 'active'");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($session) {
            if ($session['class_id']) {
                // Class chat - check enrollment
                $stmt = $conn->prepare("SELECT id FROM student_classes WHERE user_id = ? AND class_id = ?");
                $stmt->bind_param("ii", $user_id, $session['class_id']);
                $stmt->execute();
                $has_access = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } else {
                // Discussion group - check participation
                $stmt = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $session_id, $user_id);
                $stmt->execute();
                $has_access = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }
    }
    
    if (!$has_access) {
        echo json_encode(['error' => 'You cannot send messages in this session']);
        exit;
    }
    
    $media_path = null;
    if (!empty($_FILES['message_media']['name']) && $_FILES['message_media']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/chat_media/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_ext = strtolower(pathinfo($_FILES['message_media']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'pdf', 'doc', 'docx'];
        if (!in_array($file_ext, $allowed_exts) || $_FILES['message_media']['size'] > 10 * 1024 * 1024) {
            echo json_encode(['error' => 'Invalid file type or size (max 10MB)']);
            exit;
        }
        $filename = 'msg_' . time() . '_' . uniqid() . '.' . $file_ext;
        $full_path = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['message_media']['tmp_name'], $full_path)) {
            $media_path = 'uploads/chat_media/' . $filename;
        } else {
            echo json_encode(['error' => 'Failed to upload media']);
            exit;
        }
    }
    
    if (!$message && !$media_path) {
        echo json_encode(['error' => 'Empty message']);
        exit;
    }
    
    if ($is_private) {
        $stmt = $conn->prepare("INSERT INTO private_chat_messages (chat_session_id, user_id, message, message_media, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiss", $session_id, $user_id, $message, $media_path);
    } else {
        $stmt = $conn->prepare("INSERT INTO chat_messages (chat_session_id, user_id, message, message_media, is_active, last_activity, created_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->bind_param("iiss", $session_id, $user_id, $message, $media_path);
    }
    
    if ($stmt->execute()) {
        // Update session last activity
        if (!$is_private) {
            $update = $conn->prepare("UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?");
            $update->bind_param("i", $session_id);
            $update->execute();
            $update->close();
        }
        echo json_encode(['success' => true, 'message_id' => $stmt->insert_id]);
    } else {
        echo json_encode(['error' => 'Failed to send message: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

// ============================================================
// FETCH CHATS - UPDATED to include all teacher-created chats
// ============================================================
$chat_sessions = [];

// 1. Fetch class group chats (from teachers)
$stmt = $conn->prepare("
    SELECT cs.id, cs.title, cs.status, cs.start_time, cs.end_time, cs.session_token, cs.group_picture, 
           c.class_name, 'class' AS chat_type,
           (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id = cs.id) AS message_count,
           (SELECT message FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
           (SELECT created_at FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
           (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id = cs.id AND user_id != ? AND read_status = 'unread') AS unread,
           (SELECT name FROM users WHERE id = cs.teacher_id) AS teacher_name
    FROM chat_sessions cs 
    JOIN student_classes sc ON cs.class_id = sc.class_id 
    JOIN classes c ON cs.class_id = c.id 
    WHERE sc.user_id = ? 
      AND cs.term_id = ? 
      AND cs.status IN ('active', 'closed')
      AND cs.class_id IS NOT NULL
    ORDER BY cs.updated_at DESC, cs.created_at DESC
");
$stmt->bind_param("iii", $user_id, $user_id, $current_term['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $chat_sessions[] = $row;
}
$stmt->close();

// 2. Fetch discussion groups (custom groups where student is participant)
$stmt = $conn->prepare("
    SELECT cs.id, cs.title, cs.status, cs.start_time, cs.end_time, cs.session_token, cs.group_picture,
           'discussion' AS chat_type, NULL AS class_name,
           (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id = cs.id) AS message_count,
           (SELECT message FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
           (SELECT created_at FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
           (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id = cs.id AND user_id != ? AND read_status = 'unread') AS unread,
           (SELECT name FROM users WHERE id = cs.teacher_id) AS teacher_name,
           (SELECT COUNT(*) FROM chat_participants WHERE chat_session_id = cs.id) AS member_count
    FROM chat_sessions cs
    JOIN chat_participants cp ON cs.id = cp.chat_session_id
    WHERE cp.user_id = ?
      AND cs.class_id IS NULL 
      AND cs.parent_id IS NULL 
      AND cs.recipient_id IS NULL
      AND cs.status IN ('active', 'closed')
    ORDER BY cs.updated_at DESC, cs.created_at DESC
");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['display_title'] = $row['title'];
    $chat_sessions[] = $row;
}
$stmt->close();

// 3. Fetch private chats (1-on-1 with teachers)
// FIXED: Corrected bind_param for private chats - 5 parameters needed
$stmt = $conn->prepare("
    SELECT ps.id, ps.title, ps.status, ps.start_time, ps.session_token,
           'private' AS chat_type,
           CASE WHEN ps.sender_id = ? THEN u2.name ELSE u1.name END AS teacher_name,
           CASE WHEN ps.sender_id = ? THEN u2.profile_picture ELSE u1.profile_picture END AS teacher_pic,
           (SELECT COUNT(*) FROM private_chat_messages WHERE chat_session_id = ps.id) AS message_count,
           (SELECT message FROM private_chat_messages WHERE chat_session_id = ps.id ORDER BY created_at DESC LIMIT 1) AS last_message,
           (SELECT created_at FROM private_chat_messages WHERE chat_session_id = ps.id ORDER BY created_at DESC LIMIT 1) AS last_time,
           (SELECT COUNT(*) FROM private_chat_messages WHERE chat_session_id = ps.id AND user_id != ? AND read_status = 'unread') AS unread
    FROM private_chat_sessions ps
    LEFT JOIN users u1 ON u1.id = ps.sender_id
    LEFT JOIN users u2 ON u2.id = ps.recipient_id
    WHERE (ps.sender_id = ? OR ps.recipient_id = ?)
      AND ps.status = 'active'
    ORDER BY last_time DESC
");
// Parameters: user_id (for sender case), user_id (for recipient case), user_id (for unread count), user_id (for WHERE sender), user_id (for WHERE recipient)
$stmt->bind_param("iiiii", $user_id, $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['display_title'] = $row['teacher_name'] ? 'Chat with ' . $row['teacher_name'] : $row['title'];
    $row['class_name'] = $row['teacher_name'] ? 'Teacher: ' . $row['teacher_name'] : 'Private Chat';
    $chat_sessions[] = $row;
}
$stmt->close();

// Sort all chats by last_time (most recent first)
usort($chat_sessions, function($a, $b) {
    $timeA = strtotime($a['last_time'] ?? '1970-01-01');
    $timeB = strtotime($b['last_time'] ?? '1970-01-01');
    return $timeB - $timeA;
});

// Get current session if selected
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : (count($chat_sessions) > 0 ? $chat_sessions[0]['id'] : null);
$session_data = null;
$messages = [];
$is_private_session = false;

if ($session_id) {
    // Check if it's a private session first
    $check_private = $conn->prepare("SELECT id FROM private_chat_sessions WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
    $check_private->bind_param("iii", $session_id, $user_id, $user_id);
    $check_private->execute();
    $is_private_session = $check_private->get_result()->fetch_assoc();
    $check_private->close();
    
    if ($is_private_session) {
        // Fetch private chat session - FIXED: 5 parameters needed
        $stmt = $conn->prepare("
            SELECT ps.*, 
                   CASE WHEN ps.sender_id = ? THEN u2.name ELSE u1.name END AS teacher_name,
                   CASE WHEN ps.sender_id = ? THEN u2.profile_picture ELSE u1.profile_picture END AS teacher_pic
            FROM private_chat_sessions ps
            LEFT JOIN users u1 ON u1.id = ps.sender_id
            LEFT JOIN users u2 ON u2.id = ps.recipient_id
            WHERE ps.id = ? AND (ps.sender_id = ? OR ps.recipient_id = ?)
        ");
        $stmt->bind_param("iiiii", $user_id, $user_id, $session_id, $user_id, $user_id);
        $stmt->execute();
        $session_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($session_data) {
            // Fetch private messages
            $stmt = $conn->prepare("
                SELECT pm.*, u.name, u.role, u.profile_picture 
                FROM private_chat_messages pm 
                JOIN users u ON pm.user_id = u.id 
                WHERE pm.chat_session_id = ? 
                ORDER BY pm.created_at ASC LIMIT 200
            ");
            $stmt->bind_param("i", $session_id);
            $stmt->execute();
            $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            // Mark private messages as read
            $stmt = $conn->prepare("UPDATE private_chat_messages SET read_status = 'read' WHERE chat_session_id = ? AND user_id != ? AND read_status = 'unread'");
            $stmt->bind_param("ii", $session_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // Fetch group chat session (class or discussion)
        $stmt = $conn->prepare("
            SELECT cs.*, c.class_name, 
                   (SELECT name FROM users WHERE id = cs.teacher_id) AS teacher_name
            FROM chat_sessions cs 
            LEFT JOIN classes c ON cs.class_id = c.id
            WHERE cs.id = ?
        ");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $session_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($session_data) {
            // Verify student access
            $has_access = false;
            if ($session_data['class_id']) {
                $stmt = $conn->prepare("SELECT id FROM student_classes WHERE user_id = ? AND class_id = ?");
                $stmt->bind_param("ii", $user_id, $session_data['class_id']);
                $stmt->execute();
                $has_access = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $session_id, $user_id);
                $stmt->execute();
                $has_access = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            
            if ($has_access) {
                // Fetch messages with user info
                $stmt = $conn->prepare("
                    SELECT cm.*, u.name, u.role, u.profile_picture 
                    FROM chat_messages cm 
                    JOIN users u ON cm.user_id = u.id 
                    WHERE cm.chat_session_id = ? 
                    ORDER BY cm.created_at ASC LIMIT 200
                ");
                $stmt->bind_param("i", $session_id);
                $stmt->execute();
                $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                // Mark messages as read
                $stmt = $conn->prepare("UPDATE chat_messages SET read_status = 'read' WHERE chat_session_id = ? AND user_id != ? AND read_status = 'unread'");
                $stmt->bind_param("ii", $session_id, $user_id);
                $stmt->execute();
                $stmt->close();
            } else {
                $session_data = null;
            }
        }
    }
}

// Handle AJAX fetch for messages
if (isset($_GET['fetch_messages']) && $session_id) {
    header('Content-Type: application/json');
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    $is_private = isset($_GET['is_private']) ? (int)$_GET['is_private'] : 0;
    
    if ($is_private) {
        $stmt = $conn->prepare("
            SELECT pm.*, u.name, u.role, u.profile_picture 
            FROM private_chat_messages pm 
            JOIN users u ON pm.user_id = u.id 
            WHERE pm.chat_session_id = ? AND pm.id > ?
            ORDER BY pm.created_at ASC LIMIT 100
        ");
        $stmt->bind_param("ii", $session_id, $last_id);
    } else {
        $stmt = $conn->prepare("
            SELECT cm.*, u.name, u.role, u.profile_picture 
            FROM chat_messages cm 
            JOIN users u ON cm.user_id = u.id 
            WHERE cm.chat_session_id = ? AND cm.id > ?
            ORDER BY cm.created_at ASC LIMIT 100
        ");
        $stmt->bind_param("ii", $session_id, $last_id);
    }
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode(['messages' => $messages]);
    exit;
}

// Handle AJAX fetch for chats list
if (isset($_GET['fetch_chats'])) {
    header('Content-Type: application/json');
    echo json_encode(['chats' => $chat_sessions]);
    exit;
}

// Determine chat type for current session
$current_chat_type = 'class';
if ($is_private_session) {
    $current_chat_type = 'private';
} elseif ($session_data && !$session_data['class_id']) {
    $current_chat_type = 'discussion';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Live Chat — QueensPa School</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/emoji-mart@latest/dist/browser.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/emoji-mart@latest/css/emoji-mart.css">
<style>
/* ============================================================
   Design Tokens — warm orange & white (matches teacher portal)
   ============================================================ */
:root {
    --or:       #F97316;
    --or-d:     #EA6C0A;
    --or-l:     #FED7AA;
    --or-pale:  #FFF7ED;
    --or-glow:  rgba(249,115,22,.14);
    --white:    #FFFFFF;
    --bg:       #FEF9F5;
    --grey-50:  #FAFAF9;
    --grey-100: #F3F4F6;
    --grey-200: #E5E7EB;
    --grey-300: #D1D5DB;
    --grey-400: #9CA3AF;
    --grey-500: #6B7280;
    --grey-700: #374151;
    --grey-900: #111827;
    --green:    #22C55E;
    --red:      #EF4444;
    --shadow:   0 2px 12px rgba(0,0,0,.08);
    --shadow-or:0 4px 18px rgba(249,115,22,.22);
    --r-sm:     8px;
    --r-md:     14px;
    --r-lg:     20px;
    --sidebar:  320px;
    --topbar:   64px;
    --ease:     .2s cubic-bezier(.4,0,.2,1);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}

body{
    font-family:'DM Sans',sans-serif;
    background:var(--bg);
    color:var(--grey-900);
    display:flex;
    flex-direction:column;
}

/* ── Scrollbars ── */
*{scrollbar-width:thin;scrollbar-color:var(--or-l) transparent}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-thumb{background:var(--or-l);border-radius:4px}

/* ============================================================
   Top Bar
   ============================================================ */
.topbar{
    height:var(--topbar);
    background:var(--white);
    border-bottom:1px solid var(--grey-200);
    display:flex;align-items:center;gap:14px;
    padding:0 22px;
    flex-shrink:0;
    box-shadow:var(--shadow);
    position:relative;z-index:100;
}
.topbar-brand{
    display:flex;align-items:center;gap:10px;
    font-family:'Sora',sans-serif;font-weight:700;font-size:1.05rem;color:var(--or);
    text-decoration:none;
}
.topbar-brand-icon{
    width:36px;height:36px;border-radius:10px;
    background:linear-gradient(135deg,var(--or),var(--or-d));
    display:grid;place-items:center;color:#fff;font-size:.95rem;
}
.topbar-divider{width:1px;height:26px;background:var(--grey-200)}
.topbar-title{font-family:'Sora',sans-serif;font-size:.9rem;font-weight:600;color:var(--grey-700)}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.topbar-user{
    display:flex;align-items:center;gap:8px;
    padding:6px 12px;background:var(--or-pale);border-radius:var(--r-sm);
    font-size:.8rem;font-weight:500;color:var(--or-d);
}

.t-btn{
    display:flex;align-items:center;gap:7px;
    padding:8px 16px;border-radius:var(--r-sm);
    font-size:.82rem;font-weight:500;cursor:pointer;
    border:none;font-family:inherit;text-decoration:none;
    transition:var(--ease);
}
.t-btn-primary{background:var(--or);color:#fff;box-shadow:var(--shadow-or)}
.t-btn-primary:hover{background:var(--or-d);transform:translateY(-1px)}
.t-btn-ghost{background:transparent;color:var(--grey-500);border:1px solid var(--grey-200)}
.t-btn-ghost:hover{background:var(--or-pale);color:var(--or)}

/* ============================================================
   Layout
   ============================================================ */
.layout{display:flex;flex:1;overflow:hidden}

/* ============================================================
   Sidebar
   ============================================================ */
.sidebar{
    width:var(--sidebar);flex-shrink:0;
    background:var(--white);
    border-right:1px solid var(--grey-200);
    display:flex;flex-direction:column;
    overflow:hidden;
}
.sidebar-head{
    padding:16px 18px 12px;
    border-bottom:1px solid var(--grey-100);
    flex-shrink:0;
}
.sidebar-head-top{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:12px;
}
.sidebar-head-top h2{
    font-family:'Sora',sans-serif;font-size:.9rem;font-weight:700;
    color:var(--grey-900);display:flex;align-items:center;gap:7px;
}
.badge-pill{
    background:var(--or);color:#fff;
    font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:50px;
}

.search-box{position:relative}
.search-box input{
    width:100%;padding:8px 12px 8px 34px;
    border:1.5px solid var(--grey-200);border-radius:var(--r-sm);
    font-size:.82rem;font-family:inherit;background:var(--grey-50);
    outline:none;transition:var(--ease);color:var(--grey-900);
}
.search-box input:focus{border-color:var(--or);background:#fff;box-shadow:0 0 0 3px var(--or-glow)}
.search-box i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--grey-400);font-size:.8rem}

/* Chat tabs */
.chat-tabs{
    display:flex;gap:4px;
    padding:10px 18px 0;flex-shrink:0;
}
.chat-tab{
    flex:1;padding:5px 6px;border-radius:var(--r-sm);
    border:1px solid var(--grey-200);font-size:.74rem;font-weight:500;
    color:var(--grey-500);cursor:pointer;background:transparent;text-align:center;
    transition:var(--ease);
}
.chat-tab.active,.chat-tab:hover{
    background:var(--or-pale);border-color:var(--or-l);color:var(--or-d);
}

/* Chat list */
.chat-list{flex:1;overflow-y:auto;padding:8px 10px}

.chat-item{
    display:flex;align-items:center;gap:10px;
    padding:10px 10px;border-radius:var(--r-md);
    cursor:pointer;transition:var(--ease);
    border:1.5px solid transparent;margin-bottom:3px;
    position:relative;
}
.chat-item:hover{background:var(--grey-50)}
.chat-item.active{background:var(--or-pale);border-color:var(--or-l)}

.chat-item-avi{
    width:44px;height:44px;border-radius:13px;
    display:grid;place-items:center;
    font-weight:700;font-size:1rem;color:#fff;flex-shrink:0;
    overflow:hidden;position:relative;
}
.chat-item-avi.class{background:linear-gradient(135deg,#8B5CF6,#6D28D9)}
.chat-item-avi.discussion{background:linear-gradient(135deg,var(--or),var(--or-d))}
.chat-item-avi.private{background:linear-gradient(135deg,#3B82F6,#1D4ED8)}
.chat-item-avi img{width:100%;height:100%;object-fit:cover}

.type-dot{
    position:absolute;bottom:2px;right:2px;
    width:10px;height:10px;border-radius:50%;
    border:2px solid var(--white);
}
.type-dot.active{background:var(--green)}
.type-dot.closed{background:var(--grey-300)}

.chat-item-info{flex:1;min-width:0}
.chat-item-name{
    font-family:'Sora',sans-serif;font-size:.82rem;font-weight:600;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--grey-900);
}
.chat-item-sub{font-size:.72rem;color:var(--grey-400);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-item-meta{display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0}
.chat-item-time{font-size:.67rem;color:var(--grey-400)}
.unread-chip{
    background:var(--or);color:#fff;
    font-size:.62rem;font-weight:700;padding:2px 6px;border-radius:50px;min-width:18px;text-align:center;
}

.chat-list-empty{text-align:center;padding:36px 16px;color:var(--grey-400)}
.chat-list-empty i{font-size:2rem;margin-bottom:8px;display:block}
.chat-list-empty p{font-size:.82rem}

/* Join session section */
.join-session-card{
    margin:16px;
    padding:12px;
    background:var(--or-pale);
    border-radius:var(--r-sm);
    border:1px solid var(--or-l);
    flex-shrink:0;
}
.join-session-card h3{
    font-family:'Sora',sans-serif;
    font-size:.75rem;
    font-weight:600;
    margin-bottom:8px;
    display:flex;
    align-items:center;
    gap:6px;
    color:var(--or-d);
}
.join-session-input{
    display:flex;
    gap:6px;
}
.join-session-input input{
    flex:1;
    padding:6px 10px;
    border:1px solid var(--grey-200);
    border-radius:var(--r-sm);
    font-size:.75rem;
    font-family:inherit;
    background:var(--white);
    outline:none;
}
.join-session-input input:focus{
    border-color:var(--or);
    box-shadow:0 0 0 3px var(--or-glow);
}
.join-session-input button{
    padding:6px 12px;
    background:var(--or);
    color:#fff;
    border:none;
    border-radius:var(--r-sm);
    font-size:.7rem;
    font-weight:500;
    cursor:pointer;
    transition:var(--ease);
}
.join-session-input button:hover{
    background:var(--or-d);
}

/* ============================================================
   Chat Panel
   ============================================================ */
.chat-panel{
    flex:1;display:flex;flex-direction:column;
    overflow:hidden;background:var(--bg);
    position:relative;
}

/* Empty state */
.chat-empty{
    flex:1;display:flex;flex-direction:column;
    align-items:center;justify-content:center;text-align:center;padding:40px;
}
.chat-empty-icon{
    width:88px;height:88px;border-radius:26px;
    background:linear-gradient(135deg,var(--or),var(--or-d));
    display:grid;place-items:center;color:#fff;font-size:2rem;
    margin-bottom:20px;box-shadow:var(--shadow-or);
    animation:float 3s ease-in-out infinite;
}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.chat-empty h3{font-family:'Sora',sans-serif;font-size:1.15rem;font-weight:700;color:var(--grey-700);margin-bottom:6px}
.chat-empty p{font-size:.85rem;color:var(--grey-400);max-width:280px}

/* Chat top bar */
.chat-topbar{
    display:flex;align-items:center;gap:12px;
    padding:12px 18px;
    background:var(--white);
    border-bottom:1px solid var(--grey-200);
    flex-shrink:0;box-shadow:var(--shadow);
}
.chat-topbar-avi{
    width:40px;height:40px;border-radius:12px;
    display:grid;place-items:center;font-weight:700;color:#fff;overflow:hidden;
    flex-shrink:0;
}
.chat-topbar-avi.class{background:linear-gradient(135deg,#8B5CF6,#6D28D9)}
.chat-topbar-avi.discussion{background:linear-gradient(135deg,var(--or),var(--or-d))}
.chat-topbar-avi.private{background:linear-gradient(135deg,#3B82F6,#1D4ED8)}
.chat-topbar-avi img{width:100%;height:100%;object-fit:cover}

.chat-topbar-title{font-family:'Sora',sans-serif;font-size:.9rem;font-weight:700;color:var(--grey-900)}
.chat-topbar-sub{font-size:.72rem;color:var(--grey-400);margin-top:1px}
.chat-topbar-actions{margin-left:auto;display:flex;gap:7px;align-items:center}

.icon-btn{
    width:34px;height:34px;border-radius:var(--r-sm);
    display:grid;place-items:center;
    background:var(--grey-50);border:1px solid var(--grey-200);
    color:var(--grey-500);cursor:pointer;font-size:.85rem;
    transition:var(--ease);
}
.icon-btn:hover{background:var(--or-pale);border-color:var(--or-l);color:var(--or)}

/* Messages */
.messages-wrap{flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:6px}

.date-sep{text-align:center;margin:6px 0}
.date-sep span{
    background:var(--white);border:1px solid var(--grey-200);
    border-radius:50px;font-size:.68rem;color:var(--grey-500);padding:3px 12px;
}

.msg-row{display:flex;gap:8px;animation:msgIn .18s ease}
@keyframes msgIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}
.msg-row.me{align-self:flex-end;flex-direction:row-reverse;max-width:72%}
.msg-row.them{align-self:flex-start;max-width:72%}

.msg-avi{
    width:30px;height:30px;border-radius:50%;
    display:grid;place-items:center;font-size:.7rem;font-weight:700;color:#fff;
    flex-shrink:0;align-self:flex-end;overflow:hidden;
    background:linear-gradient(135deg,var(--or),var(--or-d));
}
.msg-avi img{width:100%;height:100%;object-fit:cover}

.msg-bubble{padding:9px 13px;border-radius:18px;font-size:.85rem;line-height:1.5;word-break:break-word}
.msg-row.me   .msg-bubble{background:var(--or);color:#fff;border-bottom-right-radius:4px}
.msg-row.them .msg-bubble{background:var(--white);color:var(--grey-900);border-bottom-left-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.07)}

.msg-sender{font-size:.68rem;font-weight:600;color:var(--grey-400);margin-bottom:3px;padding:0 4px}
.msg-time{font-size:.62rem;opacity:.7;margin-top:4px;display:flex;align-items:center;justify-content:flex-end;gap:8px}
.msg-row.them .msg-time{color:var(--grey-400);opacity:1}

.msg-media{max-width:200px;border-radius:10px;overflow:hidden;margin-bottom:4px}
.msg-media img{width:100%;display:block;cursor:pointer}
.msg-file-link{
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 10px;background:rgba(0,0,0,.08);border-radius:8px;
    font-size:.78rem;text-decoration:none;color:inherit;margin-bottom:4px;
}
.msg-row.them .msg-file-link{background:var(--grey-100);color:var(--grey-700)}

/* Input bar */
.chat-input{
    padding:12px 18px;background:var(--white);
    border-top:1px solid var(--grey-200);flex-shrink:0;
    position:relative;
}
.chat-input.disabled{opacity:.5;pointer-events:none}

.media-prev-wrap{
    display:none;align-items:center;gap:8px;
    background:var(--grey-50);border:1px dashed var(--grey-300);
    border-radius:var(--r-sm);padding:6px 10px;margin-bottom:8px;
    font-size:.78rem;color:var(--grey-700);
}
.media-prev-wrap.show{display:flex}
.media-prev-img{width:40px;height:40px;border-radius:6px;object-fit:cover}
.media-prev-clear{margin-left:auto;background:none;border:none;cursor:pointer;color:var(--red)}

.input-row{display:flex;gap:8px;align-items:flex-end;position:relative;}

.input-icon-btn{
    width:38px;height:38px;border-radius:var(--r-sm);
    border:1.5px solid var(--grey-200);background:var(--grey-50);
    color:var(--grey-500);cursor:pointer;display:grid;place-items:center;
    font-size:.85rem;flex-shrink:0;transition:var(--ease);
}
.input-icon-btn:hover{background:var(--or-pale);border-color:var(--or-l);color:var(--or)}

.input-field{
    flex:1;background:var(--grey-50);border:1.5px solid var(--grey-200);
    border-radius:20px;padding:9px 14px;
    display:flex;align-items:flex-end;gap:8px;transition:var(--ease);
}
.input-field:focus-within{border-color:var(--or);background:#fff;box-shadow:0 0 0 3px var(--or-glow)}
.input-field textarea{
    flex:1;border:none;background:transparent;
    font-family:inherit;font-size:.88rem;color:var(--grey-900);
    resize:none;outline:none;max-height:90px;line-height:1.5;
    overflow-y:auto;
}
.input-field textarea::placeholder{color:var(--grey-400)}

.send-btn{
    width:42px;height:42px;border-radius:50%;
    background:var(--or);color:#fff;border:none;
    cursor:pointer;display:grid;place-items:center;font-size:.95rem;
    box-shadow:var(--shadow-or);transition:var(--ease);flex-shrink:0;
}
.send-btn:hover{background:var(--or-d);transform:scale(1.06)}
.send-btn:disabled{opacity:.5;cursor:default;transform:none}

/* Emoji Picker Styles */
.emoji-picker-container {
    position: relative;
    display: inline-block;
}

.emoji-picker-dropdown {
    position: absolute;
    bottom: 50px;
    left: 0;
    z-index: 1000;
    display: none;
    width: 320px;
    height: 350px;
    background: var(--white);
    border-radius: var(--r-md);
    box-shadow: var(--shadow);
    border: 1px solid var(--grey-200);
    overflow: hidden;
}

.emoji-picker-dropdown.open {
    display: block;
}

/* Toast */
.toast-wrap{position:fixed;bottom:20px;right:20px;z-index:999;display:flex;flex-direction:column;gap:6px}
.toast{
    display:flex;align-items:center;gap:8px;
    padding:10px 14px;border-radius:var(--r-md);
    font-size:.82rem;color:#fff;box-shadow:var(--shadow);
    animation:tIn .22s ease;max-width:280px;
}
@keyframes tIn{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:translateX(0)}}
.toast.success{background:#15803D}
.toast.error{background:var(--red)}
.toast.info{background:var(--grey-700)}

.type-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:2px 7px;border-radius:50px;font-size:.65rem;font-weight:600;
}
.type-badge.class{background:#F5F3FF;color:#6D28D9}
.type-badge.discussion{background:var(--or-pale);color:var(--or-d)}
.type-badge.private{background:#EFF6FF;color:#1D4ED8}

/* Responsive */
@media(max-width:768px){
    .sidebar{position:absolute;inset:0;z-index:50;transition:.3s}
    .sidebar.hidden{transform:translateX(-100%)}
    :root{--sidebar:100%}
}
</style>
</head>
<body>

<!-- TOP BAR -->
<header class="topbar">
    <a href="dashboard.php" class="topbar-brand">
        <div class="topbar-brand-icon"><i class="fas fa-graduation-cap"></i></div>
        QueenSpa
    </a>
    <div class="topbar-divider"></div>
    <span class="topbar-title">Student Live Chat</span>
    <div class="topbar-right">
        <div class="topbar-user">
            <i class="fas fa-user-graduate"></i>
            <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Student'); ?></span>
        </div>
        <a href="dashboard.php" class="t-btn t-btn-ghost"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
</header>

<!-- LAYOUT -->
<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <div class="sidebar-head-top">
                <h2><i class="fas fa-comments" style="color:var(--or)"></i> Chats <span class="badge-pill" id="totalBadge"><?php echo count($chat_sessions); ?></span></h2>
            </div>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search conversations…" oninput="filterChats()">
            </div>
        </div>
        
        <div class="chat-tabs">
            <button class="chat-tab active" data-filter="all" onclick="setTab(this)">All</button>
            <button class="chat-tab" data-filter="class" onclick="setTab(this)">Class</button>
            <button class="chat-tab" data-filter="discussion" onclick="setTab(this)">Groups</button>
            <button class="chat-tab" data-filter="private" onclick="setTab(this)">Private</button>
        </div>
        
        <!-- Join Session by Token -->
        <div class="join-session-card">
            <h3><i class="fas fa-key"></i> Join by Token</h3>
            <div class="join-session-input">
                <input type="text" id="sessionToken" placeholder="Enter session token">
                <button onclick="joinByToken()">Join</button>
            </div>
        </div>
        
        <div class="chat-list" id="chatList">
            <?php if (empty($chat_sessions)): ?>
            <div class="chat-list-empty"><i class="fas fa-inbox"></i><p>No chat sessions available</p><p class="text-xs mt-2">Ask your teacher for a session token</p></div>
            <?php else: ?>
            <?php foreach ($chat_sessions as $index => $session): ?>
            <div class="chat-item <?php echo ($session_id == $session['id']) ? 'active' : ''; ?>" data-id="<?php echo $session['id']; ?>" data-type="<?php echo $session['chat_type']; ?>" onclick="selectChat(<?php echo $session['id']; ?>, '<?php echo $session['chat_type']; ?>', this)">
                <div class="chat-item-avi <?php echo $session['chat_type']; ?>">
                    <?php 
                    if ($session['chat_type'] == 'private') {
                        echo substr($session['teacher_name'] ?? $session['title'], 0, 2);
                    } else {
                        echo substr($session['title'], 0, 2);
                    }
                    ?>
                    <span class="type-dot <?php echo $session['status'] == 'active' ? 'active' : 'closed'; ?>"></span>
                </div>
                <div class="chat-item-info">
                    <div class="chat-item-name">
                        <?php 
                        if ($session['chat_type'] == 'private') {
                            echo htmlspecialchars($session['teacher_name'] ?? $session['title']);
                        } else {
                            echo htmlspecialchars($session['title']);
                        }
                        ?>
                    </div>
                    <div class="chat-item-sub">
                        <?php 
                        if ($session['chat_type'] == 'class') {
                            echo htmlspecialchars($session['class_name']);
                        } elseif ($session['chat_type'] == 'discussion') {
                            echo 'Discussion Group';
                        } else {
                            echo 'Private Chat';
                        }
                        ?>
                    </div>
                </div>
                <div class="chat-item-meta">
                    <span class="chat-item-time"><?php echo $session['last_time'] ? date('M d', strtotime($session['last_time'])) : ''; ?></span>
                    <?php if ($session['unread'] > 0): ?>
                    <span class="unread-chip"><?php echo $session['unread']; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <!-- CHAT PANEL -->
    <main class="chat-panel" id="chatPanel">

        <!-- Empty state -->
        <div class="chat-empty" id="chatEmpty" style="<?php echo $session_data ? 'display:none' : 'display:flex'; ?>">
            <div class="chat-empty-icon"><i class="fas fa-comments"></i></div>
            <h3>Select a Chat</h3>
            <p>Choose a conversation from the left, or join using a token from your teacher.</p>
        </div>

        <!-- Active chat -->
        <div id="activeChat" style="<?php echo $session_data ? 'display:flex' : 'display:none'; ?>;flex:1;flex-direction:column;overflow:hidden;">

            <div class="chat-topbar">
                <button class="icon-btn" id="mobileBackBtn" style="display:none" onclick="mobileBack()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-topbar-avi <?php echo $current_chat_type; ?>" id="topbarAvi">
                    <?php 
                    if ($session_data) {
                        if ($is_private_session) {
                            echo substr($session_data['teacher_name'] ?? '?', 0, 2);
                        } else {
                            echo substr($session_data['title'] ?? '?', 0, 2);
                        }
                    } else {
                        echo '?';
                    }
                    ?>
                </div>
                <div>
                    <div class="chat-topbar-title" id="topbarTitle">
                        <?php 
                        if ($session_data) {
                            if ($is_private_session) {
                                echo htmlspecialchars($session_data['teacher_name'] ?? $session_data['title']);
                            } else {
                                echo htmlspecialchars($session_data['title']);
                            }
                        } else {
                            echo 'Chat';
                        }
                        ?>
                    </div>
                    <div class="chat-topbar-sub" id="topbarSub">
                        <?php 
                        if ($session_data) {
                            if ($is_private_session) {
                                echo 'Private Chat with Teacher';
                            } elseif ($session_data['class_id']) {
                                echo htmlspecialchars($session_data['class_name'] ?? 'Class Session');
                            } else {
                                echo 'Discussion Group';
                            }
                        } else {
                            echo 'Chat Session';
                        }
                        ?>
                    </div>
                </div>
                <div class="chat-topbar-actions">
                    <span id="topbarBadge" class="type-badge <?php echo $current_chat_type; ?>">
                        <?php echo $session_data && $session_data['status'] == 'active' ? 'Active' : ($session_data && $session_data['status'] == 'closed' ? 'Closed' : 'Active'); ?>
                    </span>
                </div>
            </div>

            <div class="messages-wrap" id="messagesWrap">
                <?php if ($session_data && empty($messages)): ?>
                <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--grey-400);font-size:.85rem">
                    <div style="text-align:center"><i class="fas fa-comment" style="font-size:1.8rem;margin-bottom:8px;opacity:.3"></i><p>No messages yet. Start the conversation!</p></div>
                </div>
                <?php else: ?>
                <?php 
                $lastDate = '';
                foreach ($messages as $msg): 
                    $msgDate = date('l, M d', strtotime($msg['created_at']));
                    if ($msgDate !== $lastDate):
                ?>
                <div class="date-sep"><span><?php echo $msgDate; ?></span></div>
                <?php 
                        $lastDate = $msgDate;
                    endif; 
                ?>
                <div class="msg-row <?php echo $msg['user_id'] == $user_id ? 'me' : 'them'; ?>">
                    <?php if ($msg['user_id'] != $user_id): ?>
                    <div class="msg-avi">
                        <?php echo $msg['profile_picture'] ? '<img src="'.htmlspecialchars($msg['profile_picture']).'">' : substr($msg['name'], 0, 1); ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <?php if ($msg['user_id'] != $user_id): ?>
                        <div class="msg-sender"><?php echo htmlspecialchars($msg['name']); ?> (<?php echo $msg['role'] == 'teacher' ? 'Teacher' : 'Student'; ?>)</div>
                        <?php endif; ?>
                        <div class="msg-bubble">
                            <?php if ($msg['message_media']): ?>
                                <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $msg['message_media'])): ?>
                                <div class="msg-media"><img src="<?php echo htmlspecialchars($msg['message_media']); ?>" onclick="window.open('<?php echo htmlspecialchars($msg['message_media']); ?>')"></div>
                                <?php elseif (preg_match('/\.(mp4|mov|webm)$/i', $msg['message_media'])): ?>
                                <video controls style="max-width:200px;border-radius:10px;margin-bottom:4px"><source src="<?php echo htmlspecialchars($msg['message_media']); ?>"></video>
                                <?php else: ?>
                                <a class="msg-file-link" href="<?php echo htmlspecialchars($msg['message_media']); ?>" target="_blank"><i class="fas fa-file-alt"></i> <?php echo basename($msg['message_media']); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($msg['message']): ?>
                            <div><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                            <?php endif; ?>
                            <div class="msg-time">
                                <span><?php echo date('H:i', strtotime($msg['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php if ($msg['user_id'] == $user_id): ?>
                    <div class="msg-avi">
                        <?php echo $msg['profile_picture'] ? '<img src="'.htmlspecialchars($msg['profile_picture']).'">' : substr($msg['name'], 0, 1); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Input -->
            <div class="chat-input <?php echo ($session_data && $session_data['status'] != 'active') ? 'disabled' : ''; ?>" id="chatInput">
                <div class="media-prev-wrap" id="mediaPrev">
                    <img id="mediaPrevImg" class="media-prev-img" src="" alt="" style="display:none">
                    <span id="mediaPrevName"></span>
                    <button class="media-prev-clear" onclick="clearMedia()"><i class="fas fa-times"></i></button>
                </div>
                <div class="input-row">
                    <div class="emoji-picker-container">
                        <button class="input-icon-btn" id="emojiBtn" title="Emojis" type="button">
                            <i class="far fa-smile-wink"></i>
                        </button>
                        <div class="emoji-picker-dropdown" id="emojiPickerDropdown">
                            <div id="emojiPicker"></div>
                        </div>
                    </div>
                    <label class="input-icon-btn" title="Attach file">
                        <i class="fas fa-paperclip"></i>
                        <input type="file" id="mediaFile" accept="image/*,video/*,.pdf,.doc,.docx" style="display:none" onchange="onMediaSelect(this)">
                    </label>
                    <div class="input-field">
                        <textarea id="msgInput" rows="1" placeholder="<?php echo ($session_data && $session_data['status'] == 'active') ? 'Type a message…' : 'This session is closed'; ?>" 
                            onkeydown="onKeydown(event)" oninput="autoGrow(this)" <?php echo ($session_data && $session_data['status'] != 'active') ? 'disabled' : ''; ?>></textarea>
                    </div>
                    <button class="send-btn" id="sendBtn" onclick="sendMessage()" <?php echo ($session_data && $session_data['status'] != 'active') ? 'disabled' : ''; ?>>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div><!-- /activeChat -->
    </main>
</div><!-- /layout -->

<div class="toast-wrap" id="toastWrap"></div>

<script>
const USER_ID = <?php echo json_encode($user_id); ?>;
const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;
let allChats = <?php echo json_encode($chat_sessions); ?>;
let currentChat = { 
    id: <?php echo $session_id ?: 'null'; ?>, 
    status: '<?php echo $session_data ? $session_data['status'] : ''; ?>',
    type: '<?php echo $current_chat_type; ?>'
};
let lastMsgId = 0;
let pollTimer = null;
let currentFile = null;
let chatFilter = 'all';

// Init
(async function init() {
    initEmojiPicker();
    if (window.innerWidth <= 768) {
        document.getElementById('mobileBackBtn').style.display = 'flex';
    }
    if (currentChat.id) {
        const messagesWrap = document.getElementById('messagesWrap');
        if (messagesWrap) messagesWrap.scrollTop = messagesWrap.scrollHeight;
        startPolling();
    }
    // Auto-refresh chats list every 10 seconds
    setInterval(refreshChatsList, 10000);
})();

// ============================================================
// Emoji Picker
// ============================================================
function initEmojiPicker() {
    const emojiBtn = document.getElementById('emojiBtn');
    const emojiPickerDropdown = document.getElementById('emojiPickerDropdown');
    const msgInput = document.getElementById('msgInput');
    
    if (typeof EmojiMart !== 'undefined' && EmojiMart.Picker) {
        const picker = new EmojiMart.Picker({
            data: async () => {
                const response = await fetch('https://cdn.jsdelivr.net/npm/@emoji-mart/data');
                return await response.json();
            },
            onEmojiSelect: (emoji) => {
                const cursorPos = msgInput.selectionStart;
                const textBefore = msgInput.value.substring(0, cursorPos);
                const textAfter = msgInput.value.substring(cursorPos);
                msgInput.value = textBefore + emoji.native + textAfter;
                msgInput.focus();
                msgInput.selectionStart = cursorPos + emoji.native.length;
                msgInput.selectionEnd = cursorPos + emoji.native.length;
                autoGrow(msgInput);
                emojiPickerDropdown.classList.remove('open');
            }
        });
        document.getElementById('emojiPicker').appendChild(picker);
    } else {
        const simpleEmojis = ['😀', '😂', '😊', '😍', '👍', '❤️', '🎉', '🔥', '🙏', '👏'];
        const emojiContainer = document.getElementById('emojiPicker');
        emojiContainer.innerHTML = '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:5px;padding:10px;">' +
            simpleEmojis.map(emoji => 
                `<button style="font-size:1.5rem;padding:5px;cursor:pointer;border:none;background:transparent;" 
                         onclick="insertEmoji('${emoji}')">${emoji}</button>`
            ).join('') + '</div>';
    }

    emojiBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        emojiPickerDropdown.classList.toggle('open');
    });

    document.addEventListener('click', (e) => {
        if (!emojiBtn.contains(e.target) && !emojiPickerDropdown.contains(e.target)) {
            emojiPickerDropdown.classList.remove('open');
        }
    });
}

function insertEmoji(emoji) {
    const msgInput = document.getElementById('msgInput');
    const cursorPos = msgInput.selectionStart;
    const textBefore = msgInput.value.substring(0, cursorPos);
    const textAfter = msgInput.value.substring(cursorPos);
    msgInput.value = textBefore + emoji + textAfter;
    msgInput.focus();
    msgInput.selectionStart = cursorPos + emoji.length;
    msgInput.selectionEnd = cursorPos + emoji.length;
    autoGrow(msgInput);
    document.getElementById('emojiPickerDropdown').classList.remove('open');
}

// ============================================================
// Chat Functions
// ============================================================
function setTab(btn) {
    document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    chatFilter = btn.dataset.filter;
    renderChats();
}

function filterChats() {
    renderChats();
}

function renderChats() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    let items = [...allChats];
    
    // Apply tab filter
    if (chatFilter !== 'all') {
        items = items.filter(c => c.chat_type === chatFilter);
    }
    
    // Apply search filter
    if (q) {
        items = items.filter(c => {
            const title = c.chat_type === 'private' ? (c.teacher_name || c.title) : c.title;
            return (title || '').toLowerCase().includes(q) || (c.class_name || '').toLowerCase().includes(q);
        });
    }

    const list = document.getElementById('chatList');
    if (!items.length) {
        list.innerHTML = `<div class="chat-list-empty"><i class="fas fa-inbox"></i><p>No conversations found</p></div>`;
        return;
    }

    list.innerHTML = items.map(c => {
        const isActive = currentChat && currentChat.id == c.id;
        const displayName = c.chat_type === 'private' ? (c.teacher_name || c.title) : c.title;
        const initials = (displayName || '?').substring(0,2).toUpperCase();
        const subText = c.chat_type === 'class' ? (c.class_name || 'Class') : 
                       (c.chat_type === 'discussion' ? 'Discussion Group' : 'Private Chat');

        return `
        <div class="chat-item ${isActive ? 'active' : ''}" data-id="${c.id}" data-type="${c.chat_type}" onclick="selectChat(${c.id}, '${c.chat_type}', this)">
            <div class="chat-item-avi ${c.chat_type}">
                ${initials}
                <span class="type-dot ${c.status == 'active' ? 'active' : 'closed'}"></span>
            </div>
            <div class="chat-item-info">
                <div class="chat-item-name">${escapeHtml(displayName)}</div>
                <div class="chat-item-sub">${escapeHtml(subText)} • ${c.message_count || 0} msgs</div>
            </div>
            <div class="chat-item-meta">
                <span class="chat-item-time">${c.last_time ? new Date(c.last_time).toLocaleDateString([],{month:'short',day:'numeric'}) : ''}</span>
                ${c.unread > 0 ? `<span class="unread-chip">${c.unread}</span>` : ''}
            </div>
        </div>`;
    }).join('');
}

async function refreshChatsList() {
    try {
        const response = await fetch('?fetch_chats=1&_=' + Date.now());
        const data = await response.json();
        if (data.chats) {
            allChats = data.chats;
            document.getElementById('totalBadge').textContent = allChats.length;
            renderChats();
        }
    } catch (error) {
        console.error('Refresh chats error:', error);
    }
}

async function selectChat(id, type, el) {
    document.querySelectorAll('.chat-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');

    const chatData = allChats.find(c => c.id == id && c.chat_type === type);
    if (!chatData) return;

    currentChat = { 
        id, 
        title: chatData.title, 
        type: type,
        status: chatData.status,
        class_name: chatData.class_name,
        teacher_name: chatData.teacher_name
    };
    lastMsgId = 0;

    document.getElementById('chatEmpty').style.display = 'none';
    const ac = document.getElementById('activeChat');
    ac.style.display = 'flex';

    const avi = document.getElementById('topbarAvi');
    avi.className = `chat-topbar-avi ${type}`;
    const displayName = type === 'private' ? (chatData.teacher_name || chatData.title) : chatData.title;
    avi.textContent = (displayName || '?').substring(0,2).toUpperCase();
    document.getElementById('topbarTitle').textContent = displayName || '—';
    
    let subText = '';
    if (type === 'private') {
        subText = 'Private Chat with Teacher';
    } else if (type === 'class') {
        subText = chatData.class_name || 'Class Session';
    } else {
        subText = 'Discussion Group';
    }
    document.getElementById('topbarSub').textContent = subText;
    
    const badge = document.getElementById('topbarBadge');
    badge.className = `type-badge ${type}`;
    badge.textContent = chatData.status == 'active' ? 'Active' : 'Closed';

    const inputArea = document.getElementById('chatInput');
    const msgInput = document.getElementById('msgInput');
    const sendBtn = document.getElementById('sendBtn');
    
    if (chatData.status != 'active') {
        inputArea.classList.add('disabled');
        if (msgInput) msgInput.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        msgInput.placeholder = 'This session is closed';
    } else {
        inputArea.classList.remove('disabled');
        if (msgInput) msgInput.disabled = false;
        if (sendBtn) sendBtn.disabled = false;
        msgInput.placeholder = 'Type a message…';
    }

    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.add('hidden');
    }

    window.history.pushState({}, '', `live_chat.php?session_id=${id}`);
    
    stopPolling();
    await fetchMessages(true);
    if (chatData.status == 'active') startPolling();
}

function mobileBack() {
    document.getElementById('sidebar').classList.remove('hidden');
    document.getElementById('activeChat').style.display = 'none';
    document.getElementById('chatEmpty').style.display = 'flex';
    stopPolling();
    currentChat = null;
    window.history.pushState({}, '', 'live_chat.php');
}

async function fetchMessages(initial = false) {
    if (!currentChat || !currentChat.id) return;
    
    try {
        const isPrivate = currentChat.type === 'private' ? 1 : 0;
        const params = `?fetch_messages=1&chat_session_id=${currentChat.id}&last_id=${lastMsgId}&is_private=${isPrivate}&_=${Date.now()}`;
        const response = await fetch(params);
        const data = await response.json();
        const msgs = data.messages || [];
        
        if (msgs.length) {
            appendMessages(msgs, initial);
            lastMsgId = Math.max(...msgs.map(m => m.id));
        }
    } catch (error) {
        console.error('Fetch error:', error);
    }
}

function appendMessages(msgs, initial) {
    const wrap = document.getElementById('messagesWrap');
    let lastDate = '';

    if (initial) wrap.innerHTML = '';

    msgs.forEach(msg => {
        const msgDate = new Date(msg.created_at).toLocaleDateString('en-GB', {weekday:'long', month:'short', day:'numeric'});
        if (msgDate !== lastDate) {
            const sep = document.createElement('div');
            sep.className = 'date-sep';
            sep.innerHTML = `<span>${msgDate}</span>`;
            wrap.appendChild(sep);
            lastDate = msgDate;
        }
        
        const isMine = msg.user_id == USER_ID;
        const time = new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        const aviContent = msg.profile_picture 
            ? `<img src="${escapeHtml(msg.profile_picture)}" alt="">` 
            : (msg.name || '?').substring(0,1).toUpperCase();

        let mediaHtml = '';
        if (msg.message_media) {
            const ext = msg.message_media.split('.').pop().toLowerCase();
            if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
                mediaHtml = `<div class="msg-media"><img src="${escapeHtml(msg.message_media)}" onclick="window.open('${escapeHtml(msg.message_media)}')"></div>`;
            } else if (['mp4','mov','webm'].includes(ext)) {
                mediaHtml = `<video controls style="max-width:200px;border-radius:10px;margin-bottom:4px"><source src="${escapeHtml(msg.message_media)}"></video>`;
            } else {
                mediaHtml = `<a class="msg-file-link" href="${escapeHtml(msg.message_media)}" target="_blank"><i class="fas fa-file-alt"></i> ${escapeHtml(msg.message_media.split('/').pop())}</a>`;
            }
        }

        const msgDiv = document.createElement('div');
        msgDiv.className = `msg-row ${isMine ? 'me' : 'them'}`;
        msgDiv.innerHTML = `
            ${!isMine ? `<div class="msg-avi">${aviContent}</div>` : ''}
            <div>
                ${!isMine ? `<div class="msg-sender">${escapeHtml(msg.name)} (${msg.role == 'teacher' ? 'Teacher' : 'Student'})</div>` : ''}
                <div class="msg-bubble">
                    ${mediaHtml}
                    ${msg.message ? `<div>${escapeHtml(msg.message).replace(/\n/g,'<br>')}</div>` : ''}
                    <div class="msg-time">
                        <span>${time}</span>
                    </div>
                </div>
            </div>
            ${isMine ? `<div class="msg-avi">${aviContent}</div>` : ''}
        `;
        wrap.appendChild(msgDiv);
    });

    if (initial || msgs.length) wrap.scrollTop = wrap.scrollHeight;
}

async function sendMessage() {
    if (!currentChat || !currentChat.id) {
        toast('Select a chat first', 'error');
        return;
    }
    
    if (currentChat.status != 'active') {
        toast('This chat session is closed', 'error');
        return;
    }
    
    const input = document.getElementById('msgInput');
    const msg = input.value.trim();
    
    if (!msg && !currentFile) {
        toast('Please enter a message or select a file', 'error');
        return;
    }

    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('action', 'send_message');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('chat_session_id', currentChat.id);
        fd.append('is_private', currentChat.type === 'private' ? '1' : '0');
        fd.append('message', msg);
        
        if (currentFile) {
            fd.append('message_media', currentFile);
        }

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const res = await response.json();
        
        if (res.success) {
            input.value = '';
            autoGrow(input);
            clearMedia();
            await fetchMessages(false);
            await refreshChatsList();
            toast('Message sent!', 'success');
        } else {
            toast(res.error || 'Failed to send message', 'error');
        }
    } catch (error) {
        console.error('Send error:', error);
        toast('Network error - please try again', 'error');
    } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
    }
}

async function joinByToken() {
    const token = document.getElementById('sessionToken').value.trim();
    if (!token) {
        toast('Please enter a session token', 'error');
        return;
    }
    
    // Try to join as group/class chat first
    let fd = new FormData();
    fd.append('join_session', '1');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('session_token', token);
    
    try {
        let response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        let res = await response.json();
        
        if (res.success) {
            toast('Joined session successfully!', 'success');
            window.location.href = `live_chat.php?session_id=${res.session_id}`;
            return;
        }
        
        // Try private chat
        fd = new FormData();
        fd.append('access_private_chat', '1');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('session_token', token);
        
        response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        res = await response.json();
        
        if (res.success) {
            toast('Joined private chat successfully!', 'success');
            window.location.href = `live_chat.php?session_id=${res.session_id}`;
        } else {
            toast(res.error || 'Invalid session token', 'error');
        }
    } catch (error) {
        console.error('Join error:', error);
        toast('Network error - please try again', 'error');
    }
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function autoGrow(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 90) + 'px';
}

function onMediaSelect(input) {
    if (!input.files[0]) return;
    currentFile = input.files[0];
    const prev = document.getElementById('mediaPrev');
    prev.classList.add('show');
    document.getElementById('mediaPrevName').textContent = currentFile.name;
    const img = document.getElementById('mediaPrevImg');
    if (currentFile.type.startsWith('image/')) {
        img.src = URL.createObjectURL(currentFile);
        img.style.display = 'block';
    } else {
        img.style.display = 'none';
    }
}

function clearMedia() {
    currentFile = null;
    document.getElementById('mediaFile').value = '';
    document.getElementById('mediaPrev').classList.remove('show');
    document.getElementById('mediaPrevImg').src = '';
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(() => {
        if (currentChat && currentChat.id && currentChat.status == 'active') {
            fetchMessages(false);
            refreshChatsList();
        }
    }, 3000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[m]);
}

function toast(msg, type = 'info') {
    const wrap = document.getElementById('toastWrap');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    const icons = {success:'fa-check-circle', error:'fa-times-circle', info:'fa-info-circle'};
    t.innerHTML = `<i class="fas ${icons[type]||'fa-info-circle'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 3200);
}

window.addEventListener('beforeunload', stopPolling);
</script>
</body>
</html>