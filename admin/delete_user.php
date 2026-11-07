<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Verify that log_activity.php is included correctly
$log_activity_file = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_file)) {
    require_once($log_activity_file);
} else {
    // Fallback if log_activity.php is missing
    function log_activity($conn, $user_id, $activity) {
        $query = "INSERT INTO activity_logs (user_id, activity, created_at) VALUES (?, ?, NOW())";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("is", $user_id, $activity);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Handle deletion
if (!isset($_GET['id'])) {
    $error = "Invalid user ID.";
} else {
    $user_id = intval($_GET['id']);
    
    // Prevent self-deletion for safety
    if ($user_id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account.";
    } else {
        // Start transaction to ensure atomicity
        $conn->begin_transaction();
        
        try {
            // 1. Handle advisor references: Set to NULL
            $query_advisor = "UPDATE users SET advisor_id = NULL WHERE advisor_id = ?";
            $stmt_advisor = $conn->prepare($query_advisor);
            $stmt_advisor->bind_param("i", $user_id);
            $stmt_advisor->execute();
            $stmt_advisor->close();
            
            // 2. Delete from parents table FIRST (due to foreign key constraint)
            $query_parents = "DELETE FROM parents WHERE user_id = ?";
            $stmt_parents = $conn->prepare($query_parents);
            $stmt_parents->bind_param("i", $user_id);
            $stmt_parents->execute();
            $stmt_parents->close();
            
            // 3. Handle parent-student mappings: Delete related rows
            $query_mapping = "DELETE FROM parent_student_mapping WHERE parent_id = ? OR student_id = ?";
            $stmt_mapping = $conn->prepare($query_mapping);
            $stmt_mapping->bind_param("ii", $user_id, $user_id);
            $stmt_mapping->execute();
            $stmt_mapping->close();
            
            // 4. Handle class-related records if needed (students in classes)
            $query_class_students = "DELETE FROM class_students WHERE student_id = ?";
            $stmt_class_students = $conn->prepare($query_class_students);
            $stmt_class_students->bind_param("i", $user_id);
            $stmt_class_students->execute();
            $stmt_class_students->close();
            
            // 5. Handle teacher-class assignments if needed
            $query_teacher_classes = "DELETE FROM teacher_classes WHERE teacher_id = ?";
            $stmt_teacher_classes = $conn->prepare($query_teacher_classes);
            $stmt_teacher_classes->bind_param("i", $user_id);
            $stmt_teacher_classes->execute();
            $stmt_teacher_classes->close();
            
            // 6. Delete activity logs for this user
            $query_logs = "DELETE FROM activity_logs WHERE user_id = ?";
            $stmt_logs = $conn->prepare($query_logs);
            $stmt_logs->bind_param("i", $user_id);
            $stmt_logs->execute();
            $stmt_logs->close();
            
            // 7. Finally, delete the user
            $query = "DELETE FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete user: " . $stmt->error);
            }
            $stmt->close();
            
            // Log the activity
            if (function_exists('log_activity')) {
                log_activity($conn, $_SESSION['user_id'], "Deleted user ID: $user_id");
            } else {
                error_log("User ID $user_id deleted by admin ID " . $_SESSION['user_id']);
            }
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['success_msg'] = "User deleted successfully!";
            header("Location: user_management.php");
            exit();
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = "Error deleting user: " . $e->getMessage();
        }
    }
}

// Include header after logic
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Delete user from QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, delete user, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <!-- Open Graph -->
    <meta property="og:title" content="QueensPa School Delete User">
    <meta property="og:description" content="Admin interface for deleting users from QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://queenspa.com/admin/delete_user.php">
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Delete User">
    <meta name="twitter:description" content="Admin interface for deleting users from QueensPa School.">
    <title>Delete User - QueensPa School</title>

    <!-- Custom Styles -->
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">🗑️ Delete User</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                        <div class="mt-3">
                            <a href="user_management.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left"></i> Back to User Management
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Processing...</span>
                            </div>
                            <p class="mt-2">Processing deletion request...</p>
                        </div>
                        <script>
                            // Redirect if something went wrong with the header redirect
                            setTimeout(function() {
                                window.location.href = 'user_management.php';
                            }, 3000);
                        </script>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (isset($conn) && !$conn->connect_error) {
        mysqli_close($conn);
    }
    ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>