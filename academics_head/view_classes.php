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


if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Initialize variables
$success_msg = $error_msg = "";

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_class'])) {
    $class_id = (int)($_POST['class_id'] ?? 0);
    if ($class_id > 0) {
        $conn->begin_transaction();
        try {
            // Delete class (cascades to student_classes and fees via foreign keys)
            $delete_sql = "DELETE FROM classes WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $class_id);
            $delete_stmt->execute();
            $delete_stmt->close();

            // Log action
            $user_id = $_SESSION['user_id'];
            $action = "Deleted Class";
            $description = "Class ID: $class_id";
            logActivity($conn, $user_id, $action, $description);

            $conn->commit();
            $success_msg = "Class deleted successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error deleting class: " . $e->getMessage();
        }
    } else {
        $error_msg = "Invalid class ID.";
    }
}

// Fetch all classes
$classes_sql = "SELECT id, class_name, created_at FROM classes ORDER BY class_name";
$classes_result = mysqli_query($conn, $classes_sql);
$classes = [];
while ($row = mysqli_fetch_assoc($classes_result)) {
    $classes[] = $row;
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage classes in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, view classes, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School View Classes">
    <meta property="og:description" content="Admin interface for viewing and managing classes in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://queenspa.com/admin/view_classes.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School View Classes">
    <meta name="twitter:description" content="Admin interface for viewing and managing classes in QueensPa School.">
    <title>View Classes - QueensPa School</title>
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
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-danger, .btn-print {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-danger:hover, .btn-print:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .table thead {
            background: var(--primary-color);
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .print-section {
            visibility: hidden;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
        }
        @media (max-width: 767.98px) {
            .table th, .table td {
                font-size: 0.9rem;
            }
        }
        @media print {
            body * {
                visibility: hidden;
            }
            .print-section, .print-section * {
                visibility: visible;
                display: block;
            }
            .print-section {
                position: static;
                font-size: 12pt;
            }
            .print-header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #ff4500;
                padding-bottom: 10px;
            }
            .print-header img {
                max-width: 100px;
            }
            .print-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            .print-table th, .print-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            .print-table th {
                background: linear-gradient(to right, #ff4500, #ff6b00);
                color: white;
            }
            .print-footer {
                text-align: center;
                margin-top: 20px;
                font-size: 10pt;
                color: #555;
            }
            @page {
                size: A4;
                margin: 20mm;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📚 View All Classes</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <div class="text-end mb-3">
                        <button class="btn btn-print" onclick="printContent()"><i class="fas fa-print"></i> Print Class List</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Class Name</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($classes)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">No classes found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($classes as $class): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                            <td><?php echo htmlspecialchars($class['created_at']); ?></td>
                                            <td>
                                                <a href="edit_class.php?id=<?php echo $class['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                                                    <button type="submit" name="delete_class" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this class? This will also remove related assignments and fees.');">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <a href="class_management.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <div class="print-section">
                        <div class="print-header">
                            <img src="/queenspa/assets/images/logo.png" alt="QueensPa School Logo">
                            <h2>QueensPa School</h2>
                            <p>P.O. Box 123, Accra, Ghana | info@queenspa.com</p>
                            <p>Class List - <?php echo date('F j, Y'); ?></p>
                        </div>
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th>Class Name</th>
                                    <th>Created At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($class['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="print-footer">
                            <p>Generated by QueensPa School Management System</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        function printContent() {
            const printSection = document.querySelector('.print-section');
            printSection.style.visibility = 'visible';
            printSection.style.display = 'block';
            window.print();
            printSection.style.visibility = 'hidden';
            printSection.style.display = 'block';
        }
    </script>
</body>
</html>