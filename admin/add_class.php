<?php
session_start();

// Restrict to admins only and validate session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Invalid session: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    header("Location: ../login.php");
    exit();
}

// Include database connection
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    die("System error: Unable to connect to database. Please contact the administrator.");
}

if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include logging function
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
} catch (Exception $e) {
    error_log("Failed to include log_activity.php: " . $e->getMessage());
    die("System error: Unable to load logging functions. Please contact the administrator.");
}

// Validate user_id exists in users table
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin' AND status = 'active'";
if ($user_check_stmt = $conn->prepare($user_check_sql)) {
    $user_check_stmt->bind_param("i", $_SESSION['user_id']);
    $user_check_stmt->execute();
    $user_check_result = $user_check_stmt->get_result();
    if ($user_check_result->num_rows === 0) {
        error_log("Invalid or inactive admin user_id: " . $_SESSION['user_id']);
        header("Location: ../login.php?error=invalid_user");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    die("System error: Unable to validate user. Please contact the administrator.");
}

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
if ($csrf_stmt = $conn->prepare($csrf_sql)) {
    $csrf_stmt->bind_param("is", $_SESSION['user_id'], $csrf_token);
    $csrf_stmt->execute();
    $csrf_stmt->close();
} else {
    error_log("Failed to prepare CSRF token query: " . $conn->error);
    die("System error: Unable to generate CSRF token. Please contact the administrator.");
}

// Fetch academic year dynamically
$current_date = new DateTime();
$current_month = (int)$current_date->format('m');
$current_year = (int)$current_date->format('Y');
$academic_year = ($current_month >= 9) ? "$current_year/" . ($current_year + 1) : ($current_year - 1) . "/$current_year";
$academic_sql = "SELECT year FROM academic_calendar WHERE year = ? LIMIT 1";
if ($academic_stmt = $conn->prepare($academic_sql)) {
    $academic_stmt->bind_param("s", $academic_year);
    $academic_stmt->execute();
    $academic_result = $academic_stmt->get_result();
    $academic_year = $academic_result->num_rows > 0 ? $academic_result->fetch_assoc()['year'] : '2024/2025';
    $_SESSION['academic_year'] = $academic_year;
    $academic_stmt->close();
} else {
    error_log("Failed to fetch academic year: " . $conn->error);
    $academic_year = '2024/2025';
}

// Fetch teachers (active only)
$teachers_sql = "SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name";
$teachers_result = $conn->query($teachers_sql);
if (!$teachers_result) {
    error_log("Error fetching teachers: " . $conn->error);
    $error_msg = "Unable to fetch teachers. Please try again later.";
    $teachers = [];
} else {
    $teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);
    $teachers_result->free();
}

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_result = $conn->query($departments_sql);
if (!$departments_result) {
    error_log("Error fetching departments: " . $conn->error);
    $error_msg = "Unable to fetch departments. Please try again later.";
    $departments = [];
} else {
    $departments = $departments_result->fetch_all(MYSQLI_ASSOC);
    $departments_result->free();
}

// Initialize variables
$success_msg = $error_msg = "";
$last_class = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    $csrf_check_sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_check_stmt = $conn->prepare($csrf_check_sql)) {
        $csrf_check_stmt->bind_param("is", $_SESSION['user_id'], $submitted_csrf);
        $csrf_check_stmt->execute();
        $csrf_check_result = $csrf_check_stmt->get_result();
        if ($csrf_check_result->num_rows === 0) {
            $error_msg = "Invalid CSRF token. Please try again.";
            error_log("CSRF validation failed for user_id: " . $_SESSION['user_id']);
        } else {
            $csrf_check_stmt->close();
            // Delete used CSRF token
            $csrf_delete_sql = "DELETE FROM csrf_tokens WHERE token = ?";
            if ($csrf_delete_stmt = $conn->prepare($csrf_delete_sql)) {
                $csrf_delete_stmt->bind_param("s", $submitted_csrf);
                $csrf_delete_stmt->execute();
                $csrf_delete_stmt->close();
            }

            $class_name = trim($_POST['class_name'] ?? '');
            $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
            $department_id = (int)$_POST['department_id'] ?? null;
            $description = trim($_POST['description'] ?? '');

            // Validate inputs
            if (empty($class_name)) {
                $error_msg = "Class name is required.";
            } elseif (strlen($class_name) > 255) {
                $error_msg = "Class name must be 255 characters or less.";
            } elseif ($teacher_id && !in_array($teacher_id, array_column($teachers, 'id'))) {
                $error_msg = "Invalid teacher selected.";
            } elseif (empty($department_id) || !in_array($department_id, array_column($departments, 'id'))) {
                $error_msg = "Valid department is required.";
            } elseif (strlen($description) > 65535) {
                $error_msg = "Description is too long.";
            } else {
                $conn->begin_transaction();
                try {
                    // Check for duplicate class name in department
                    $check_sql = "SELECT id FROM classes WHERE class_name = ? AND department_id = ?";
                    if ($check_stmt = $conn->prepare($check_sql)) {
                        $check_stmt->bind_param("si", $class_name, $department_id);
                        $check_stmt->execute();
                        if ($check_stmt->get_result()->num_rows > 0) {
                            $error_msg = "Class name already exists in this department.";
                            throw new Exception("Duplicate class name");
                        }
                        $check_stmt->close();
                    } else {
                        throw new Exception("Failed to prepare duplicate check query: " . $conn->error);
                    }

                    // Check if teacher is already assigned
                    if ($teacher_id) {
                        $teacher_check_sql = "SELECT COUNT(*) as class_count FROM classes WHERE teacher_id = ? AND department_id = ?";
                        if ($teacher_check_stmt = $conn->prepare($teacher_check_sql)) {
                            $teacher_check_stmt->bind_param("ii", $teacher_id, $department_id);
                            $teacher_check_stmt->execute();
                            $class_count = $teacher_check_stmt->get_result()->fetch_assoc()['class_count'];
                            $teacher_check_stmt->close();
                            if ($class_count > 0) {
                                $error_msg = "This teacher is already assigned to another class in this department.";
                                throw new Exception("Teacher already assigned");
                            }
                        } else {
                            throw new Exception("Failed to prepare teacher check query: " . $conn->error);
                        }
                    }

                    // Insert class (subject_id and class_teacher_id set to NULL)
                    $insert_sql = "INSERT INTO classes (class_name, teacher_id, subject_id, department_id, description, created_at, updated_at, class_teacher_id) VALUES (?, ?, NULL, ?, ?, NOW(), NOW(), NULL)";
                    if ($insert_stmt = $conn->prepare($insert_sql)) {
                        $insert_stmt->bind_param("siis", $class_name, $teacher_id, $department_id, $description);
                        $insert_stmt->execute();
                        $class_id = $conn->insert_id;
                        $insert_stmt->close();
                    } else {
                        throw new Exception("Failed to prepare class insert query: " . $conn->error);
                    }

                    // Fetch class details for confirmation
                    $last_class_sql = "SELECT c.class_name, u.name as teacher_name, d.name as department_name, c.created_at 
                                      FROM classes c 
                                      LEFT JOIN users u ON c.teacher_id = u.id 
                                      JOIN departments d ON c.department_id = d.id 
                                      WHERE c.id = ?";
                    if ($last_class_stmt = $conn->prepare($last_class_sql)) {
                        $last_class_stmt->bind_param("i", $class_id);
                        $last_class_stmt->execute();
                        $last_class = $last_class_stmt->get_result()->fetch_assoc();
                        $last_class_stmt->close();
                    }

                    // Log action
                    $user_id = $_SESSION['user_id'];
                    $action = "Added Class";
                    $description = "Class ID: $class_id, Name: $class_name" . ($teacher_id ? ", Teacher ID: $teacher_id" : "") . ", Department ID: $department_id";
                    logActivity($conn, $user_id, $action, $description);

                    $conn->commit();
                    $success_msg = "Class added successfully!";
                    $_POST = [];
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error adding class: " . ($e->getCode() == 1062 ? "Class name already exists in this department." : $e->getMessage());
                    error_log("Add class error: " . $e->getMessage());
                }
            }
        }
    } else {
        $error_msg = "CSRF token validation failed.";
        error_log("CSRF token preparation failed: " . $conn->error);
    }
}

// Include header
try {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
} catch (Exception $e) {
    error_log("Failed to include admin_header.php: " . $e->getMessage());
    die("System error: Unable to load page header. Please contact the administrator.");
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Add a new class with teacher and department assignments in Queen's Park Montessori School management system.">
    <meta name="keywords" content="Queen's Park Montessori, add class, teacher assignment, department assignment, admin dashboard, school management">
    <meta name="author" content="Queen's Park Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Queen's Park Montessori Add Class">
    <meta property="og:description" content="Admin interface for adding classes with teacher and department assignments in Queen's Park Montessori School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/add_class.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Queen's Park Montessori Add Class">
    <meta name="twitter:description" content="Admin interface for adding classes with teacher and department assignments in Queen's Park Montessori School.">
    <title>Add Class - Queen's Park Montessori</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            500: '#f97316',
                            600: '#ea580c',
                        },
                        secondary: '#6b7280',
                        accent: '#10b981',
                        background: '#f4f4f4',
                    },
                },
            },
        };
    </script>
    <style>
        [data-theme="dark"] {
            --primary-500: #fb923c;
            --primary-600: #f97316;
            --secondary: #9ca3af;
            --accent: #34d399;
            --background: #1f2937;
            --text: #d1d5db;
        }
        body {
            background-color: var(--background);
            color: var(--text, #1f2937);
        }
        .dark .bg-background { background-color: var(--background); }
        .dark .text-text { color: var(--text); }
        .print-section {
            display: none;
        }
        @media print {
            body { background: white; color: black; }
            .print-section { display: block; }
            .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #f97316; padding-bottom: 10px; }
            .print-header img { max-width: 100px; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .print-table th, .print-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .print-table th { background: linear-gradient(to right, #f97316, #ea580c); color: white; }
            .print-footer { text-align: center; margin-top: 20px; font-size: 10pt; color: #555; }
            @page { size: A4; margin: 20mm; }
        }
    </style>
</head>
<body class="min-h-screen bg-background text-text">
    <div class="content" id="content">
        <main class="container mx-auto p-4">
            <section class="bg-white dark:bg-gray-800 shadow-lg rounded-lg p-6 mb-6">
                <h3 class="text-2xl font-bold text-primary-500 mb-4">📚 Add New Class</h3>
                <?php if (!empty($success_msg)): ?>
                    <div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php if ($last_class): ?>
                        <div class="print-section">
                            <div class="print-header">
                                <img src="/assets/images/logo.png" alt="Queen's Park Montessori Logo" onerror="this.src='/assets/images/default_logo.png'">
                                <h2>Queen's Park Montessori</h2>
                                <p>P.O. Box 123, Accra, Ghana | info@qpmsedu.com</p>
                                <p>Class Creation Confirmation - <?php echo date('F j, Y'); ?></p>
                            </div>
                            <table class="print-table">
                                <tr><th>Class Name</th><td><?php echo htmlspecialchars($last_class['class_name']); ?></td></tr>
                                <tr><th>Assigned Teacher</th><td><?php echo htmlspecialchars($last_class['teacher_name'] ?? 'None'); ?></td></tr>
                                <tr><th>Department</th><td><?php echo htmlspecialchars($last_class['department_name']); ?></td></tr>
                                <tr><th>Created At</th><td><?php echo htmlspecialchars($last_class['created_at']); ?></td></tr>
                            </table>
                            <div class="print-footer">
                                <p>Generated by Queen's Park Montessori School Management System</p>
                            </div>
                        </div>
                        <button class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg mt-3" onclick="window.print()">
                            <i class="fas fa-print mr-2"></i>Print Confirmation
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($error_msg)): ?>
                    <div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($error_msg); ?></div>
                <?php endif; ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div>
                        <label for="class_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Class Name</label>
                        <input type="text" id="class_name" name="class_name" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" 
                               value="<?php echo isset($_POST['class_name']) ? htmlspecialchars($_POST['class_name']) : ''; ?>" required maxlength="255" placeholder="e.g., Grade 1A" aria-required="true">
                    </div>
                    <div>
                        <label for="department_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Department</label>
                        <select name="department_id" id="department_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" required aria-required="true">
                            <option value="">Select Department</option>
                            <?php if (empty($departments)): ?>
                                <option value="" disabled>No departments available</option>
                            <?php else: ?>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?php echo $department['id']; ?>" <?php echo isset($_POST['department_id']) && $_POST['department_id'] == $department['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label for="teacher_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Assign Teacher (Optional)</label>
                        <select name="teacher_id" id="teacher_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white">
                            <option value="">No Teacher</option>
                            <?php if (empty($teachers)): ?>
                                <option value="" disabled>No teachers available</option>
                            <?php else: ?>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>" <?php echo isset($_POST['teacher_id']) && $_POST['teacher_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description (Optional)</label>
                        <textarea id="description" name="description" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" rows="4" maxlength="65535"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    </div>
                    <div class="flex justify-center space-x-4">
                        <button type="submit" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Add Class
                        </button>
                        <a href="/admin/class_management.php" class="bg-secondary hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </section>
        </main>
    </div>
    <?php 
    try {
        include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    } catch (Exception $e) {
        error_log("Failed to include admin_footer.php: " . $e->getMessage());
        echo "<footer class='text-center py-4 text-gray-500'>System error: Unable to load page footer. Please contact the administrator.</footer>";
    }
    $conn->close();
    ?>
    <button id="theme-toggle" class="fixed bottom-4 right-4 bg-primary-500 hover:bg-primary-600 text-white p-2 rounded-full">
        <i class="fas fa-moon"></i>
    </button>
    <script>
        // Theme toggle
        const toggleButton = document.getElementById('theme-toggle');
        const htmlElement = document.documentElement;
        const currentTheme = localStorage.getItem('theme') || 'light';
        htmlElement.setAttribute('data-theme', currentTheme);
        if (currentTheme === 'dark') {
            toggleButton.innerHTML = '<i class="fas fa-sun"></i>';
        }
        toggleButton.addEventListener('click', () => {
            const newTheme = htmlElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            htmlElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            toggleButton.innerHTML = newTheme === 'light' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        });
    </script>
</body>
</html>