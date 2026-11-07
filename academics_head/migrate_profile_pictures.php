<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access to migrate_profile_pictures.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    header("Location: ../login.php");
    exit();
}

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    die("Connection failed. Please try again later.");
}

try {
    // Define target directory and URL
    define('UPLOAD_DIR', $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/');
    define('UPLOAD_URL', '/Uploads/profiles/');
    define('LEGACY_DIR', $_SERVER['DOCUMENT_ROOT'] . '/uploads/profiles/');

    // Ensure target directory exists
    if (!is_dir(UPLOAD_DIR)) {
        if (!mkdir(UPLOAD_DIR, 0755, true)) {
            error_log("Failed to create directory: " . UPLOAD_DIR);
            die("Failed to create upload directory.");
        }
    }

    // Fetch all users with profile pictures
    $stmt = $conn->prepare("SELECT id, profile_picture FROM users WHERE profile_picture IS NOT NULL AND profile_picture != '' AND profile_picture != '/assets/images/default_profile.png'");
    $stmt->execute();
    $result = $stmt->get_result();
    $updated = 0;
    $moved_files = 0;
    $invalid_files = 0;

    while ($row = $result->fetch_assoc()) {
        $old_path = $row['profile_picture'];
        $filename = basename($old_path);
        $new_path = UPLOAD_URL . $filename;

        // Check if file is invalid (e.g., 375 bytes or .jfif)
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($old_path, '/');
        $new_file_path = UPLOAD_DIR . $filename;
        $is_invalid = false;

        if (file_exists($file_path)) {
            // Check file size
            if (filesize($file_path) <= 375) {
                $is_invalid = true;
                error_log("Invalid file size (too small) for user_id: {$row['id']}, path: $old_path");
            }
            // Check .jfif extension
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'jfif') {
                $is_invalid = true;
                error_log("Unsupported .jfif file for user_id: {$row['id']}, path: $old_path");
            }
        } else {
            // Check legacy directory
            $legacy_file_path = LEGACY_DIR . $filename;
            if (file_exists($legacy_file_path)) {
                if (filesize($legacy_file_path) <= 375 || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'jfif') {
                    $is_invalid = true;
                    error_log("Invalid file in legacy directory for user_id: {$row['id']}, path: $legacy_file_path");
                } else {
                    // Move file to new directory
                    if (rename($legacy_file_path, $new_file_path)) {
                        $moved_files++;
                        error_log("Moved file for user_id: {$row['id']} from $legacy_file_path to $new_file_path");
                    } else {
                        error_log("Failed to move file for user_id: {$row['id']} from $legacy_file_path to $new_file_path");
                        $is_invalid = true;
                    }
                }
            } else {
                $is_invalid = true;
                error_log("File not found for user_id: {$row['id']}, path: $old_path");
            }
        }

        // Update database path
        $update_path = $is_invalid ? '/assets/images/default_profile.png' : $new_path;
        if ($old_path !== $update_path) {
            $update_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
            $update_stmt->bind_param("si", $update_path, $row['id']);
            if ($update_stmt->execute()) {
                $updated++;
            } else {
                error_log("Failed to update profile_picture for user_id: {$row['id']}, old_path: $old_path, new_path: $update_path");
            }
            $update_stmt->close();
        }

        if ($is_invalid) {
            $invalid_files++;
        }
    }
    $stmt->close();

    logActivity($conn, $_SESSION['user_id'], "Ran Profile Picture Migration", "Updated $updated profile picture paths, moved $moved_files files, marked $invalid_files as invalid");
    echo "Migration completed. Updated $updated profile picture paths, moved $moved_files files, and marked $invalid_files as invalid. Check error_log.txt for details.";
} catch (Exception $e) {
    error_log("Migration error: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');
    echo "Error during migration: " . htmlspecialchars($e->getMessage());
}

$conn->close();
?>