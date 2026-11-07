<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');


// Get subject ID
$subject_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($subject_id === 0) {
    $error = "Invalid subject ID.";
} else {
    // Fetch subject details
    $query = "SELECT subject_name FROM subjects WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $error = "Subject not found.";
    } else {
        $subject = $result->fetch_assoc();
    }
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $delete_query = "DELETE FROM subjects WHERE id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $subject_id);
    if ($stmt->execute()) {
        $_SESSION['success_msg'] = "Subject deleted successfully!";
        header("Location: subject_management.php");
        exit();
    } else {
        $error = "Error deleting subject: " . $conn->error;
    }
}

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Delete subject from QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, delete subject, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <!-- Open Graph -->
    <meta property="og:title" content="QueensPa School Delete Subject">
    <meta property="og:description" content="Admin interface for deleting subjects from QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://queenspa.com/admin/delete_subject.php">
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Delete Subject">
    <meta name="twitter:description" content="Admin interface for deleting subjects from QueensPa School.">
    <title>Delete Subject - QueensPa School</title>

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

        .btn-danger, .btn-secondary {
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">🗑️ Delete Subject</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo $error; ?></div>
                    <?php elseif (isset($subject)): ?>
                        <p>Are you sure you want to delete the subject <strong><?= htmlspecialchars($subject['subject_name']) ?></strong>?</p>
                        <form method="POST">
                            <button type="submit" name="confirm_delete" class="btn btn-danger"><i class="fas fa-trash"></i> Yes, Delete</button>
                            <a href="subject_management.php" class="btn btn-secondary ms-3"><i class="fas fa-times"></i> Cancel</a>
                        </form>
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