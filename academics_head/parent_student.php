<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and logging function
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    die("System error. Please contact support.");
}

// Generate or validate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    $stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['csrf_token']);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare("SELECT expires_at FROM csrf_tokens WHERE token = ? AND user_id = ?");
    $stmt->bind_param("si", $_SESSION['csrf_token'], $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0 || strtotime($result->fetch_assoc()['expires_at']) < time()) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['csrf_token']);
        $stmt->execute();
    }
    $stmt->close();
}

// Initialize messages
$success_msg = isset($_GET['success']) ? htmlspecialchars(trim($_GET['success'])) : '';
$error_msg = isset($_GET['error']) ? htmlspecialchars(trim($_GET['error'])) : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
        error_log("CSRF token validation failed for user ID: " . $_SESSION['user_id'], 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        header("Location: parent_student.php?error=" . urlencode($error_msg));
        exit();
    }

    if ($_POST['action'] === 'edit_mapping') {
        $parent_id = filter_var($_POST['parent_id'], FILTER_VALIDATE_INT);
        $student_ids = isset($_POST['student_ids']) ? array_filter($_POST['student_ids'], 'is_numeric') : [];

        if ($parent_id && !empty($student_ids)) {
            // Delete existing mappings for this parent
            $stmt = $conn->prepare("DELETE FROM parent_student_mapping WHERE parent_id = ?");
            $stmt->bind_param("i", $parent_id);
            $stmt->execute();
            $stmt->close();

            // Add new mappings
            $stmt = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
            foreach ($student_ids as $student_id) {
                $student_id_int = (int)$student_id;
                $stmt->bind_param("ii", $parent_id, $student_id_int);
                if ($stmt->execute()) {
                    logActivity($conn, $_SESSION['user_id'], "Edited parent-student mapping", "Parent ID: $parent_id, Student ID: $student_id");
                } else {
                    error_log("Failed to add mapping: Parent ID: $parent_id, Student ID: $student_id, Error: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
                }
            }
            $stmt->close();
            $success_msg = "Mapping updated successfully.";
            header("Location: parent_student.php?success=" . urlencode($success_msg));
            exit();
        } else {
            $error_msg = "Invalid input data.";
            header("Location: parent_student.php?error=" . urlencode($error_msg));
            exit();
        }
    } elseif ($_POST['action'] === 'delete_mapping') {
        $parent_id = filter_var($_POST['parent_id'], FILTER_VALIDATE_INT);
        if ($parent_id) {
            $stmt = $conn->prepare("DELETE FROM parent_student_mapping WHERE parent_id = ?");
            $stmt->bind_param("i", $parent_id);
            if ($stmt->execute()) {
                logActivity($conn, $_SESSION['user_id'], "Deleted parent-student mapping", "Parent ID: $parent_id");
            }
            $stmt->close();
            $success_msg = "Mapping deleted successfully.";
            header("Location: parent_student.php?success=" . urlencode($success_msg));
            exit();
        } else {
            $error_msg = "Invalid parent ID.";
            header("Location: parent_student.php?error=" . urlencode($error_msg));
            exit();
        }
    } elseif ($_POST['action'] === 'bulk_delete') {
        $parent_ids = isset($_POST['parent_ids']) ? array_filter($_POST['parent_ids'], 'is_numeric') : [];
        if (!empty($parent_ids)) {
            $placeholders = implode(',', array_fill(0, count($parent_ids), '?'));
            $stmt = $conn->prepare("DELETE FROM parent_student_mapping WHERE parent_id IN ($placeholders)");
            $stmt->bind_param(str_repeat('i', count($parent_ids)), ...$parent_ids);
            $stmt->execute();
            foreach ($parent_ids as $id) {
                logActivity($conn, $_SESSION['user_id'], "Bulk deleted parent-student mapping", "Parent ID: $id");
            }
            $stmt->close();
            $success_msg = "Selected mappings deleted successfully.";
            header("Location: parent_student.php?success=" . urlencode($success_msg));
            exit();
        } else {
            $error_msg = "No mappings selected for deletion.";
            header("Location: parent_student.php?error=" . urlencode($error_msg));
            exit();
        }
    }
}

// Fetch parents for dropdown
$parents = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name ASC");
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $parents[] = $row;
    }
    $stmt->close();
} else {
    error_log("Parent query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch students grouped by class
$students_by_class = [];
$stmt = $conn->prepare("
    SELECT u.id, u.name AS student_name, c.id AS class_id, c.class_name 
    FROM users u 
    LEFT JOIN student_classes sc ON u.id = sc.user_id 
    LEFT JOIN classes c ON sc.class_id = c.id 
    WHERE u.role = 'student' AND u.status = 'active' 
    ORDER BY c.class_name ASC, u.name ASC
");
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $class_name = $row['class_name'] ?: 'Unassigned';
        if (!isset($students_by_class[$class_name])) {
            $students_by_class[$class_name] = [];
        }
        $students_by_class[$class_name][] = [
            'id' => $row['id'],
            'student_name' => $row['student_name']
        ];
    }
    $stmt->close();
} else {
    error_log("Student query failed: " . ($stmt ? $stmt->error : $conn->error), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch mappings with pagination, search, and sorting
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) && in_array($_GET['sort_by'], ['parent_name', 'email']) ? $_GET['sort_by'] : 'parent_name';
$sort_order = isset($_GET['sort_order']) && $_GET['sort_order'] === 'desc' ? 'DESC' : 'ASC';
$page = isset($_GET['page']) ? max(1, filter_var($_GET['page'], FILTER_VALIDATE_INT)) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Count total mappings (unique parents)
$where_clause = '';
$params = [];
$param_types = '';
if ($search) {
    $where_clause = "WHERE p.name LIKE ? OR u.name LIKE ?";
    $search_term = "%$search%";
    $params = [$search_term, $search_term];
    $param_types = "ss";
}
$total_query = "SELECT COUNT(DISTINCT m.parent_id) as total 
                FROM parent_student_mapping m 
                JOIN users p ON m.parent_id = p.id 
                JOIN users u ON m.student_id = u.id 
                $where_clause";
$stmt = $conn->prepare($total_query);
if ($search) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$total_mappings = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total_mappings / $per_page);

// Fetch mappings grouped by parent
$mappings = [];
$query = "SELECT m.parent_id, p.name AS parent_name, p.email AS parent_email, 
                 GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS student_names, 
                 GROUP_CONCAT(m.student_id ORDER BY u.name SEPARATOR ',') AS student_ids
          FROM parent_student_mapping m 
          JOIN users p ON m.parent_id = p.id 
          JOIN users u ON m.student_id = u.id 
          $where_clause 
          GROUP BY m.parent_id, p.name, p.email 
          ORDER BY $sort_by $sort_order 
          LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($query);
if ($search) {
    $stmt->bind_param($param_types, ...$params);
} else {
    $stmt->bind_param("ii", $per_page, $offset);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $mappings[] = [
        'parent_id' => $row['parent_id'],
        'parent_name' => $row['parent_name'],
        'parent_email' => $row['parent_email'],
        'student_names' => $row['student_names'],
        'student_ids' => explode(',', $row['student_ids'])
    ];
}
$stmt->close();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="parent_student_mappings.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Parent ID', 'Parent Name', 'Parent Email', 'Student Names', 'Student IDs', 'Created At']);
    $stmt = $conn->prepare("SELECT m.parent_id, p.name AS parent_name, p.email AS parent_email, 
                                   GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS student_names, 
                                   GROUP_CONCAT(m.student_id ORDER BY u.name SEPARATOR ', ') AS student_ids, 
                                   MAX(m.created_at) AS created_at
                            FROM parent_student_mapping m 
                            JOIN users p ON m.parent_id = p.id 
                            JOIN users u ON m.student_id = u.id 
                            $where_clause 
                            GROUP BY m.parent_id 
                            ORDER BY $sort_by $sort_order");
    if ($search) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['parent_id'], $row['parent_name'], $row['parent_email'], $row['student_names'], $row['student_ids'], $row['created_at']]);
    }
    $stmt->close();
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage parent-student mappings in QueensPa School.">
    <meta name="keywords" content="QueensPa School, parent-student mapping, admin dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Parent-Student Mapping - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #ff6b00;
            --accent-color: #ffc107;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .gradient-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .btn-primary, .btn-success, .btn-danger, .btn-info {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-success:hover, .btn-danger:hover, .btn-info:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }
        .table th {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .table tr:hover {
            background-color: #f5f5f5;
        }
        .modal-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .btn-close-white {
            filter: invert(1);
        }
        .sort-icon {
            margin-left: 5px;
        }
        .student-checkboxes {
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        .accordion-button {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .accordion-button:not(.collapsed) {
            background: linear-gradient(to right, #e03e00, #cc5c00);
            color: white;
        }
        .accordion-button::after {
            filter: invert(1);
        }
        .action-buttons {
            gap: 0.5rem;
        }
        @media (max-width: 768px) {
            .card {
                margin-bottom: 1rem;
            }
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body data-bs-theme="light">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>
    <div class="container my-5 animate__animated animate__fadeIn">
        <h1 class="text-center mb-4 gradient-header p-3 rounded">Parent-Student Mapping</h1>

        <!-- Messages -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Links -->
        <div class="d-flex justify-content-between mb-4 action-buttons">
            <a href="/admin/dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <div class="d-flex action-buttons">
                <a href="add_mapping.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Mapping</a>
                <a href="?export=csv&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" class="btn btn-success"><i class="fas fa-download"></i> Export CSV</a>
                <button class="btn btn-info" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>

        <!-- Search and Bulk Actions -->
        <form method="GET" class="mb-4">
            <div class="input-group mb-3">
                <input type="text" name="search" class="form-control" placeholder="Search by parent or student name" value="<?php echo htmlspecialchars($search); ?>" aria-label="Search mappings">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
        </form>
        <form method="POST" id="bulkDeleteForm">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="mb-3">
                <button type="submit" class="btn btn-danger btn-sm" id="bulkDeleteBtn" disabled><i class="fas fa-trash"></i> Delete Selected</button>
            </div>

            <!-- Mappings Table -->
            <div class="card">
                <div class="card-body table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>
                                    <a href="?sort_by=parent_name&sort_order=<?php echo $sort_by === 'parent_name' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&search=<?php echo urlencode($search); ?>" class="text-white text-decoration-none">
                                        Parent Name
                                        <?php if ($sort_by === 'parent_name'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?sort_by=email&sort_order=<?php echo $sort_by === 'email' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&search=<?php echo urlencode($search); ?>" class="text-white text-decoration-none">
                                        Parent Email
                                        <?php if ($sort_by === 'email'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mappings)): ?>
                                <tr><td colspan="5" class="text-center">No mappings found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($mappings as $mapping): ?>
                                    <tr>
                                        <td><input type="checkbox" name="parent_ids[]" value="<?php echo $mapping['parent_id']; ?>" class="mapping-checkbox"></td>
                                        <td><?php echo htmlspecialchars($mapping['parent_name']); ?></td>
                                        <td><?php echo htmlspecialchars($mapping['parent_email']); ?></td>
                                        <td><?php echo htmlspecialchars($mapping['student_names']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editMappingModal" 
                                                    data-parent-id="<?php echo $mapping['parent_id']; ?>" 
                                                    data-student-ids="<?php echo htmlspecialchars(json_encode($mapping['student_ids'])); ?>"><i class="fas fa-edit"></i> Edit</button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteMappingModal" 
                                                    data-parent-id="<?php echo $mapping['parent_id']; ?>"><i class="fas fa-trash"></i> Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
                        <a class="page-link" href="?page=1&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">First</a>
                    </li>
                    <li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">Previous</a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">Next</a>
                    </li>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">Last</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <!-- Edit Mapping Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Parent-Student Mapping</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_mapping">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="parent_id" id="edit_parent_id">
                        <div class="mb-3">
                            <label for="edit_parent_name" class="form-label">Parent</label>
                            <input type="text" id="edit_parent_name" class="form-control" readonly value="">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Students</label>
                            <div class="student-checkboxes">
                                <?php if (empty($students_by_class)): ?>
                                    <p class="text-muted">No students available.</p>
                                <?php else: ?>
                                    <div class="accordion" id="editStudentAccordion">
                                        <?php foreach ($students_by_class as $class_name => $students): ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="edit-class-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>">
                                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" 
                                                            data-bs-target="#edit-collapse-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>" 
                                                            aria-expanded="true" 
                                                            aria-controls="edit-collapse-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>">
                                                        <?php echo htmlspecialchars($class_name); ?> (<?php echo count($students); ?>)
                                                    </button>
                                                </h2>
                                                <div id="edit-collapse-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>" 
                                                    class="accordion-collapse collapse show" 
                                                    data-bs-parent="#editStudentAccordion">
                                                    <div class="accordion-body">
                                                        <?php foreach ($students as $student): ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input student-checkbox" 
                                                                       type="checkbox" 
                                                                       name="student_ids[]" 
                                                                       value="<?php echo $student['id']; ?>" 
                                                                       id="edit-student-<?php echo $student['id']; ?>">
                                                                <label class="form-check-label" for="edit-student-<?php echo $student['id']; ?>">
                                                                    <?php echo htmlspecialchars($student['student_name']); ?>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Mapping Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Delete Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_mapping">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="parent_id" id="delete_parent_id">
                        <p>Are you sure you want to delete all mappings for this parent?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <?php $conn->close(); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // Populate edit modal
        document.querySelectorAll('[data-bs-target="#editModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const modal = document.getElementById('editModal');
                const parentId = this.dataset.parentId;
                const studentIds = JSON.parse(this.dataset.studentIds || '[]');
                modal.querySelector('#edit_parent_id').value = parentId;
                modal.querySelector('#edit_parent_name').value = this.closest('tr').querySelector('td:nth-child(2)').textContent;
                const checkboxes = modal.querySelectorAll('.student-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = studentIds.includes(cb.value);
                });
            });
        });

        // Populate delete modal
        document.querySelectorAll('[data-bs-target="#deleteModal"]').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('delete_parent_id').value = this.dataset.parentId;
            });
        });

        // Bulk delete functionality
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.mapping-checkbox');
        const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

        selectAll.addEventListener('change', function() {
            checkboxes.forEach(checkbox => checkbox.checked = this.checked);
            bulkDeleteBtn.disabled = !Array.from(checkboxes).some(cb => cb.checked);
        });

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                selectAll.checked = checkboxes.length === Array.from(checkboxes).filter(cb => cb.checked).length;
                bulkDeleteBtn.disabled = !Array.from(checkboxes).some(cb => cb.checked);
            });
        });

        document.getElementById('bulkDeleteForm').addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to delete the selected mappings?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>