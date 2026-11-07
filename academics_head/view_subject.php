<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}

// Get subject ID
$subject_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($subject_id === 0) {
    $error = "Invalid subject ID.";
} else {
    // Fetch subject details
    $query = "SELECT s.id, s.subject_name, s.subject_code, d.name AS department_name, s.description, s.status, 
                     s.created_at, s.updated_at, u1.name AS teacher_name, u2.name AS creator_name, u3.name AS updater_name
              FROM subjects s
              LEFT JOIN departments d ON s.department_id = d.id
              LEFT JOIN users u1 ON s.teacher_id = u1.id
              LEFT JOIN users u2 ON s.created_by = u2.id
              LEFT JOIN users u3 ON s.updated_by = u3.id
              WHERE s.id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        die("Query preparation failed. Please try again later.");
    }
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $error = "Subject not found.";
    } else {
        $subject = $result->fetch_assoc();
        // Log activity
        $admin_id = $_SESSION['user_id'];
        $action = "Viewed Subject";
        $description = "Viewed subject ID: $subject_id, Name: {$subject['subject_name']}";
        logActivity($conn, $admin_id, $action, $description);
    }
    $stmt->close();
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View subject details in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, view subject, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School View Subject">
    <meta property="og:description" content="Admin interface for viewing subject details in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/view_subject.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School View Subject">
    <meta name="twitter:description" content="Admin interface for viewing subject details in QueensPa School.">
    <title>View Subject - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background-color: var(--bg-color);
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            pointer-events: auto;
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-secondary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-secondary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .table th {
            background-color: #f5f5f5;
            color: var(--text-color);
        }
        .content {
            position: relative;
            z-index: 1;
            filter: none !important;
        }
        @media (max-width: 767.98px) {
            .table th, .table td {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📚 View Subject: <?php echo htmlspecialchars($subject['subject_name'] ?? 'N/A'); ?></h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error); ?></div>
                    <?php else: ?>
                        <table class="table table-bordered">
                            <tr>
                                <th>Subject Name</th>
                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Subject Code</th>
                                <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                            </tr>
                            <tr>
                                <th>Department</th>
                                <td><?php echo htmlspecialchars($subject['department_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Teacher</th>
                                <td><?php echo htmlspecialchars($subject['teacher_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Description</th>
                                <td><?php echo htmlspecialchars($subject['description'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>
                                    <span class="badge bg-<?php echo $subject['status'] === 'Active' ? 'success' : 'secondary'; ?>">
                                        <?php echo htmlspecialchars($subject['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Created By</th>
                                <td><?php echo htmlspecialchars($subject['creator_name'] ?? 'Unknown'); ?></td>
                            </tr>
                            <tr>
                                <th>Created At</th>
                                <td><?php echo htmlspecialchars($subject['created_at']); ?></td>
                            </tr>
                            <tr>
                                <th>Updated By</th>
                                <td><?php echo htmlspecialchars($subject['updater_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Updated At</th>
                                <td><?php echo htmlspecialchars($subject['updated_at'] ?? 'N/A'); ?></td>
                            </tr>
                        </table>
                        <a href="subject_management.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Subject Management</a>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    mysqli_close($conn);
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        // Ensure content is interactive
        document.addEventListener('DOMContentLoaded', function() {
            const content = document.querySelector('.content');
            if (content) {
                content.style.filter = 'none';
                content.style.pointerEvents = 'auto';
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>