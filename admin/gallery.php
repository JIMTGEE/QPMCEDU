<?php
ob_start();
session_start();

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include the database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check if the database connection was successful
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}

// Initialize messages
$message = '';
$message_type = '';

// Handle album creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_album'])) {
    $album_title = mysqli_real_escape_string($conn, trim($_POST['album_title']));
    $album_description = mysqli_real_escape_string($conn, trim($_POST['album_description']));
    $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : 'NULL';
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : 'NULL';
    
    if (empty($album_title)) {
        $message = "Album title is required!";
        $message_type = "danger";
    } else {
        $sql = "INSERT INTO gallery_albums (title, description, class_id, department_id, created_by) 
                VALUES ('$album_title', '$album_description', $class_id, $department_id, {$_SESSION['user_id']})";
        
        if (mysqli_query($conn, $sql)) {
            $message = "Album created successfully!";
            $message_type = "success";
        } else {
            $message = "Error creating album: " . mysqli_error($conn);
            $message_type = "danger";
        }
    }
}

// Handle album deletion with all its media
if (isset($_GET['delete_album']) && is_numeric($_GET['delete_album'])) {
    $album_id = (int)$_GET['delete_album'];
    
    $media_result = mysqli_query($conn, "SELECT file_path, thumbnail_path FROM gallery_media WHERE album_id = $album_id");
    while ($media = mysqli_fetch_assoc($media_result)) {
        if ($media['file_path'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $media['file_path'])) {
            unlink($_SERVER['DOCUMENT_ROOT'] . $media['file_path']);
        }
        if ($media['thumbnail_path'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $media['thumbnail_path'])) {
            unlink($_SERVER['DOCUMENT_ROOT'] . $media['thumbnail_path']);
        }
    }
    mysqli_free_result($media_result);
    
    if (mysqli_query($conn, "DELETE FROM gallery_albums WHERE id = $album_id")) {
        $message = "Album and all its media deleted successfully!";
        $message_type = "success";
    } else {
        $message = "Error deleting album: " . mysqli_error($conn);
        $message_type = "danger";
    }
}

// Handle bulk media upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_media'])) {
    $album_id = !empty($_POST['album_id']) ? (int)$_POST['album_id'] : null;
    $media_description = mysqli_real_escape_string($conn, trim($_POST['media_description']));
    $tags = !empty($_POST['tags']) ? explode(',', $_POST['tags']) : [];
    
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/gallery/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $uploaded_count = 0;
    $errors = [];
    
    if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $image_files = $_FILES['images'];
        $image_count = count($image_files['name']);
        
        for ($i = 0; $i < $image_count; $i++) {
            if ($image_files['error'][$i] === UPLOAD_ERR_OK && $image_files['size'][$i] > 0) {
                $file = [
                    'name' => $image_files['name'][$i],
                    'type' => $image_files['type'][$i],
                    'tmp_name' => $image_files['tmp_name'][$i],
                    'size' => $image_files['size'][$i]
                ];
                
                $result = uploadMediaFile($conn, $file, 'image', $album_id, $media_description, $tags, $upload_dir, $_SESSION['user_id']);
                if ($result['success']) {
                    $uploaded_count++;
                } else {
                    $errors[] = htmlspecialchars($file['name']) . ": " . $result['error'];
                }
            } elseif ($image_files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $errors[] = htmlspecialchars($image_files['name'][$i]) . ": Upload error";
            }
        }
    }
    
    if (isset($_FILES['videos']) && is_array($_FILES['videos']['name'])) {
        $video_files = $_FILES['videos'];
        $video_count = count($video_files['name']);
        
        for ($i = 0; $i < $video_count; $i++) {
            if ($video_files['error'][$i] === UPLOAD_ERR_OK && $video_files['size'][$i] > 0) {
                $file = [
                    'name' => $video_files['name'][$i],
                    'type' => $video_files['type'][$i],
                    'tmp_name' => $video_files['tmp_name'][$i],
                    'size' => $video_files['size'][$i]
                ];
                
                $result = uploadMediaFile($conn, $file, 'video', $album_id, $media_description, $tags, $upload_dir, $_SESSION['user_id']);
                if ($result['success']) {
                    $uploaded_count++;
                } else {
                    $errors[] = htmlspecialchars($file['name']) . ": " . $result['error'];
                }
            } elseif ($video_files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $errors[] = htmlspecialchars($video_files['name'][$i]) . ": Upload error";
            }
        }
    }
    
    if ($uploaded_count > 0) {
        $message = "Successfully uploaded {$uploaded_count} file(s)!";
        $message_type = "success";
    }
    
    if (!empty($errors)) {
        $error_list = implode("<br>", $errors);
        if ($uploaded_count > 0) {
            $message .= "<br><strong>Errors:</strong><br>" . $error_list;
            $message_type = "warning";
        } else {
            $message = "<strong>Upload failed:</strong><br>" . $error_list;
            $message_type = "danger";
        }
    }
}

// Handle media deletion
if (isset($_GET['delete_media']) && is_numeric($_GET['delete_media'])) {
    $media_id = (int)$_GET['delete_media'];
    
    $result = mysqli_query($conn, "SELECT file_path, thumbnail_path FROM gallery_media WHERE id = $media_id");
    if ($media = mysqli_fetch_assoc($result)) {
        if ($media['file_path'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $media['file_path'])) {
            unlink($_SERVER['DOCUMENT_ROOT'] . $media['file_path']);
        }
        if ($media['thumbnail_path'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $media['thumbnail_path'])) {
            unlink($_SERVER['DOCUMENT_ROOT'] . $media['thumbnail_path']);
        }
    }
    mysqli_free_result($result);
    
    if (mysqli_query($conn, "DELETE FROM gallery_media WHERE id = $media_id")) {
        $message = "Media deleted successfully!";
        $message_type = "success";
    } else {
        $message = "Error deleting media: " . mysqli_error($conn);
        $message_type = "danger";
    }
}

// Handle bulk media deletion
if (isset($_POST['bulk_delete']) && isset($_POST['selected_media']) && is_array($_POST['selected_media'])) {
    $selected_ids = array_map('intval', $_POST['selected_media']);
    $ids_string = implode(',', $selected_ids);
    
    $result = mysqli_query($conn, "SELECT file_path, thumbnail_path FROM gallery_media WHERE id IN ($ids_string)");
    while ($media = mysqli_fetch_assoc($result)) {
        if ($media['file_path'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $media['file_path'])) {
            unlink($_SERVER['DOCUMENT_ROOT'] . $media['file_path']);
        }
        if ($media['thumbnail_path'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $media['thumbnail_path'])) {
            unlink($_SERVER['DOCUMENT_ROOT'] . $media['thumbnail_path']);
        }
    }
    mysqli_free_result($result);
    
    $delete_count = count($selected_ids);
    if (mysqli_query($conn, "DELETE FROM gallery_media WHERE id IN ($ids_string)")) {
        $message = "{$delete_count} media file(s) deleted successfully!";
        $message_type = "success";
    } else {
        $message = "Error deleting media: " . mysqli_error($conn);
        $message_type = "danger";
    }
}

// Handle comment deletion
if (isset($_GET['delete_comment']) && is_numeric($_GET['delete_comment'])) {
    $comment_id = (int)$_GET['delete_comment'];
    if (mysqli_query($conn, "DELETE FROM gallery_comments WHERE id = $comment_id OR parent_comment_id = $comment_id")) {
        $message = "Comment deleted successfully!";
        $message_type = "success";
    } else {
        $message = "Error deleting comment: " . mysqli_error($conn);
        $message_type = "danger";
    }
}

// Handle media approval toggle
if (isset($_GET['toggle_approval']) && is_numeric($_GET['toggle_approval'])) {
    $media_id = (int)$_GET['toggle_approval'];
    $result = mysqli_query($conn, "SELECT is_approved FROM gallery_media WHERE id = $media_id");
    if ($row = mysqli_fetch_assoc($result)) {
        $new_status = $row['is_approved'] == 1 ? 0 : 1;
        mysqli_query($conn, "UPDATE gallery_media SET is_approved = $new_status WHERE id = $media_id");
        $message = "Media " . ($new_status ? "approved" : "unapproved") . " successfully!";
        $message_type = "success";
    }
}

// AJAX handler for comments and likes detail
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'media_detail' && isset($_GET['media_id'])) {
        $media_id = (int)$_GET['media_id'];
        
        // Get comments
        $comments = [];
        $comments_result = mysqli_query($conn, 
            "SELECT gc.*, u.name as user_name, u.role as user_role 
             FROM gallery_comments gc 
             LEFT JOIN users u ON gc.user_id = u.id 
             WHERE gc.media_id = $media_id 
             ORDER BY gc.created_at DESC");
        while ($comment = mysqli_fetch_assoc($comments_result)) {
            $comments[] = [
                'id' => $comment['id'],
                'user_name' => $comment['user_name'] ?? 'Anonymous',
                'user_role' => $comment['user_role'] ?? 'guest',
                'comment_text' => $comment['comment_text'],
                'created_at' => date('M d, Y h:i A', strtotime($comment['created_at'])),
                'is_edited' => $comment['is_edited']
            ];
        }
        
        // Get likes with reactions
        $likes = [];
        $likes_result = mysqli_query($conn, 
            "SELECT gl.*, u.name as user_name, u.role as user_role 
             FROM gallery_likes gl 
             LEFT JOIN users u ON gl.user_id = u.id 
             WHERE gl.media_id = $media_id 
             ORDER BY gl.created_at DESC LIMIT 50");
        while ($like = mysqli_fetch_assoc($likes_result)) {
            $likes[] = [
                'id' => $like['id'],
                'user_name' => $like['user_name'] ?? 'Guest',
                'user_role' => $like['user_role'] ?? 'guest',
                'reaction_type' => $like['reaction_type'],
                'created_at' => date('M d, Y h:i A', strtotime($like['created_at']))
            ];
        }
        
        // Get reaction counts
        $reactions_result = mysqli_query($conn, 
            "SELECT reaction_type, COUNT(*) as count 
             FROM gallery_likes 
             WHERE media_id = $media_id 
             GROUP BY reaction_type");
        $reactions = [];
        while ($reaction = mysqli_fetch_assoc($reactions_result)) {
            $reactions[$reaction['reaction_type']] = $reaction['count'];
        }
        
        echo json_encode([
            'success' => true,
            'comments' => $comments,
            'likes' => $likes,
            'reactions' => $reactions,
            'total_comments' => count($comments),
            'total_likes' => array_sum($reactions)
        ]);
        exit;
    }
}

// Helper function to upload a single media file
function uploadMediaFile($conn, $file, $media_type, $album_id, $description, $tags, $upload_dir, $user_id) {
    $max_size = ($media_type === 'image') ? 20 * 1024 * 1024 : 100 * 1024 * 1024;
    $allowed_images = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_videos = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
    $allowed_types = ($media_type === 'image') ? $allowed_images : $allowed_videos;
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'File too large (max ' . ($max_size / 1024 / 1024) . 'MB)'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type'];
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $unique_filename = uniqid('gallery_') . '_' . time() . '.' . $file_extension;
    $file_path = '/uploads/gallery/' . $unique_filename;
    $file_size = $file['size'];
    
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $unique_filename)) {
        return ['success' => false, 'error' => 'Failed to save file'];
    }
    
    $thumbnail_path = null;
    if ($media_type === 'image') {
        $thumbnail_filename = 'thumb_' . $unique_filename;
        if (createThumbnail($upload_dir . $unique_filename, $upload_dir . $thumbnail_filename, 300)) {
            $thumbnail_path = '/uploads/gallery/' . $thumbnail_filename;
        }
    }
    
    $title = pathinfo($file['name'], PATHINFO_FILENAME);
    $title = str_replace(['-', '_'], ' ', $title);
    $title = mysqli_real_escape_string($conn, $title);
    $description_sql = !empty($description) ? "'" . mysqli_real_escape_string($conn, $description) . "'" : 'NULL';
    $file_path_sql = "'" . mysqli_real_escape_string($conn, $file_path) . "'";
    $thumbnail_path_sql = $thumbnail_path ? "'" . mysqli_real_escape_string($conn, $thumbnail_path) . "'" : 'NULL';
    $mime_type_sql = "'" . mysqli_real_escape_string($conn, $mime_type) . "'";
    $album_id_sql = $album_id ? $album_id : 'NULL';
    
    $sql = "INSERT INTO gallery_media (album_id, title, description, media_type, file_path, thumbnail_path, 
            file_size, mime_type, uploaded_by) 
            VALUES ($album_id_sql, '$title', $description_sql, '$media_type', 
            $file_path_sql, $thumbnail_path_sql, $file_size, $mime_type_sql, $user_id)";
    
    if (!mysqli_query($conn, $sql)) {
        if (file_exists($upload_dir . $unique_filename)) {
            unlink($upload_dir . $unique_filename);
        }
        if ($thumbnail_path && file_exists($upload_dir . basename($thumbnail_path))) {
            unlink($upload_dir . basename($thumbnail_path));
        }
        return ['success' => false, 'error' => 'Database error'];
    }
    
    $media_id = mysqli_insert_id($conn);
    
    if (!empty($tags)) {
        foreach ($tags as $tag_name) {
            $tag_name = trim($tag_name);
            if (!empty($tag_name)) {
                $tag_name_sql = mysqli_real_escape_string($conn, $tag_name);
                $tag_slug = strtolower(str_replace(' ', '-', $tag_name));
                $tag_slug_sql = mysqli_real_escape_string($conn, $tag_slug);
                
                $check_tag = mysqli_query($conn, "SELECT id FROM gallery_tags WHERE slug = '$tag_slug_sql'");
                
                if (mysqli_num_rows($check_tag) > 0) {
                    $tag_row = mysqli_fetch_assoc($check_tag);
                    $tag_id = $tag_row['id'];
                } else {
                    mysqli_query($conn, "INSERT INTO gallery_tags (name, slug) VALUES ('$tag_name_sql', '$tag_slug_sql')");
                    $tag_id = mysqli_insert_id($conn);
                }
                
                if ($tag_id) {
                    mysqli_query($conn, "INSERT INTO gallery_media_tags (media_id, tag_id) VALUES ($media_id, $tag_id)");
                }
            }
        }
    }
    
    return ['success' => true];
}

function createThumbnail($source_path, $thumb_path, $thumb_width = 300) {
    if (!function_exists('imagecreatetruecolor')) return false;
    
    $image_info = @getimagesize($source_path);
    if (!$image_info) return false;
    
    list($width, $height, $type) = $image_info;
    if ($width <= 0 || $height <= 0) return false;
    
    $thumb_height = floor($height * ($thumb_width / $width));
    $thumb_image = imagecreatetruecolor($thumb_width, $thumb_height);
    if (!$thumb_image) return false;
    
    switch ($type) {
        case IMAGETYPE_JPEG: $source_image = @imagecreatefromjpeg($source_path); break;
        case IMAGETYPE_PNG: 
            $source_image = @imagecreatefrompng($source_path);
            if ($source_image) { imagealphablending($thumb_image, false); imagesavealpha($thumb_image, true); }
            break;
        case IMAGETYPE_GIF: $source_image = @imagecreatefromgif($source_path); break;
        case IMAGETYPE_WEBP: $source_image = @imagecreatefromwebp($source_path); break;
        default: return false;
    }
    
    if (!$source_image) { imagedestroy($thumb_image); return false; }
    
    imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $width, $height);
    
    switch ($type) {
        case IMAGETYPE_JPEG: $success = imagejpeg($thumb_image, $thumb_path, 80); break;
        case IMAGETYPE_PNG: $success = imagepng($thumb_image, $thumb_path, 8); break;
        case IMAGETYPE_GIF: $success = imagegif($thumb_image, $thumb_path); break;
        case IMAGETYPE_WEBP: $success = imagewebp($thumb_image, $thumb_path, 80); break;
    }
    
    imagedestroy($source_image);
    imagedestroy($thumb_image);
    return $success ?? false;
}

// Fetch data
$albums_result = mysqli_query($conn, 
    "SELECT ga.*, c.class_name, d.name as department_name, u.name as creator_name,
    (SELECT COUNT(*) FROM gallery_media WHERE album_id = ga.id) as media_count
    FROM gallery_albums ga
    LEFT JOIN classes c ON ga.class_id = c.id
    LEFT JOIN departments d ON ga.department_id = d.id
    LEFT JOIN users u ON ga.created_by = u.id
    ORDER BY ga.created_at DESC");
$albums = [];
while ($row = mysqli_fetch_assoc($albums_result)) $albums[] = $row;
mysqli_free_result($albums_result);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 24;
$offset = ($page - 1) * $per_page;

$total_media = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM gallery_media"))['total'];
$total_pages = ceil($total_media / $per_page);

$media_result = mysqli_query($conn, 
    "SELECT gm.*, ga.title as album_title, u.name as uploader_name,
    (SELECT COUNT(*) FROM gallery_comments WHERE media_id = gm.id) as comment_count,
    (SELECT COUNT(*) FROM gallery_likes WHERE media_id = gm.id) as like_count,
    (SELECT COUNT(*) FROM gallery_likes WHERE media_id = gm.id AND reaction_type = 'love') as love_count,
    (SELECT COUNT(*) FROM gallery_likes WHERE media_id = gm.id AND reaction_type = 'wow') as wow_count
    FROM gallery_media gm
    LEFT JOIN gallery_albums ga ON gm.album_id = ga.id
    LEFT JOIN users u ON gm.uploaded_by = u.id
    ORDER BY gm.created_at DESC
    LIMIT $offset, $per_page");
$media_items = [];
while ($row = mysqli_fetch_assoc($media_result)) $media_items[] = $row;
mysqli_free_result($media_result);

$classes_result = mysqli_query($conn, "SELECT id, class_name FROM classes ORDER BY class_name");
$classes = [];
while ($row = mysqli_fetch_assoc($classes_result)) $classes[] = $row;
mysqli_free_result($classes_result);

$departments_result = mysqli_query($conn, "SELECT id, name FROM departments ORDER BY name");
$departments = [];
while ($row = mysqli_fetch_assoc($departments_result)) $departments[] = $row;
mysqli_free_result($departments_result);

// Stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT COUNT(*) as total_media, 
    COALESCE(SUM(like_count), 0) as total_likes,
    COALESCE(SUM(comment_count), 0) as total_comments,
    COUNT(CASE WHEN is_approved = 0 THEN 1 END) as pending_count
    FROM gallery_media"));

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery Management - QueensPa School</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lightbox2@2.11.4/dist/css/lightbox.min.css">
    
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #ff6b00;
            --accent-color: #ff8c00;
        }
        
        body {
            background: linear-gradient(135deg, #ff9a00 0%, #ff4500 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .content {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            margin: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .dashboard-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }
        
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-bottom: none;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .btn-primary {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 69, 0, 0.4);
        }
        
        .media-card {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #eee;
            background: white;
        }
        
        .media-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }
        
        .media-card img, .media-card video {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        
        .media-card .media-overlay {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            gap: 5px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .media-card:hover .media-overlay {
            opacity: 1;
        }
        
        .media-checkbox {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 10;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .approval-badge {
            position: absolute;
            top: 10px;
            right: 50px;
            z-index: 10;
        }
        
        .album-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary-color);
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            cursor: pointer;
        }
        
        .album-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .reaction-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .reaction-like { background: #fee2e2; color: #dc2626; }
        .reaction-love { background: #fce7f3; color: #db2777; }
        .reaction-wow { background: #fef3c7; color: #d97706; }
        
        .comment-item {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s ease;
        }
        
        .comment-item:hover {
            background: #fafafa;
        }
        
        .detail-modal .modal-content {
            border-radius: 20px;
            overflow: hidden;
        }
        
        .detail-modal .modal-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .nav-pills .nav-link {
            color: var(--primary-color);
            border-radius: 10px;
            margin: 5px;
            padding: 10px 20px;
        }
        
        .nav-pills .nav-link.active {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border-color: var(--primary-color);
            color: white;
        }
        
        .upload-area {
            border: 2px dashed var(--secondary-color);
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .upload-area:hover {
            background: rgba(255, 69, 0, 0.05);
            border-color: var(--accent-color);
        }
        
        .upload-area.has-files {
            border-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
        }
        
        .file-preview-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        
        .file-preview-item {
            width: 100px;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            border: 2px solid #e0e0e0;
        }
        
        .file-preview-item img, .file-preview-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .file-preview-item .remove-file {
            position: absolute;
            top: 2px;
            right: 2px;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            line-height: 1;
        }
        
        .bulk-actions {
            background: #fff3e0;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: none;
        }
        
        .tab-content-wrapper {
            min-height: 400px;
        }
        
        @media (max-width: 768px) {
            .content {
                margin: 10px;
                padding: 15px;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .media-card img, .media-card video {
                height: 150px;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <!-- Header -->
        <div class="dashboard-header animate__animated animate__fadeInDown">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h1 class="mb-0"><i class="fas fa-images me-2"></i>Gallery Management</h1>
                <div class="mt-2 mt-md-0">
                    <a href="dashboard.php" class="btn btn-light me-2">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#createAlbumModal">
                        <i class="fas fa-folder-plus me-1"></i>New Album
                    </button>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row animate__animated animate__fadeIn">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ff4500, #ff6b00);">
                    <i class="fas fa-photo-video"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format($stats['total_media']) ?></div>
                    <small class="text-muted">Total Media</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <i class="fas fa-heart"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format($stats['total_likes']) ?></div>
                    <small class="text-muted">Total Likes</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <i class="fas fa-comments"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format($stats['total_comments']) ?></div>
                    <small class="text-muted">Total Comments</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format($stats['pending_count']) ?></div>
                    <small class="text-muted">Pending Approval</small>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <ul class="nav nav-pills mb-4 animate__animated animate__fadeIn" id="galleryTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="albums-tab" data-bs-toggle="pill" data-bs-target="#albums" type="button">
                    <i class="fas fa-folder me-1"></i>Albums
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="media-tab" data-bs-toggle="pill" data-bs-target="#all-media" type="button">
                    <i class="fas fa-photo-video me-1"></i>All Media
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="upload-tab" data-bs-toggle="pill" data-bs-target="#upload" type="button">
                    <i class="fas fa-upload me-1"></i>Bulk Upload
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content tab-content-wrapper" id="galleryTabsContent">
            <!-- Albums Tab -->
            <div class="tab-pane fade show active animate__animated animate__fadeIn" id="albums" role="tabpanel">
                <?php if (empty($albums)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-folder-open fa-4x text-muted mb-3"></i>
                        <h4>No Albums Yet</h4>
                        <p class="text-muted">Create your first album to organize your media</p>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAlbumModal">
                            <i class="fas fa-plus me-2"></i>Create Album
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($albums as $album): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="album-card" onclick="filterByAlbum(<?= $album['id'] ?>)">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="mb-0">
                                            <i class="fas fa-folder text-warning me-2"></i>
                                            <?= htmlspecialchars($album['title']) ?>
                                        </h5>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="event.stopPropagation(); confirmDeleteAlbum(<?= $album['id'] ?>, '<?= htmlspecialchars(addslashes($album['title'])) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    
                                    <?php if (!empty($album['description'])): ?>
                                        <p class="text-muted small"><?= htmlspecialchars(substr($album['description'], 0, 100)) ?><?= strlen($album['description']) > 100 ? '...' : '' ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <span class="badge badge-primary">
                                            <?= $album['media_count'] ?> items
                                        </span>
                                        <small class="text-muted">
                                            <?= $album['class_name'] ? htmlspecialchars($album['class_name']) : 'School-wide' ?>
                                        </small>
                                    </div>
                                    
                                    <small class="text-muted d-block mt-2">
                                        Created by <?= htmlspecialchars($album['creator_name'] ?? 'Unknown') ?> 
                                        on <?= date('M d, Y', strtotime($album['created_at'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- All Media Tab -->
            <div class="tab-pane fade animate__animated animate__fadeIn" id="all-media" role="tabpanel">
                <form method="POST" id="bulkDeleteForm">
                    <input type="hidden" name="bulk_delete" value="1">
                    
                    <div class="bulk-actions" id="bulkActions">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Selected: <span id="selectedCount">0</span> items</strong>
                            </div>
                            <div>
                                <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="deselectAll()">
                                    <i class="fas fa-times me-1"></i>Clear
                                </button>
                                <button type="submit" class="btn btn-danger btn-sm" 
                                        onclick="return confirm('Delete all selected media? This action cannot be undone.')">
                                    <i class="fas fa-trash me-1"></i>Delete Selected
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (empty($media_items)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-camera-retro fa-4x text-muted mb-3"></i>
                            <h4>No Media Yet</h4>
                            <p class="text-muted">Upload your first photo or video</p>
                            <button type="button" class="btn btn-primary" onclick="switchToUpload()">
                                <i class="fas fa-upload me-2"></i>Upload Media
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($media_items as $media): ?>
                                <div class="col-6 col-md-4 col-lg-3 col-xl-2 mb-4">
                                    <div class="media-card" style="cursor: pointer;" onclick="showMediaDetail(<?= $media['id'] ?>)">
                                        <input type="checkbox" class="form-check-input media-checkbox" 
                                               name="selected_media[]" value="<?= $media['id'] ?>" 
                                               onchange="event.stopPropagation(); updateBulkActions()"
                                               onclick="event.stopPropagation()">
                                        
                                        <?php if (!$media['is_approved']): ?>
                                        <div class="approval-badge">
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-clock"></i> Pending
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($media['media_type'] === 'image'): ?>
                                            <img src="<?= htmlspecialchars($media['thumbnail_path'] ?? $media['file_path']) ?>" 
                                                 alt="<?= htmlspecialchars($media['title']) ?>"
                                                 loading="lazy">
                                        <?php else: ?>
                                            <video preload="metadata">
                                                <source src="<?= htmlspecialchars($media['file_path']) ?>" 
                                                        type="<?= htmlspecialchars($media['mime_type'] ?? 'video/mp4') ?>">
                                            </video>
                                            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none;">
                                                <i class="fas fa-play-circle text-white" style="font-size: 40px; opacity: 0.8;"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="media-overlay">
                                            <button class="btn btn-sm btn-info" onclick="event.stopPropagation(); showMediaDetail(<?= $media['id'] ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="?toggle_approval=<?= $media['id'] ?>&page=<?= $page ?>" 
                                               class="btn btn-sm btn-<?= $media['is_approved'] ? 'warning' : 'success' ?>"
                                               onclick="event.stopPropagation()"
                                               title="<?= $media['is_approved'] ? 'Unapprove' : 'Approve' ?>">
                                                <i class="fas fa-<?= $media['is_approved'] ? 'ban' : 'check' ?>"></i>
                                            </a>
                                            <a href="?delete_media=<?= $media['id'] ?>&page=<?= $page ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="event.stopPropagation(); return confirm('Delete this media item?')"
                                               title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                        
                                        <div class="card-body p-2">
                                            <h6 class="card-title mb-1 small text-truncate" title="<?= htmlspecialchars($media['title']) ?>">
                                                <?= htmlspecialchars(substr($media['title'], 0, 20)) ?>
                                            </h6>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <i class="fas fa-<?= $media['media_type'] === 'image' ? 'image' : 'video' ?>"></i>
                                                </small>
                                                <div class="d-flex gap-1">
                                                    <span class="reaction-badge reaction-like" title="Likes">
                                                        ❤️ <?= $media['like_count'] ?>
                                                    </span>
                                                    <span class="badge bg-primary" title="Comments" style="font-size: 0.7rem;">
                                                        💬 <?= $media['comment_count'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php if ($media['album_title']): ?>
                                            <small class="text-muted d-block mt-1" style="font-size: 0.65rem;">
                                                <i class="fas fa-folder"></i> <?= htmlspecialchars($media['album_title']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?= $page - 1 ?>#all-media">&laquo; Previous</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>#all-media"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?= $page + 1 ?>#all-media">Next &raquo;</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Bulk Upload Tab -->
            <div class="tab-pane fade animate__animated animate__fadeIn" id="upload" role="tabpanel">
                <div class="row">
                    <div class="col-lg-10 mx-auto">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-cloud-upload-alt me-2"></i>Bulk Media Upload
                                <span class="float-end">
                                    <span class="badge bg-light text-dark me-1">
                                        <i class="fas fa-image"></i> Max 50 Images
                                    </span>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-video"></i> Max 10 Videos
                                    </span>
                                </span>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                                    <input type="hidden" name="upload_media" value="1">
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6 mb-3 mb-md-0">
                                            <label for="album_id" class="form-label fw-bold">
                                                <i class="fas fa-folder me-1"></i>Select Album
                                            </label>
                                            <select class="form-select" id="album_id" name="album_id">
                                                <option value="">No Album (General)</option>
                                                <?php foreach ($albums as $album): ?>
                                                    <option value="<?= $album['id'] ?>">
                                                        <?= htmlspecialchars($album['title']) ?>
                                                        <?= $album['class_name'] ? ' (' . htmlspecialchars($album['class_name']) . ')' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="tags" class="form-label fw-bold">
                                                <i class="fas fa-tags me-1"></i>Tags
                                            </label>
                                            <input type="text" class="form-control" id="tags" name="tags" 
                                                   placeholder="e.g., sports, event, graduation">
                                            <small class="text-muted">Separate multiple tags with commas</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="media_description" class="form-label fw-bold">
                                            <i class="fas fa-align-left me-1"></i>Common Description
                                        </label>
                                        <textarea class="form-control" id="media_description" name="media_description" 
                                                  rows="2" placeholder="Enter a description for all uploaded media (optional)"></textarea>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-images me-1"></i>Upload Images
                                            <small class="text-muted">(Max 50, up to 20MB each)</small>
                                        </label>
                                        <div class="upload-area" id="imageUploadArea">
                                            <i class="fas fa-cloud-upload-alt fa-2x mb-2" style="color: var(--primary-color);"></i>
                                            <h5>Click to Select Images or Drag & Drop</h5>
                                            <p class="text-muted mb-0">JPG, PNG, GIF, WebP</p>
                                            <input type="file" id="images" name="images[]" 
                                                   accept="image/*" multiple style="display: none;"
                                                   onchange="handleFileSelect('images', 'imagePreview', 'imageUploadArea')">
                                        </div>
                                        <div class="file-preview-container" id="imagePreview"></div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-video me-1"></i>Upload Videos
                                            <small class="text-muted">(Max 10, up to 100MB each)</small>
                                        </label>
                                        <div class="upload-area" id="videoUploadArea">
                                            <i class="fas fa-cloud-upload-alt fa-2x mb-2" style="color: var(--primary-color);"></i>
                                            <h5>Click to Select Videos or Drag & Drop</h5>
                                            <p class="text-muted mb-0">MP4, WebM, OGG</p>
                                            <input type="file" id="videos" name="videos[]" 
                                                   accept="video/*" multiple style="display: none;"
                                                   onchange="handleFileSelect('videos', 'videoPreview', 'videoUploadArea')">
                                        </div>
                                        <div class="file-preview-container" id="videoPreview"></div>
                                    </div>
                                    
                                    <div class="alert alert-info" id="uploadSummary" style="display: none;">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <span id="uploadSummaryText"></span>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" disabled>
                                            <i class="fas fa-upload me-2"></i>
                                            <span id="submitBtnText">Select Files to Upload</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Media Detail Modal -->
    <div class="modal fade detail-modal" id="mediaDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailModalTitle">
                        <i class="fas fa-info-circle me-2"></i>Media Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="mediaDetailContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Album Modal -->
    <div class="modal fade" id="createAlbumModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(to right, var(--primary-color), var(--secondary-color)); color: white;">
                    <h5 class="modal-title">
                        <i class="fas fa-folder-plus me-2"></i>Create New Album
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="create_album" value="1">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="album_title" class="form-label">Album Title *</label>
                            <input type="text" class="form-control" id="album_title" name="album_title" required 
                                   placeholder="e.g., Sports Day 2025">
                        </div>
                        
                        <div class="mb-3">
                            <label for="album_description" class="form-label">Description</label>
                            <textarea class="form-control" id="album_description" name="album_description" 
                                      rows="3" placeholder="Brief description of the album"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="class_id" class="form-label">Class (Optional)</label>
                            <select class="form-select" id="class_id" name="class_id">
                                <option value="">School-wide</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>">
                                        <?= htmlspecialchars($class['class_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="department_id" class="form-label">Department (Optional)</label>
                            <select class="form-select" id="department_id" name="department_id">
                                <option value="">None</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= $department['id'] ?>">
                                        <?= htmlspecialchars($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Album
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Album Modal -->
    <div class="modal fade" id="deleteAlbumModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete Album
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the album <strong id="deleteAlbumName"></strong>?</p>
                    <p class="text-danger"><strong>Warning:</strong> This will permanently delete the album and ALL media files within it.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Delete Album
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lightbox2@2.11.4/dist/js/lightbox.min.js"></script>
    
    <script>
        lightbox.option({
            'resizeDuration': 200,
            'wrapAround': true,
            'albumLabel': 'Image %1 of %2',
            'fadeDuration': 300
        });
        
        // Show media detail modal with comments and likes
        function showMediaDetail(mediaId) {
            const modal = new bootstrap.Modal(document.getElementById('mediaDetailModal'));
            const content = document.getElementById('mediaDetailContent');
            
            content.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading details...</p>
                </div>
            `;
            
            modal.show();
            
            fetch(`gallery.php?ajax=media_detail&media_id=${mediaId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        renderMediaDetail(content, data);
                    } else {
                        content.innerHTML = '<div class="alert alert-danger">Error loading details</div>';
                    }
                })
                .catch(err => {
                    content.innerHTML = '<div class="alert alert-danger">Error loading details</div>';
                });
        }
        
        function renderMediaDetail(container, data) {
            const reactionIcons = {
                'like': '❤️',
                'love': '😍',
                'wow': '😮',
                'sad': '😢',
                'angry': '😡'
            };
            
            let reactionsHtml = '';
            if (data.reactions) {
                Object.entries(data.reactions).forEach(([type, count]) => {
                    reactionsHtml += `
                        <span class="reaction-badge reaction-${type} me-2 mb-2">
                            ${reactionIcons[type] || '❤️'} ${count} ${type}
                        </span>
                    `;
                });
            }
            
            container.innerHTML = `
                <div class="row mb-4">
                    <div class="col-md-4">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-heart text-danger me-2"></i>Reactions (${data.total_likes})
                        </h6>
                        <div class="mb-3">
                            ${reactionsHtml || '<p class="text-muted">No reactions yet</p>'}
                        </div>
                        ${data.likes.length > 0 ? `
                            <div class="likes-list" style="max-height: 200px; overflow-y: auto;">
                                <h6 class="fw-bold small">Recent Likes:</h6>
                                ${data.likes.slice(0, 10).map(like => `
                                    <div class="d-flex align-items-center gap-2 mb-2 p-2 bg-light rounded">
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: 30px; height: 30px; font-size: 12px;">
                                            ${like.user_name.charAt(0).toUpperCase()}
                                        </div>
                                        <div>
                                            <strong class="small">${like.user_name}</strong>
                                            <span class="badge bg-light text-dark ms-1">${reactionIcons[like.reaction_type] || '❤️'}</span>
                                            <br><small class="text-muted">${like.created_at}</small>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        ` : ''}
                    </div>
                    <div class="col-md-8">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-comments text-primary me-2"></i>Comments (${data.total_comments})
                        </h6>
                        <div style="max-height: 400px; overflow-y: auto;">
                            ${data.comments.length > 0 ? data.comments.map(comment => `
                                <div class="comment-item">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 35px; height: 35px; font-size: 14px;">
                                            ${comment.user_name.charAt(0).toUpperCase()}
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong>${comment.user_name}</strong>
                                                    ${comment.user_role !== 'guest' ? `<span class="badge bg-info ms-1" style="font-size: 0.6rem;">${comment.user_role}</span>` : ''}
                                                    ${comment.is_edited == 1 ? '<small class="text-muted ms-1">(edited)</small>' : ''}
                                                </div>
                                                <div>
                                                    <small class="text-muted">${comment.created_at}</small>
                                                    <a href="?delete_comment=${comment.id}&page=<?= $page ?>" 
                                                       class="btn btn-sm btn-outline-danger ms-2"
                                                       onclick="return confirm('Delete this comment?')"
                                                       title="Delete comment">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <p class="mb-0 mt-1">${comment.comment_text}</p>
                                        </div>
                                    </div>
                                </div>
                            `).join('') : '<p class="text-muted text-center py-3">No comments yet</p>'}
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" onclick="window.open('gallery.php?media=${data.media_id || ''}', '_blank')">
                        <i class="fas fa-external-link-alt me-1"></i>View Public
                    </button>
                    <a href="?delete_media=${data.media_id || ''}" class="btn btn-danger btn-sm" 
                       onclick="return confirm('Delete this media and all its comments/likes?')">
                        <i class="fas fa-trash me-1"></i>Delete Media
                    </a>
                </div>
            `;
        }
        
        // File handling
        let imageFiles = [];
        let videoFiles = [];
        
        document.getElementById('imageUploadArea').addEventListener('click', function(e) {
            if (e.target !== this && !e.target.closest('.file-preview-item')) return;
            document.getElementById('images').click();
        });
        
        document.getElementById('videoUploadArea').addEventListener('click', function(e) {
            if (e.target !== this && !e.target.closest('.file-preview-item')) return;
            document.getElementById('videos').click();
        });
        
        function handleFileSelect(inputId, previewId, areaId) {
            const input = document.getElementById(inputId);
            const files = Array.from(input.files);
            
            if (inputId === 'images' && files.length > 50) {
                alert('Maximum 50 images allowed!');
                files.splice(50);
            }
            
            if (inputId === 'videos' && files.length > 10) {
                alert('Maximum 10 videos allowed!');
                files.splice(10);
            }
            
            if (inputId === 'images') imageFiles = files;
            else videoFiles = files;
            
            const dt = new DataTransfer();
            files.forEach(file => dt.items.add(file));
            input.files = dt.files;
            
            showPreviews(files, previewId, inputId, areaId);
            updateUploadSummary();
        }
        
        function showPreviews(files, previewId, inputId, areaId) {
            const preview = document.getElementById(previewId);
            const area = document.getElementById(areaId);
            preview.innerHTML = '';
            
            if (files.length > 0) area.classList.add('has-files');
            else area.classList.remove('has-files');
            
            files.forEach((file, index) => {
                const reader = new FileReader();
                const previewItem = document.createElement('div');
                previewItem.className = 'file-preview-item';
                
                reader.onload = function(e) {
                    if (file.type.startsWith('image/')) {
                        previewItem.innerHTML = `
                            <img src="${e.target.result}" alt="${file.name}">
                            <button type="button" class="remove-file" onclick="event.stopPropagation(); removeFile('${inputId}', ${index})" title="Remove">×</button>
                        `;
                    } else {
                        previewItem.innerHTML = `
                            <div style="position: relative; width: 100%; height: 100%; background: #000;">
                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; color: white;">
                                    <i class="fas fa-play-circle fa-2x"></i>
                                    <div style="font-size: 10px; margin-top: 5px;">Video</div>
                                </div>
                            </div>
                            <button type="button" class="remove-file" onclick="event.stopPropagation(); removeFile('${inputId}', ${index})" title="Remove">×</button>
                        `;
                    }
                    preview.appendChild(previewItem);
                };
                
                reader.readAsDataURL(file);
            });
        }
        
        function removeFile(inputId, index) {
            const input = document.getElementById(inputId);
            let files = Array.from(input.files);
            files.splice(index, 1);
            
            if (inputId === 'images') imageFiles = files;
            else videoFiles = files;
            
            const dt = new DataTransfer();
            files.forEach(file => dt.items.add(file));
            input.files = dt.files;
            
            const previewId = inputId === 'images' ? 'imagePreview' : 'videoPreview';
            const areaId = inputId === 'images' ? 'imageUploadArea' : 'videoUploadArea';
            showPreviews(files, previewId, inputId, areaId);
            updateUploadSummary();
        }
        
        function updateUploadSummary() {
            const summary = document.getElementById('uploadSummary');
            const summaryText = document.getElementById('uploadSummaryText');
            const submitBtn = document.getElementById('submitBtn');
            const submitBtnText = document.getElementById('submitBtnText');
            
            const totalFiles = imageFiles.length + videoFiles.length;
            
            if (totalFiles > 0) {
                summary.style.display = 'block';
                let text = `Ready to upload: `;
                if (imageFiles.length > 0) text += `${imageFiles.length} image(s)`;
                if (videoFiles.length > 0) {
                    if (imageFiles.length > 0) text += ' and ';
                    text += `${videoFiles.length} video(s)`;
                }
                
                const totalSize = [...imageFiles, ...videoFiles].reduce((acc, file) => acc + file.size, 0);
                text += ` (Total: ${(totalSize / 1024 / 1024).toFixed(2)} MB)`;
                
                summaryText.textContent = text;
                submitBtn.disabled = false;
                submitBtnText.textContent = `Upload ${totalFiles} File(s)`;
            } else {
                summary.style.display = 'none';
                submitBtn.disabled = true;
                submitBtnText.textContent = 'Select Files to Upload';
            }
        }
        
        // Drag and drop
        function setupDragDrop(areaId, inputId) {
            const area = document.getElementById(areaId);
            
            area.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.backgroundColor = 'rgba(255, 69, 0, 0.1)';
                this.style.borderColor = '#28a745';
            });
            
            area.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.backgroundColor = '';
                this.style.borderColor = '';
            });
            
            area.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.backgroundColor = '';
                this.style.borderColor = '';
                
                const files = Array.from(e.dataTransfer.files);
                const input = document.getElementById(inputId);
                
                let filteredFiles;
                if (inputId === 'images') {
                    filteredFiles = files.filter(f => f.type.startsWith('image/'));
                    if (filteredFiles.length > 50) {
                        alert('Maximum 50 images allowed!');
                        filteredFiles = filteredFiles.slice(0, 50);
                    }
                } else {
                    filteredFiles = files.filter(f => f.type.startsWith('video/'));
                    if (filteredFiles.length > 10) {
                        alert('Maximum 10 videos allowed!');
                        filteredFiles = filteredFiles.slice(0, 10);
                    }
                }
                
                if (filteredFiles.length > 0) {
                    const dt = new DataTransfer();
                    filteredFiles.forEach(file => dt.items.add(file));
                    input.files = dt.files;
                    
                    handleFileSelect(inputId, 
                        inputId === 'images' ? 'imagePreview' : 'videoPreview',
                        inputId === 'images' ? 'imageUploadArea' : 'videoUploadArea');
                }
            });
        }
        
        setupDragDrop('imageUploadArea', 'images');
        setupDragDrop('videoUploadArea', 'videos');
        
        // Bulk actions
        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.media-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            
            if (checkboxes.length > 0) {
                bulkActions.style.display = 'block';
                selectedCount.textContent = checkboxes.length;
            } else {
                bulkActions.style.display = 'none';
                selectedCount.textContent = '0';
            }
        }
        
        function deselectAll() {
            document.querySelectorAll('.media-checkbox').forEach(cb => cb.checked = false);
            updateBulkActions();
        }
        
        // Album deletion
        function confirmDeleteAlbum(albumId, albumName) {
            document.getElementById('deleteAlbumName').textContent = albumName;
            document.getElementById('confirmDeleteBtn').href = '?delete_album=' + albumId;
            new bootstrap.Modal(document.getElementById('deleteAlbumModal')).show();
        }
        
        // Filter by album
        function filterByAlbum(albumId) {
            document.getElementById('media-tab').click();
            // Could add server-side filtering here
        }
        
        function switchToUpload() {
            new bootstrap.Tab(document.getElementById('upload-tab')).show();
        }
        
        // Hash navigation
        window.addEventListener('load', function() {
            const hash = window.location.hash;
            if (hash === '#all-media') {
                new bootstrap.Tab(document.getElementById('media-tab')).show();
            } else if (hash === '#upload') {
                new bootstrap.Tab(document.getElementById('upload-tab')).show();
            }
        });
        
        document.querySelectorAll('#galleryTabs button').forEach(button => {
            button.addEventListener('shown.bs.tab', function(e) {
                const target = e.target.getAttribute('data-bs-target');
                if (target === '#all-media') window.location.hash = 'all-media';
                else if (target === '#upload') window.location.hash = 'upload';
                else window.location.hash = '';
            });
        });
        
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert-dismissible').forEach(alert => {
                new bootstrap.Alert(alert).close();
            });
        }, 8000);
        
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        updateUploadSummary();
    </script>
</body>
</html>