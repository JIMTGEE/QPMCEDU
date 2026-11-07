<?php
// ============================================================
// teacher_communication.php — Teacher Communication Hub
// Features: Private chats · Class group chats · Custom discussion groups
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();

// DB connection
$host = 'localhost';
$dbname = 'qpmceduc_db';
$username = 'qpmceduc_wise';
$password = 'Kaokbnia.4040@1957';
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

// Auth — keep your existing session or fallback for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 26;
    $_SESSION['role']    = 'teacher';
    $_SESSION['name']    = 'Test Teacher';
}
$teacher_id = (int)$_SESSION['user_id'];

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// AJAX Handler
// ============================================================
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
           isset($_GET['fetch_chats'], $_GET['fetch_messages'], $_GET['fetch_users'],
                 $_GET['fetch_group_members'], $_GET['fetch_classes']) ||
           $_SERVER['REQUEST_METHOD'] === 'POST';

// Individual GET flag checks
if (isset($_GET['fetch_chats']) || isset($_GET['fetch_messages']) || isset($_GET['fetch_users']) ||
    isset($_GET['fetch_group_members']) || isset($_GET['fetch_classes'])) {
    $is_ajax = true;
}

if ($is_ajax && ($_SERVER['REQUEST_METHOD'] === 'POST' || array_intersect(array_keys($_GET),
    ['fetch_chats','fetch_messages','fetch_users','fetch_group_members','fetch_classes']))) {

    header('Content-Type: application/json');

    // ---- GET: All chats (private + class + discussion groups) ----
    if (isset($_GET['fetch_chats'])) {
        $chats = [];

        // 1. Private chats
        $sql = "SELECT ps.id, ps.title, ps.status, ps.start_time,
                    CASE WHEN ps.sender_id=? THEN u2.name ELSE u1.name END AS contact_name,
                    CASE WHEN ps.sender_id=? THEN u2.profile_picture ELSE u1.profile_picture END AS contact_pic,
                    'private' AS chat_type,
                    (SELECT message FROM private_chat_messages WHERE chat_session_id=ps.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM private_chat_messages WHERE chat_session_id=ps.id ORDER BY created_at DESC LIMIT 1) AS last_time,
                    (SELECT COUNT(*) FROM private_chat_messages WHERE chat_session_id=ps.id AND user_id!=? AND read_status='unread') AS unread
                FROM private_chat_sessions ps
                LEFT JOIN users u1 ON u1.id=ps.sender_id
                LEFT JOIN users u2 ON u2.id=ps.recipient_id
                WHERE ps.sender_id=? OR ps.recipient_id=?
                ORDER BY last_time DESC";
        $st = $conn->prepare($sql);
        $st->bind_param('iiiii', $teacher_id,$teacher_id,$teacher_id,$teacher_id,$teacher_id);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $r['display_title'] = $r['contact_name'] ?: $r['title'];
            $chats[] = $r;
        }
        $st->close();

        // 2. Class group chats
        $sql2 = "SELECT cs.id, cs.title, cs.status, cs.class_id, cs.created_at,
                     c.class_name, 'class' AS chat_type,
                     (SELECT message FROM chat_messages WHERE chat_session_id=cs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                     (SELECT created_at FROM chat_messages WHERE chat_session_id=cs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
                     (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id=cs.id AND user_id!=? AND read_status='unread') AS unread
                 FROM chat_sessions cs
                 LEFT JOIN classes c ON c.id=cs.class_id
                 WHERE cs.teacher_id=? AND cs.status='active'
                 ORDER BY last_time DESC";
        $st2 = $conn->prepare($sql2);
        $st2->bind_param('ii', $teacher_id, $teacher_id);
        $st2->execute();
        $res2 = $st2->get_result();
        while ($r = $res2->fetch_assoc()) {
            $r['display_title'] = $r['title'];
            $r['contact_name']  = $r['class_name'] ?? 'Class';
            $r['contact_pic']   = null;
            $chats[] = $r;
        }
        $st2->close();

        // 3. Discussion groups
        $sql3 = "SELECT cs.id, cs.title, cs.status, cs.created_at, cs.group_picture,
                     'discussion' AS chat_type,
                     (SELECT message FROM chat_messages WHERE chat_session_id=cs.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                     (SELECT created_at FROM chat_messages WHERE chat_session_id=cs.id ORDER BY created_at DESC LIMIT 1) AS last_time,
                     (SELECT COUNT(*) FROM chat_messages WHERE chat_session_id=cs.id AND user_id!=? AND read_status='unread') AS unread,
                     (SELECT COUNT(*) FROM chat_participants WHERE chat_session_id=cs.id) AS member_count
                 FROM chat_sessions cs
                 WHERE cs.teacher_id=? AND cs.class_id IS NULL AND cs.parent_id IS NULL AND cs.recipient_id IS NULL
                 ORDER BY last_time DESC, cs.created_at DESC";
        $st3 = $conn->prepare($sql3);
        $st3->bind_param('ii', $teacher_id, $teacher_id);
        $st3->execute();
        $res3 = $st3->get_result();
        while ($r = $res3->fetch_assoc()) {
            $r['display_title'] = $r['title'];
            $r['contact_name']  = ($r['member_count']).' members';
            $r['contact_pic']   = $r['group_picture'];
            $chats[] = $r;
        }
        $st3->close();

        // Sort by last_time desc
        usort($chats, fn($a,$b) => strtotime($b['last_time']??'1970-01-01') - strtotime($a['last_time']??'1970-01-01'));

        echo json_encode(['chats' => $chats]);
        exit;
    }

    // ---- GET: Messages ----
    if (isset($_GET['fetch_messages'])) {
        $sid      = (int)$_GET['chat_session_id'];
        $is_priv  = (int)($_GET['is_private'] ?? 0);
        $last_id  = (int)($_GET['last_id'] ?? 0);

        if ($is_priv) {
            $sql = "SELECT pm.id, pm.user_id, pm.message, pm.message_media, pm.read_status, pm.created_at,
                        u.name, u.profile_picture, u.role
                    FROM private_chat_messages pm
                    LEFT JOIN users u ON u.id=pm.user_id
                    WHERE pm.chat_session_id=? AND pm.id>?
                    ORDER BY pm.created_at ASC";
        } else {
            $sql = "SELECT cm.id, cm.user_id, cm.message, cm.message_media, cm.read_status, cm.created_at,
                        u.name, u.profile_picture, u.role
                    FROM chat_messages cm
                    LEFT JOIN users u ON u.id=cm.user_id
                    WHERE cm.chat_session_id=? AND cm.id>?
                    ORDER BY cm.created_at ASC";
        }
        $st = $conn->prepare($sql);
        $st->bind_param('ii', $sid, $last_id);
        $st->execute();
        $res = $st->get_result();
        $msgs = [];
        while ($r = $res->fetch_assoc()) $msgs[] = $r;
        $st->close();

        // Mark read
        if ($is_priv) {
            $mr = $conn->prepare("UPDATE private_chat_messages SET read_status='read' WHERE chat_session_id=? AND user_id!=? AND read_status='unread'");
        } else {
            $mr = $conn->prepare("UPDATE chat_messages SET read_status='read' WHERE chat_session_id=? AND user_id!=? AND read_status='unread'");
        }
        $mr->bind_param('ii', $sid, $teacher_id);
        $mr->execute();
        $mr->close();

        echo json_encode(['messages' => $msgs]);
        exit;
    }

    // ---- GET: Users for new chat ----
    if (isset($_GET['fetch_users'])) {
        $role     = $_GET['role'] ?? 'teacher';
        $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

        if ($class_id && in_array($role, ['student'])) {
            $sql = "SELECT u.id, u.name, u.role, u.profile_picture
                    FROM users u
                    JOIN student_classes sc ON sc.user_id=u.id
                    WHERE u.role='student' AND u.status='active' AND u.id!=? AND sc.class_id=?
                    ORDER BY u.name";
            $st = $conn->prepare($sql);
            $st->bind_param('ii', $teacher_id, $class_id);
        } else {
            $allowed = ['teacher','admin','parent','student'];
            if (!in_array($role, $allowed)) $role = 'teacher';
            $sql = "SELECT id, name, role, profile_picture FROM users WHERE role=? AND status='active' AND id!=? ORDER BY name";
            $st  = $conn->prepare($sql);
            $st->bind_param('si', $role, $teacher_id);
        }
        $st->execute();
        $res   = $st->get_result();
        $users = [];
        while ($r = $res->fetch_assoc()) $users[] = $r;
        $st->close();
        echo json_encode($users);
        exit;
    }

    // ---- GET: Classes for teacher ----
    if (isset($_GET['fetch_classes'])) {
        $sql = "SELECT DISTINCT c.id, c.class_name FROM classes c
                WHERE c.teacher_id=? OR c.class_teacher_id=?
                ORDER BY c.class_name";
        $st = $conn->prepare($sql);
        $st->bind_param('ii', $teacher_id, $teacher_id);
        $st->execute();
        $res     = $st->get_result();
        $classes = [];
        while ($r = $res->fetch_assoc()) $classes[] = $r;
        $st->close();
        echo json_encode($classes);
        exit;
    }

    // ---- GET: Group members ----
    if (isset($_GET['fetch_group_members'])) {
        $sid = (int)$_GET['session_id'];
        $sql = "SELECT u.id, u.name, u.role, u.profile_picture
                FROM chat_participants cp
                JOIN users u ON u.id=cp.user_id
                WHERE cp.chat_session_id=?
                ORDER BY u.name";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $sid);
        $st->execute();
        $res     = $st->get_result();
        $members = [];
        while ($r = $res->fetch_assoc()) $members[] = $r;
        $st->close();
        echo json_encode($members);
        exit;
    }

    // ---- POST actions ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        // CSRF check
        if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
            echo json_encode(['error' => 'Invalid CSRF token']); exit;
        }

        $action = $_POST['action'];

        // Create private chat
        if ($action === 'create_private_chat') {
            $rid = (int)$_POST['recipient_id'];
            // Check existing
            $chk = $conn->prepare("SELECT id FROM private_chat_sessions WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)");
            $chk->bind_param('iiii', $teacher_id,$rid,$rid,$teacher_id);
            $chk->execute();
            $ex = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($ex) { echo json_encode(['success'=>true,'chat_id'=>$ex['id'],'chat_type'=>'private']); exit; }

            $st = $conn->prepare("SELECT name FROM users WHERE id=?");
            $st->bind_param('i', $rid); $st->execute();
            $rec = $st->get_result()->fetch_assoc(); $st->close();
            if (!$rec) { echo json_encode(['error'=>'User not found']); exit; }

            $token = bin2hex(random_bytes(16));
            $title = 'Chat with '.$rec['name'];
            $term  = 1;
            $st = $conn->prepare("INSERT INTO private_chat_sessions (sender_id,recipient_id,session_token,title,status,start_time,term_id) VALUES (?,?,?,?,'active',NOW(),?)");
            $st->bind_param('iissi', $teacher_id,$rid,$token,$title,$term);
            $st->execute();
            $id = $st->insert_id; $st->close();
            echo json_encode(['success'=>true,'chat_id'=>$id,'chat_type'=>'private','display_title'=>$title]);
            exit;
        }

        // Create discussion group
        if ($action === 'create_discussion_group') {
            $title   = trim($_POST['group_title'] ?? '');
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
            $st = $conn->prepare(
                "INSERT INTO chat_sessions (teacher_id,session_token,title,group_picture,status,start_time,is_real_time,created_at)
                 VALUES (?,?,?,?,'active',NOW(),1,NOW())"
            );
            $st->bind_param('isss', $teacher_id,$token,$title,$grp_pic);
            $st->execute();
            $sid = $st->insert_id;
            $st->close();

            $ins = $conn->prepare("INSERT INTO chat_participants (chat_session_id,user_id) VALUES (?,?)");
            $ins->bind_param('ii', $sid, $teacher_id);
            $ins->execute();

            foreach ($members as $mid) {
                $mid = (int)$mid;
                if ($mid > 0) {
                    $ins->bind_param('ii', $sid, $mid);
                    $ins->execute();
                }
            }
            $ins->close();

            echo json_encode(['success'=>true,'chat_id'=>$sid,'chat_type'=>'discussion','display_title'=>$title]);
            exit;
        }

        // Add member to discussion group
        if ($action === 'add_group_member') {
            $sid = (int)$_POST['session_id'];
            $uid = (int)$_POST['user_id'];
            $chk = $conn->prepare("SELECT id FROM chat_sessions WHERE id=? AND teacher_id=?");
            $chk->bind_param('ii',$sid,$teacher_id); $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) { echo json_encode(['error'=>'Not authorised']); exit; }
            $chk->close();
            $chk2 = $conn->prepare("SELECT id FROM chat_participants WHERE chat_session_id=? AND user_id=?");
            $chk2->bind_param('ii',$sid,$uid); $chk2->execute();
            if ($chk2->get_result()->fetch_assoc()) { echo json_encode(['success'=>true,'message'=>'Already a member']); exit; }
            $chk2->close();
            $st = $conn->prepare("INSERT INTO chat_participants (chat_session_id,user_id) VALUES (?,?)");
            $st->bind_param('ii',$sid,$uid); $st->execute(); $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Remove member from discussion group
        if ($action === 'remove_group_member') {
            $sid = (int)$_POST['session_id'];
            $uid = (int)$_POST['user_id'];
            if ($uid === $teacher_id) { echo json_encode(['error'=>'Cannot remove yourself']); exit; }
            $chk = $conn->prepare("SELECT id FROM chat_sessions WHERE id=? AND teacher_id=?");
            $chk->bind_param('ii',$sid,$teacher_id); $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) { echo json_encode(['error'=>'Not authorised']); exit; }
            $chk->close();
            $st = $conn->prepare("DELETE FROM chat_participants WHERE chat_session_id=? AND user_id=?");
            $st->bind_param('ii',$sid,$uid); $st->execute(); $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Delete entire chat session
        if ($action === 'delete_chat') {
            $sid = (int)$_POST['session_id'];
            $type = $_POST['chat_type'] ?? '';
            
            try {
                $conn->begin_transaction();
                
                if ($type === 'private') {
                    // Delete private chat messages and session
                    $st = $conn->prepare("DELETE FROM private_chat_messages WHERE chat_session_id=?");
                    $st->bind_param('i', $sid);
                    $st->execute();
                    $st->close();
                    
                    $st = $conn->prepare("DELETE FROM private_chat_sessions WHERE id=? AND (sender_id=? OR recipient_id=?)");
                    $st->bind_param('iii', $sid, $teacher_id, $teacher_id);
                    $st->execute();
                    $deleted = $st->affected_rows;
                    $st->close();
                } else {
                    // Delete group chat messages, participants, and session
                    $st = $conn->prepare("DELETE FROM chat_messages WHERE chat_session_id=?");
                    $st->bind_param('i', $sid);
                    $st->execute();
                    $st->close();
                    
                    $st = $conn->prepare("DELETE FROM chat_participants WHERE chat_session_id=?");
                    $st->bind_param('i', $sid);
                    $st->execute();
                    $st->close();
                    
                    $st = $conn->prepare("DELETE FROM chat_sessions WHERE id=? AND teacher_id=?");
                    $st->bind_param('ii', $sid, $teacher_id);
                    $st->execute();
                    $deleted = $st->affected_rows;
                    $st->close();
                }
                
                $conn->commit();
                
                if ($deleted > 0) {
                    echo json_encode(['success'=>true, 'message'=>'Chat deleted successfully']);
                } else {
                    echo json_encode(['success'=>false, 'error'=>'Chat not found or you don\'t have permission']);
                }
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success'=>false, 'error'=>'Failed to delete chat: '.$e->getMessage()]);
            }
            exit;
        }

        // Send message - Fixed without reply_to_id
        if ($action === 'send_message') {
            $sid      = (int)$_POST['chat_session_id'];
            $is_priv  = (int)($_POST['is_private'] ?? 0);
            $message  = trim($_POST['message'] ?? '');

            $media = null;
            
            // Handle file upload with better error checking
            if (isset($_FILES['message_media']) && $_FILES['message_media']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['message_media']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg','jpeg','png','gif','webp','mp4','mov','pdf','doc','docx'];
                if (in_array($ext, $allowed_ext)) {
                    $dir = __DIR__.'/uploads/chat_media/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fn = 'msg_'.time().'_'.uniqid().'.'.$ext;
                    if (move_uploaded_file($_FILES['message_media']['tmp_name'], $dir.$fn)) {
                        $media = 'uploads/chat_media/'.$fn;
                    } else {
                        echo json_encode(['error'=>'Failed to upload file']);
                        exit;
                    }
                } else {
                    echo json_encode(['error'=>'File type not allowed']);
                    exit;
                }
            }

            if (!$message && !$media) { 
                echo json_encode(['error'=>'Empty message']); 
                exit; 
            }

            try {
                if ($is_priv) {
                    $chk = $conn->prepare("SELECT id FROM private_chat_sessions WHERE id=? AND (sender_id=? OR recipient_id=?)");
                    $chk->bind_param('iii',$sid,$teacher_id,$teacher_id); 
                    $chk->execute();
                    if (!$chk->get_result()->fetch_assoc()) { 
                        echo json_encode(['error'=>'Not a participant']); 
                        exit; 
                    }
                    $chk->close();
                    $st = $conn->prepare("INSERT INTO private_chat_messages (chat_session_id,user_id,message,message_media,created_at) VALUES (?,?,?,?,NOW())");
                    $st->bind_param('iiss',$sid,$teacher_id,$message,$media);
                } else {
                    $chk = $conn->prepare("SELECT id FROM chat_sessions WHERE id=?");
                    $chk->bind_param('i',$sid); 
                    $chk->execute();
                    $session = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    
                    if (!$session) {
                        echo json_encode(['error'=>'Chat session not found']);
                        exit;
                    }
                    
                    $st = $conn->prepare("INSERT INTO chat_messages (chat_session_id,user_id,message,message_media,is_active,last_activity,created_at) VALUES (?,?,?,?,1,NOW(),NOW())");
                    $st->bind_param('iiss',$sid,$teacher_id,$message,$media);
                    $conn->query("UPDATE chat_sessions SET updated_at=NOW() WHERE id=$sid");
                }

                if ($st->execute()) {
                    $new_id = $st->insert_id;
                    $st->close();
                    echo json_encode(['success'=>true,'message_id'=>$new_id]);
                } else {
                    echo json_encode(['error'=>'DB error: '.$st->error]);
                }
            } catch (Exception $e) {
                echo json_encode(['error'=>'Server error: '.$e->getMessage()]);
            }
            exit;
        }

        // Delete message
        if ($action === 'delete_message') {
            $mid     = (int)$_POST['message_id'];
            $is_priv = (int)($_POST['is_private'] ?? 0);
            $tbl     = $is_priv ? 'private_chat_messages' : 'chat_messages';
            $st = $conn->prepare("DELETE FROM $tbl WHERE id=? AND user_id=?");
            $st->bind_param('ii',$mid,$teacher_id); 
            $st->execute(); 
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Mark read
        if ($action === 'mark_read') {
            $sid     = (int)$_POST['chat_session_id'];
            $is_priv = (int)($_POST['is_private'] ?? 0);
            $tbl     = $is_priv ? 'private_chat_messages' : 'chat_messages';
            $st = $conn->prepare("UPDATE $tbl SET read_status='read' WHERE chat_session_id=? AND user_id!=? AND read_status='unread'");
            $st->bind_param('ii',$sid,$teacher_id); 
            $st->execute(); 
            $st->close();
            echo json_encode(['success'=>true]);
            exit;
        }

        // Close group
        if ($action === 'close_group') {
            $sid = (int)$_POST['session_id'];
            $st = $conn->prepare("UPDATE chat_sessions SET status='closed', end_time=NOW() WHERE id=? AND teacher_id=?");
            $st->bind_param('ii',$sid,$teacher_id); 
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Communication Hub — Teacher Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- Emoji Picker - Simple and reliable -->
<script src="https://cdn.jsdelivr.net/npm/emoji-mart@latest/dist/browser.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/emoji-mart@latest/css/emoji-mart.css">
<style>
/* ============================================================
   Design Tokens — warm orange & white
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

/* Tab filters */
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

.chat-list-empty{text-align:center;padding:36px 16px;color:var(--grey-400)}
.chat-list-empty i{font-size:2rem;margin-bottom:8px;display:block}
.chat-list-empty p{font-size:.82rem}

/* Delete chat button in sidebar */
.chat-item-delete {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0;
    transition: var(--ease);
    background: var(--grey-100);
    border: 1px solid var(--grey-200);
    border-radius: var(--r-sm);
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    cursor: pointer;
    color: var(--grey-500);
    font-size: .7rem;
    z-index: 10;
}
.chat-item:hover .chat-item-delete {
    opacity: 1;
}
.chat-item-delete:hover {
    background: var(--red);
    color: white;
    border-color: var(--red);
}

/* New chat buttons */
.new-chat-btns{
    padding:12px 14px;border-top:1px solid var(--grey-100);
    display:flex;gap:8px;flex-shrink:0;
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

.msg-actions{display:none;gap:4px}
.msg-row:hover .msg-actions{display:flex}
.msg-act-btn{
    padding:2px 6px;border-radius:4px;border:none;cursor:pointer;
    font-size:.65rem;background:rgba(0,0,0,.1);color:inherit;
    transition:var(--ease);
}
.msg-row.me .msg-act-btn{background:rgba(255,255,255,.2);color:#fff}
.msg-act-btn:hover{opacity:.8}

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

/* ============================================================
   Members Panel (right drawer for discussion groups)
   ============================================================ */
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
.add-member-btn:hover{border-color:var(--or);background:var(--or-l)}

/* ============================================================
   Modals
   ============================================================ */
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
select.form-ctrl{cursor:pointer}

/* Member selection multi-checkboxes */
.members-select-list{
    max-height:200px;overflow-y:auto;
    border:1.5px solid var(--grey-200);border-radius:var(--r-sm);
    background:var(--grey-50);
}
.member-check-item{
    display:flex;align-items:center;gap:10px;
    padding:8px 12px;cursor:pointer;transition:var(--ease);
    border-bottom:1px solid var(--grey-100);
}
.member-check-item:last-child{border-bottom:none}
.member-check-item:hover{background:var(--or-pale)}
.member-check-item input[type=checkbox]{
    accent-color:var(--or);width:15px;height:15px;cursor:pointer;
}
.member-check-avatar{
    width:28px;height:28px;border-radius:50%;
    background:linear-gradient(135deg,var(--or),var(--or-d));
    display:grid;place-items:center;color:#fff;font-size:.65rem;font-weight:700;flex-shrink:0;
}
.member-check-name{font-size:.82rem;font-weight:500;color:var(--grey-700)}
.member-check-role{font-size:.68rem;color:var(--grey-400)}

.pic-drop{
    border:2px dashed var(--grey-200);border-radius:var(--r-sm);
    padding:20px;text-align:center;cursor:pointer;transition:var(--ease);
    position:relative;overflow:hidden;
}
.pic-drop:hover{border-color:var(--or);background:var(--or-pale)}
.pic-drop input{position:absolute;inset:0;opacity:0;cursor:pointer}
.pic-drop i{font-size:1.5rem;color:var(--grey-300);margin-bottom:6px;display:block}
.pic-drop p{font-size:.76rem;color:var(--grey-400)}

.btn{
    padding:9px 18px;border-radius:var(--r-sm);
    font-size:.84rem;font-weight:600;cursor:pointer;border:none;
    font-family:inherit;display:flex;align-items:center;gap:6px;transition:var(--ease);
}
.btn-primary{background:var(--or);color:#fff;box-shadow:var(--shadow-or)}
.btn-primary:hover{background:var(--or-d);transform:translateY(-1px)}
.btn-secondary{background:var(--grey-100);color:var(--grey-700);border:1px solid var(--grey-200)}
.btn-secondary:hover{background:var(--grey-200)}
.btn-danger{background:#FEE2E2;color:var(--red);border:1px solid #FECACA}
.btn-danger:hover{background:var(--red);color:#fff}

/* Search in modal */
.modal-search{
    position:relative;margin-bottom:10px;
}
.modal-search input{
    width:100%;padding:8px 12px 8px 32px;
    border:1.5px solid var(--grey-200);border-radius:var(--r-sm);
    font-size:.82rem;font-family:inherit;background:var(--grey-50);
    outline:none;transition:var(--ease);
}
.modal-search input:focus{border-color:var(--or);box-shadow:0 0 0 3px var(--or-glow)}
.modal-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--grey-400);font-size:.78rem}

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

/* Responsive */
@media(max-width:768px){
    .sidebar{position:absolute;inset:0;z-index:50;transition:.3s}
    .sidebar.hidden{transform:translateX(-100%)}
    .members-panel{display:none}
    :root{--sidebar:100%}
}

.type-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:2px 7px;border-radius:50px;font-size:.65rem;font-weight:600;
}
.type-badge.private{background:#EFF6FF;color:#1D4ED8}
.type-badge.class{background:#F5F3FF;color:#6D28D9}
.type-badge.discussion{background:var(--or-pale);color:var(--or-d)}

.loading-dots span{
    display:inline-block;width:7px;height:7px;border-radius:50%;
    background:var(--grey-300);margin:0 2px;
    animation:ldot .8s ease-in-out infinite;
}
.loading-dots span:nth-child(2){animation-delay:.15s}
.loading-dots span:nth-child(3){animation-delay:.3s}
@keyframes ldot{0%,80%,100%{transform:scale(0.6);opacity:.4}40%{transform:scale(1);opacity:1}}
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
    <span class="topbar-title">Communication Hub</span>
    <div class="topbar-right">
        <a href="dashboard.php" class="t-btn t-btn-ghost"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
</header>

<!-- LAYOUT -->
<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <div class="sidebar-head-top">
                <h2><i class="fas fa-comments" style="color:var(--or)"></i> Chats <span class="badge-pill" id="totalBadge">0</span></h2>
            </div>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search conversations…" oninput="renderChats()">
            </div>
        </div>
        <div class="chat-tabs">
            <button class="chat-tab active" data-filter="all"        onclick="setTab(this)">All</button>
            <button class="chat-tab"        data-filter="private"    onclick="setTab(this)">Private</button>
            <button class="chat-tab"        data-filter="class"      onclick="setTab(this)">Class</button>
            <button class="chat-tab"        data-filter="discussion" onclick="setTab(this)">Groups</button>
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
        </div>
    </aside>

    <!-- CHAT PANEL -->
    <main class="chat-panel" id="chatPanel">

        <!-- Empty state -->
        <div class="chat-empty" id="chatEmpty">
            <div class="chat-empty-icon"><i class="fas fa-comments"></i></div>
            <h3>Pick a Conversation</h3>
            <p>Select a chat from the left, or start a new private chat or group discussion.</p>
        </div>

        <!-- Active chat (hidden until selected) -->
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
                    <button class="icon-btn red" id="closeGroupBtn" title="Close group" style="display:none" onclick="closeGroup()">
                        <i class="fas fa-door-open"></i>
                    </button>
                </div>
            </div>

            <div style="display:flex;flex:1;overflow:hidden">
                <div class="messages-wrap" id="messagesWrap"></div>
                <!-- Members panel (discussion groups only) -->
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

            <!-- Input -->
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
        </div><!-- /activeChat -->
    </main>
</div><!-- /layout -->

<!-- ============================================================
     MODAL: New Private Chat
     ============================================================ -->
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

<!-- ============================================================
     MODAL: New Discussion Group
     ============================================================ -->
<div class="overlay" id="groupModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-icon orange"><i class="fas fa-users"></i></div>
            <div class="modal-head-text">
                <h3>Create Discussion Group</h3>
                <p>Chat with a custom group of students</p>
            </div>
            <button class="modal-close" onclick="closeModal('groupModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Group Name *</label>
                <input type="text" class="form-ctrl" id="gm_title" placeholder="e.g. Advanced Maths Group A">
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

<!-- ============================================================
     MODAL: Add Member to Group
     ============================================================ -->
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

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
const TEACHER_ID = <?= json_encode($teacher_id) ?>;
const CSRF       = <?= json_encode($csrf_token) ?>;

// ── State ──
let allChats     = [];
let currentChat  = null;
let lastMsgId    = 0;
let pollTimer    = null;
let currentFile  = null;
let tabFilter    = 'all';
let allUsers_pm  = [];
let allUsers_gm  = [];
let allUsers_am  = [];
let teacherClasses = [];

// ── Init ──
(async function init() {
    await loadClasses();
    await loadChats();
    if (window.innerWidth <= 768) {
        document.getElementById('mobileBackBtn').style.display = 'flex';
    }
    initEmojiPicker();
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
// Classes
// ============================================================
async function loadClasses() {
    const res  = await apiFetch('?fetch_classes=1');
    teacherClasses = res || [];
    ['pm_class','gm_class'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const current = sel.value;
        sel.innerHTML = '<option value="">All classes</option>' +
            teacherClasses.map(c => `<option value="${c.id}">${esc(c.class_name)}</option>`).join('');
        sel.value = current;
    });
}

// ============================================================
// Chat list
// ============================================================
async function loadChats() {
    const data = await apiFetch('?fetch_chats=1');
    allChats   = data.chats || [];
    document.getElementById('totalBadge').textContent = allChats.length;
    renderChats();
}

function setTab(btn) {
    document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    tabFilter = btn.dataset.filter;
    renderChats();
}

async function deleteChat(chatId, chatType, event) {
    event.stopPropagation();
    
    if (!confirm('Are you sure you want to delete this chat? This action cannot be undone.')) {
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'delete_chat');
    fd.append('csrf_token', CSRF);
    fd.append('session_id', chatId);
    fd.append('chat_type', chatType);
    
    try {
        const res = await postForm(fd);
        if (res.success) {
            toast('Chat deleted successfully', 'success');
            
            if (currentChat && currentChat.id == chatId) {
                mobileBack();
            }
            
            await loadChats();
        } else {
            toast(res.error || 'Failed to delete chat', 'error');
        }
    } catch (error) {
        console.error('Delete error:', error);
        toast('Network error - please try again', 'error');
    }
}

function renderChats() {
    const q     = (document.getElementById('searchInput').value || '').toLowerCase();
    const items = allChats.filter(c => {
        const matchTab  = tabFilter === 'all' || c.chat_type === tabFilter;
        const matchQ    = !q || (c.display_title || '').toLowerCase().includes(q);
        return matchTab && matchQ;
    });

    const list = document.getElementById('chatList');
    if (!items.length) {
        list.innerHTML = `<div class="chat-list-empty"><i class="fas fa-inbox"></i><p>No conversations</p></div>`;
        return;
    }

    list.innerHTML = items.map(c => {
        const isActive = currentChat && currentChat.id == c.id;
        const initials = (c.display_title || '?').substring(0,2).toUpperCase();
        const unread   = c.unread > 0;

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

        return `
        <div class="chat-item ${isActive?'active':''} ${unread?'unread':''}"
             data-id="${c.id}" data-type="${c.chat_type}" data-title="${esc(c.display_title)}"
             onclick="selectChat(${c.id},'${c.chat_type}',this)">
            <div class="chat-item-avi ${c.chat_type}">
                ${aviPic}
                <span class="type-dot active"></span>
            </div>
            <div class="chat-item-info">
                <div class="chat-item-name">${esc(c.display_title)}</div>
                <div class="chat-item-sub">${c.last_message ? esc(c.last_message.substring(0,40))+'…' : 'No messages yet'}</div>
            </div>
            <div class="chat-item-meta">
                <span class="chat-item-time">${timeLabel}</span>
                ${unread ? `<span class="unread-chip">${c.unread}</span>` : ''}
            </div>
            <button class="chat-item-delete" onclick="deleteChat(${c.id},'${c.chat_type}',event)" title="Delete chat">
                <i class="fas fa-trash"></i>
            </button>
        </div>`;
    }).join('');
}

// ============================================================
// Select Chat
// ============================================================
async function selectChat(id, type, el) {
    document.querySelectorAll('.chat-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');

    const chatData = allChats.find(c => c.id == id && c.chat_type === type);
    const isPriv   = type === 'private' ? 1 : 0;

    currentChat = { id, chat_type: type, display_title: chatData?.display_title || '', is_private: isPriv };
    lastMsgId   = 0;

    document.getElementById('chatEmpty').style.display  = 'none';
    const ac = document.getElementById('activeChat');
    ac.style.display = 'flex';

    const avi = document.getElementById('topbarAvi');
    avi.className = `chat-topbar-avi ${type}`;
    if (chatData?.contact_pic) {
        avi.innerHTML = `<img src="${esc(chatData.contact_pic)}" alt="">`;
    } else {
        avi.textContent = (chatData?.display_title||'?').substring(0,2).toUpperCase();
    }
    document.getElementById('topbarTitle').textContent = chatData?.display_title || '—';

    const sub = document.getElementById('topbarSub');
    const badge = document.getElementById('topbarBadge');
    const typeLabels = {private:'Private',class:'Class Group',discussion:'Discussion Group'};
    badge.className = `type-badge ${type}`;
    badge.textContent = typeLabels[type] || type;

    const memBtn   = document.getElementById('membersToggleBtn');
    const closeBtn = document.getElementById('closeGroupBtn');
    if (type === 'discussion') {
        memBtn.style.display   = 'flex';
        closeBtn.style.display = 'flex';
        loadGroupMembers();
        sub.textContent = (chatData?.contact_name || '') + ' members';
    } else {
        memBtn.style.display   = 'none';
        closeBtn.style.display = 'none';
        document.getElementById('membersPanel').classList.remove('open');
        sub.textContent = chatData?.contact_name || (type === 'private' ? 'Private conversation' : 'Class group');
    }

    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.add('hidden');
    }

    stopPolling();
    await fetchMessages(true);
    startPolling();
    markRead();
}

function mobileBack() {
    document.getElementById('sidebar').classList.remove('hidden');
    document.getElementById('activeChat').style.display = 'none';
    document.getElementById('chatEmpty').style.display  = 'flex';
    stopPolling();
    currentChat = null;
    document.getElementById('membersPanel').classList.remove('open');
}

// ============================================================
// Messages
// ============================================================
async function fetchMessages(initial = false) {
    if (!currentChat) return;
    const params = `?fetch_messages=1&chat_session_id=${currentChat.id}&is_private=${currentChat.is_private}&last_id=${lastMsgId}&_=${Date.now()}`;
    const data   = await apiFetch(params);
    const msgs   = data.messages || [];
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
    const wrap    = document.getElementById('messagesWrap');
    let lastDate  = '';

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
    const isMine = msg.user_id == TEACHER_ID;
    const time   = new Date(msg.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    const init   = (msg.name || '?').substring(0,1).toUpperCase();

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

    const deleteBtn = isMine ? `<button class="msg-act-btn" title="Delete" onclick="deleteMsg(${msg.id})"><i class="fas fa-trash"></i></button>` : '';

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
                    <div class="msg-actions">${deleteBtn}</div>
                </div>
            </div>
        </div>
        ${isMine ? aviHtml : ''}
    `;
    return row;
}

// ============================================================
// Send message - Fixed
// ============================================================
async function sendMessage() {
    if (!currentChat) { 
        toast('Select a conversation first', 'error'); 
        return; 
    }
    
    const input = document.getElementById('msgInput');
    const msg   = input.value.trim();
    
    if (!msg && !currentFile) {
        toast('Please enter a message or select a file', 'error');
        return;
    }

    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('action',          'send_message');
        fd.append('csrf_token',      CSRF);
        fd.append('chat_session_id', currentChat.id);
        fd.append('is_private',      currentChat.is_private);
        fd.append('message',         msg);
        
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

// ── Media ──
function onMediaSelect(input) {
    if (!input.files[0]) return;
    currentFile = input.files[0];
    const prev  = document.getElementById('mediaPrev');
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

// ── Delete message ──
async function deleteMsg(id) {
    if (!confirm('Delete this message?')) return;
    const fd = new FormData();
    fd.append('action',     'delete_message');
    fd.append('csrf_token', CSRF);
    fd.append('message_id', id);
    fd.append('is_private', currentChat.is_private);
    const res = await postForm(fd);
    if (res.success) {
        const el = document.querySelector(`.msg-row[data-id="${id}"]`);
        if (el) el.remove();
        toast('Message deleted', 'success');
    } else {
        toast('Failed to delete', 'error');
    }
}

// ── Mark read ──
async function markRead() {
    if (!currentChat) return;
    const fd = new FormData();
    fd.append('action',          'mark_read');
    fd.append('csrf_token',      CSRF);
    fd.append('chat_session_id', currentChat.id);
    fd.append('is_private',      currentChat.is_private);
    await postForm(fd);
}

// ── Polling ──
function startPolling() { 
    pollTimer = setInterval(() => { 
        if(currentChat){ 
            fetchMessages(false); 
            loadChats(); 
        }
    }, 4000); 
}

function stopPolling()  { 
    if (pollTimer) { 
        clearInterval(pollTimer); 
        pollTimer = null; 
    } 
}

// ============================================================
// Members Panel (Discussion Groups)
// ============================================================
function toggleMembersPanel() {
    document.getElementById('membersPanel').classList.toggle('open');
}

async function loadGroupMembers() {
    if (!currentChat || currentChat.chat_type !== 'discussion') return;
    const data    = await apiFetch(`?fetch_group_members=1&session_id=${currentChat.id}`);
    const members = data || [];
    const list    = document.getElementById('membersList');

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
            ${m.id != TEACHER_ID ? `<button class="member-remove" title="Remove" onclick="removeMember(${m.id})"><i class="fas fa-times"></i></button>` : ''}
        </div>
    `).join('');
}

async function removeMember(uid) {
    if (!confirm('Remove this member from the group?')) return;
    const fd = new FormData();
    fd.append('action',     'remove_group_member');
    fd.append('csrf_token', CSRF);
    fd.append('session_id', currentChat.id);
    fd.append('user_id',    uid);
    const res = await postForm(fd);
    if (res.success) { 
        toast('Member removed', 'success'); 
        loadGroupMembers(); 
        loadChats();
    } else {
        toast(res.error || 'Failed', 'error');
    }
}

async function closeGroup() {
    if (!confirm('Close this group? Members will no longer be able to send messages.')) return;
    const fd = new FormData();
    fd.append('action',     'close_group');
    fd.append('csrf_token', CSRF);
    fd.append('session_id', currentChat.id);
    const res = await postForm(fd);
    if (res.success) { 
        toast('Group closed', 'success'); 
        await loadChats(); 
        mobileBack(); 
    } else {
        toast(res.error || 'Failed', 'error');
    }
}

// ============================================================
// MODAL: Private Chat
// ============================================================
function openPrivateModal() {
    openModal('privateModal');
    loadUsersForPrivate();
    document.getElementById('pm_role').dispatchEvent(new Event('change'));
}

async function loadUsersForPrivate() {
    const role    = document.getElementById('pm_role').value;
    const classId = document.getElementById('pm_class').value;
    document.getElementById('pm_class_wrap').style.display = role === 'student' ? 'block' : 'none';
    const qs = `?fetch_users=1&role=${role}${classId ? '&class_id='+classId : ''}&_=${Date.now()}`;
    allUsers_pm  = await apiFetch(qs) || [];
    filterPrivateUsers();
}

function filterPrivateUsers() {
    const q   = document.getElementById('pm_user_search').value.toLowerCase();
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
    fd.append('action',       'create_private_chat');
    fd.append('csrf_token',   CSRF);
    fd.append('recipient_id', uid);
    const res = await postForm(fd);
    if (res.success) {
        closeModal('privateModal');
        toast('Chat ready!', 'success');
        await loadChats();
        const item = document.querySelector(`.chat-item[data-id="${res.chat_id}"][data-type="private"]`);
        if (item) item.click();
    } else {
        toast(res.error || 'Failed', 'error');
    }
}

// ============================================================
// MODAL: Create Group
// ============================================================
function openGroupModal() {
    openModal('groupModal');
    loadUsersForGroup();
}

async function loadUsersForGroup() {
    const role    = document.getElementById('gm_role').value;
    const classId = document.getElementById('gm_class').value;
    document.getElementById('gm_class_wrap').style.display = role === 'student' ? 'block' : 'none';
    const qs = `?fetch_users=1&role=${role}${classId ? '&class_id='+classId : ''}&_=${Date.now()}`;
    allUsers_gm = await apiFetch(qs) || [];
    filterGroupUsers();
}

function filterGroupUsers() {
    const q    = document.getElementById('gm_search').value.toLowerCase();
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
    if (!title)         { toast('Enter a group name', 'error'); return; }
    if (!checked.length){ toast('Select at least one member', 'error'); return; }

    const fd = new FormData();
    fd.append('action',     'create_discussion_group');
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
        const item = document.querySelector(`.chat-item[data-id="${res.chat_id}"][data-type="discussion"]`);
        if (item) item.click();
    } else {
        toast(res.error || 'Failed to create group', 'error');
    }
}

// ============================================================
// MODAL: Add Member
// ============================================================
function openAddMemberModal() {
    openModal('addMemberModal');
    loadUsersForAdd();
}

async function loadUsersForAdd() {
    const role  = document.getElementById('am_role').value;
    allUsers_am = await apiFetch(`?fetch_users=1&role=${role}&_=${Date.now()}`) || [];
    filterAddUsers();
}

function filterAddUsers() {
    const q   = document.getElementById('am_search').value.toLowerCase();
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
    fd.append('action',     'add_group_member');
    fd.append('csrf_token', CSRF);
    fd.append('session_id', currentChat.id);
    fd.append('user_id',    uid);
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

// ============================================================
// Helpers
// ============================================================
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.overlay').forEach(ov => {
    ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('open'); });
});

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
    const t    = document.createElement('div');
    t.className = `toast ${type}`;
    const icons = {success:'fa-check-circle', error:'fa-times-circle', info:'fa-info-circle'};
    t.innerHTML = `<i class="fas ${icons[type]||'fa-info-circle'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 3200);
}

window.addEventListener('beforeunload', stopPolling);

document.getElementById('pm_role').addEventListener('change', loadUsersForPrivate);
document.getElementById('gm_role').addEventListener('change', loadUsersForGroup);
document.getElementById('am_role').addEventListener('change', loadUsersForAdd);
</script>
</body>
</html>