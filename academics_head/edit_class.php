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

// Fetch teachers
$teachers_sql = "SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name";
$teachers_result = mysqli_query($conn, $teachers_sql);
if (!$teachers_result) {
    error_log("Error fetching teachers: " . mysqli_error($conn));
    $teachers = [];
} else {
    $teachers = [];
    while ($teacher_row = mysqli_fetch_assoc($teachers_result)) {
        $teachers[] = $teacher_row;
    }
}

// Initialize variables
$success_msg = $error_msg = "";
$class = null;
$class_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch class details
if ($class_id > 0) {
    $class_sql = "SELECT id, class_name, teacher_id FROM classes WHERE id = ?";
    $class_stmt = $conn->prepare($class_sql);
    $class_stmt->bind_param("i", $class_id);
    $class_stmt->execute();
    $class = $class_stmt->get_result()->fetch_assoc();
    $class_stmt->close();
    if (!$class) {
        $error_msg = "Class not found.";
    }
} else {
    $error_msg = "Invalid class ID.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class) {
    $class_name = trim($_POST['class_name'] ?? '');
    $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;

    // Validate inputs
    if (empty($class_name)) {
        $error_msg = "Class name is required.";
    } elseif (strlen($class_name) > 100) {
        $error_msg = "Class name must be 100 characters or less.";
    } elseif ($teacher_id && !array_key_exists($teacher_id, array_column($teachers, 'id', 'id'))) {
        $error_msg = "Invalid teacher selected.";
    } else {
        $conn->begin_transaction();
        try {
            // Update class
            $update_sql = "UPDATE classes SET class_name = ?, teacher_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("sii", $class_name, $teacher_id, $class_id);
            $update_stmt->execute();
            $update_stmt->close();

            // Log action
            $user_id = $_SESSION['user_id'];
            $action = "Edited Class";
            $description = "Class ID: $class_id, New Name: $class_name" . ($teacher_id ? ", Teacher ID: $teacher_id" : ", Teacher Removed");
            logActivity($conn, $user_id, $action, $description);

            $conn->commit();
            $success_msg = "Class updated successfully!";
            $class['class_name'] = $class_name;
            $class['teacher_id'] = $teacher_id;
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error updating class: " . ($e->getCode() == 1062 ? "Class name already exists." : $e->getMessage());
            error_log("Edit class error: " . $e->getMessage());
        }
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit class details and teacher assignment in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, edit class, teacher assignment, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Edit Class">
    <meta property="og:description" content="Admin interface for editing classes and teacher assignments in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://queenspa.com/admin/edit_class.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Edit Class">
    <meta name="twitter:description" content="Admin interface for editing classes and teacher assignments in QueensPa School.">
    <title>Edit Class - QueensPa School</title>
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
        .btn-primary, .btn-success {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-success:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📚 Edit Class</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <?php if ($class): ?>
                        <form method="POST" class="form-group">
                            <div class="mb-3">
                                <label for="class_name" class="form-label">Class Name</label>
                                <input type="text" class="form-control" id="class_name" name="class_name" value="<?php echo htmlspecialchars($class['class_name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="teacher_id" class="form-label">Assign Teacher (Optional)</label>
                                <select name="teacher_id" id="teacher_id" class="form-select">
                                    <option value="">No Teacher</option>
                                    <?php if (empty($teachers)): ?>
                                        <option value="" disabled>No teachers available</option>
                                    <?php else: ?>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>" <?php echo $class['teacher_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update Class</button>
                                <a href="view_classes.php" class="btn btn-secondary ms-3"><i class="fas fa-times"></i> Cancel</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="text-center text-muted">No class selected or class not found.</p>
                        <div class="text-center">
                            <a href="view_classes.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Classes</a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>