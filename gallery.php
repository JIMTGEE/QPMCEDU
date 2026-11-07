<?php
ob_start();
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Fetch albums with full details
$albums_sql = "SELECT ga.*, c.class_name, d.name as department_name, u.name as creator_name,
              (SELECT COUNT(*) FROM gallery_media WHERE album_id = ga.id AND is_approved = 1) as media_count,
              (SELECT file_path FROM gallery_media WHERE album_id = ga.id AND is_approved = 1 ORDER BY created_at DESC LIMIT 1) as cover_image
              FROM gallery_albums ga
              LEFT JOIN classes c ON ga.class_id = c.id
              LEFT JOIN departments d ON ga.department_id = d.id
              LEFT JOIN users u ON ga.created_by = u.id
              WHERE ga.is_published = 1
              ORDER BY ga.created_at DESC";
$albums_result = mysqli_query($conn, $albums_sql);
$albums = [];
while ($row = mysqli_fetch_assoc($albums_result)) {
    $albums[] = $row;
}
mysqli_free_result($albums_result);

// Get total stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as total_media, COALESCE(SUM(like_count), 0) as total_likes, COALESCE(SUM(comment_count), 0) as total_comments 
     FROM gallery_media WHERE is_approved = 1"));

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Get album media with details
    if ($_POST['action'] === 'get_album_media' && isset($_POST['album_id'])) {
        $album_id = (int)$_POST['album_id'];
        
        $album_result = mysqli_query($conn, 
            "SELECT ga.*, c.class_name, d.name as department_name, u.name as creator_name
             FROM gallery_albums ga
             LEFT JOIN classes c ON ga.class_id = c.id
             LEFT JOIN departments d ON ga.department_id = d.id
             LEFT JOIN users u ON ga.created_by = u.id
             WHERE ga.id = $album_id");
        $album = mysqli_fetch_assoc($album_result);
        mysqli_free_result($album_result);
        
        if (!$album) {
            echo json_encode(['success' => false, 'message' => 'Album not found']);
            exit;
        }
        
        $media_items = [];
        $media_result = mysqli_query($conn, 
            "SELECT gm.*,
             (SELECT COUNT(*) FROM gallery_comments WHERE media_id = gm.id) as comment_count,
             (SELECT COUNT(*) FROM gallery_likes WHERE media_id = gm.id) as like_count
             FROM gallery_media gm
             WHERE gm.album_id = $album_id AND gm.is_approved = 1
             ORDER BY gm.created_at DESC");
        
        while ($row = mysqli_fetch_assoc($media_result)) {
            // Generate a nice display title instead of filename
            $displayTitle = '';
            if (!empty($row['description'])) {
                $displayTitle = substr($row['description'], 0, 60);
            } else {
                // Create a friendly title based on album and index
                $displayTitle = 'Gallery Photo';
            }
            
            $media_items[] = [
                'id' => $row['id'],
                'title' => htmlspecialchars($displayTitle),
                'description' => htmlspecialchars($row['description'] ?? ''),
                'media_type' => $row['media_type'],
                'file_path' => $row['file_path'],
                'thumbnail_path' => $row['thumbnail_path'] ?: $row['file_path'],
                'mime_type' => $row['mime_type'],
                'like_count' => (int)$row['like_count'],
                'comment_count' => (int)$row['comment_count'],
                'created_at' => $row['created_at']
            ];
        }
        mysqli_free_result($media_result);
        
        echo json_encode([
            'success' => true,
            'album' => [
                'id' => $album['id'],
                'title' => htmlspecialchars($album['title']),
                'description' => htmlspecialchars($album['description'] ?? ''),
                'class_name' => $album['class_name'],
                'department_name' => $album['department_name'],
                'creator_name' => $album['creator_name'],
                'created_at' => date('M Y', strtotime($album['created_at'])),
                'media_count' => count($media_items)
            ],
            'media' => $media_items
        ]);
        exit;
    }
    
    // Like/Unlike functionality
    if ($_POST['action'] === 'like' && isset($_POST['media_id'])) {
        $media_id = (int)$_POST['media_id'];
        $session_id = session_id();
        
        // Check if user is logged in
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        
        // Check if already liked
        $check_query = "SELECT id FROM gallery_likes WHERE media_id = $media_id";
        if ($user_id > 0) {
            $check_query .= " AND user_id = $user_id";
        } else {
            $check_query .= " AND user_id = 0 AND DATE(created_at) = CURDATE()";
        }
        $check_query .= " LIMIT 1";
        
        $check = mysqli_query($conn, $check_query);
        
        if (mysqli_num_rows($check) > 0) {
            // Unlike
            $like_row = mysqli_fetch_assoc($check);
            mysqli_query($conn, "DELETE FROM gallery_likes WHERE id = {$like_row['id']}");
            $liked = false;
        } else {
            // Like
            $insert_query = "INSERT INTO gallery_likes (media_id, user_id, reaction_type) VALUES ($media_id, $user_id, 'like')";
            mysqli_query($conn, $insert_query);
            $liked = true;
        }
        
        // Update like count in gallery_media
        $count_query = "SELECT COUNT(*) as count FROM gallery_likes WHERE media_id = $media_id";
        $count_result = mysqli_query($conn, $count_query);
        $count = mysqli_fetch_assoc($count_result)['count'];
        mysqli_query($conn, "UPDATE gallery_media SET like_count = $count WHERE id = $media_id");
        
        echo json_encode([
            'success' => true, 
            'liked' => $liked, 
            'like_count' => (int)$count
        ]);
        exit;
    }
    
    // Check if media is liked
    if ($_POST['action'] === 'check_like' && isset($_POST['media_id'])) {
        $media_id = (int)$_POST['media_id'];
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        
        $check_query = "SELECT id FROM gallery_likes WHERE media_id = $media_id";
        if ($user_id > 0) {
            $check_query .= " AND user_id = $user_id";
        } else {
            $check_query .= " AND user_id = 0 AND DATE(created_at) = CURDATE()";
        }
        
        $result = mysqli_query($conn, $check_query);
        $liked = mysqli_num_rows($result) > 0;
        
        echo json_encode(['success' => true, 'liked' => $liked]);
        exit;
    }
    
    // Get Comments
    if ($_POST['action'] === 'get_comments' && isset($_POST['media_id'])) {
        $media_id = (int)$_POST['media_id'];
        $comments = [];
        
        $result = mysqli_query($conn, 
            "SELECT gc.*, u.name as user_name 
             FROM gallery_comments gc 
             LEFT JOIN users u ON gc.user_id = u.id 
             WHERE gc.media_id = $media_id AND gc.parent_comment_id IS NULL 
             ORDER BY gc.created_at DESC LIMIT 50");
        
        while ($row = mysqli_fetch_assoc($result)) {
            $replies = [];
            $replies_result = mysqli_query($conn, 
                "SELECT gc.*, u.name as user_name 
                 FROM gallery_comments gc 
                 LEFT JOIN users u ON gc.user_id = u.id 
                 WHERE gc.parent_comment_id = {$row['id']} 
                 ORDER BY gc.created_at ASC LIMIT 10");
            while ($reply = mysqli_fetch_assoc($replies_result)) {
                $replies[] = [
                    'id' => $reply['id'],
                    'name' => !empty($reply['user_name']) ? htmlspecialchars($reply['user_name']) : 'Visitor',
                    'text' => htmlspecialchars($reply['comment_text']),
                    'date' => date('M d', strtotime($reply['created_at'])),
                    'time' => date('h:i A', strtotime($reply['created_at']))
                ];
            }
            mysqli_free_result($replies_result);
            
            $comments[] = [
                'id' => $row['id'],
                'name' => !empty($row['user_name']) ? htmlspecialchars($row['user_name']) : 'Visitor',
                'text' => htmlspecialchars($row['comment_text']),
                'date' => date('M d', strtotime($row['created_at'])),
                'time' => date('h:i A', strtotime($row['created_at'])),
                'replies' => $replies
            ];
        }
        mysqli_free_result($result);
        
        echo json_encode(['success' => true, 'comments' => $comments]);
        exit;
    }
    
    // Add Comment
    if ($_POST['action'] === 'add_comment' && isset($_POST['media_id']) && isset($_POST['comment_text'])) {
        $media_id = (int)$_POST['media_id'];
        $comment_text = trim($_POST['comment_text']);
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        
        if (empty($comment_text)) {
            echo json_encode(['success' => false, 'message' => 'Comment cannot be empty']);
            exit;
        }
        
        if (strlen($comment_text) > 1000) {
            echo json_encode(['success' => false, 'message' => 'Comment is too long (max 1000 characters)']);
            exit;
        }
        
        $comment_text = mysqli_real_escape_string($conn, $comment_text);
        
        $sql = "INSERT INTO gallery_comments (media_id, user_id, comment_text, created_at) VALUES ($media_id, $user_id, '$comment_text', NOW())";
        
        if (mysqli_query($conn, $sql)) {
            $comment_id = mysqli_insert_id($conn);
            
            // Update comment count
            $count_query = "SELECT COUNT(*) as count FROM gallery_comments WHERE media_id = $media_id";
            $count_result = mysqli_query($conn, $count_query);
            $count = mysqli_fetch_assoc($count_result)['count'];
            mysqli_query($conn, "UPDATE gallery_media SET comment_count = $count WHERE id = $media_id");
            
            $user_name = 'Visitor';
            if ($user_id > 0) {
                $user_result = mysqli_query($conn, "SELECT name FROM users WHERE id = $user_id");
                if ($user_row = mysqli_fetch_assoc($user_result)) {
                    $user_name = htmlspecialchars($user_row['name']);
                }
            }
            
            echo json_encode([
                'success' => true,
                'comment' => [
                    'id' => $comment_id,
                    'name' => $user_name,
                    'text' => htmlspecialchars($comment_text),
                    'date' => date('M d'),
                    'time' => date('h:i A'),
                    'replies' => []
                ],
                'comment_count' => (int)$count
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }
    
    // Get Stats
    if ($_POST['action'] === 'get_stats') {
        $stats = mysqli_fetch_assoc(mysqli_query($conn, 
            "SELECT COALESCE(SUM(like_count), 0) as total_likes, 
                    COALESCE(SUM(comment_count), 0) as total_comments 
             FROM gallery_media WHERE is_approved = 1"));
        echo json_encode([
            'success' => true, 
            'total_likes' => (int)$stats['total_likes'], 
            'total_comments' => (int)$stats['total_comments']
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#f8f9fa">
    <title>Gallery - Queenest Paradise Montessori Complex</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'Poppins', 'ui-sans-serif', 'system-ui'],
                        'display': ['Poppins', 'Inter', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out',
                        'slide-up': 'slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
                        'slide-down': 'slideDown 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
                        'heart-beat': 'heartBeat 0.4s ease',
                        'float': 'float 3s ease-in-out infinite',
                        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
                        'bounce-in': 'bounceIn 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55)',
                        'scale-in': 'scaleIn 0.3s ease-out',
                        'shimmer': 'shimmer 2s infinite',
                        'rotate-slow': 'rotateSlow 20s linear infinite',
                        'gradient-shift': 'gradientShift 8s ease infinite',
                    },
                    keyframes: {
                        fadeIn: { 
                            '0%': { opacity: '0', transform: 'translateY(20px)' }, 
                            '100%': { opacity: '1', transform: 'translateY(0)' } 
                        },
                        slideUp: { 
                            '0%': { transform: 'translateY(100%)' }, 
                            '100%': { transform: 'translateY(0)' } 
                        },
                        slideDown: { 
                            '0%': { transform: 'translateY(-100%)', opacity: '0' }, 
                            '100%': { transform: 'translateY(0)', opacity: '1' } 
                        },
                        heartBeat: { 
                            '0%, 100%': { transform: 'scale(1)' }, 
                            '25%': { transform: 'scale(1.3)' }, 
                            '50%': { transform: 'scale(1)' } 
                        },
                        float: { 
                            '0%, 100%': { transform: 'translateY(0)' }, 
                            '50%': { transform: 'translateY(-10px)' } 
                        },
                        pulseSoft: { 
                            '0%, 100%': { opacity: '1' }, 
                            '50%': { opacity: '0.8' } 
                        },
                        bounceIn: { 
                            '0%': { transform: 'scale(0.3)', opacity: '0' }, 
                            '50%': { transform: 'scale(1.05)' }, 
                            '70%': { transform: 'scale(0.9)' }, 
                            '100%': { transform: 'scale(1)', opacity: '1' } 
                        },
                        scaleIn: { 
                            '0%': { transform: 'scale(0.9)', opacity: '0' }, 
                            '100%': { transform: 'scale(1)', opacity: '1' } 
                        },
                        shimmer: { 
                            '0%': { backgroundPosition: '-1000px 0' }, 
                            '100%': { backgroundPosition: '1000px 0' } 
                        },
                        rotateSlow: { 
                            '0%': { transform: 'rotate(0deg)' }, 
                            '100%': { transform: 'rotate(360deg)' } 
                        },
                        gradientShift: {
                            '0%, 100%': { backgroundPosition: '0% 50%' },
                            '50%': { backgroundPosition: '100% 50%' }
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            overflow-x: hidden;
            position: relative;
        }
        
        /* Animated background elements */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255, 154, 139, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(102, 126, 234, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 50% 80%, rgba(254, 182, 146, 0.3) 0%, transparent 50%);
            animation: rotateSlow 30s linear infinite;
            z-index: 0;
            pointer-events: none;
        }
        
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background: 
                radial-gradient(circle at 50% 0%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 0% 100%, rgba(255, 154, 139, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 100% 100%, rgba(102, 126, 234, 0.1) 0%, transparent 50%);
            z-index: 0;
            pointer-events: none;
        }
        
        .glass-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 2;
        }
        
        .content-wrapper {
            position: relative;
            z-index: 1;
        }
        
        .album-card {
            position: relative; 
            border-radius: 24px; 
            overflow: hidden; 
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
            aspect-ratio: 1; 
            background: linear-gradient(135deg, #667eea, #764ba2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transform: translateY(0);
        }
        .album-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        .album-card:active { 
            transform: scale(0.95); 
            transition: all 0.2s ease;
        }
        .album-card img { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .album-card:hover img { 
            transform: scale(1.1); 
        }
        .album-card::after {
            content: ''; 
            position: absolute; 
            inset: 0;
            background: linear-gradient(
                to top, 
                rgba(0, 0, 0, 0.4) 0%, 
                rgba(0, 0, 0, 0.1) 50%, 
                transparent 100%
            ); 
            z-index: 1;
            transition: all 0.3s ease;
        }
        .album-card:hover::after {
            background: linear-gradient(
                to top, 
                rgba(0, 0, 0, 0.3) 0%, 
                rgba(0, 0, 0, 0.05) 50%, 
                transparent 100%
            );
        }
        .album-overlay { 
            position: absolute; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            padding: 24px; 
            z-index: 2; 
            color: white;
            transform: translateY(0);
            transition: transform 0.3s ease;
        }
        .album-card:hover .album-overlay {
            transform: translateY(-4px);
        }
        
        .reels-container {
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            z-index: 1000;
            overflow-y: scroll; 
            scroll-snap-type: y mandatory; 
            -webkit-overflow-scrolling: touch;
        }
        .reels-container::-webkit-scrollbar { display: none; }
        .reel-item {
            position: relative; 
            width: 100%; 
            height: 100vh; 
            scroll-snap-align: start;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            background: #000;
        }
        .reel-item img, .reel-item video { 
            width: 100%; 
            height: 100%; 
            object-fit: contain; 
        }
        .reel-gradient-top {
            position: absolute; 
            top: 0; 
            left: 0; 
            right: 0; 
            height: 120px;
            background: linear-gradient(to bottom, rgba(0,0,0,0.4), transparent); 
            z-index: 5; 
            pointer-events: none;
        }
        .reel-gradient-bottom {
            position: absolute; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            height: 250px;
            background: linear-gradient(to top, rgba(0,0,0,0.6) 0%, rgba(0,0,0,0.2) 60%, transparent 100%); 
            z-index: 5; 
            pointer-events: none;
        }
        .reel-overlay { 
            position: absolute; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            padding: 24px 100px 40px 24px; 
            z-index: 6; 
        }
        .reel-actions {
            position: absolute; 
            right: 16px; 
            bottom: 140px; 
            display: flex; 
            flex-direction: column;
            align-items: center; 
            gap: 24px; 
            z-index: 10;
        }
        .action-btn {
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            gap: 6px;
            cursor: pointer; 
            transition: all 0.2s ease; 
            color: white; 
            border: none; 
            background: none; 
            padding: 0;
            outline: none;
        }
        .action-btn:focus-visible {
            outline: 2px solid #f97316;
            outline-offset: 2px;
            border-radius: 50%;
        }
        .action-btn:active { transform: scale(0.85); }
        .action-icon {
            width: 50px; 
            height: 50px; 
            border-radius: 50%; 
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px); 
            display: flex; 
            align-items: center; 
            justify-content: center;
            font-size: 22px; 
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .action-btn:hover .action-icon {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.1);
        }
        .action-btn.liked .action-icon { 
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.6), rgba(251, 113, 133, 0.6));
            border-color: rgba(239, 68, 68, 0.4);
        }
        .action-btn.liked .action-icon i { 
            color: #fecdd3;
        }
        .action-btn span { 
            font-size: 12px; 
            font-weight: 700; 
            text-shadow: 0 1px 3px rgba(0,0,0,0.8); 
            user-select: none;
        }
        
        .back-btn {
            position: fixed; 
            top: 16px; 
            left: 16px; 
            z-index: 1001; 
            width: 44px; 
            height: 44px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px); 
            border-radius: 50%;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: white; 
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.2); 
            transition: all 0.3s ease;
        }
        .back-btn:hover { 
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }
        .back-btn:active { transform: scale(0.9); }
        
        .album-info-bar {
            position: fixed; 
            top: 16px; 
            left: 50%; 
            transform: translateX(-50%); 
            z-index: 1001;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px); 
            border-radius: 50px;
            padding: 8px 20px; 
            color: white; 
            font-size: 14px; 
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.2); 
            white-space: nowrap;
            max-width: 65vw; 
            overflow: hidden; 
            text-overflow: ellipsis;
        }
        
        .comments-panel {
            position: fixed; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px); 
            border-radius: 28px 28px 0 0; 
            z-index: 1100;
            max-height: 75vh; 
            display: flex; 
            flex-direction: column;
            transform: translateY(100%); 
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.15);
        }
        .comments-panel.open { transform: translateY(0); }
        .comment-item { 
            animation: fadeIn 0.3s ease-out; 
            transition: background 0.2s ease;
        }
        .comment-item:hover {
            background: rgba(102, 126, 234, 0.05);
            border-radius: 12px;
        }
        
        .loader {
            width: 40px; 
            height: 40px; 
            border: 4px solid rgba(255, 255, 255, 0.2);
            border-top-color: #667eea; 
            border-radius: 50%; 
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .notification {
            position: fixed; 
            top: 80px; 
            left: 50%; 
            transform: translateX(-50%); 
            z-index: 2000;
            padding: 14px 28px; 
            border-radius: 50px; 
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px); 
            color: #1a1a2e; 
            font-weight: 600; 
            font-size: 14px;
            animation: slideDown 0.4s ease; 
            white-space: nowrap;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        @keyframes slideDown { 
            from { transform: translateX(-50%) translateY(-100%); opacity: 0; } 
            to { transform: translateX(-50%) translateY(0); opacity: 1; } 
        }
        
        /* Comments Overlay */
        .comments-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.3);
            z-index: 1099;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .comments-overlay.active {
            opacity: 1;
            pointer-events: all;
        }
        
        /* Colorful card backgrounds */
        .card-gradient-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-gradient-2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .card-gradient-3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .card-gradient-4 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .card-gradient-5 { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .card-gradient-6 { background: linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%); }
        
        @media (min-width: 768px) {
            .reel-item { 
                max-width: 500px; 
                margin: 0 auto; 
                border-radius: 16px; 
                overflow: hidden; 
            }
            .reels-container { 
                background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            }
            .reel-actions { 
                right: calc(50% - 220px); 
            }
            .comments-panel {
                max-width: 500px;
                left: 50%;
                transform: translate(-50%, 100%);
            }
            .comments-panel.open {
                transform: translate(-50%, 0);
            }
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Comments Overlay -->
    <div class="comments-overlay" id="commentsOverlay" onclick="closeComments()"></div>
    
    <!-- Main Albums View -->
    <div id="albumsView" class="content-wrapper">
        <header class="sticky top-0 z-50 glass-header">
            <div class="max-w-7xl mx-auto px-4 py-4">
                <div class="flex items-center justify-between">
                    <a href="index.php" class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-pink-500 rounded-2xl flex items-center justify-center shadow-lg shadow-orange-500/20">
                            <i class="fas fa-graduation-cap text-white"></i>
                        </div>
                        <div>
                            <h1 class="text-gray-800 font-bold text-lg leading-tight">Gallery</h1>
                            <p class="text-gray-500 text-[10px] tracking-wider uppercase">QPMCE</p>
                        </div>
                    </a>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5 bg-white/50 backdrop-blur-sm rounded-full px-3 py-1.5 shadow-sm">
                            <i class="fas fa-photo-video text-purple-500 text-xs"></i>
                            <span class="text-gray-700 font-bold text-sm"><?= number_format($stats['total_media']) ?></span>
                        </div>
                        <div class="flex items-center gap-1.5 bg-white/50 backdrop-blur-sm rounded-full px-3 py-1.5 shadow-sm">
                            <i class="fas fa-heart text-pink-500 text-xs"></i>
                            <span class="text-gray-700 font-bold text-sm" id="headerLikes"><?= number_format($stats['total_likes']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        
        <div class="max-w-7xl mx-auto px-4 py-6">
            <div class="mb-8 text-center">
                <h2 class="text-3xl md:text-4xl font-bold font-display text-white mb-2 animate-fade-in">
                    📸 Our Photo Gallery
                </h2>
                <p class="text-white/80 text-lg animate-fade-in" style="animation-delay: 0.2s;">
                    <?= count($albums) ?> beautiful collections to explore
                </p>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6" id="albumsGrid">
                <?php 
                $gradients = ['card-gradient-1', 'card-gradient-2', 'card-gradient-3', 'card-gradient-4', 'card-gradient-5', 'card-gradient-6'];
                foreach ($albums as $index => $album): 
                    $gradient = $gradients[$index % count($gradients)];
                ?>
                <div class="album-card animate-bounce-in" 
                     style="animation-delay: <?= $index * 0.1 ?>s;"
                     onclick="openAlbum(<?= $album['id'] ?>)"
                     onkeypress="if(event.key==='Enter')openAlbum(<?= $album['id'] ?>)"
                     tabindex="0"
                     role="button"
                     aria-label="Open album: <?= htmlspecialchars($album['title']) ?>">
                    <?php if ($album['cover_image']): ?>
                        <img src="<?= htmlspecialchars($album['cover_image']) ?>" 
                             alt="<?= htmlspecialchars($album['title']) ?>" 
                             loading="lazy" 
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="w-full h-full <?= $gradient ?> items-center justify-center" style="display:none;">
                            <i class="fas fa-images text-white text-5xl opacity-30"></i>
                        </div>
                    <?php else: ?>
                        <div class="w-full h-full <?= $gradient ?> flex items-center justify-center">
                            <i class="fas fa-images text-white text-5xl opacity-30"></i>
                        </div>
                    <?php endif; ?>
                    <div class="album-overlay">
                        <span class="inline-flex items-center gap-1 bg-white/20 backdrop-blur-sm rounded-full px-3 py-1 text-xs font-bold mb-2">
                            <i class="fas fa-image"></i> <?= $album['media_count'] ?> photos
                        </span>
                        <h3 class="font-bold text-sm md:text-base leading-tight"><?= htmlspecialchars($album['title']) ?></h3>
                        <?php if ($album['class_name']): ?>
                            <p class="text-white/80 text-xs mt-1"><?= htmlspecialchars($album['class_name']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($albums)): ?>
                <div class="col-span-full rounded-3xl p-12 text-center bg-white/10 backdrop-blur-sm border border-white/20">
                    <div class="text-7xl mb-6 animate-float">📸</div>
                    <h3 class="text-white text-xl font-bold mb-2">No Albums Yet</h3>
                    <p class="text-white/70 max-w-md mx-auto text-sm">Check back soon for amazing photos!</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Album Detail View -->
    <div id="albumDetailView" class="fixed inset-0 z-[999] overflow-y-auto" style="display:none; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="back-btn" onclick="closeAlbumDetail()">
            <i class="fas fa-chevron-left"></i>
        </div>
        <div id="albumDetailContent" class="max-w-4xl mx-auto pt-16 pb-32 px-4">
            <div class="flex justify-center py-20"><div class="loader"></div></div>
        </div>
    </div>
    
    <!-- Reels View -->
    <div id="reelsView" class="reels-container" style="display: none;">
        <div class="back-btn" onclick="closeReels()"><i class="fas fa-chevron-left"></i></div>
        <div class="album-info-bar" id="albumInfoBar">
            <i class="fas fa-folder-open mr-2 text-orange-400"></i>
            <span id="albumInfoText">Album</span>
        </div>
        <div id="reelsContent"></div>
    </div>
    
    <!-- Comments Panel -->
    <div class="comments-panel" id="commentsPanel">
        <div class="flex justify-center py-3">
            <div class="w-10 h-1 bg-gray-300 rounded-full"></div>
        </div>
        <div class="flex items-center justify-between px-6 py-3 border-b border-gray-200">
            <h3 class="text-gray-800 font-bold text-lg flex items-center gap-2">
                <i class="fas fa-comments text-purple-500"></i>Comments 
                <span id="commentCount" class="text-sm text-gray-500"></span>
            </h3>
            <button onclick="closeComments()" class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 hover:bg-gray-300 transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto px-6 py-4" id="commentsList">
            <div class="flex justify-center py-8"><div class="loader"></div></div>
        </div>
        <div class="border-t border-gray-200 p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-user text-white text-sm"></i>
                </div>
                <input type="text" id="commentInput" 
                       class="flex-1 bg-gray-100 text-gray-800 rounded-full px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 placeholder-gray-400 transition-all"
                       placeholder="Add a comment..." 
                       maxlength="1000"
                       onkeypress="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();submitComment();}">
                <button onclick="submitComment()" 
                        class="bg-gradient-to-r from-purple-500 to-pink-500 text-white font-bold py-2 px-4 rounded-full hover:shadow-lg hover:shadow-purple-500/30 transition-all duration-300 flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed transform hover:scale-105 active:scale-95"
                        id="postCommentBtn">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentAlbumData = null;
        let currentMediaList = [];
        let currentIndex = 0;
        let currentMediaId = null;
        let likedMedia = new Set();
        
        function openAlbum(albumId) {
            const detailView = document.getElementById('albumDetailView');
            const content = document.getElementById('albumDetailContent');
            
            detailView.style.display = 'block';
            document.body.style.overflow = 'hidden';
            content.innerHTML = '<div class="flex justify-center py-20"><div class="loader"></div></div>';
            
            const formData = new URLSearchParams();
            formData.append('action', 'get_album_media');
            formData.append('album_id', albumId);
            
            fetch('gallery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    currentAlbumData = data.album;
                    currentMediaList = data.media;
                    renderAlbumDetail(data.album, data.media);
                } else {
                    content.innerHTML = `
                        <div class="text-center py-20">
                            <p class="text-red-400">${data.message || 'Error loading album'}</p>
                            <button onclick="closeAlbumDetail()" class="mt-4 text-white underline hover:text-purple-200 transition-colors">Go back</button>
                        </div>`;
                }
            })
            .catch(err => {
                console.error('Error:', err);
                content.innerHTML = `
                    <div class="text-center py-20">
                        <p class="text-red-400">Failed to load. Please try again.</p>
                        <button onclick="closeAlbumDetail()" class="mt-4 text-white underline hover:text-purple-200 transition-colors">Go back</button>
                    </div>`;
            });
        }
        
        function closeAlbumDetail() {
            document.getElementById('albumDetailView').style.display = 'none';
            document.body.style.overflow = '';
            currentAlbumData = null;
            currentMediaList = [];
        }
        
        function renderAlbumDetail(album, media) {
            const content = document.getElementById('albumDetailContent');
            
            let mediaGrid = '';
            if (media.length === 0) {
                mediaGrid = `
                    <div class="rounded-2xl p-10 text-center mt-6 bg-white/10 backdrop-blur-sm border border-white/20">
                        <div class="text-5xl mb-4">📭</div>
                        <p class="text-white font-medium">No media in this album yet</p>
                        <p class="text-white/70 text-sm mt-1">Check back soon!</p>
                    </div>
                `;
            } else {
                mediaGrid = `
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mt-6">
                        ${media.map((item, i) => `
                            <div class="relative rounded-2xl overflow-hidden cursor-pointer aspect-square bg-white/10 group transform hover:scale-105 transition-all duration-300 hover:shadow-xl" 
                                 onclick="openReelsFromDetail(${i})"
                                 onkeypress="if(event.key==='Enter')openReelsFromDetail(${i})"
                                 tabindex="0"
                                 role="button"
                                 aria-label="View photo">
                                <img src="${item.thumbnail_path}" alt="Gallery photo" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" loading="lazy" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22%3E%3Crect fill=%22%23667eea%22 width=%22100%22 height=%22100%22/%3E%3Ctext fill=%22%23fff%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3EPhoto%3C/text%3E%3C/svg%3E'">
                                ${item.media_type === 'video' ? `
                                    <div class="absolute inset-0 flex items-center justify-center bg-black/20 group-hover:bg-black/30 transition-colors">
                                        <div class="w-14 h-14 rounded-full bg-white/20 backdrop-blur-sm flex items-center justify-center group-hover:scale-110 transition-transform">
                                            <i class="fas fa-play text-white text-xl ml-1"></i>
                                        </div>
                                    </div>
                                ` : ''}
                                <div class="absolute bottom-0 left-0 right-0 p-3 bg-gradient-to-t from-black/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                    <div class="flex items-center gap-2">
                                        <span class="text-white text-xs"><i class="fas fa-heart text-pink-400 mr-0.5"></i>${formatCount(item.like_count)}</span>
                                        <span class="text-white text-xs"><i class="fas fa-comment text-blue-400 mr-0.5"></i>${formatCount(item.comment_count)}</span>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            content.innerHTML = `
                <div class="rounded-2xl p-6 mb-6 bg-white/10 backdrop-blur-sm border border-white/20 animate-fade-in">
                    <div class="flex items-start gap-4">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center flex-shrink-0 shadow-lg">
                            <i class="fas fa-folder-open text-white text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <h2 class="text-white text-xl font-bold">${album.title}</h2>
                            ${album.description ? `<p class="text-white/80 text-sm mt-2 leading-relaxed">${album.description}</p>` : ''}
                            <div class="flex flex-wrap items-center gap-3 mt-3">
                                ${album.class_name ? `<span class="text-white/80 text-xs bg-white/10 rounded-full px-3 py-1"><i class="fas fa-school mr-1"></i>${album.class_name}</span>` : ''}
                                ${album.department_name ? `<span class="text-white/80 text-xs bg-white/10 rounded-full px-3 py-1"><i class="fas fa-building mr-1"></i>${album.department_name}</span>` : ''}
                                <span class="text-white/80 text-xs bg-white/10 rounded-full px-3 py-1"><i class="fas fa-image mr-1"></i>${media.length} photos</span>
                                <span class="text-white/60 text-xs"><i class="fas fa-calendar mr-1"></i>${album.created_at}</span>
                            </div>
                        </div>
                    </div>
                    ${media.length > 0 ? `
                    <button onclick="openReelsFromDetail(0)" class="mt-5 w-full bg-white/20 backdrop-blur-sm text-white font-bold py-3 rounded-xl hover:bg-white/30 transition-all duration-300 flex items-center justify-center gap-2 transform hover:scale-[1.02] active:scale-[0.98] border border-white/20">
                        <i class="fas fa-play"></i> View All Photos
                    </button>
                    ` : ''}
                </div>
                ${mediaGrid}
            `;
        }
        
        function openReelsFromDetail(startIndex) {
            currentIndex = startIndex;
            document.getElementById('albumDetailView').style.display = 'none';
            document.getElementById('albumInfoText').textContent = currentAlbumData?.title || 'Album';
            showReels();
            renderReels();
            setTimeout(() => {
                const el = document.getElementById(`reel-${startIndex}`);
                if (el) el.scrollIntoView({ behavior: 'instant' });
            }, 100);
        }
        
        function showReels() {
            document.getElementById('albumsView').style.display = 'none';
            document.getElementById('reelsView').style.display = 'block';
        }
        
        function closeReels() {
            document.getElementById('reelsView').style.display = 'none';
            document.getElementById('albumDetailView').style.display = 'block';
            pauseAllVideos();
            updateStats();
        }
        
        function renderReels() {
            const container = document.getElementById('reelsContent');
            container.innerHTML = currentMediaList.map((media, index) => `
                <div class="reel-item" id="reel-${index}" data-index="${index}">
                    <div class="reel-gradient-top"></div>
                    <div class="reel-gradient-bottom"></div>
                    ${media.media_type === 'image' ? 
                        `<img src="${media.file_path}" alt="Gallery photo" loading="lazy">` :
                        `<video src="${media.file_path}" loop muted playsinline preload="metadata"></video>
                         <div style="position:absolute;bottom:0;left:0;height:3px;background:linear-gradient(to right,#667eea,#764ba2);z-index:7;transition:width 0.1s linear;width:0%" id="progress-${index}"></div>`
                    }
                    <div class="reel-overlay">
                        <h3 class="text-white font-bold text-lg mb-2">${media.description ? media.description.substring(0, 100) : 'Gallery Photo'}</h3>
                        <p class="text-gray-300 text-sm">${timeAgo(media.created_at)}</p>
                    </div>
                    <div class="reel-actions">
                        <button class="action-btn ${likedMedia.has(media.id) ? 'liked' : ''}" onclick="toggleLike(${media.id}, this, ${index})" type="button" aria-label="Like photo">
                            <div class="action-icon"><i class="fas fa-heart"></i></div>
                            <span>${formatCount(media.like_count)}</span>
                        </button>
                        <button class="action-btn" onclick="openComments(${media.id})" type="button" aria-label="View comments">
                            <div class="action-icon"><i class="fas fa-comment"></i></div>
                            <span>${formatCount(media.comment_count)}</span>
                        </button>
                        <button class="action-btn" onclick="shareMedia(${media.id})" type="button" aria-label="Share photo">
                            <div class="action-icon"><i class="fas fa-paper-plane"></i></div>
                            <span>Share</span>
                        </button>
                    </div>
                </div>
            `).join('');
            
            setupScrollObserver();
            setTimeout(() => {
                playVideoAtIndex(currentIndex);
                checkLikeStatus(currentMediaList[currentIndex].id);
            }, 300);
        }
        
        function setupScrollObserver() {
            const container = document.getElementById('reelsView');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    const index = parseInt(entry.target.dataset.index);
                    if (entry.isIntersecting && entry.intersectionRatio > 0.6) {
                        currentIndex = index;
                        playVideoAtIndex(index);
                        if (currentMediaList[index]) {
                            checkLikeStatus(currentMediaList[index].id);
                        }
                    } else {
                        pauseVideoAtIndex(index);
                    }
                });
            }, { root: container, threshold: 0.6 });
            document.querySelectorAll('.reel-item').forEach(item => observer.observe(item));
        }
        
        function playVideoAtIndex(index) {
            const reel = document.getElementById(`reel-${index}`);
            if (!reel) return;
            const video = reel.querySelector('video');
            if (video) {
                video.currentTime = 0;
                video.play().catch(() => { 
                    video.muted = true; 
                    video.play(); 
                });
                video.ontimeupdate = function() {
                    const progress = document.getElementById(`progress-${index}`);
                    if (progress && video.duration) {
                        progress.style.width = (video.currentTime / video.duration) * 100 + '%';
                    }
                };
            }
            currentMediaList.forEach((_, i) => { if (i !== index) pauseVideoAtIndex(i); });
        }
        
        function pauseVideoAtIndex(index) {
            const video = document.getElementById(`reel-${index}`)?.querySelector('video');
            if (video) video.pause();
        }
        
        function pauseAllVideos() {
            document.querySelectorAll('#reelsContent video').forEach(v => v.pause());
        }
        
        function toggleLike(mediaId, btn, index) {
            if (btn.dataset.loading === 'true') return;
            btn.dataset.loading = 'true';
            
            const formData = new URLSearchParams();
            formData.append('action', 'like');
            formData.append('media_id', mediaId);
            
            fetch('gallery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    btn.querySelector('span').textContent = formatCount(data.like_count);
                    if (data.liked) {
                        btn.classList.add('liked');
                        likedMedia.add(mediaId);
                    } else {
                        btn.classList.remove('liked');
                        likedMedia.delete(mediaId);
                    }
                    if (currentMediaList[index]) {
                        currentMediaList[index].like_count = data.like_count;
                    }
                    updateStats();
                }
            })
            .catch(err => console.error('Like error:', err))
            .finally(() => { btn.dataset.loading = 'false'; });
        }
        
        function checkLikeStatus(mediaId) {
            const formData = new URLSearchParams();
            formData.append('action', 'check_like');
            formData.append('media_id', mediaId);
            
            fetch('gallery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.liked) {
                    likedMedia.add(mediaId);
                    const btn = document.querySelector(`button[onclick*="toggleLike(${mediaId}"]`);
                    if (btn) btn.classList.add('liked');
                }
            })
            .catch(() => {});
        }
        
        function openComments(mediaId) {
            currentMediaId = mediaId;
            document.getElementById('commentsPanel').classList.add('open');
            document.getElementById('commentsOverlay').classList.add('active');
            document.getElementById('commentInput').focus();
            loadComments(mediaId);
        }
        
        function closeComments() {
            document.getElementById('commentsPanel').classList.remove('open');
            document.getElementById('commentsOverlay').classList.remove('active');
            currentMediaId = null;
        }
        
        function loadComments(mediaId) {
            const list = document.getElementById('commentsList');
            const countEl = document.getElementById('commentCount');
            list.innerHTML = '<div class="flex justify-center py-8"><div class="loader"></div></div>';
            
            const formData = new URLSearchParams();
            formData.append('action', 'get_comments');
            formData.append('media_id', mediaId);
            
            fetch('gallery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    countEl.textContent = `(${data.comments.length})`;
                    if (data.comments.length === 0) {
                        list.innerHTML = `
                            <div class="text-center py-10">
                                <div class="text-4xl mb-3">💬</div>
                                <p class="text-gray-600">No comments yet</p>
                                <p class="text-gray-400 text-sm">Be the first!</p>
                            </div>`;
                    } else {
                        list.innerHTML = data.comments.map(c => `
                            <div class="comment-item flex gap-3 mb-5 p-2">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center flex-shrink-0">
                                    <span class="text-white text-xs font-bold">${c.name.charAt(0).toUpperCase()}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-0.5">
                                        <span class="text-gray-800 font-semibold text-sm">${c.name}</span>
                                        <span class="text-gray-400 text-xs">${c.date} at ${c.time}</span>
                                    </div>
                                    <p class="text-gray-600 text-sm break-words">${c.text}</p>
                                    ${c.replies && c.replies.length > 0 ? `
                                        <div class="mt-2 ml-4 pl-3 border-l-2 border-purple-300 space-y-2">
                                            ${c.replies.map(r => `
                                                <div class="py-1">
                                                    <span class="text-gray-800 font-semibold text-xs">${r.name}</span>
                                                    <span class="text-gray-400 text-xs ml-1">${r.date}</span>
                                                    <p class="text-gray-500 text-xs mt-0.5 break-words">${r.text}</p>
                                                </div>
                                            `).join('')}
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        `).join('');
                    }
                }
            })
            .catch(err => {
                console.error('Comments error:', err);
                list.innerHTML = '<p class="text-center text-red-400 py-8">Failed to load comments</p>';
            });
        }
        
        function submitComment() {
            const input = document.getElementById('commentInput');
            const text = input.value.trim();
            const btn = document.getElementById('postCommentBtn');
            
            if (!text || !currentMediaId) return;
            
            if (text.length > 1000) {
                showNotification('⚠️ Comment is too long');
                return;
            }
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            input.disabled = true;
            
            const formData = new URLSearchParams();
            formData.append('action', 'add_comment');
            formData.append('media_id', currentMediaId);
            formData.append('comment_text', text);
            
            fetch('gallery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    input.value = '';
                    loadComments(currentMediaId);
                    
                    const media = currentMediaList.find(m => m.id == currentMediaId);
                    if (media) {
                        media.comment_count = data.comment_count || (media.comment_count || 0) + 1;
                    }
                    updateStats();
                    showNotification('💬 Comment posted!');
                } else {
                    showNotification(data.message || '❌ Error posting comment');
                }
            })
            .catch(err => {
                console.error('Comment error:', err);
                showNotification('❌ Error posting comment');
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                input.disabled = false;
                input.focus();
            });
        }
        
        function shareMedia(mediaId) {
            const url = `${window.location.origin}${window.location.pathname}?media=${mediaId}`;
            if (navigator.share) {
                navigator.share({ 
                    title: 'Gallery - Queenest Paradise Montessori Complex', 
                    url 
                }).catch(() => {});
            } else {
                navigator.clipboard.writeText(url)
                    .then(() => showNotification('📋 Link copied!'))
                    .catch(() => showNotification('❌ Failed to copy link'));
            }
        }
        
        function updateStats() {
            const formData = new URLSearchParams();
            formData.append('action', 'get_stats');
            
            fetch('gallery.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => { 
                if (data.success) {
                    document.getElementById('headerLikes').textContent = formatCount(data.total_likes); 
                }
            })
            .catch(() => {});
        }
        
        function formatCount(c) {
            if (!c) return '0';
            if (c >= 1000000) return (c / 1000000).toFixed(1) + 'M';
            if (c >= 1000) return (c / 1000).toFixed(1) + 'K';
            return c.toString();
        }
        
        function timeAgo(d) {
            const s = Math.floor((new Date() - new Date(d)) / 1000);
            if (s < 60) return 'Just now';
            if (s < 3600) return Math.floor(s / 60) + 'm ago';
            if (s < 86400) return Math.floor(s / 3600) + 'h ago';
            if (s < 604800) return Math.floor(s / 86400) + 'd ago';
            return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
        
        function showNotification(msg) {
            const existing = document.querySelector('.notification');
            if (existing) existing.remove();
            const el = document.createElement('div');
            el.className = 'notification';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(() => { 
                el.style.opacity = '0'; 
                el.style.transition = 'opacity 0.3s ease'; 
                setTimeout(() => el.remove(), 300); 
            }, 2000);
        }
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            const reelsVisible = document.getElementById('reelsView').style.display !== 'none';
            const commentsOpen = document.getElementById('commentsPanel').classList.contains('open');
            
            if (e.key === 'Escape') {
                if (commentsOpen) {
                    closeComments();
                    e.preventDefault();
                } else if (reelsVisible) {
                    closeReels();
                    e.preventDefault();
                } else if (document.getElementById('albumDetailView').style.display !== 'none') {
                    closeAlbumDetail();
                    e.preventDefault();
                }
                return;
            }
            
            if (!reelsVisible || commentsOpen) return;
            
            if (e.key === 'ArrowDown' || e.key === 'j') {
                e.preventDefault();
                if (currentIndex < currentMediaList.length - 1) { 
                    currentIndex++; 
                    document.getElementById(`reel-${currentIndex}`)?.scrollIntoView({ behavior: 'smooth' }); 
                }
            } else if (e.key === 'ArrowUp' || e.key === 'k') {
                e.preventDefault();
                if (currentIndex > 0) { 
                    currentIndex--; 
                    document.getElementById(`reel-${currentIndex}`)?.scrollIntoView({ behavior: 'smooth' }); 
                }
            }
        });
        
        // Touch swipe for reels
        let touchY = 0;
        document.addEventListener('DOMContentLoaded', () => {
            const reelsView = document.getElementById('reelsView');
            reelsView.addEventListener('touchstart', e => { 
                touchY = e.touches[0].clientY; 
            }, { passive: true });
            
            reelsView.addEventListener('touchend', e => {
                const diff = touchY - e.changedTouches[0].clientY;
                if (Math.abs(diff) > 60) {
                    if (diff > 0 && currentIndex < currentMediaList.length - 1) {
                        currentIndex++;
                    } else if (diff < 0 && currentIndex > 0) {
                        currentIndex--;
                    }
                    document.getElementById(`reel-${currentIndex}`)?.scrollIntoView({ behavior: 'smooth' });
                }
            }, { passive: true });
        });
    </script>
</body>
</html>
<?php 
mysqli_close($conn);
ob_end_flush(); 
?>