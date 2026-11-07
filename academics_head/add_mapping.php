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

// Get current academic year from database
$current_academic_year = '';
$current_academic_year_id = 0;

$year_query = "SELECT id, year_label FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
$year_result = $conn->query($year_query);
if ($year_result && $year_result->num_rows > 0) {
    $year_data = $year_result->fetch_assoc();
    $current_academic_year_id = $year_data['id'];
    $current_academic_year = $year_data['year_label'];
} else {
    // Fallback to hardcoded values if no active year found
    $current_academic_year = '2025/2026';
    $current_academic_year_id = 2;
}

// Generate or validate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    $stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['csrf_token']);
    if (!$stmt->execute()) {
        error_log("CSRF token insertion failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
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
        if (!$stmt->execute()) {
            error_log("CSRF token insertion failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        }
    }
    $stmt->close();
}

// Initialize variables
$errors = [];
$success_msg = '';
$parent_id = '';
$student_ids = [];

// Fetch parents
$parents = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name ASC");
if (!$stmt->execute()) {
    error_log("Parent query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    $errors[] = "Failed to fetch parents due to a system error.";
} else {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $parents[] = $row;
    }
    $stmt->close();
}

// Fetch students with their class information - SIMPLIFIED QUERY
$students_by_class = [];

// First, get all active students with their class assignments for the current academic year
$query = "
    SELECT 
        u.id, 
        u.name AS student_name,
        u.status,
        sc.class_id,
        c.class_name,
        c.department_id,
        d.name AS department_name,
        sc.academic_year,
        sc.academic_year_id
    FROM users u 
    LEFT JOIN student_classes sc ON u.id = sc.user_id 
    LEFT JOIN classes c ON sc.class_id = c.id 
    LEFT JOIN departments d ON c.department_id = d.id
    WHERE u.role = 'student' 
        AND u.status = 'active'
    ORDER BY d.name ASC, c.class_name ASC, u.name ASC
";

$result = $conn->query($query);

if (!$result) {
    error_log("Student query failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    $errors[] = "Failed to fetch students due to a system error.";
} else {
    if ($result->num_rows === 0) {
        $errors[] = "No active students found in the system. Please add students first.";
    } else {
        // Group students by department and class
        while ($row = $result->fetch_assoc()) {
            $department = $row['department_name'] ?: 'Unassigned';
            $class_name = $row['class_name'] ?: 'No Class Assigned';
            $academic_year = $row['academic_year'] ?: 'Not Set';
            
            // Create a combined key for department + class
            $class_key = $department . ' - ' . $class_name;
            
            if (!isset($students_by_class[$class_key])) {
                $students_by_class[$class_key] = [
                    'department' => $department,
                    'class_name' => $class_name,
                    'class_id' => $row['class_id'],
                    'students' => []
                ];
            }
            
            $students_by_class[$class_key]['students'][] = [
                'id' => $row['id'],
                'name' => $row['student_name'],
                'academic_year' => $academic_year
            ];
        }
    }
}

// Get total count for debugging
$total_students_query = "SELECT COUNT(*) as total FROM users WHERE role = 'student' AND status = 'active'";
$total_result = $conn->query($total_students_query);
$total_students = $total_result->fetch_assoc()['total'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mapping') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
        error_log("CSRF token validation failed for user ID: " . $_SESSION['user_id'], 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    } else {
        $parent_id = filter_var($_POST['parent_id'], FILTER_VALIDATE_INT);
        $student_ids = isset($_POST['student_ids']) ? $_POST['student_ids'] : [];
        
        // Ensure student_ids is an array of integers
        if (!is_array($student_ids)) {
            $student_ids = [];
        } else {
            $student_ids = array_filter($student_ids, 'is_numeric');
            $student_ids = array_map('intval', $student_ids);
        }

        // Validation
        if (!$parent_id) {
            $errors[] = "Please select a parent.";
        }
        
        if (empty($student_ids)) {
            $errors[] = "Please select at least one student.";
        }

        // Check for existing mappings
        if (empty($errors) && !empty($student_ids)) {
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
            $types = str_repeat('i', count($student_ids) + 1);
            $params = array_merge([$parent_id], $student_ids);
            
            $check_sql = "SELECT student_id FROM parent_student_mapping WHERE parent_id = ? AND student_id IN ($placeholders)";
            $check_stmt = $conn->prepare($check_sql);
            
            if ($check_stmt) {
                $check_stmt->bind_param($types, ...$params);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                $existing = [];
                while ($row = $check_result->fetch_assoc()) {
                    $existing[] = $row['student_id'];
                }
                $check_stmt->close();
                
                if (!empty($existing)) {
                    $errors[] = "Some students are already mapped to this parent.";
                }
            }
        }

        // Insert mappings if no errors
        if (empty($errors)) {
            $insert_stmt = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
            $success_count = 0;
            
            foreach ($student_ids as $student_id) {
                $insert_stmt->bind_param("ii", $parent_id, $student_id);
                
                if ($insert_stmt->execute()) {
                    $success_count++;
                    logActivity($conn, $_SESSION['user_id'], "Added parent-student mapping", "Parent ID: $parent_id, Student ID: $student_id");
                } else {
                    $errors[] = "Failed to add mapping for student ID: $student_id.";
                    error_log("Failed to add mapping: Parent ID: $parent_id, Student ID: $student_id, Error: " . $insert_stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
                }
            }
            $insert_stmt->close();
            
            if ($success_count > 0) {
                $success_msg = "$success_count mapping(s) added successfully.";
                header("Location: parent_student.php?success=" . urlencode($success_msg));
                exit();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Add parent-student mappings in QueensPa School.">
    <meta name="keywords" content="QueensPa School, parent-student mapping, admin dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Add Parent-Student Mapping - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #ff6b00;
            --accent-color: #ffc107;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background-color: #f8f9fa;
        }
        .gradient-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(255, 69, 0, 0.2);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
            padding: 15px 20px;
            border: none;
        }
        .card-header i {
            margin-right: 10px;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(255, 69, 0, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
        }
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-color: transparent;
            color: white;
        }
        .student-checkboxes {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 15px;
            background: white;
        }
        .class-group {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-color);
        }
        .class-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #dee2e6;
        }
        .student-item {
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.2s;
        }
        .student-item:hover {
            background-color: #fff3e6;
        }
        .student-item label {
            width: 100%;
            cursor: pointer;
        }
        .badge-academic {
            background-color: var(--secondary-color);
            color: white;
            font-size: 0.8em;
            padding: 3px 8px;
            border-radius: 20px;
            margin-left: 10px;
        }
        .action-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .stats-number {
            font-size: 2em;
            font-weight: bold;
            color: var(--primary-color);
        }
        .stats-label {
            color: #6c757d;
            font-size: 0.9em;
        }
        .search-box {
            border-radius: 20px;
            padding: 10px 20px;
            border: 2px solid #dee2e6;
            transition: all 0.3s;
            width: 100%;
        }
        .search-box:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 69, 0, 0.25);
            outline: none;
        }
        .action-links .btn {
            margin-right: 10px;
        }
        @media (max-width: 768px) {
            .gradient-header {
                font-size: 1.5rem;
                padding: 15px;
            }
            .action-links {
                flex-direction: column;
                gap: 10px;
            }
            .action-links .btn {
                width: 100%;
                margin-right: 0;
            }
        }
    </style>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>

    <div class="container my-5">
        <!-- Header -->
        <div class="gradient-header mb-4 animate__animated animate__fadeInDown">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="mb-0"><i class="fas fa-link me-2"></i>Add Parent-Student Mapping</h2>
                <div class="text-white-50">Academic Year: <strong><?php echo htmlspecialchars($current_academic_year); ?></strong></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-bar animate__animated animate__fadeIn">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="action-links">
                        <a href="/admin/dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Dashboard
                        </a>
                        <a href="/admin/parent_student.php" class="btn btn-primary">
                            <i class="fas fa-list me-2"></i>View Mappings
                        </a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card p-0">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stats-number"><?php echo count($parents); ?></div>
                                <div class="stats-label">Active Parents</div>
                            </div>
                            <div class="col-6">
                                <div class="stats-number"><?php echo $total_students; ?></div>
                                <div class="stats-label">Total Students</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Main Form -->
        <div class="card animate__animated animate__fadeInUp">
            <div class="card-header">
                <i class="fas fa-edit"></i>Mapping Form
            </div>
            <div class="card-body">
                <form method="POST" id="mappingForm">
                    <input type="hidden" name="action" value="add_mapping">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="row">
                        <!-- Parent Selection -->
                        <div class="col-md-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title text-primary mb-3">
                                        <i class="fas fa-user me-2"></i>Select Parent
                                    </h5>
                                    <select name="parent_id" id="parent_id" class="form-select form-select-lg" required>
                                        <option value="">Choose a parent...</option>
                                        <?php foreach ($parents as $parent): ?>
                                            <option value="<?php echo $parent['id']; ?>" <?php echo $parent_id == $parent['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($parent['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($parents)): ?>
                                        <div class="alert alert-warning mt-3">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No active parents found. <a href="add_parent.php" class="alert-link">Add a parent</a> first.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Student Selection -->
                        <div class="col-md-8 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title text-primary mb-3">
                                        <i class="fas fa-users me-2"></i>Select Students
                                        <span class="badge bg-secondary ms-2" id="selectedCount">0 selected</span>
                                    </h5>
                                    
                                    <!-- Search Box -->
                                    <input type="text" class="search-box mb-3" id="studentSearch" 
                                           placeholder="🔍 Search students by name...">

                                    <!-- Selection Controls -->
                                    <div class="btn-group mb-3" role="group">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAll()">
                                            <i class="fas fa-check-double me-1"></i>Select All
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="deselectAll()">
                                            <i class="fas fa-times me-1"></i>Deselect All
                                        </button>
                                    </div>

                                    <!-- Student List -->
                                    <div class="student-checkboxes" id="studentList">
                                        <?php if (empty($students_by_class)): ?>
                                            <div class="text-center py-5">
                                                <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">No active students found in the system.</p>
                                                <p class="text-muted small">Please ensure students are registered in the system.</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($students_by_class as $class_key => $class_data): ?>
                                                <div class="class-group student-group" 
                                                     data-department="<?php echo htmlspecialchars($class_data['department']); ?>"
                                                     data-class="<?php echo htmlspecialchars($class_data['class_name']); ?>">
                                                    <div class="class-title">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span>
                                                                <i class="fas fa-school me-2"></i>
                                                                <?php echo htmlspecialchars($class_data['department']); ?> - 
                                                                <?php echo htmlspecialchars($class_data['class_name']); ?>
                                                            </span>
                                                            <small class="text-muted">
                                                                <?php echo count($class_data['students']); ?> students
                                                                <a href="#" onclick="selectClass('<?php echo htmlspecialchars(addslashes($class_key)); ?>'); return false;" 
                                                                   class="ms-2 text-primary">Select All</a>
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <?php foreach ($class_data['students'] as $student): ?>
                                                            <div class="col-md-6 student-item" 
                                                                 data-student-name="<?php echo strtolower(htmlspecialchars($student['name'])); ?>">
                                                                <div class="form-check">
                                                                    <input class="form-check-input student-checkbox class-<?php echo md5($class_key); ?>" 
                                                                           type="checkbox" 
                                                                           name="student_ids[]" 
                                                                           value="<?php echo $student['id']; ?>" 
                                                                           id="student-<?php echo $student['id']; ?>"
                                                                           <?php echo in_array($student['id'], $student_ids) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label d-flex justify-content-between align-items-center" 
                                                                           for="student-<?php echo $student['id']; ?>">
                                                                        <span>
                                                                            <i class="fas fa-user-graduate me-2 text-muted"></i>
                                                                            <?php echo htmlspecialchars($student['name']); ?>
                                                                        </span>
                                                                        <?php if ($student['academic_year'] !== $current_academic_year): ?>
                                                                            <span class="badge-academic">Previous Year</span>
                                                                        <?php endif; ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary btn-lg" 
                                <?php echo empty($parents) || empty($students_by_class) ? 'disabled' : ''; ?>>
                            <i class="fas fa-save me-2"></i>Save Mapping
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <?php $conn->close(); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update selected count
        function updateSelectedCount() {
            const count = document.querySelectorAll('.student-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count + ' selected';
        }

        // Select all students
        function selectAll() {
            document.querySelectorAll('.student-checkbox').forEach(cb => {
                cb.checked = true;
            });
            updateSelectedCount();
        }

        // Deselect all students
        function deselectAll() {
            document.querySelectorAll('.student-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateSelectedCount();
        }

        // Select all students in a specific class
        function selectClass(classKey) {
            const className = 'class-' + md5(classKey);
            document.querySelectorAll('.' + className).forEach(cb => {
                cb.checked = true;
            });
            updateSelectedCount();
        }

        // Simple MD5 hash function for class identification
        function md5(string) {
            let hash = 0;
            for (let i = 0; i < string.length; i++) {
                const char = string.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return Math.abs(hash).toString(36);
        }

        // Search functionality
        document.getElementById('studentSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase().trim();
            const studentGroups = document.querySelectorAll('.student-group');
            
            studentGroups.forEach(group => {
                let hasVisibleStudents = false;
                const students = group.querySelectorAll('.student-item');
                
                students.forEach(student => {
                    const studentName = student.getAttribute('data-student-name');
                    if (studentName.includes(searchTerm) || searchTerm === '') {
                        student.style.display = '';
                        hasVisibleStudents = true;
                    } else {
                        student.style.display = 'none';
                    }
                });
                
                // Hide/show entire group based on visible students
                if (hasVisibleStudents || searchTerm === '') {
                    group.style.display = '';
                } else {
                    group.style.display = 'none';
                }
            });
        });

        // Form validation
        document.getElementById('mappingForm').addEventListener('submit', function(e) {
            const parentId = document.getElementById('parent_id').value;
            const selectedStudents = document.querySelectorAll('.student-checkbox:checked');
            
            if (!parentId) {
                e.preventDefault();
                alert('Please select a parent.');
                return;
            }
            
            if (selectedStudents.length === 0) {
                e.preventDefault();
                alert('Please select at least one student.');
                return;
            }
            
            const parentName = document.querySelector('#parent_id option:checked').text;
            const message = `Add ${selectedStudents.length} student(s) to parent "${parentName}"?\n\nThis action cannot be undone.`;
            
            if (!confirm(message)) {
                e.preventDefault();
            }
        });

        // Initialize selected count on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
            
            // Add change event to all checkboxes to update count
            document.querySelectorAll('.student-checkbox').forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>