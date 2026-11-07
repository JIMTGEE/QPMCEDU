<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access to cleanup_profile_pictures.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    header("Location: ../login.php");
    exit();
}

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    die("Connection failed. Please try again later.");
}

try {
    define('UPLOAD_DIR', $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/');
    define('DEFAULT_PICTURE', '/assets/images/default_profile.png');

    // Fetch users with profile pictures
    $stmt = $conn->prepare("SELECT id, profile_picture FROM users WHERE profile_picture IS NOT NULL AND profile_picture != '' AND profile_picture != ?");
    $stmt->bind_param("s", DEFAULT_PICTURE);
    $stmt->execute();
    $result = $stmt->get_result();
    $updated = 0;
    $invalid_files = [];

    while ($row = $result->fetch_assoc()) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($row['profile_picture'], '/');
        if (!file_exists($file_path) || filesize($file_path) < 100) {
            $invalid_files[] = $row['profile_picture'];
            $update_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
            $update_stmt->bind_param("si", DEFAULT_PICTURE, $row['id']);
            if ($update_stmt->execute()) {
                $updated++;
                error_log("Set default profile picture for user_id: {$row['id']}, old path: {$row['profile_picture']}, size: " . (file_exists($file_path) ? filesize($file_path) : 'N/A'));
            } else {
                error_log("Failed to update profile_picture for user_id: {$row['id']}, path: {$row['profile_picture']}");
            }
            $update_stmt->close();
        } else {
            $image_info = @getimagesize($file_path);
            if ($image_info === false || !in_array($image_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
                $invalid_files[] = $row['profile_picture'];
                $update_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $update_stmt->bind_param("si", DEFAULT_PICTURE, $row['id']);
                if ($update_stmt->execute()) {
                    $updated++;
                    error_log("Set default profile picture for user_id: {$row['id']}, invalid image: {$row['profile_picture']}, mime: " . (mime_content_type($file_path) ?? 'unknown'));
                } else {
                    error_log("Failed to update profile_picture for user_id: {$row['id']}, path: {$row['profile_picture']}");
                }
                $update_stmt->close();
            }
        }
    }
    $stmt->close();

    // Delete invalid files
    foreach ($invalid_files as $file) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file, '/');
        if (file_exists($file_path) && unlink($file_path)) {
            error_log("Deleted invalid file: $file_path");
        } else {
            error_log("Failed to delete or file not found: $file_path");
        }
    }

    logActivity($conn, $_SESSION['user_id'], "Ran Profile Picture Cleanup", "Updated $updated profile picture paths, marked " . count($invalid_files) . " files as invalid");
    echo "Cleanup completed. Updated $updated profile picture paths. Check error_log.txt for details.";
} catch (Exception $e) {
    error_log("Cleanup error: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    echo "Error during cleanup: " . htmlspecialchars($e->getMessage());
}

$conn->close();
?>