<?php
// ============================================================
// live_chat.php — Admin Live Chat Portal (Unified Communication)
// Features: 
// - Admin can chat with ANY user (parents, students, teachers)
// - Supports private chats, class groups, and discussion groups
// - Admin can join any existing group/class chat
// - FETCH ALL CHATS from ALL users across ALL portals
// - Unified message feed showing ALL conversations
// - Real-time polling for all active chats
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();

// Include central database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Verify connection
if (!$conn) {
    die("Database connection failed. Please try again later.");
}
$conn->set_charset("utf8mb4");

// Auth — Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    // For testing only — replace with proper admin check
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';
        $_SESSION['name'] = 'Admin User';
        $_SESSION['user_role'] = 'admin';
    }
}
$admin_id = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['name'] ?? 'Admin';

// CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get active term ID
$active_term_id = null;
$term_result = $conn->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
if ($term_result && $term_result->num_rows > 0) {
    $active_term_id = $term_result->fetch_assoc()['id'];
}
if (!$active_term_id) {
    $term_result = $conn->query("SELECT id FROM academic_terms LIMIT 1");
    if ($term_result && $term_result->num_rows > 0) {
        $active_term_id = $term_result->fetch_assoc()['id'];
    }
}
if (!$active_term_id) $active_term_id = 1;

// ============================================================
// Helper Functions
// ============================================================

function getPrivateChatParticipantNames($conn, $session_id) {
    $sql = "SELECT u1.name AS sender_name, u2.name AS recipient_name
            FROM private_chat_sessions pcs
            LEFT JOIN users u1 ON u1.id = pcs.sender_id
            LEFT JOIN users u2 ON u2.id = pcs.recipient_id
            WHERE pcs.id = ?";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $session_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    if ($res) {
        return $res['sender_name'] . ' ↔ ' . $res['recipient_name'];
    }
    return 'Unknown';
}

function getGroupMemberCount($conn, $session_id) {
    $sql = "SELECT COUNT(*) as cnt FROM chat_participants WHERE chat_session_id = ?";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $session_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    return $res['cnt'] ?? 0;
}

function getUnreadFromAdmin($conn, $user_id, $admin_id) {
    $sql = "SELECT COUNT(*) as cnt FROM private_chat_messages pm
            JOIN private_chat_sessions pcs ON pcs.id = pm.chat_session_id
            WHERE pm.user_id = ? AND (pcs.recipient_id = ? OR pcs.sender_id = ?) 
            AND pm.read_status = 'unread'";
    $st = $conn->prepare($sql);
    $st->bind_param('iii', $user_id, $admin_id, $admin_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    return $res['cnt'] ?? 0;
}

function getUserTotalMessages($conn, $user_id) {
    $sql_priv = "SELECT COUNT(*) as cnt FROM private_chat_messages WHERE user_id = ?";
    $st_priv = $conn->prepare($sql_priv);
    $st_priv->bind_param('i', $user_id);
    $st_priv->execute();
    $res_priv = $st_priv->get_result()->fetch_assoc();
    $st_priv->close();
    
    $sql_group = "SELECT COUNT(*) as cnt FROM chat_messages WHERE user_id = ?";
    $st_group = $conn->prepare($sql_group);
    $st_group->bind_param('i', $user_id);
    $st_group->execute();
    $res_group = $st_group->get_result()->fetch_assoc();
    $st_group->close();
    
    return ($res_priv['cnt'] ?? 0) + ($res_group['cnt'] ?? 0);
}

// ============================================================
// AJAX Handler
// ============================================================
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
           isset($_GET['fetch_all_messages'], $_GET['fetch_chats'], $_GET['fetch_messages'], $_GET['fetch_users'],
                 $_GET['fetch_stats'], $_GET['fetch_classes'],
                 $_GET['fetch_group_members'], $_GET['fetch_all_groups'], $_GET['join_group'],
                 $_GET['fetch_user_conversations'], $_GET['fetch_all_users']) ||
           $_SERVER['REQUEST_METHOD'] === 'POST';

if ($is_ajax && ($_SERVER['REQUEST_METHOD'] === 'POST' || array_intersect(array_keys($_GET),
    ['fetch_all_messages', 'fetch_chats', 'fetch_messages', 'fetch_users', 'fetch_stats', 'fetch_classes',
     'fetch_group_members', 'fetch_all_groups', 'join_group', 'fetch_user_conversations', 'fetch_all_users']))) {

    header('Content-Type: application/json');

    // ---- GET: ALL MESSAGES FROM ALL USERS (Unified Feed) ----
    if (isset($_GET['fetch_all_messages'])) {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
        $chat_type = $_GET['chat_type'] ?? 'all';
        
        $messages = [];
        
        // 1. Private chat messages
        $sql_private = "SELECT 
                            pm.id, 
                            pm.user_id, 
                            pm.message, 
                            pm.message_media, 
                            pm.read_status, 
                            pm.created_at,
                            u.name, 
                            u.role, 
                            u.profile_picture,
                            pcs.title AS chat_title,
                            pcs.sender_id,
                            pcs.recipient_id,
                            'private' AS chat_type,
                            pcs.id AS session_id
                        FROM private_chat_messages pm
                        LEFT JOIN users u ON u.id = pm.user_id
                        LEFT JOIN private_chat_sessions pcs ON pcs.id = pm.chat_session_id
                        WHERE pm.id > ?
                        ORDER BY pm.created_at DESC
                        LIMIT ?";
        $st_priv = $conn->prepare($sql_private);
        $st_priv->bind_param('ii', $last_id, $limit);
        $st_priv->execute();
        $res_priv = $st_priv->get_result();
        while ($r = $res_priv->fetch_assoc()) {
            $r['participant_names'] = getPrivateChatParticipantNames($conn, $r['session_id']);
            $messages[] = $r;
        }
        $st_priv->close();
        
        // 2. Group chat messages
        $sql_group = "SELECT 
                            cm.id, 
                            cm.user_id, 
                            cm.message, 
                            cm.message_media, 
                            cm.read_status, 
                            cm.created_at,
                            u.name, 
                            u.role, 
                            u.profile_picture,
                            cs.title AS chat_title,
                            cs.class_id,
                            cs.teacher_id,
                            'group' AS chat_type,
                            cs.id AS session_id,
                            CASE WHEN cs.class_id IS NOT NULL THEN 'class' ELSE 'discussion' END AS group_type
                        FROM chat_messages cm
                        LEFT JOIN users u ON u.id = cm.user_id
                        LEFT JOIN chat_sessions cs ON cs.id = cm.chat_session_id
                        WHERE cm.id > ?
                        ORDER BY cm.created_at DESC
                        LIMIT ?";
        $st_group = $conn->prepare($sql_group);
        $st_group->bind_param('ii', $last_id, $limit);
        $st_group->execute();
        $res_group = $st_group->get_result();
        while ($r = $res_group->fetch_assoc()) {
            $r['member_count'] = getGroupMemberCount($conn, $r['session_id']);
            $messages[] = $r;
        }
        $st_group->close();
        
        // Sort by created_at descending
        usort($messages, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Apply chat type filter
        if ($chat_type !== 'all') {
            if ($chat_type === 'private') {
                $messages = array_filter($messages, function($m) { return $m['chat_type'] === 'private'; });
            } elseif ($chat_type === 'class') {
                $messages = array_filter($messages, function($m) { 
                    return isset($m['group_type']) && $m['group_type'] === 'class'; 
                });
            } elseif ($chat_type === 'discussion') {
                $messages = array_filter($messages, function($m) { 
                    return isset($m['group_type']) && $m['group_type'] === 'discussion'; 
                });
            }
            $messages = array_values($messages);
        }
        
        $latest_id = !empty($messages) ? max(array_column($messages, 'id')) : $last_id;
        
        echo json_encode([
            'messages' => $messages,
            'latest_id' => $latest_id,
            'total' => count($messages)
        ]);
        exit;
    }
    
    // ---- GET: User conversations ----
    if (isset($_GET['fetch_user_conversations'])) {
        $target_user_id = (int)$_GET['user_id'];
        if (!$target_user_id) {
            echo json_encode(['error' => 'User ID required']);
            exit;
        }
        
        $conversations = [];
        
        $sql_priv = "SELECT 
                        pcs.id, 
                        pcs.title, 
                        pcs.status, 
                        pcs.created_at,
                        'private' AS chat_type,
                        CASE WHEN pcs.sender_id = ? THEN u2.name ELSE u1.name END AS other_party_name,
                        CASE WHEN pcs.sender_id = ? THEN u2.id ELSE u1.id END AS other_party_id,
                        (SELECT message FROM private_chat_messages WHERE chat_session_id = pcs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM private_chat_messages WHERE chat_session_id = pcs.id ORDER BY created_at DESC LIMIT 1) AS last_time
                    FROM private_chat_sessions pcs
                    LEFT JOIN users u1 ON u1.id = pcs.sender_id
                    LEFT JOIN users u2 ON u2.id = pcs.recipient_id
                    WHERE pcs.sender_id = ? OR pcs.recipient_id = ?
                    ORDER BY last_time DESC";
        $st_priv = $conn->prepare($sql_priv);
        $st_priv->bind_param('iiii', $target_user_id, $target_user_id, $target_user_id, $target_user_id);
        $st_priv->execute();
        $res_priv = $st_priv->get_result();
        while ($r = $res_priv->fetch_assoc()) {
            $conversations[] = $r;
        }
        $st_priv->close();
        
        $sql_group = "SELECT 
                        cs.id, 
                        cs.title, 
                        cs.status, 
                        cs.created_at,
                        CASE WHEN cs.class_id IS NOT NULL THEN 'class' ELSE 'discussion' END AS chat_type,
                        (SELECT message FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
                        (SELECT COUNT(*) FROM chat_participants WHERE chat_session_id = cs.id) AS member_count
                    FROM chat_sessions cs
                    JOIN chat_participants cp ON cp.chat_session_id = cs.id
                    WHERE cp.user_id = ? AND cs.status = 'active'
                    ORDER BY last_time DESC";
        $st_group = $conn->prepare($sql_group);
        $st_group->bind_param('i', $target_user_id);
        $st_group->execute();
        $res_group = $st_group->get_result();
        while ($r = $res_group->fetch_assoc()) {
            $conversations[] = $r;
        }
        $st_group->close();
        
        usort($conversations, function($a, $b) {
            return strtotime($b['last_time'] ?? '1970-01-01') - strtotime($a['last_time'] ?? '1970-01-01');
        });
        
        echo json_encode(['conversations' => $conversations]);
        exit;
    }
    
    // ---- GET: All users for monitoring ----
    if (isset($_GET['fetch_all_users'])) {
        $search = $_GET['search'] ?? '';
        $role = $_GET['role'] ?? 'all';
        
        $sql = "SELECT id, name, role, profile_picture, email, status, last_login
                FROM users 
                WHERE status = 'active' AND id != ?
                ORDER BY name";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $admin_id);
        $st->execute();
        $res = $st->get_result();
        $users = [];
        while ($r = $res->fetch_assoc()) {
            $r['unread_from_admin'] = getUnreadFromAdmin($conn, $r['id'], $admin_id);
            $r['total_messages'] = getUserTotalMessages($conn, $r['id']);
            $users[] = $r;
        }
        $st->close();
        
        if ($role !== 'all') {
            $users = array_filter($users, function($u) use ($role) {
                return $u['role'] === $role;
            });
            $users = array_values($users);
        }
        
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $users = array_filter($users, function($u) use ($search_lower) {
                return strpos(strtolower($u['name']), $search_lower) !== false ||
                       strpos(strtolower($u['email'] ?? ''), $search_lower) !== false;
            });
            $users = array_values($users);
        }
        
        echo json_encode($users);
        exit;
    }
    
    // ---- GET: All chats for admin ----
    if (isset($_GET['fetch_chats'])) {
        $filter_type = $_GET['filter_type'] ?? 'all';
        $user_role = $_GET['user_role'] ?? 'all';
        $chats = [];

        // 1. Private chats
        $sql = "SELECT pcs.id, pcs.title, pcs.status, pcs.created_at, 'private' AS chat_type,
                       CASE WHEN pcs.sender_id = ? THEN u2.name ELSE u1.name END AS contact_name,
                       CASE WHEN pcs.sender_id = ? THEN u2.id ELSE u1.id END AS contact_id,
                       CASE WHEN pcs.sender_id = ? THEN u2.role ELSE u1.role END AS contact_role,
                       CASE WHEN pcs.sender_id = ? THEN u2.profile_picture ELSE u1.profile_picture END AS contact_pic,
                       (SELECT message FROM private_chat_messages WHERE chat_session_id = pcs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                       (SELECT created_at FROM private_chat_messages WHERE chat_session_id = pcs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
                       (SELECT COUNT(*) FROM private_chat_messages WHERE chat_session_id = pcs.id AND user_id != ? AND read_status = 'unread') AS unread
                FROM private_chat_sessions pcs
                LEFT JOIN users u1 ON u1.id = pcs.sender_id
                LEFT JOIN users u2 ON u2.id = pcs.recipient_id
                WHERE pcs.sender_id = ? OR pcs.recipient_id = ?
                ORDER BY last_time DESC";
        $st = $conn->prepare($sql);
        $st->bind_param('iiiiiiii', $admin_id, $admin_id, $admin_id, $admin_id, $admin_id, $admin_id, $admin_id);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $r['display_title'] = $r['contact_name'] ?: $r['title'];
            $r['is_participant'] = true;
            $chats[] = $r;
        }
        $st->close();

        // 2. Class group chats
        $sql2 = "SELECT cs.id, cs.title, cs.status, cs.class_id, cs.created_at, cs.teacher_id,
                        c.class_name, 'class' AS chat_type,
                        (SELECT message FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
                        (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id = cs.id AND user_id != ? AND read_status = 'unread') AS unread,
                        (SELECT COUNT(*) FROM chat_participants WHERE chat_session_id = cs.id) AS member_count
                 FROM chat_sessions cs
                 LEFT JOIN classes c ON c.id = cs.class_id
                 WHERE cs.class_id IS NOT NULL AND cs.status = 'active'
                 ORDER BY last_time DESC";
        $st2 = $conn->prepare($sql2);
        $st2->bind_param('i', $admin_id);
        $st2->execute();
        $res2 = $st2->get_result();
        while ($r = $res2->fetch_assoc()) {
            $check = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
            $check->bind_param('ii', $r['id'], $admin_id);
            $check->execute();
            $isPart = $check->get_result()->fetch_assoc();
            $check->close();
            $r['is_participant'] = !empty($isPart);
            $r['display_title'] = $r['title'] . ($r['class_name'] ? " ({$r['class_name']})" : "");
            $r['contact_name'] = $r['class_name'] ?? 'Class Group';
            $r['contact_role'] = 'class';
            $r['contact_pic'] = null;
            $r['contact_id'] = null;
            $chats[] = $r;
        }
        $st2->close();

        // 3. Discussion groups
        $sql3 = "SELECT cs.id, cs.title, cs.status, cs.created_at, cs.group_picture, cs.teacher_id,
                        'discussion' AS chat_type,
                        (SELECT message FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM chat_messages WHERE chat_session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
                        (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id = cs.id AND user_id != ? AND read_status = 'unread') AS unread,
                        (SELECT COUNT(*) FROM chat_participants WHERE chat_session_id = cs.id) AS member_count
                 FROM chat_sessions cs
                 WHERE cs.class_id IS NULL AND cs.parent_id IS NULL AND cs.recipient_id IS NULL AND cs.status = 'active'
                 ORDER BY last_time DESC, cs.created_at DESC";
        $st3 = $conn->prepare($sql3);
        $st3->bind_param('i', $admin_id);
        $st3->execute();
        $res3 = $st3->get_result();
        while ($r = $res3->fetch_assoc()) {
            $check = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
            $check->bind_param('ii', $r['id'], $admin_id);
            $check->execute();
            $isPart = $check->get_result()->fetch_assoc();
            $check->close();
            $r['is_participant'] = !empty($isPart);
            $r['display_title'] = $r['title'];
            $r['contact_name'] = $r['member_count'] . ' members';
            $r['contact_role'] = 'discussion';
            $r['contact_pic'] = $r['group_picture'];
            $r['contact_id'] = null;
            $chats[] = $r;
        }
        $st3->close();

        if ($user_role !== 'all') {
            $chats = array_filter($chats, function($chat) use ($user_role) {
                return $chat['contact_role'] === $user_role;
            });
        }
        if ($filter_type !== 'all') {
            $chats = array_filter($chats, function($chat) use ($filter_type) {
                return $chat['chat_type'] === $filter_type;
            });
        }

        usort($chats, fn($a,$b) => strtotime($b['last_time']??'1970-01-01') - strtotime($a['last_time']??'1970-01-01'));

        echo json_encode(['chats' => array_values($chats)]);
        exit;
    }

    // ---- GET: Join a group/class chat ----
    if (isset($_GET['join_group'])) {
        $sid = (int)$_GET['session_id'];
        $check = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
        $check->bind_param('ii', $sid, $admin_id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) {
            $st = $conn->prepare("INSERT INTO chat_participants (chat_session_id, user_id, joined_at) VALUES (?, ?, NOW())");
            $st->bind_param('ii', $sid, $admin_id);
            $st->execute();
            $st->close();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => true, 'message' => 'Already a member']);
        }
        exit;
    }

    // ---- GET: All available groups for joining ----
    if (isset($_GET['fetch_all_groups'])) {
        $groups = [];
        $sql = "SELECT cs.id, cs.title, cs.group_picture, cs.created_at,
                       (SELECT COUNT(*) FROM chat_participants WHERE chat_session_id = cs.id) AS member_count
                FROM chat_sessions cs
                WHERE cs.class_id IS NULL AND cs.parent_id IS NULL AND cs.recipient_id IS NULL AND cs.status = 'active'
                  AND cs.id NOT IN (SELECT chat_session_id FROM chat_participants WHERE user_id = ?)
                ORDER BY cs.created_at DESC";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $admin_id);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $r['type'] = 'discussion';
            $groups[] = $r;
        }
        $st->close();
        
        $sql2 = "SELECT cs.id, cs.title, cs.class_id, c.class_name, cs.created_at,
                        (SELECT COUNT(*) FROM chat_participants WHERE chat_session_id = cs.id) AS member_count
                 FROM chat_sessions cs
                 LEFT JOIN classes c ON c.id = cs.class_id
                 WHERE cs.class_id IS NOT NULL AND cs.status = 'active'
                   AND cs.id NOT IN (SELECT chat_session_id FROM chat_participants WHERE user_id = ?)
                 ORDER BY cs.created_at DESC";
        $st2 = $conn->prepare($sql2);
        $st2->bind_param('i', $admin_id);
        $st2->execute();
        $res2 = $st2->get_result();
        while ($r = $res2->fetch_assoc()) {
            $r['type'] = 'class';
            $r['display_title'] = $r['title'] . ($r['class_name'] ? " ({$r['class_name']})" : "");
            $groups[] = $r;
        }
        $st2->close();
        
        echo json_encode(['groups' => $groups]);
        exit;
    }

    // ---- GET: Messages for a specific chat ----
    if (isset($_GET['fetch_messages'])) {
        $sid = (int)$_GET['chat_session_id'];
        $is_priv = (int)($_GET['is_private'] ?? 0);
        $last_id = (int)($_GET['last_id'] ?? 0);

        if ($is_priv) {
            $sql = "SELECT pm.id, pm.user_id, pm.message, pm.message_media, pm.read_status, pm.created_at,
                           u.name, u.role, u.profile_picture
                    FROM private_chat_messages pm
                    LEFT JOIN users u ON u.id = pm.user_id
                    WHERE pm.chat_session_id = ? AND pm.id > ?
                    ORDER BY pm.created_at ASC";
        } else {
            $sql = "SELECT cm.id, cm.user_id, cm.message, cm.message_media, cm.read_status, cm.created_at,
                           u.name, u.role, u.profile_picture
                    FROM chat_messages cm
                    LEFT JOIN users u ON u.id = cm.user_id
                    WHERE cm.chat_session_id = ? AND cm.id > ?
                    ORDER BY cm.created_at ASC";
        }
        $st = $conn->prepare($sql);
        $st->bind_param('ii', $sid, $last_id);
        $st->execute();
        $res = $st->get_result();
        $msgs = [];
        while ($r = $res->fetch_assoc()) $msgs[] = $r;
        $st->close();

        if ($is_priv) {
            $mr = $conn->prepare("UPDATE private_chat_messages SET read_status='read' WHERE chat_session_id = ? AND user_id != ? AND read_status='unread'");
        } else {
            $mr = $conn->prepare("UPDATE chat_messages SET read_status='read' WHERE chat_session_id = ? AND user_id != ? AND read_status='unread'");
        }
        $mr->bind_param('ii', $sid, $admin_id);
        $mr->execute();
        $mr->close();

        echo json_encode(['messages' => $msgs]);
        exit;
    }

    // ---- GET: Users for new chat ----
    if (isset($_GET['fetch_users'])) {
        $role = $_GET['role'] ?? 'all';
        $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
        $search = $_GET['search'] ?? '';

        if ($class_id && $role === 'student') {
            $sql = "SELECT u.id, u.name, u.role, u.profile_picture, u.email
                    FROM users u
                    JOIN student_classes sc ON sc.user_id = u.id
                    WHERE u.role = 'student' AND u.status = 'active' AND u.id != ? AND sc.class_id = ?
                    ORDER BY u.name";
            $st = $conn->prepare($sql);
            $st->bind_param('ii', $admin_id, $class_id);
        } else {
            $allowed = ['teacher', 'admin', 'parent', 'student'];
            if (!in_array($role, $allowed)) $role = 'all';
            if ($role !== 'all') {
                $sql = "SELECT id, name, role, profile_picture, email FROM users WHERE role = ? AND status = 'active' AND id != ? ORDER BY name";
                $st = $conn->prepare($sql);
                $st->bind_param('si', $role, $admin_id);
            } else {
                $sql = "SELECT id, name, role, profile_picture, email FROM users WHERE status = 'active' AND id != ? ORDER BY name";
                $st = $conn->prepare($sql);
                $st->bind_param('i', $admin_id);
            }
        }
        $st->execute();
        $res = $st->get_result();
        $users = [];
        while ($r = $res->fetch_assoc()) $users[] = $r;
        $st->close();

        if (!empty($search)) {
            $search_lower = strtolower($search);
            $users = array_filter($users, function($u) use ($search_lower) {
                return strpos(strtolower($u['name']), $search_lower) !== false ||
                       strpos(strtolower($u['email'] ?? ''), $search_lower) !== false;
            });
            $users = array_values($users);
        }
        echo json_encode($users);
        exit;
    }

    // ---- GET: Statistics ----
    if (isset($_GET['fetch_stats'])) {
        $stats = [];
        
        $res = $conn->query("SELECT COUNT(*) as total FROM private_chat_sessions WHERE sender_id = $admin_id OR recipient_id = $admin_id");
        $stats['active_private'] = $res->fetch_assoc()['total'] ?? 0;
        
        $res = $conn->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
        $stats['total_users'] = $res->fetch_assoc()['total'] ?? 0;
        
        $res_priv = $conn->query("SELECT COUNT(*) as total FROM private_chat_messages");
        $res_group = $conn->query("SELECT COUNT(*) as total FROM chat_messages");
        $stats['total_messages'] = ($res_priv->fetch_assoc()['total'] ?? 0) + ($res_group->fetch_assoc()['total'] ?? 0);
        
        $res = $conn->query("SELECT COUNT(*) as total FROM private_chat_messages WHERE chat_session_id IN (SELECT id FROM private_chat_sessions WHERE sender_id = $admin_id OR recipient_id = $admin_id) AND user_id != $admin_id AND read_status='unread'");
        $stats['unread_count'] = $res->fetch_assoc()['total'] ?? 0;
        $res = $conn->query("SELECT COUNT(*) as total FROM chat_messages cm JOIN chat_participants cp ON cm.chat_session_id = cp.chat_session_id WHERE cp.user_id = $admin_id AND cm.user_id != $admin_id AND cm.read_status='unread'");
        $stats['unread_count'] += $res->fetch_assoc()['total'] ?? 0;
        
        $res = $conn->query("SELECT COUNT(*) as total FROM chat_sessions WHERE status = 'active' AND class_id IS NULL");
        $stats['active_groups'] = $res->fetch_assoc()['total'] ?? 0;
        
        $res = $conn->query("SELECT COUNT(*) as total FROM chat_sessions WHERE status = 'active' AND class_id IS NOT NULL");
        $stats['active_classes'] = $res->fetch_assoc()['total'] ?? 0;
        
        echo json_encode($stats);
        exit;
    }

    // ---- GET: Classes ----
    if (isset($_GET['fetch_classes'])) {
        $sql = "SELECT id, class_name FROM classes ORDER BY class_name";
        $res = $conn->query($sql);
        $classes = [];
        while ($r = $res->fetch_assoc()) $classes[] = $r;
        echo json_encode($classes);
        exit;
    }

    // ---- GET: Group members ----
    if (isset($_GET['fetch_group_members'])) {
        $sid = (int)$_GET['session_id'];
        $sql = "SELECT u.id, u.name, u.role, u.profile_picture
                FROM chat_participants cp
                JOIN users u ON u.id = cp.user_id
                WHERE cp.chat_session_id = ?
                ORDER BY u.name";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $sid);
        $st->execute();
        $res = $st->get_result();
        $members = [];
        while ($r = $res->fetch_assoc()) $members[] = $r;
        $st->close();
        echo json_encode($members);
        exit;
    }

    // ---- POST actions ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }

        $action = $_POST['action'];

        // Send message
        if ($action === 'send_message') {
            $sid = (int)$_POST['chat_session_id'];
            $is_priv = (int)($_POST['is_private'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $media = null;
            
            if (isset($_FILES['message_media']) && $_FILES['message_media']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['message_media']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp','mp4','mov','pdf','doc','docx'];
                if (in_array($ext, $allowed)) {
                    $dir = __DIR__.'/uploads/chat_media/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fn = 'msg_'.time().'_'.uniqid().'.'.$ext;
                    if (move_uploaded_file($_FILES['message_media']['tmp_name'], $dir.$fn)) {
                        $media = 'uploads/chat_media/'.$fn;
                    }
                }
            }
            
            if (!$message && !$media) {
                echo json_encode(['error'=>'Empty message']);
                exit;
            }

            if ($is_priv) {
                $chk = $conn->prepare("SELECT id FROM private_chat_sessions WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
                $chk->bind_param('iii', $sid, $admin_id, $admin_id);
                $chk->execute();
                if (!$chk->get_result()->fetch_assoc()) {
                    echo json_encode(['error'=>'Not a participant']);
                    exit;
                }
                $chk->close();
                $st = $conn->prepare("INSERT INTO private_chat_messages (chat_session_id, user_id, message, message_media, created_at, read_status, is_active, last_activity) VALUES (?, ?, ?, ?, NOW(), 'unread', 1, NOW())");
                $st->bind_param('iiss', $sid, $admin_id, $message, $media);
            } else {
                $chk = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
                $chk->bind_param('ii', $sid, $admin_id);
                $chk->execute();
                if (!$chk->get_result()->fetch_assoc()) {
                    echo json_encode(['error'=>'Not a participant']);
                    exit;
                }
                $chk->close();
                $st = $conn->prepare("INSERT INTO chat_messages (chat_session_id, user_id, message, message_media, created_at, read_status, is_active, last_activity) VALUES (?, ?, ?, ?, NOW(), 'unread', 1, NOW())");
                $st->bind_param('iiss', $sid, $admin_id, $message, $media);
                $conn->query("UPDATE chat_sessions SET updated_at = NOW() WHERE id = $sid");
            }
            
            if ($st->execute()) {
                $st->close();
                echo json_encode(['success'=>true]);
            } else {
                echo json_encode(['error'=>'DB error: '.$st->error]);
            }
            exit;
        }

        // Create private chat
        if ($action === 'create_private_chat') {
            $rid = (int)$_POST['recipient_id'];
            $user_check = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? AND status = 'active'");
            $user_check->bind_param('i', $rid);
            $user_check->execute();
            $recipient = $user_check->get_result()->fetch_assoc();
            $user_check->close();
            if (!$recipient) {
                echo json_encode(['error' => 'User not found or inactive']);
                exit;
            }
            $chk = $conn->prepare("SELECT id FROM private_chat_sessions WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?) AND status != 'closed'");
            $chk->bind_param('iiii', $admin_id, $rid, $rid, $admin_id);
            $chk->execute();
            $ex = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($ex) {
                echo json_encode(['success' => true, 'chat_id' => $ex['id'], 'chat_type' => 'private']);
                exit;
            }
            $token = bin2hex(random_bytes(16));
            $title = 'Chat with ' . $recipient['name'];
            $st = $conn->prepare("INSERT INTO private_chat_sessions (sender_id, recipient_id, session_token, title, status, start_time, term_id) VALUES (?, ?, ?, ?, 'active', NOW(), ?)");
            $st->bind_param('iissi', $admin_id, $rid, $token, $title, $active_term_id);
            $st->execute();
            $new_id = $st->insert_id;
            $st->close();
            echo json_encode(['success' => true, 'chat_id' => $new_id, 'chat_type' => 'private', 'display_title' => $title]);
            exit;
        }

        // Create discussion group
        if ($action === 'create_discussion_group') {
            $title = trim($_POST['group_title'] ?? '');
            $members = json_decode($_POST['member_ids'] ?? '[]', true);
            if (!$title) { echo json_encode(['error'=>'Title is required']); exit; }
            if (count($members) < 1) { echo json_encode(['error'=>'Add at least one member']); exit; }

            $grp_pic = null;
            if (!empty($_FILES['group_picture']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['group_picture']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $dir = __DIR__.'/uploads/chat_pics/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fn = 'grp_'.time().'_'.uniqid().'.'.$ext;
                    move_uploaded_file($_FILES['group_picture']['tmp_name'], $dir.$fn);
                    $grp_pic = 'uploads/chat_pics/'.$fn;
                }
            }
            $token = bin2hex(random_bytes(16));
            $st = $conn->prepare("INSERT INTO chat_sessions (teacher_id, session_token, title, group_picture, status, start_time, is_real_time, created_at) VALUES (?, ?, ?, ?, 'active', NOW(), 1, NOW())");
            $st->bind_param('isss', $admin_id, $token, $title, $grp_pic);
            $st->execute();
            $sid = $st->insert_id;
            $st->close();

            $ins = $conn->prepare("INSERT INTO chat_participants (chat_session_id, user_id, joined_at) VALUES (?, ?, NOW())");
            $ins->bind_param('ii', $sid, $admin_id);
            $ins->execute();
            foreach ($members as $mid) {
                $mid = (int)$mid;
                if ($mid > 0 && $mid != $admin_id) {
                    $ins->bind_param('ii', $sid, $mid);
                    $ins->execute();
                }
            }
            $ins->close();
            echo json_encode(['success'=>true, 'chat_id'=>$sid, 'chat_type'=>'discussion', 'display_title'=>$title]);
            exit;
        }

        // Add member to group
        if ($action === 'add_group_member') {
            $sid = (int)$_POST['session_id'];
            $uid = (int)$_POST['user_id'];
            $check = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
            $check->bind_param('ii', $sid, $admin_id);
            $check->execute();
            if (!$check->get_result()->fetch_assoc()) {
                echo json_encode(['error' => 'You are not a member of this group']);
                exit;
            }
            $check->close();
            $chk2 = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
            $chk2->bind_param('ii', $sid, $uid);
            $chk2->execute();
            if ($chk2->get_result()->fetch_assoc()) {
                echo json_encode(['success'=>true, 'message'=>'Already a member']);
                exit;
            }
            $chk2->close();
            $st = $conn->prepare("INSERT INTO chat_participants (chat_session_id, user_id, joined_at) VALUES (?, ?, NOW())");
            $st->bind_param('ii', $sid, $uid);
            $st->execute();
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Remove member from group
        if ($action === 'remove_group_member') {
            $sid = (int)$_POST['session_id'];
            $uid = (int)$_POST['user_id'];
            if ($uid == $admin_id) {
                echo json_encode(['error'=>'Cannot remove yourself']);
                exit;
            }
            $check = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
            $check->bind_param('ii', $sid, $admin_id);
            $check->execute();
            if (!$check->get_result()->fetch_assoc()) {
                echo json_encode(['error'=>'You are not a member of this group']);
                exit;
            }
            $check->close();
            $st = $conn->prepare("DELETE FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
            $st->bind_param('ii', $sid, $uid);
            $st->execute();
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Delete message
        if ($action === 'delete_message') {
            $mid = (int)$_POST['message_id'];
            $is_priv = (int)($_POST['is_private'] ?? 0);
            $table = $is_priv ? 'private_chat_messages' : 'chat_messages';
            $st = $conn->prepare("DELETE FROM $table WHERE id = ? AND user_id = ?");
            $st->bind_param('ii', $mid, $admin_id);
            $st->execute();
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Archive chat
        if ($action === 'archive_chat') {
            $sid = (int)$_POST['chat_session_id'];
            $is_priv = (int)($_POST['is_private'] ?? 0);
            if ($is_priv) {
                $st = $conn->prepare("UPDATE private_chat_sessions SET status = 'closed', end_time = NOW() WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
                $st->bind_param('iii', $sid, $admin_id, $admin_id);
            } else {
                $st = $conn->prepare("UPDATE chat_sessions SET status = 'closed', end_time = NOW() WHERE id = ?");
                $st->bind_param('i', $sid);
            }
            $st->execute();
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Reopen chat
        if ($action === 'reopen_chat') {
            $sid = (int)$_POST['chat_session_id'];
            $is_priv = (int)($_POST['is_private'] ?? 0);
            if ($is_priv) {
                $st = $conn->prepare("UPDATE private_chat_sessions SET status = 'active', end_time = NULL WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
                $st->bind_param('iii', $sid, $admin_id, $admin_id);
            } else {
                $st = $conn->prepare("UPDATE chat_sessions SET status = 'active', end_time = NULL WHERE id = ?");
                $st->bind_param('i', $sid);
            }
            $st->execute();
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Delete entire chat
        if ($action === 'delete_chat') {
            $sid = (int)$_POST['session_id'];
            $type = $_POST['chat_type'] ?? 'private';
            try {
                $conn->begin_transaction();
                if ($type === 'private') {
                    $st = $conn->prepare("DELETE FROM private_chat_messages WHERE chat_session_id = ?");
                    $st->bind_param('i', $sid);
                    $st->execute();
                    $st->close();
                    $st = $conn->prepare("DELETE FROM private_chat_sessions WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
                    $st->bind_param('iii', $sid, $admin_id, $admin_id);
                } else {
                    $st = $conn->prepare("DELETE FROM chat_messages WHERE chat_session_id = ?");
                    $st->bind_param('i', $sid);
                    $st->execute();
                    $st->close();
                    $st = $conn->prepare("DELETE FROM chat_participants WHERE chat_session_id = ?");
                    $st->bind_param('i', $sid);
                    $st->execute();
                    $st->close();
                    $st = $conn->prepare("DELETE FROM chat_sessions WHERE id = ?");
                    $st->bind_param('i', $sid);
                }
                $st->execute();
                $affected = $st->affected_rows;
                $st->close();
                $conn->commit();
                echo json_encode(['success' => $affected > 0]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['error' => 'Failed to delete chat']);
            }
            exit;
        }

        // Mark all read
        if ($action === 'mark_all_read') {
            $conn->query("UPDATE private_chat_messages SET read_status='read' WHERE chat_session_id IN (SELECT id FROM private_chat_sessions WHERE sender_id = $admin_id OR recipient_id = $admin_id) AND user_id != $admin_id AND read_status='unread'");
            $conn->query("UPDATE chat_messages cm JOIN chat_participants cp ON cm.chat_session_id = cp.chat_session_id SET cm.read_status='read' WHERE cp.user_id = $admin_id AND cm.user_id != $admin_id AND cm.read_status='unread'");
            echo json_encode(['success'=>true]);
            exit;
        }

        // Mark read for specific chat
        if ($action === 'mark_read') {
            $sid = (int)$_POST['chat_session_id'];
            $is_priv = (int)($_POST['is_private'] ?? 0);
            if ($is_priv) {
                $st = $conn->prepare("UPDATE private_chat_messages SET read_status='read' WHERE chat_session_id = ? AND user_id != ? AND read_status='unread'");
            } else {
                $st = $conn->prepare("UPDATE chat_messages SET read_status='read' WHERE chat_session_id = ? AND user_id != ? AND read_status='unread'");
            }
            $st->bind_param('ii', $sid, $admin_id);
            $st->execute();
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        echo json_encode(['error'=>'Unknown action']);
        exit;
    }

    echo json_encode(['error'=>'Bad request']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Unified Live Chat — Admin Portal | QueenSpa</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/emoji-mart@latest/dist/browser.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/emoji-mart@latest/css/emoji-mart.css">
<style>
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
    --blue:     #3B82F6;
    --red:      #EF4444;
    --purple:   #8B5CF6;
    --shadow:   0 2px 12px rgba(0,0,0,.08);
    --shadow-or:0 4px 18px rgba(249,115,22,.22);
    --r-sm:     8px;
    --r-md:     14px;
    --r-lg:     20px;
    --sidebar:  360px;
    --topbar:   64px;
    --ease:     .2s cubic-bezier(.4,0,.2,1);
}

[data-theme="dark"] {
    --bg: #0f172a;
    --white: #1e293b;
    --grey-50: #334155;
    --grey-100: #475569;
    --grey-200: #334155;
    --grey-300: #475569;
    --grey-400: #94a3b8;
    --grey-500: #cbd5e1;
    --grey-700: #e2e8f0;
    --grey-900: #f1f5f9;
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

*{scrollbar-width:thin;scrollbar-color:var(--or-l) transparent}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-thumb{background:var(--or-l);border-radius:4px}

.topbar{
    height:var(--topbar);
    background:var(--white);
    border-bottom:1px solid var(--grey-200);
    display:flex;align-items:center;gap:14px;
    padding:0 22px;
    flex-shrink:0;
    box-shadow:var(--shadow);
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

.layout{display:flex;flex:1;overflow:hidden}

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

.role-filter{
    display:flex;gap:8px;
    padding:10px 18px 0;flex-shrink:0;flex-wrap:wrap;
}
.role-chip{
    padding:4px 12px;border-radius:20px;
    background:var(--grey-50);border:1px solid var(--grey-200);
    font-size:.7rem;font-weight:500;color:var(--grey-500);
    cursor:pointer;transition:var(--ease);
}
.role-chip.active{
    background:var(--or);border-color:var(--or);color:#fff;
}

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
.chat-item-avi.private{background:linear-gradient(135deg,#3B82F6,#1D4ED8)}
.chat-item-avi.class  {background:linear-gradient(135deg,#8B5CF6,#6D28D9)}
.chat-item-avi.discussion{background:linear-gradient(135deg,var(--or),var(--or-d))}
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

.join-badge {
    background: var(--green);
    color: white;
    font-size: .6rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 50px;
    margin-left: 6px;
    display: inline-block;
}

.chat-list-empty{text-align:center;padding:36px 16px;color:var(--grey-400)}
.chat-list-empty i{font-size:2rem;margin-bottom:8px;display:block}
.chat-list-empty p{font-size:.82rem}

.new-chat-btns{
    padding:12px 14px;border-top:1px solid var(--grey-100);
    display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;
}
.new-chat-btn{
    flex:1;padding:8px 10px;border-radius:var(--r-sm);border:none;
    font-size:.75rem;font-weight:600;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:6px;
    transition:var(--ease);
}
.new-chat-btn-private{background:linear-gradient(135deg,#EFF6FF,#DBEAFE);color:#1D4ED8}
.new-chat-btn-private:hover{background:linear-gradient(135deg,#DBEAFE,#BFDBFE)}
.new-chat-btn-group{background:linear-gradient(135deg,var(--or-pale),var(--or-l));color:var(--or-d)}
.new-chat-btn-group:hover{background:linear-gradient(135deg,var(--or-l),#FDBA74)}
.new-chat-btn-join{background:linear-gradient(135deg,#DCFCE7,#BBF7D0);color:#15803D}
.new-chat-btn-join:hover{background:linear-gradient(135deg,#BBF7D0,#86EFAC)}

.chat-panel{
    flex:1;display:flex;flex-direction:column;
    overflow:hidden;background:var(--bg);
    position:relative;
}

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
.chat-topbar-avi.private{background:linear-gradient(135deg,#3B82F6,#1D4ED8)}
.chat-topbar-avi.class  {background:linear-gradient(135deg,#8B5CF6,#6D28D9)}
.chat-topbar-avi.discussion{background:linear-gradient(135deg,var(--or),var(--or-d))}
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
.icon-btn.red:hover{background:#FEE2E2;border-color:#FECACA;color:var(--red)}

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

.chat-input{
    padding:12px 18px;background:var(--white);
    border-top:1px solid var(--grey-200);flex-shrink:0;
    position:relative;
}

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

.stats-bar{
    background:var(--white);
    border-bottom:1px solid var(--grey-200);
    padding:12px 24px;
    display:flex;
    gap:24px;
    flex-shrink:0;
    overflow-x:auto;
}
.stat-card{
    display:flex;align-items:center;gap:12px;
    padding:8px 16px;
    background:var(--grey-50);
    border-radius:var(--r-md);
    cursor:pointer;
    transition:var(--ease);
}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.stat-icon{
    width:40px;height:40px;border-radius:var(--r-sm);
    display:grid;place-items:center;font-size:1.2rem;
}
.stat-icon.orange{background:var(--or-pale);color:var(--or)}
.stat-icon.blue{background:#EFF6FF;color:#3B82F6}
.stat-icon.purple{background:#F5F3FF;color:#8B5CF6}
.stat-info h4{font-size:1.5rem;font-weight:700;color:var(--grey-900)}
.stat-info p{font-size:.75rem;color:var(--grey-400)}

.unified-feed{
    flex:1;display:flex;flex-direction:column;
    overflow:hidden;background:var(--bg);
}
.feed-header{
    padding:12px 18px;
    background:var(--white);
    border-bottom:1px solid var(--grey-200);
    display:flex;align-items:center;justify-content:space-between;
    flex-shrink:0;
}
.feed-header h3{
    font-family:'Sora',sans-serif;font-size:.9rem;font-weight:700;
    display:flex;align-items:center;gap:8px;
}
.feed-filters{
    display:flex;gap:8px;
}
.feed-filter-btn{
    padding:4px 12px;border-radius:20px;
    font-size:.7rem;font-weight:500;
    border:1px solid var(--grey-200);
    background:var(--grey-50);
    cursor:pointer;
    transition:var(--ease);
}
.feed-filter-btn.active{
    background:var(--or);border-color:var(--or);color:#fff;
}
.feed-messages{
    flex:1;overflow-y:auto;padding:16px;
}
.feed-message-item{
    background:var(--white);
    border-radius:var(--r-md);
    padding:12px 16px;
    margin-bottom:12px;
    box-shadow:var(--shadow);
    transition:var(--ease);
    border-left:3px solid transparent;
}
.feed-message-item.private{border-left-color:#3B82F6}
.feed-message-item.class{border-left-color:#8B5CF6}
.feed-message-item.discussion{border-left-color:var(--or)}
.feed-message-header{
    display:flex;align-items:center;gap:10px;
    margin-bottom:8px;
    flex-wrap:wrap;
}
.feed-user-avatar{
    width:32px;height:32px;border-radius:50%;
    background:linear-gradient(135deg,var(--or),var(--or-d));
    display:grid;place-items:center;color:#fff;
    font-size:.75rem;font-weight:600;
    overflow:hidden;
}
.feed-user-avatar img{width:100%;height:100%;object-fit:cover}
.feed-user-info{
    flex:1;
}
.feed-user-name{
    font-weight:600;font-size:.85rem;color:var(--grey-900);
}
.feed-chat-context{
    font-size:.7rem;color:var(--grey-400);
}
.feed-badge{
    font-size:.6rem;padding:2px 8px;border-radius:50px;
}
.feed-badge.private{background:#EFF6FF;color:#1D4ED8}
.feed-badge.class{background:#F5F3FF;color:#6D28D9}
.feed-badge.discussion{background:var(--or-pale);color:var(--or)}
.feed-message-content{
    font-size:.82rem;color:var(--grey-700);
    margin:8px 0;
    word-break:break-word;
}
.feed-message-time{
    font-size:.65rem;color:var(--grey-400);
    text-align:right;
}

.user-monitor-panel{
    width:280px;flex-shrink:0;
    background:var(--white);
    border-left:1px solid var(--grey-200);
    display:flex;flex-direction:column;
    overflow:hidden;
}
.monitor-header{
    padding:14px 16px;
    border-bottom:1px solid var(--grey-100);
    flex-shrink:0;
}
.monitor-header h4{
    font-family:'Sora',sans-serif;font-size:.85rem;font-weight:700;
    display:flex;align-items:center;gap:6px;
}
.monitor-search{
    margin-top:10px;
    position:relative;
}
.monitor-search input{
    width:100%;padding:7px 10px 7px 30px;
    border:1px solid var(--grey-200);border-radius:var(--r-sm);
    font-size:.75rem;background:var(--grey-50);
}
.monitor-search i{
    position:absolute;left:10px;top:50%;transform:translateY(-50%);
    font-size:.7rem;color:var(--grey-400);
}
.user-list-monitor{
    flex:1;overflow-y:auto;padding:8px;
}
.monitor-user-item{
    display:flex;align-items:center;gap:10px;
    padding:8px 10px;
    border-radius:var(--r-sm);
    cursor:pointer;
    transition:var(--ease);
    margin-bottom:4px;
}
.monitor-user-item:hover{background:var(--grey-50)}
.monitor-user-item.active{background:var(--or-pale);border-left:3px solid var(--or)}
.monitor-user-avatar{
    width:36px;height:36px;border-radius:50%;
    background:linear-gradient(135deg,var(--or),var(--or-d));
    display:grid;place-items:center;color:#fff;
    font-weight:600;font-size:.8rem;
    overflow:hidden;
    flex-shrink:0;
}
.monitor-user-avatar img{width:100%;height:100%;object-fit:cover}
.monitor-user-info{flex:1;min-width:0}
.monitor-user-name{
    font-weight:600;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.monitor-user-role{
    font-size:.65rem;color:var(--grey-400);
}
.monitor-user-badge{
    background:var(--or);color:#fff;
    font-size:.6rem;padding:2px 5px;border-radius:50px;
}

.overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.4);
    backdrop-filter:blur(4px);z-index:200;
    display:none;align-items:center;justify-content:center;padding:20px;
}
.overlay.open{display:flex}

.modal{
    background:var(--white);border-radius:var(--r-lg);
    width:100%;max-width:480px;max-height:90vh;overflow-y:auto;
    box-shadow:0 20px 60px rgba(0,0,0,.15);
    animation:mIn .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes mIn{from{opacity:0;transform:scale(.93) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}

.modal-head{
    display:flex;align-items:center;gap:12px;
    padding:20px 22px 16px;border-bottom:1px solid var(--grey-100);
}
.modal-icon{
    width:42px;height:42px;border-radius:12px;
    display:grid;place-items:center;font-size:1.05rem;flex-shrink:0;
}
.modal-icon.orange{background:var(--or-pale);color:var(--or)}
.modal-icon.blue{background:#EFF6FF;color:#2563EB}
.modal-head-text h3{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;color:var(--grey-900)}
.modal-head-text p{font-size:.75rem;color:var(--grey-400);margin-top:2px}
.modal-close{
    margin-left:auto;width:30px;height:30px;border-radius:var(--r-sm);
    border:1px solid var(--grey-200);background:transparent;color:var(--grey-500);
    cursor:pointer;display:grid;place-items:center;font-size:.8rem;transition:var(--ease);
}
.modal-close:hover{background:var(--grey-50)}

.modal-body{padding:18px 22px}
.modal-foot{padding:14px 22px 20px;border-top:1px solid var(--grey-100);display:flex;gap:8px;justify-content:flex-end}

.form-group{margin-bottom:14px}
.form-label{
    display:block;font-size:.74rem;font-weight:600;color:var(--grey-700);
    margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;
}
.form-ctrl{
    width:100%;padding:9px 12px;border:1.5px solid var(--grey-200);
    border-radius:var(--r-sm);font-size:.84rem;font-family:inherit;
    background:var(--grey-50);color:var(--grey-900);outline:none;transition:var(--ease);
}
.form-ctrl:focus{border-color:var(--or);background:#fff;box-shadow:0 0 0 3px var(--or-glow)}

.btn{
    padding:9px 18px;border-radius:var(--r-sm);
    font-size:.84rem;font-weight:600;cursor:pointer;border:none;
    font-family:inherit;display:flex;align-items:center;gap:6px;transition:var(--ease);
}
.btn-primary{background:var(--or);color:#fff;box-shadow:var(--shadow-or)}
.btn-primary:hover{background:var(--or-d);transform:translateY(-1px)}
.btn-secondary{background:var(--grey-100);color:var(--grey-700);border:1px solid var(--grey-200)}
.btn-secondary:hover{background:var(--grey-200)}

.members-select-list{
    max-height:250px;overflow-y:auto;
    border:1px solid var(--grey-200);border-radius:var(--r-sm);
}
.member-check-item{
    display:flex;align-items:center;gap:10px;
    padding:10px;cursor:pointer;transition:var(--ease);
    border-bottom:1px solid var(--grey-100);
}
.member-check-item:hover{background:var(--or-pale)}
.member-check-avatar{
    width:36px;height:36px;border-radius:50%;
    background:linear-gradient(135deg,var(--or),var(--or-d));
    display:grid;place-items:center;color:#fff;font-weight:600;
    flex-shrink:0;overflow:hidden;
}
.member-check-avatar img{width:100%;height:100%;object-fit:cover}
.member-check-name{font-weight:500;font-size:.85rem}
.member-check-role{font-size:.7rem;color:var(--grey-400)}

.pic-drop{
    border:2px dashed var(--grey-200);border-radius:var(--r-sm);
    padding:20px;text-align:center;cursor:pointer;transition:var(--ease);
    position:relative;overflow:hidden;
}
.pic-drop:hover{border-color:var(--or);background:var(--or-pale)}
.pic-drop input{position:absolute;inset:0;opacity:0;cursor:pointer}
.pic-drop i{font-size:1.5rem;color:var(--grey-300);margin-bottom:6px;display:block}
.pic-drop p{font-size:.76rem;color:var(--grey-400)}

.members-panel{
    width:0;overflow:hidden;background:var(--white);
    border-left:0 solid var(--grey-200);
    display:flex;flex-direction:column;
    transition:.3s cubic-bezier(.4,0,.2,1);
    flex-shrink:0;
}
.members-panel.open{width:240px;border-left-width:1px}
.members-panel-head{
    padding:14px 16px;border-bottom:1px solid var(--grey-100);
    display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
}
.members-panel-head h4{font-family:'Sora',sans-serif;font-size:.85rem;font-weight:700;color:var(--grey-900)}
.members-list{flex:1;overflow-y:auto;padding:8px 10px}
.member-item{
    display:flex;align-items:center;gap:9px;
    padding:7px 8px;border-radius:var(--r-sm);
    transition:var(--ease);
}
.member-item:hover{background:var(--grey-50)}
.member-avi{
    width:32px;height:32px;border-radius:50%;
    background:linear-gradient(135deg,var(--or),var(--or-d));
    display:grid;place-items:center;color:#fff;font-size:.7rem;font-weight:700;
    flex-shrink:0;overflow:hidden;
}
.member-avi img{width:100%;height:100%;object-fit:cover}
.member-name{flex:1;font-size:.78rem;font-weight:500;color:var(--grey-700)}
.member-role{font-size:.65rem;color:var(--grey-400)}
.member-remove{
    background:none;border:none;color:var(--grey-300);cursor:pointer;
    font-size:.75rem;padding:3px;transition:var(--ease);
}
.member-remove:hover{color:var(--red)}
.add-member-row{
    padding:10px 12px;border-top:1px solid var(--grey-100);flex-shrink:0;
}
.add-member-btn{
    width:100%;padding:8px;border-radius:var(--r-sm);
    border:1.5px dashed var(--or-l);background:var(--or-pale);
    color:var(--or-d);font-size:.78rem;font-weight:600;cursor:pointer;
    font-family:inherit;transition:var(--ease);display:flex;align-items:center;justify-content:center;gap:6px;
}
.add-member-btn:hover{border-color:var(--or);background:var(--or-l);color:var(--or-d)}

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
.type-badge.private{background:#EFF6FF;color:#1D4ED8}
.type-badge.class{background:#F5F3FF;color:#6D28D9}
.type-badge.discussion{background:var(--or-pale);color:var(--or-d)}

@media(max-width:768px){
    .sidebar{position:absolute;inset:0;z-index:50;transition:.3s;background:var(--white)}
    .sidebar.hidden{transform:translateX(-100%)}
    .user-monitor-panel{display:none}
    :root{--sidebar:85%}
    .stats-bar{gap:12px;padding:12px}
    .stat-card{padding:6px 12px}
    .stat-icon{width:32px;height:32px}
    .stat-info h4{font-size:1.2rem}
}
</style>
</head>
<body>

<header class="topbar">
    <a href="dashboard.php" class="topbar-brand">
        <div class="topbar-brand-icon"><i class="fas fa-graduation-cap"></i></div>
        QueenSpa
    </a>
    <div class="topbar-divider"></div>
    <span class="topbar-title">Unified Live Chat Portal (Admin)</span>
    <div class="topbar-right">
        <button class="icon-btn" id="themeToggle" onclick="toggleTheme()"><i class="fas fa-moon"></i></button>
        <button class="icon-btn" id="toggleMonitorBtn" onclick="toggleMonitorPanel()" title="User Monitor">
            <i class="fas fa-users"></i>
        </button>
        <a href="dashboard.php" class="t-btn t-btn-ghost"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
</header>

<div class="stats-bar">
    <div class="stat-card" onclick="loadChats()">
        <div class="stat-icon orange"><i class="fas fa-comments"></i></div>
        <div class="stat-info"><h4 id="statActive">0</h4><p>Active Chats</p></div>
    </div>
    <div class="stat-card" onclick="markAllRead()">
        <div class="stat-icon orange"><i class="fas fa-envelope"></i></div>
        <div class="stat-info"><h4 id="statUnread">0</h4><p>Unread</p></div>
    </div>
    <div class="stat-card" onclick="loadUnifiedFeed()">
        <div class="stat-icon blue"><i class="fas fa-stream"></i></div>
        <div class="stat-info"><h4 id="statTotalMessages">0</h4><p>Total Msgs</p></div>
    </div>
    <div class="stat-card" onclick="openPrivateModal()">
        <div class="stat-icon orange"><i class="fas fa-plus"></i></div>
        <div class="stat-info"><h4>New</h4><p>Start Chat</p></div>
    </div>
</div>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <div class="sidebar-head-top">
                <h2><i class="fas fa-comments" style="color:var(--or)"></i> Conversations <span class="badge-pill" id="totalBadge">0</span></h2>
                <button class="icon-btn" onclick="openJoinGroupModal()" style="width:auto;padding:0 8px;" title="Join existing group/class chat">
                    <i class="fas fa-sign-in-alt"></i> Join
                </button>
            </div>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search conversations…" oninput="renderChats()">
            </div>
        </div>
        <div class="chat-tabs">
            <button class="chat-tab active" data-filter="all" onclick="setTab(this)">All</button>
            <button class="chat-tab" data-filter="private" onclick="setTab(this)">Private</button>
            <button class="chat-tab" data-filter="class" onclick="setTab(this)">Class</button>
            <button class="chat-tab" data-filter="discussion" onclick="setTab(this)">Groups</button>
        </div>
        <div class="role-filter" id="roleFilter">
            <span class="role-chip active" data-role="all" onclick="setRoleFilter(this)">All</span>
            <span class="role-chip" data-role="parent" onclick="setRoleFilter(this)">Parents</span>
            <span class="role-chip" data-role="student" onclick="setRoleFilter(this)">Students</span>
            <span class="role-chip" data-role="teacher" onclick="setRoleFilter(this)">Teachers</span>
            <span class="role-chip" data-role="discussion" onclick="setRoleFilter(this)">Groups</span>
        </div>
        <div class="chat-list" id="chatList">
            <div class="chat-list-empty"><i class="fas fa-spinner fa-spin"></i><p>Loading…</p></div>
        </div>
        <div class="new-chat-btns">
            <button class="new-chat-btn new-chat-btn-private" onclick="openPrivateModal()">
                <i class="fas fa-user-plus"></i> Private Chat
            </button>
            <button class="new-chat-btn new-chat-btn-group" onclick="openGroupModal()">
                <i class="fas fa-users"></i> New Group
            </button>
            <button class="new-chat-btn new-chat-btn-join" onclick="openJoinGroupModal()">
                <i class="fas fa-sign-in-alt"></i> Join
            </button>
        </div>
    </aside>

    <main class="chat-panel" id="chatPanel">
        <div class="chat-empty" id="chatEmpty">
            <div class="chat-empty-icon"><i class="fas fa-comments"></i></div>
            <h3>Select a Conversation</h3>
            <p>Choose a chat from the left, or view the unified feed</p>
            <button class="t-btn t-btn-primary" style="margin-top:16px" onclick="loadUnifiedFeed()">
                <i class="fas fa-stream"></i> View Unified Feed
            </button>
        </div>

        <div id="activeChat" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
            <div class="chat-topbar">
                <button class="icon-btn" id="mobileBackBtn" style="display:none" onclick="mobileBack()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-topbar-avi" id="topbarAvi">?</div>
                <div>
                    <div class="chat-topbar-title" id="topbarTitle">Chat</div>
                    <div class="chat-topbar-sub" id="topbarSub">—</div>
                </div>
                <div class="chat-topbar-actions">
                    <span id="topbarBadge" class="type-badge">—</span>
                    <button class="icon-btn" id="membersToggleBtn" title="Show members" style="display:none" onclick="toggleMembersPanel()">
                        <i class="fas fa-users"></i>
                    </button>
                    <button class="icon-btn" id="archiveBtn" title="Archive" onclick="archiveCurrentChat()">
                        <i class="fas fa-archive"></i>
                    </button>
                    <button class="icon-btn red" id="deleteChatBtn" title="Delete" onclick="deleteCurrentChat()">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>

            <div style="display:flex;flex:1;overflow:hidden">
                <div class="messages-wrap" id="messagesWrap"></div>
                <div class="members-panel" id="membersPanel">
                    <div class="members-panel-head">
                        <h4><i class="fas fa-users" style="color:var(--or);margin-right:6px"></i>Members</h4>
                        <button class="icon-btn" onclick="toggleMembersPanel()" style="width:26px;height:26px;font-size:.75rem">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="members-list" id="membersList"></div>
                    <div class="add-member-row">
                        <button class="add-member-btn" onclick="openAddMemberModal()">
                            <i class="fas fa-user-plus"></i> Add Member
                        </button>
                    </div>
                </div>
            </div>

            <div class="chat-input" id="chatInput">
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
                        <textarea id="msgInput" rows="1" placeholder="Type a message…"
                            onkeydown="onKeydown(event)" oninput="autoGrow(this)"></textarea>
                    </div>
                    <button class="send-btn" id="sendBtn" onclick="sendMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>

        <div id="unifiedFeed" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
            <div class="feed-header">
                <h3><i class="fas fa-stream" style="color:var(--or)"></i> Unified Message Feed</h3>
                <div class="feed-filters">
                    <button class="feed-filter-btn active" data-feed-filter="all" onclick="setFeedFilter('all')">All</button>
                    <button class="feed-filter-btn" data-feed-filter="private" onclick="setFeedFilter('private')">Private</button>
                    <button class="feed-filter-btn" data-feed-filter="class" onclick="setFeedFilter('class')">Class</button>
                    <button class="feed-filter-btn" data-feed-filter="discussion" onclick="setFeedFilter('discussion')">Discussion</button>
                </div>
            </div>
            <div class="feed-messages" id="feedMessages">
                <div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin"></i> Loading feed...</div>
            </div>
        </div>
    </main>

    <div class="user-monitor-panel" id="userMonitorPanel" style="display:none">
        <div class="monitor-header">
            <h4><i class="fas fa-user-friends" style="color:var(--or)"></i> User Activity Monitor</h4>
            <div class="monitor-search">
                <i class="fas fa-search"></i>
                <input type="text" id="monitorSearch" placeholder="Search users..." oninput="filterMonitorUsers()">
            </div>
            <div style="margin-top:10px">
                <select id="monitorRoleFilter" class="form-ctrl" style="font-size:.7rem;padding:5px" onchange="loadMonitorUsers()">
                    <option value="all">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="teacher">Teacher</option>
                    <option value="student">Student</option>
                    <option value="parent">Parent</option>
                </select>
            </div>
        </div>
        <div class="user-list-monitor" id="userListMonitor">
            <div style="text-align:center;padding:20px"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<div class="overlay" id="privateModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-icon blue"><i class="fas fa-user"></i></div>
            <div class="modal-head-text">
                <h3>New Private Chat</h3>
                <p>Start a one-on-one conversation</p>
            </div>
            <button class="modal-close" onclick="closeModal('privateModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Role</label>
                <select class="form-ctrl" id="pm_role" onchange="loadUsersForPrivate()">
                    <option value="teacher">Teacher</option>
                    <option value="admin">Admin</option>
                    <option value="parent">Parent</option>
                    <option value="student">Student</option>
                </select>
            </div>
            <div class="form-group" id="pm_class_wrap" style="display:none">
                <label class="form-label">Filter by Class</label>
                <select class="form-ctrl" id="pm_class" onchange="loadUsersForPrivate()">
                    <option value="">All classes</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Select User</label>
                <div class="modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="pm_user_search" placeholder="Search…" oninput="filterPrivateUsers()">
                </div>
                <select class="form-ctrl" id="pm_user" size="5" style="height:auto;padding:0">
                    <option disabled>Loading…</option>
                </select>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('privateModal')">Cancel</button>
            <button class="btn btn-primary" onclick="startPrivateChat()"><i class="fas fa-comment"></i> Start Chat</button>
        </div>
    </div>
</div>

<div class="overlay" id="groupModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-icon orange"><i class="fas fa-users"></i></div>
            <div class="modal-head-text">
                <h3>Create Discussion Group</h3>
                <p>Chat with a custom group of users</p>
            </div>
            <button class="modal-close" onclick="closeModal('groupModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Group Name *</label>
                <input type="text" class="form-ctrl" id="gm_title" placeholder="e.g. Admin Discussion Group">
            </div>
            <div class="form-group">
                <label class="form-label">Group Picture</label>
                <div class="pic-drop" id="gm_pic_drop">
                    <input type="file" id="gm_picture" accept="image/*" onchange="previewGroupPic(this)">
                    <i class="fas fa-image"></i>
                    <p id="gm_pic_label">Click to upload (optional)</p>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Select Members *</label>
                <div class="form-group" style="margin-bottom:6px">
                    <select class="form-ctrl" id="gm_role" onchange="loadUsersForGroup()">
                        <option value="student">Students</option>
                        <option value="teacher">Teachers</option>
                        <option value="parent">Parents</option>
                        <option value="admin">Admins</option>
                        <option value="all">All Users</option>
                    </select>
                </div>
                <div class="form-group" id="gm_class_wrap">
                    <select class="form-ctrl" id="gm_class" onchange="loadUsersForGroup()">
                        <option value="">All classes</option>
                    </select>
                </div>
                <div class="modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="gm_search" placeholder="Search members…" oninput="filterGroupUsers()">
                </div>
                <div class="members-select-list" id="gm_members_list">
                    <div style="padding:12px;text-align:center;color:var(--grey-400);font-size:.8rem">
                        <i class="fas fa-spinner fa-spin"></i> Loading…
                    </div>
                </div>
                <div style="font-size:.72rem;color:var(--grey-500);margin-top:5px">
                    <i class="fas fa-check-circle" style="color:var(--or)"></i>
                    <span id="gm_selected_count">0</span> selected
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('groupModal')">Cancel</button>
            <button class="btn btn-primary" onclick="createGroup()"><i class="fas fa-plus"></i> Create Group</button>
        </div>
    </div>
</div>

<div class="overlay" id="joinGroupModal">
    <div class="modal" style="max-width:500px">
        <div class="modal-head">
            <div class="modal-icon orange"><i class="fas fa-sign-in-alt"></i></div>
            <div class="modal-head-text">
                <h3>Join a Chat</h3>
                <p>Join an existing group or class conversation</p>
            </div>
            <button class="modal-close" onclick="closeModal('joinGroupModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Available Chats</label>
                <div id="availableGroupsList" style="max-height:300px;overflow-y:auto;border:1px solid var(--grey-200);border-radius:var(--r-sm);">
                    <div style="padding:12px;text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('joinGroupModal')">Cancel</button>
        </div>
    </div>
</div>

<div class="overlay" id="addMemberModal">
    <div class="modal" style="max-width:400px">
        <div class="modal-head">
            <div class="modal-icon orange"><i class="fas fa-user-plus"></i></div>
            <div class="modal-head-text">
                <h3>Add Member</h3>
                <p>Add a user to this group</p>
            </div>
            <button class="modal-close" onclick="closeModal('addMemberModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Role</label>
                <select class="form-ctrl" id="am_role" onchange="loadUsersForAdd()">
                    <option value="student">Student</option>
                    <option value="teacher">Teacher</option>
                    <option value="parent">Parent</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Select User</label>
                <div class="modal-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="am_search" placeholder="Search…" oninput="filterAddUsers()">
                </div>
                <select class="form-ctrl" id="am_user" size="5" style="height:auto;padding:0">
                    <option disabled>Loading…</option>
                </select>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal('addMemberModal')">Cancel</button>
            <button class="btn btn-primary" onclick="addMember()"><i class="fas fa-user-plus"></i> Add</button>
        </div>
    </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const ADMIN_ID = <?= json_encode($admin_id) ?>;
const CSRF = <?= json_encode($csrf_token) ?>;

// State
let allChats = [];
let currentChat = null;
let lastMsgId = 0;
let pollTimer = null;
let feedPollTimer = null;
let currentFile = null;
let tabFilter = 'all';
let roleFilter = 'all';
let allUsers_pm = [];
let allUsers_gm = [];
let allUsers_am = [];
let teacherClasses = [];
let currentView = 'chats';
let currentFeedFilter = 'all';
let feedLastId = 0;
let allMonitorUsers = [];

// Theme
let isDarkMode = localStorage.getItem('theme') === 'dark';
function initTheme() {
    if (isDarkMode) {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.querySelector('#themeToggle i').className = 'fas fa-sun';
    } else {
        document.documentElement.removeAttribute('data-theme');
        document.querySelector('#themeToggle i').className = 'fas fa-moon';
    }
}
function toggleTheme() {
    isDarkMode = !isDarkMode;
    localStorage.setItem('theme', isDarkMode ? 'dark' : 'light');
    initTheme();
}

// Stats
async function loadStats() {
    try {
        const res = await apiFetch('?fetch_stats=1');
        document.getElementById('statActive').textContent = res.active_private || 0;
        document.getElementById('statUnread').textContent = res.unread_count || 0;
        document.getElementById('statTotalMessages').textContent = res.total_messages || 0;
    } catch(e) { console.error(e); }
}

// Load Classes
async function loadClasses() {
    const res = await apiFetch('?fetch_classes=1');
    teacherClasses = res || [];
    ['pm_class', 'gm_class'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const current = sel.value;
        sel.innerHTML = '<option value="">All classes</option>' +
            teacherClasses.map(c => `<option value="${c.id}">${esc(c.class_name)}</option>`).join('');
        sel.value = current;
    });
}

// Load Chats
async function loadChats() {
    const data = await apiFetch(`?fetch_chats=1&filter_type=${tabFilter}&user_role=${roleFilter}`);
    allChats = data.chats || [];
    document.getElementById('totalBadge').textContent = allChats.length;
    renderChats();
    loadStats();
}

function setTab(btn) {
    document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    tabFilter = btn.dataset.filter;
    loadChats();
}

function setRoleFilter(btn) {
    document.querySelectorAll('.role-chip').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    roleFilter = btn.dataset.role;
    loadChats();
}

function renderChats() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    const items = allChats.filter(c => {
        const matchQ = !q || (c.display_title || '').toLowerCase().includes(q);
        return matchQ;
    });

    const list = document.getElementById('chatList');
    if (!items.length) {
        list.innerHTML = `<div class="chat-list-empty"><i class="fas fa-inbox"></i><p>No conversations</p></div>`;
        return;
    }

    list.innerHTML = items.map(c => {
        const isActive = currentChat && currentChat.id == c.id && currentChat.chat_type === c.chat_type;
        const initials = (c.display_title || '?').substring(0,2).toUpperCase();
        const unread = c.unread > 0;
        const needsJoin = (c.chat_type === 'discussion' || c.chat_type === 'class') && !c.is_participant;
        
        let timeLabel = '';
        if (c.last_time) {
            const d = new Date(c.last_time), now = new Date();
            if (d.toDateString() === now.toDateString())
                timeLabel = d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
            else
                timeLabel = d.toLocaleDateString([],{month:'short',day:'numeric'});
        }

        const aviPic = c.contact_pic
            ? `<img src="${esc(c.contact_pic)}" alt="">`
            : initials;

        const joinButton = needsJoin ? `<span class="join-badge" onclick="event.stopPropagation();joinGroupChat(${c.id})"><i class="fas fa-sign-in-alt"></i> Join</span>` : '';

        return `
        <div class="chat-item ${isActive?'active':''} ${unread?'unread':''}"
             data-id="${c.id}" data-type="${c.chat_type}" data-title="${esc(c.display_title)}"
             onclick="selectChat(${c.id},'${c.chat_type}',this)">
            <div class="chat-item-avi ${c.chat_type}">
                ${aviPic}
                <span class="type-dot ${c.status === 'active' ? 'active' : 'closed'}"></span>
            </div>
            <div class="chat-item-info">
                <div class="chat-item-name">${esc(c.display_title)} ${joinButton}</div>
                <div class="chat-item-sub">${c.last_message ? esc(c.last_message.substring(0,40))+'…' : 'No messages yet'}</div>
            </div>
            <div class="chat-item-meta">
                <span class="chat-item-time">${timeLabel}</span>
                ${unread ? `<span class="unread-chip">${c.unread}</span>` : ''}
            </div>
        </div>`;
    }).join('');
}

// Unified Feed Functions
async function loadUnifiedFeed() {
    currentView = 'feed';
    document.getElementById('chatEmpty').style.display = 'none';
    document.getElementById('activeChat').style.display = 'none';
    document.getElementById('unifiedFeed').style.display = 'flex';
    feedLastId = 0;
    await fetchFeedMessages(true);
    startFeedPolling();
}

function setFeedFilter(filter) {
    currentFeedFilter = filter;
    document.querySelectorAll('.feed-filter-btn').forEach(btn => {
        if (btn.getAttribute('data-feed-filter') === filter) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    feedLastId = 0;
    fetchFeedMessages(true);
}

async function fetchFeedMessages(initial = false) {
    const url = `?fetch_all_messages=1&chat_type=${currentFeedFilter}&last_id=${feedLastId}&limit=50&_=${Date.now()}`;
    const data = await apiFetch(url);
    const messages = data.messages || [];
    
    if (initial) {
        document.getElementById('feedMessages').innerHTML = '';
    }
    
    if (messages.length === 0 && initial) {
        document.getElementById('feedMessages').innerHTML = `
            <div style="text-align:center;padding:60px;color:var(--grey-400)">
                <i class="fas fa-comment-slash" style="font-size:2rem;margin-bottom:12px;display:block"></i>
                <p>No messages yet</p>
            </div>`;
        return;
    }
    
    appendFeedMessages(messages);
    if (messages.length) {
        feedLastId = Math.max(...messages.map(m => m.id));
    }
}

function appendFeedMessages(messages) {
    const container = document.getElementById('feedMessages');
    
    for (let i = messages.length - 1; i >= 0; i--) {
        const msg = messages[i];
        const isFromAdmin = msg.user_id == ADMIN_ID;
        const chatType = msg.chat_type === 'private' ? 'private' : (msg.group_type || 'discussion');
        const chatTitle = msg.chat_title || (chatType === 'private' ? msg.participant_names : 'Group Chat');
        const time = new Date(msg.created_at).toLocaleString();
        
        const userInitial = (msg.name || '?').substring(0,1).toUpperCase();
        const userAvatar = msg.profile_picture 
            ? `<img src="${esc(msg.profile_picture)}" alt="">`
            : userInitial;
        
        let mediaHtml = '';
        if (msg.message_media) {
            const ext = msg.message_media.split('.').pop().toLowerCase();
            if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
                mediaHtml = `<div class="msg-media"><img src="${esc(msg.message_media)}" style="max-width:150px;border-radius:8px"></div>`;
            } else {
                mediaHtml = `<a class="msg-file-link" href="${esc(msg.message_media)}" target="_blank"><i class="fas fa-file-alt"></i> ${esc(msg.message_media.split('/').pop())}</a>`;
            }
        }
        
        const messageEl = document.createElement('div');
        messageEl.className = `feed-message-item ${chatType}`;
        messageEl.innerHTML = `
            <div class="feed-message-header">
                <div class="feed-user-avatar">${userAvatar}</div>
                <div class="feed-user-info">
                    <div class="feed-user-name">${esc(msg.name)} <span style="font-size:.7rem;color:var(--grey-400)">(${msg.role || 'user'})</span></div>
                    <div class="feed-chat-context">
                        <i class="fas fa-comment"></i> ${esc(chatTitle)}
                    </div>
                </div>
                <span class="feed-badge ${chatType}">${chatType}</span>
            </div>
            <div class="feed-message-content">
                ${mediaHtml}
                ${msg.message ? esc(msg.message) : ''}
            </div>
            <div class="feed-message-time">
                ${time}
                ${!isFromAdmin ? `<button class="icon-btn" style="width:auto;padding:2px 8px;margin-left:8px" onclick="replyToMessage(${msg.session_id}, '${chatType === 'private' ? 'private' : 'group'}')"><i class="fas fa-reply"></i> Reply</button>` : ''}
            </div>
        `;
        container.appendChild(messageEl);
    }
    
    container.scrollTop = container.scrollHeight;
}

function replyToMessage(sessionId, chatType) {
    currentView = 'chats';
    document.getElementById('unifiedFeed').style.display = 'none';
    document.getElementById('activeChat').style.display = 'flex';
    document.getElementById('chatEmpty').style.display = 'none';
    
    const chat = allChats.find(c => c.id == sessionId && c.chat_type === chatType);
    if (chat) {
        const chatEl = document.querySelector(`.chat-item[data-id="${sessionId}"][data-type="${chatType}"]`);
        if (chatEl) {
            selectChat(sessionId, chatType, chatEl);
        } else {
            loadChats().then(() => {
                setTimeout(() => {
                    const newChatEl = document.querySelector(`.chat-item[data-id="${sessionId}"][data-type="${chatType}"]`);
                    if (newChatEl) selectChat(sessionId, chatType, newChatEl);
                }, 500);
            });
        }
    }
    stopFeedPolling();
}

// User Monitor Functions
async function toggleMonitorPanel() {
    const panel = document.getElementById('userMonitorPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'flex';
        await loadMonitorUsers();
    } else {
        panel.style.display = 'none';
    }
}

async function loadMonitorUsers() {
    const role = document.getElementById('monitorRoleFilter').value;
    const data = await apiFetch(`?fetch_all_users=1&role=${role}`);
    allMonitorUsers = data || [];
    renderMonitorUsers();
}

function renderMonitorUsers() {
    const search = document.getElementById('monitorSearch').value.toLowerCase();
    const filtered = allMonitorUsers.filter(u => u.name.toLowerCase().includes(search));
    const container = document.getElementById('userListMonitor');
    
    if (!filtered.length) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--grey-400)"><i class="fas fa-user-slash"></i><p>No users found</p></div>';
        return;
    }
    
    container.innerHTML = filtered.map(u => `
        <div class="monitor-user-item" onclick="viewUserConversations(${u.id}, '${esc(u.name)}')">
            <div class="monitor-user-avatar">
                ${u.profile_picture ? `<img src="${esc(u.profile_picture)}" alt="">` : u.name.substring(0,1).toUpperCase()}
            </div>
            <div class="monitor-user-info">
                <div class="monitor-user-name">${esc(u.name)}</div>
                <div class="monitor-user-role">${esc(u.role)}</div>
            </div>
            ${u.unread_from_admin > 0 ? `<span class="monitor-user-badge">${u.unread_from_admin}</span>` : ''}
        </div>
    `).join('');
}

function filterMonitorUsers() {
    renderMonitorUsers();
}

async function viewUserConversations(userId, userName) {
    toast(`Loading conversations for ${userName}...`, 'info');
    const data = await apiFetch(`?fetch_user_conversations=1&user_id=${userId}`);
    const conversations = data.conversations || [];
    
    if (!conversations.length) {
        toast(`No conversations found for ${userName}`, 'info');
        return;
    }
    
    showUserConversationsModal(conversations, userName);
}

function showUserConversationsModal(conversations, userName) {
    let modalHtml = `
        <div class="overlay" id="userConvModal">
            <div class="modal" style="max-width:500px">
                <div class="modal-head">
                    <div class="modal-icon orange"><i class="fas fa-comments"></i></div>
                    <div class="modal-head-text">
                        <h3>${esc(userName)}'s Conversations</h3>
                        <p>Click to join or reply</p>
                    </div>
                    <button class="modal-close" onclick="closeModal('userConvModal')"><i class="fas fa-times"></i></button>
                </div>
                <div class="modal-body">
                    <div style="max-height:400px;overflow-y:auto">
    `;
    
    conversations.forEach(c => {
        modalHtml += `
            <div style="padding:10px;border-bottom:1px solid var(--grey-100);cursor:pointer" 
                 onclick="joinUserConversation(${c.id}, '${c.chat_type}');closeModal('userConvModal')">
                <div style="font-weight:600">${esc(c.title)}</div>
                <div style="font-size:.7rem;color:var(--grey-400)">
                    ${c.chat_type} ${c.member_count ? '• ' + c.member_count + ' members' : '• Private chat'}
                </div>
                <div style="font-size:.75rem;margin-top:4px">${c.last_message ? esc(c.last_message.substring(0,60)) : 'No messages'}</div>
            </div>
        `;
    });
    
    modalHtml += `
                    </div>
                </div>
            </div>
        </div>
    `;
    
    const existing = document.getElementById('userConvModal');
    if (existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    openModal('userConvModal');
}

async function joinUserConversation(sessionId, chatType) {
    if (chatType === 'private') {
        const chat = allChats.find(c => c.id == sessionId && c.chat_type === 'private');
        if (chat && chat.is_participant) {
            const chatEl = document.querySelector(`.chat-item[data-id="${sessionId}"][data-type="private"]`);
            if (chatEl) selectChat(sessionId, 'private', chatEl);
        } else {
            toast('You are not a participant in this private chat', 'info');
        }
    } else {
        const res = await apiFetch(`?join_group=1&session_id=${sessionId}`);
        if (res.success) {
            toast('Joined conversation!', 'success');
            await loadChats();
            const chatEl = document.querySelector(`.chat-item[data-id="${sessionId}"][data-type="${chatType}"]`);
            if (chatEl) selectChat(sessionId, chatType, chatEl);
        }
    }
}

// Join group/class chat
async function joinGroupChat(chatId) {
    const res = await apiFetch(`?join_group=1&session_id=${chatId}`);
    if (res.success) {
        toast('Joined chat successfully!', 'success');
        await loadChats();
        setTimeout(async () => {
            const item = document.querySelector(`.chat-item[data-id="${chatId}"]`);
            if (item) {
                const type = item.dataset.type;
                await selectChat(chatId, type, item);
            }
        }, 500);
    } else {
        toast('Failed to join chat', 'error');
    }
}

// Select Chat
async function selectChat(id, type, el) {
    currentView = 'chats';
    stopFeedPolling();
    document.getElementById('unifiedFeed').style.display = 'none';
    document.getElementById('activeChat').style.display = 'flex';
    document.getElementById('chatEmpty').style.display = 'none';
    
    document.querySelectorAll('.chat-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');

    const chatData = allChats.find(c => c.id == id && c.chat_type === type);
    if (!chatData) return;
    
    const isPrivate = (type === 'private') ? 1 : 0;
    
    currentChat = { 
        id, 
        chat_type: type, 
        display_title: chatData.display_title || '', 
        contact_name: chatData.contact_name,
        contact_role: chatData.contact_role,
        contact_pic: chatData.contact_pic,
        contact_id: chatData.contact_id,
        status: chatData.status,
        is_private: isPrivate
    };
    lastMsgId = 0;

    const avi = document.getElementById('topbarAvi');
    avi.className = `chat-topbar-avi ${type}`;
    if (chatData.contact_pic) {
        avi.innerHTML = `<img src="${esc(chatData.contact_pic)}" alt="">`;
    } else {
        avi.textContent = (chatData.display_title || '?').substring(0,2).toUpperCase();
    }
    document.getElementById('topbarTitle').textContent = chatData.display_title || '—';
    
    const roleIcon = {
        parent: 'fa-user-friends',
        student: 'fa-user-graduate',
        teacher: 'fa-chalkboard-user',
        discussion: 'fa-users',
        class: 'fa-school'
    }[chatData.contact_role] || 'fa-user';
    document.getElementById('topbarSub').innerHTML = `<i class="fas ${roleIcon}"></i> ${chatData.contact_role || 'User'} ${chatData.contact_name ? '• ' + chatData.contact_name : ''}`;
    
    const badge = document.getElementById('topbarBadge');
    const typeLabels = {private:'Private', class:'Class Group', discussion:'Discussion Group'};
    badge.className = `type-badge ${type}`;
    badge.textContent = typeLabels[type] || type;
    
    const memBtn = document.getElementById('membersToggleBtn');
    const archiveBtn = document.getElementById('archiveBtn');
    
    if (type === 'discussion' || type === 'class') {
        memBtn.style.display = 'flex';
        loadGroupMembers();
        if (type === 'discussion') {
            document.getElementById('topbarSub').innerHTML = `<i class="fas fa-users"></i> ${chatData.contact_name || ''}`;
        } else {
            document.getElementById('topbarSub').innerHTML = `<i class="fas fa-school"></i> Class Group`;
        }
    } else {
        memBtn.style.display = 'none';
        document.getElementById('membersPanel').classList.remove('open');
    }
    
    if (chatData.status === 'closed') {
        archiveBtn.innerHTML = '<i class="fas fa-folder-open"></i>';
        archiveBtn.title = 'Reopen';
        archiveBtn.onclick = () => reopenChat(currentChat.id);
    } else {
        archiveBtn.innerHTML = '<i class="fas fa-archive"></i>';
        archiveBtn.title = 'Archive';
        archiveBtn.onclick = () => archiveChat(currentChat.id);
    }

    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('mobileBackBtn').style.display = 'flex';
    }

    stopPolling();
    await fetchMessages(true);
    startPolling();
    markRead();
}

function mobileBack() {
    document.getElementById('sidebar').classList.remove('hidden');
    document.getElementById('activeChat').style.display = 'none';
    document.getElementById('chatEmpty').style.display = 'flex';
    stopPolling();
    currentChat = null;
    document.getElementById('membersPanel').classList.remove('open');
}

// Messages
async function fetchMessages(initial = false) {
    if (!currentChat) return;
    const params = `?fetch_messages=1&chat_session_id=${currentChat.id}&is_private=${currentChat.is_private}&last_id=${lastMsgId}&_=${Date.now()}`;
    const data = await apiFetch(params);
    const msgs = data.messages || [];
    if (!msgs.length && initial) {
        document.getElementById('messagesWrap').innerHTML =
            `<div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--grey-400);font-size:.85rem">
                <div style="text-align:center"><i class="fas fa-comment" style="font-size:1.8rem;margin-bottom:8px;opacity:.3"></i><p>No messages yet. Start the conversation!</p></div>
             </div>`;
        return;
    }
    appendMessages(msgs, initial);
    if (msgs.length) lastMsgId = Math.max(...msgs.map(m => m.id));
}

function appendMessages(msgs, initial) {
    const wrap = document.getElementById('messagesWrap');
    let lastDate = '';

    if (initial) wrap.innerHTML = '';

    msgs.forEach(msg => {
        const msgDate = new Date(msg.created_at).toLocaleDateString('en-GB',{weekday:'long',month:'short',day:'numeric'});
        if (msgDate !== lastDate) {
            const sep = document.createElement('div');
            sep.className = 'date-sep';
            sep.innerHTML = `<span>${msgDate}</span>`;
            wrap.appendChild(sep);
            lastDate = msgDate;
        }
        wrap.appendChild(buildBubble(msg));
    });

    if (initial || msgs.length) wrap.scrollTop = wrap.scrollHeight;
}

function buildBubble(msg) {
    const isMine = msg.user_id == ADMIN_ID;
    const time = new Date(msg.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    const init = (msg.name || '?').substring(0,1).toUpperCase();

    const aviHtml = msg.profile_picture
        ? `<div class="msg-avi"><img src="${esc(msg.profile_picture)}" alt=""></div>`
        : `<div class="msg-avi">${init}</div>`;

    let mediaHtml = '';
    if (msg.message_media) {
        const ext = msg.message_media.split('.').pop().toLowerCase();
        if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            mediaHtml = `<div class="msg-media"><img src="${esc(msg.message_media)}" onclick="window.open('${esc(msg.message_media)}')"></div>`;
        } else if (['mp4','mov','webm'].includes(ext)) {
            mediaHtml = `<video controls style="max-width:200px;border-radius:10px;margin-bottom:4px"><source src="${esc(msg.message_media)}"></video>`;
        } else {
            mediaHtml = `<a class="msg-file-link" href="${esc(msg.message_media)}" target="_blank"><i class="fas fa-file-alt"></i> ${esc(msg.message_media.split('/').pop())}</a>`;
        }
    }

    const row = document.createElement('div');
    row.className = `msg-row ${isMine ? 'me' : 'them'}`;
    row.dataset.id = msg.id;

    row.innerHTML = `
        ${!isMine ? aviHtml : ''}
        <div>
            ${!isMine ? `<div class="msg-sender">${esc(msg.name)}</div>` : ''}
            <div class="msg-bubble">
                ${mediaHtml}
                ${msg.message ? `<div>${esc(msg.message).replace(/\n/g,'<br>')}</div>` : ''}
                <div class="msg-time">
                    <span>${time}</span>
                </div>
            </div>
        </div>
        ${isMine ? aviHtml : ''}
    `;
    return row;
}

// Send Message
async function sendMessage() {
    if (!currentChat) { 
        toast('Select a conversation first', 'error'); 
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
        fd.append('csrf_token', CSRF);
        fd.append('chat_session_id', currentChat.id);
        fd.append('is_private', currentChat.is_private);
        fd.append('message', msg);
        
        if (currentFile) {
            fd.append('message_media', currentFile);
        }

        const res = await postForm(fd);
        
        if (res.success) {
            input.value = '';
            autoGrow(input);
            clearMedia();
            await fetchMessages(false);
            await loadChats();
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

// Media
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

// Mark read
async function markRead() {
    if (!currentChat) return;
    const fd = new FormData();
    fd.append('action', 'mark_read');
    fd.append('csrf_token', CSRF);
    fd.append('chat_session_id', currentChat.id);
    fd.append('is_private', currentChat.is_private);
    await postForm(fd);
}

async function markAllRead() {
    const fd = new FormData();
    fd.append('action', 'mark_all_read');
    fd.append('csrf_token', CSRF);
    const res = await postForm(fd);
    if (res.success) {
        toast('All messages marked as read', 'success');
        loadChats();
    }
}

// Chat actions
async function archiveChat(chatId) {
    if (!confirm('Archive this conversation?')) return;
    const fd = new FormData();
    fd.append('action', 'archive_chat');
    fd.append('csrf_token', CSRF);
    fd.append('chat_session_id', chatId);
    fd.append('is_private', currentChat ? currentChat.is_private : 0);
    const res = await postForm(fd);
    if (res.success) {
        toast('Chat archived', 'success');
        if (currentChat && currentChat.id === chatId) {
            mobileBack();
        }
        loadChats();
    } else {
        toast('Failed to archive', 'error');
    }
}

async function reopenChat(chatId) {
    const fd = new FormData();
    fd.append('action', 'reopen_chat');
    fd.append('csrf_token', CSRF);
    fd.append('chat_session_id', chatId);
    fd.append('is_private', currentChat ? currentChat.is_private : 0);
    const res = await postForm(fd);
    if (res.success) {
        toast('Chat reopened', 'success');
        loadChats();
        if (currentChat && currentChat.id === chatId) {
            const el = document.querySelector(`.chat-item[data-id="${chatId}"]`);
            if (el) await selectChat(chatId, currentChat.chat_type, el);
        }
    } else {
        toast('Failed to reopen', 'error');
    }
}

function archiveCurrentChat() {
    if (currentChat) archiveChat(currentChat.id);
}

function deleteCurrentChat() {
    if (currentChat) deleteChat(currentChat.id, currentChat.chat_type);
}

async function deleteChat(chatId, chatType) {
    if (!confirm('Delete this conversation permanently? This action cannot be undone.')) return;
    
    const fd = new FormData();
    fd.append('action', 'delete_chat');
    fd.append('csrf_token', CSRF);
    fd.append('session_id', chatId);
    fd.append('chat_type', chatType);
    
    const res = await postForm(fd);
    if (res.success) {
        toast('Chat deleted', 'success');
        if (currentChat && currentChat.id === chatId) {
            mobileBack();
        }
        loadChats();
    } else {
        toast(res.error || 'Failed to delete', 'error');
    }
}

// Group Members
function toggleMembersPanel() {
    document.getElementById('membersPanel').classList.toggle('open');
}

async function loadGroupMembers() {
    if (!currentChat || (currentChat.chat_type !== 'discussion' && currentChat.chat_type !== 'class')) return;
    const data = await apiFetch(`?fetch_group_members=1&session_id=${currentChat.id}`);
    const members = data || [];
    const list = document.getElementById('membersList');

    if (!members.length) {
        list.innerHTML = '<div style="padding:12px;text-align:center;color:var(--grey-400);font-size:.8rem">No members yet</div>';
        return;
    }

    list.innerHTML = members.map(m => `
        <div class="member-item">
            <div class="member-avi">
                ${m.profile_picture ? `<img src="${esc(m.profile_picture)}" alt="">` : m.name.substring(0,1).toUpperCase()}
            </div>
            <div>
                <div class="member-name">${esc(m.name)}</div>
                <div class="member-role">${esc(m.role)}</div>
            </div>
            ${m.id != ADMIN_ID ? `<button class="member-remove" title="Remove" onclick="removeMember(${m.id})"><i class="fas fa-times"></i></button>` : ''}
        </div>
    `).join('');
}

async function removeMember(uid) {
    if (!confirm('Remove this member from the group?')) return;
    const fd = new FormData();
    fd.append('action', 'remove_group_member');
    fd.append('csrf_token', CSRF);
    fd.append('session_id', currentChat.id);
    fd.append('user_id', uid);
    const res = await postForm(fd);
    if (res.success) { 
        toast('Member removed', 'success'); 
        loadGroupMembers(); 
        loadChats();
    } else {
        toast(res.error || 'Failed', 'error');
    }
}

// Private Chat Modal
async function openPrivateModal() {
    await loadClasses();
    openModal('privateModal');
    await loadUsersForPrivate();
    document.getElementById('pm_role').dispatchEvent(new Event('change'));
}

async function loadUsersForPrivate() {
    const role = document.getElementById('pm_role').value;
    const classId = document.getElementById('pm_class').value;
    document.getElementById('pm_class_wrap').style.display = role === 'student' ? 'block' : 'none';
    const qs = `?fetch_users=1&role=${role}${classId ? '&class_id='+classId : ''}&_=${Date.now()}`;
    allUsers_pm = await apiFetch(qs) || [];
    filterPrivateUsers();
}

function filterPrivateUsers() {
    const q = document.getElementById('pm_user_search').value.toLowerCase();
    const sel = document.getElementById('pm_user');
    const filtered = allUsers_pm.filter(u => u.name.toLowerCase().includes(q));
    if (!filtered.length) {
        sel.innerHTML = '<option disabled>No users found</option>';
        return;
    }
    sel.innerHTML = filtered.map(u => `<option value="${u.id}">${esc(u.name)} (${u.role})</option>`).join('');
}

async function startPrivateChat() {
    const uid = document.getElementById('pm_user').value;
    if (!uid) { toast('Select a user', 'error'); return; }
    const fd = new FormData();
    fd.append('action', 'create_private_chat');
    fd.append('csrf_token', CSRF);
    fd.append('recipient_id', uid);
    const res = await postForm(fd);
    if (res.success) {
        closeModal('privateModal');
        toast('Chat started!', 'success');
        await loadChats();
        setTimeout(() => {
            const item = document.querySelector(`.chat-item[data-id="${res.chat_id}"][data-type="private"]`);
            if (item) item.click();
        }, 500);
    } else {
        toast(res.error || 'Failed to start chat', 'error');
    }
}

// Group Modal
async function openGroupModal() {
    await loadClasses();
    openModal('groupModal');
    await loadUsersForGroup();
}

async function loadUsersForGroup() {
    const role = document.getElementById('gm_role').value;
    const classId = document.getElementById('gm_class').value;
    document.getElementById('gm_class_wrap').style.display = role === 'student' ? 'block' : 'none';
    const qs = `?fetch_users=1&role=${role}${classId ? '&class_id='+classId : ''}&_=${Date.now()}`;
    allUsers_gm = await apiFetch(qs) || [];
    filterGroupUsers();
}

function filterGroupUsers() {
    const q = document.getElementById('gm_search').value.toLowerCase();
    const list = document.getElementById('gm_members_list');
    const filtered = allUsers_gm.filter(u => u.name.toLowerCase().includes(q));
    if (!filtered.length) {
        list.innerHTML = '<div style="padding:12px;text-align:center;color:var(--grey-400);font-size:.8rem">No users found</div>';
        return;
    }
    list.innerHTML = filtered.map(u => `
        <label class="member-check-item">
            <input type="checkbox" class="gm-chk" value="${u.id}" onchange="updateSelectedCount()">
            <div class="member-check-avatar">${u.name.substring(0,1).toUpperCase()}</div>
            <div>
                <div class="member-check-name">${esc(u.name)}</div>
                <div class="member-check-role">${esc(u.role)}</div>
            </div>
        </label>
    `).join('');
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.gm-chk:checked').length;
    document.getElementById('gm_selected_count').textContent = count;
}

function previewGroupPic(input) {
    if (input.files[0]) document.getElementById('gm_pic_label').textContent = input.files[0].name;
}

async function createGroup() {
    const title = document.getElementById('gm_title').value.trim();
    const checked = [...document.querySelectorAll('.gm-chk:checked')].map(el => el.value);
    if (!title) { toast('Enter a group name', 'error'); return; }
    if (!checked.length) { toast('Select at least one member', 'error'); return; }

    const fd = new FormData();
    fd.append('action', 'create_discussion_group');
    fd.append('csrf_token', CSRF);
    fd.append('group_title', title);
    fd.append('member_ids', JSON.stringify(checked));
    const pic = document.getElementById('gm_picture').files[0];
    if (pic) fd.append('group_picture', pic);

    const res = await postForm(fd);
    if (res.success) {
        closeModal('groupModal');
        toast('Group created!', 'success');
        document.getElementById('gm_title').value = '';
        document.getElementById('gm_picture').value = '';
        document.getElementById('gm_pic_label').textContent = 'Click to upload (optional)';
        await loadChats();
        setTimeout(() => {
            const item = document.querySelector(`.chat-item[data-id="${res.chat_id}"][data-type="discussion"]`);
            if (item) item.click();
        }, 500);
    } else {
        toast(res.error || 'Failed to create group', 'error');
    }
}

// Join Group Modal
async function openJoinGroupModal() {
    openModal('joinGroupModal');
    const res = await apiFetch('?fetch_all_groups=1');
    const groups = res.groups || [];
    const container = document.getElementById('availableGroupsList');
    
    if (!groups.length) {
        container.innerHTML = '<div style="padding:20px;text-align:center;color:var(--grey-400)"><i class="fas fa-info-circle"></i> No available chats to join</div>';
        return;
    }
    
    container.innerHTML = groups.map(g => `
        <div style="padding:12px;border-bottom:1px solid var(--grey-100);display:flex;justify-content:space-between;align-items:center">
            <div>
                <strong>${esc(g.title)}</strong>
                ${g.class_name ? `<span style="font-size:.7rem;color:var(--grey-400);display:block">${esc(g.class_name)}</span>` : ''}
                <span style="font-size:.65rem;color:var(--grey-400)">${g.member_count || 0} members</span>
            </div>
            <button class="btn btn-primary" style="padding:4px 12px;font-size:.7rem" onclick="joinGroupChat(${g.id});closeModal('joinGroupModal')">
                <i class="fas fa-sign-in-alt"></i> Join
            </button>
        </div>
    `).join('');
}

// Add Member Modal
async function openAddMemberModal() {
    openModal('addMemberModal');
    await loadUsersForAdd();
}

async function loadUsersForAdd() {
    const role = document.getElementById('am_role').value;
    allUsers_am = await apiFetch(`?fetch_users=1&role=${role}&_=${Date.now()}`) || [];
    filterAddUsers();
}

function filterAddUsers() {
    const q = document.getElementById('am_search').value.toLowerCase();
    const sel = document.getElementById('am_user');
    const filtered = allUsers_am.filter(u => u.name.toLowerCase().includes(q));
    sel.innerHTML = filtered.length
        ? filtered.map(u => `<option value="${u.id}">${esc(u.name)} (${u.role})</option>`).join('')
        : '<option disabled>No users found</option>';
}

async function addMember() {
    const uid = document.getElementById('am_user').value;
    if (!uid) { toast('Select a user', 'error'); return; }
    const fd = new FormData();
    fd.append('action', 'add_group_member');
    fd.append('csrf_token', CSRF);
    fd.append('session_id', currentChat.id);
    fd.append('user_id', uid);
    const res = await postForm(fd);
    if (res.success) {
        closeModal('addMemberModal');
        toast('Member added', 'success');
        loadGroupMembers();
        loadChats();
    } else {
        toast(res.error || 'Failed', 'error');
    }
}

// Emoji Picker
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

// Polling
function startPolling() { 
    pollTimer = setInterval(() => { 
        if(currentChat && currentView === 'chats'){ 
            fetchMessages(false); 
            loadChats(); 
        }
    }, 4000); 
}

function stopPolling() { 
    if (pollTimer) { 
        clearInterval(pollTimer); 
        pollTimer = null; 
    } 
}

function startFeedPolling() {
    if (feedPollTimer) clearInterval(feedPollTimer);
    feedPollTimer = setInterval(() => {
        if (currentView === 'feed') {
            fetchFeedMessages(false);
        }
    }, 5000);
}

function stopFeedPolling() {
    if (feedPollTimer) {
        clearInterval(feedPollTimer);
        feedPollTimer = null;
    }
}

// Helpers
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

async function apiFetch(url) {
    try {
        const res = await fetch(url + (url.includes('?') ? '&' : '?') + '_=' + Date.now());
        return await res.json();
    } catch(e) { 
        console.error('API fetch error:', e); 
        return {}; 
    }
}

async function postForm(fd) {
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        return await res.json();
    } catch(e) { 
        console.error('Post error:', e); 
        return {error: 'Network error'}; 
    }
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
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

// Event Listeners
document.getElementById('pm_role').addEventListener('change', loadUsersForPrivate);
document.getElementById('gm_role').addEventListener('change', loadUsersForGroup);
document.getElementById('am_role').addEventListener('change', loadUsersForAdd);

// Init
(async function init() {
    initTheme();
    initEmojiPicker();
    await loadClasses();
    await loadChats();
    await loadStats();
    if (window.innerWidth <= 768) {
        document.getElementById('mobileBackBtn').style.display = 'flex';
    }
    
    document.querySelectorAll('.overlay').forEach(ov => {
        ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('open'); });
    });
})();

window.addEventListener('beforeunload', () => {
    stopPolling();
    stopFeedPolling();
});
</script>
</body>
</html>