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

$success_msg = $error_msg = "";

// Get subject ID
$subject_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($subject_id === 0) {
    header("Location: subject_management.php");
    exit();
}

// Fetch subject details
$query = "SELECT s.id, s.subject_name, s.subject_code, s.department_id, s.description, s.status, s.teacher_id, d.name AS department_name 
          FROM subjects s 
          LEFT JOIN departments d ON s.department_id = d.id 
          WHERE s.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $subject_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $error_msg = "Subject not found.";
    $stmt->close();
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    echo "<div class='container my-4'><div class='alert alert-danger animate__animated animate__shakeX'>" . htmlspecialchars($error_msg) . "</div></div>";
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    exit();
}
$subject = $result->fetch_assoc();
$stmt->close();

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Fetch teachers
$teachers_sql = "SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name";
$teachers_stmt = $conn->prepare($teachers_sql);
$teachers_stmt->execute();
$teachers_result = $teachers_stmt->get_result();
$teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);
$teachers_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_name = trim($_POST['subject_name'] ?? '');
    $subject_code = trim($_POST['subject_code'] ?? '');
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
    $status = trim($_POST['status'] ?? 'Active');
    $updated_by = $_SESSION['user_id'];

    $errors = [];
    if (empty($subject_name)) $errors[] = "Subject name is required.";
    if (strlen($subject_name) > 100) $errors[] = "Subject name must be 100 characters or less.";
    if (empty($subject_code)) $errors[] = "Subject code is required.";
    if (strlen($subject_code) > 20) $errors[] = "Subject code must be 20 characters or less.";
    if (!preg_match('/^[A-Z0-9-]+$/', $subject_code)) $errors[] = "Subject code must contain only uppercase letters, numbers, or hyphens.";
    if (!in_array($status, ['Active', 'Inactive'])) $errors[] = "Invalid status selected.";
    if ($description && strlen($description) > 500) $errors[] = "Description must be 500 characters or less.";

    // Check for duplicate subject code (excluding current subject)
    $check_sql = "SELECT id FROM subjects WHERE subject_code = ? AND id != ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("si", $subject_code, $subject_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $errors[] = "Subject code already exists.";
    }
    $check_stmt->close();

    // Validate department_id if provided
    if ($department_id) {
        $dept_check_sql = "SELECT id FROM departments WHERE id = ?";
        $dept_check_stmt = $conn->prepare($dept_check_sql);
        $dept_check_stmt->bind_param("i", $department_id);
        $dept_check_stmt->execute();
        if ($dept_check_stmt->get_result()->num_rows === 0) {
            $errors[] = "Invalid department selected.";
        }
        $dept_check_stmt->close();
    }

    // Validate teacher_id if provided
    if ($teacher_id) {
        $teacher_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher' AND status = 'active'";
        $teacher_check_stmt = $conn->prepare($teacher_check_sql);
        $teacher_check_stmt->bind_param("i", $teacher_id);
        $teacher_check_stmt->execute();
        if ($teacher_check_stmt->get_result()->num_rows === 0) {
            $errors[] = "Invalid teacher selected.";
        }
        $teacher_check_stmt->close();
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $update_sql = "UPDATE subjects SET subject_name = ?, subject_code = ?, department_id = ?, description = ?, teacher_id = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssisssii", $subject_name, $subject_code, $department_id, $description, $teacher_id, $status, $updated_by, $subject_id);
            $update_stmt->execute();

            // Log activity
            $admin_id = $_SESSION['user_id'];
            $action = "Updated Subject";
            $description = "Subject ID: $subject_id, Name: $subject_name, Code: $subject_code";
            logActivity($conn, $admin_id, $action, $description);

            $conn->commit();
            header("Location: subject_management.php?success=Subject+updated+successfully");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error updating subject: " . $e->getMessage();
            error_log("Update subject error: " . $e->getMessage());
        } finally {
            $update_stmt->close();
        }
    } else {
        $error_msg = implode("<br>", $errors);
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit subject details in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, edit subject, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Edit Subject">
    <meta property="og:description" content="Admin interface for editing subject details in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/edit_subject.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Edit Subject">
    <meta name="twitter:description" content="Admin interface for editing subject details in QueensPa School.">
    <title>Edit Subject - QueensPa School</title>
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
        .btn-primary, .btn-success, .btn-secondary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-success:hover, .btn-secondary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .form-control, .form-select {
            border-radius: 8px;
            pointer-events: auto;
        }
        .content {
            position: relative;
            z-index: 1;
            filter: none !important;
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">✏️ Edit Subject</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <form method="POST" class="form-group" id="editSubjectForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subject_name" class="form-label">Subject Name</label>
                                    <input type="text" name="subject_name" id="subject_name" class="form-control" value="<?php echo htmlspecialchars($subject['subject_name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subject_code" class="form-label">Subject Code</label>
                                    <input type="text" name="subject_code" id="subject_code" class="form-control" value="<?php echo htmlspecialchars($subject['subject_code']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="department_id" class="form-label">Department (Optional)</label>
                                    <select name="department_id" id="department_id" class="form-select">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" <?php echo $subject['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="teacher_id" class="form-label">Teacher (Optional)</label>
                                    <select name="teacher_id" id="teacher_id" class="form-select">
                                        <option value="">Select Teacher</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>" <?php echo $subject['teacher_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select name="status" id="status" class="form-select" required>
                                        <option value="Active" <?php echo $subject['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $subject['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description (Optional)</label>
                                    <textarea name="description" id="description" class="form-control" rows="4"><?php echo htmlspecialchars($subject['description'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update Subject</button>
                            <a href="subject_management.php" class="btn btn-secondary ms-3"><i class="fas fa-arrow-left"></i> Cancel</a>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        // Ensure form is interactive
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('editSubjectForm');
            if (form) {
                form.style.pointerEvents = 'auto';
                form.style.opacity = '1';
            }
            document.querySelector('.content').style.filter = 'none';
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>