<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Handle deletion
$class_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($class_id === 0) {
    $error = "Invalid class ID.";
} else {
    $query = "DELETE FROM classes WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $_SESSION['success_msg'] = "Class deleted successfully!";
        header("Location: class_management.php");
        exit();
    } else {
        $error = "Error deleting class: " . $conn->error;
    }
}


?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Delete class from QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, delete class, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <!-- Open Graph -->
    <meta property="og:title" content="QueensPa School Delete Class">
    <meta property="og:description" content="Admin interface for deleting classes from QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://queenspa.com/admin/delete_class.php">
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Delete Class">
    <meta name="twitter:description" content="Admin interface for deleting classes from QueensPa School.">
    <title>Delete Class - QueensPa School</title>

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
                    <h3 class="mb-0">🗑️ Delete Class</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo $error; ?></div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    mysqli_close($conn);
    ?>

    <!-- Scripts (from admin_header.php) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>