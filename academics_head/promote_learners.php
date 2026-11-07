<?php
session_start();

// Restrict to admins only and validate session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Invalid session: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    header("Location: ../login.php");
    exit();
}

// Include database connection
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to connect to database. Please contact the administrator.");
}

if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include logging function
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
} catch (Exception $e) {
    error_log("Failed to include log_activity.php: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to load logging functions. Please contact the administrator.");
}

// Define fallback logActivity function if undefined
if (!function_exists('logActivity')) {
    function logActivity($conn, $user_id, $action, $details) {
        $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] [user_id: $user_id] $action: $details\n";
        error_log($log_message, 3, $log_file);
    }
}

// Validate user_id exists in users table
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin' AND status = 'active'";
if ($user_check_stmt = $conn->prepare($user_check_sql)) {
    $user_check_stmt->bind_param("i", $_SESSION['user_id']);
    $user_check_stmt->execute();
    $user_check_result = $user_check_stmt->get_result();
    if ($user_check_result->num_rows === 0) {
        error_log("Invalid or inactive admin user_id: " . $_SESSION['user_id'], 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        logActivity($conn, $_SESSION['user_id'] ?? 0, 'access_denied', "Attempted to access promote_learners.php without valid admin credentials");
        header("Location: ../login.php?error=invalid_user");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to validate user. Please contact the administrator.");
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
logActivity($conn, $_SESSION['user_id'], 'page_access', "Admin accessed promote_learners.php, IP: $ip_address, User Agent: $user_agent");

// Generate and store CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
$csrf_query = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
if ($csrf_stmt = $conn->prepare($csrf_query)) {
    $csrf_stmt->bind_param("iss", $_SESSION['user_id'], $csrf_token, $csrf_expiry);
    $csrf_stmt->execute();
    $csrf_stmt->close();
} else {
    error_log("Failed to store CSRF token: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
}
$_SESSION['csrf_token'] = $csrf_token;

// Fetch academic years from academic_calendar
$years_sql = "SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC";
$years_result = $conn->query($years_sql);
if (!$years_result) {
    error_log("Error fetching academic years: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    $error_msg = "Unable to fetch academic years. Please try again later.";
    $academic_years = [];
} else {
    $academic_years = [];
    while ($year_row = $years_result->fetch_assoc()) {
        $academic_years[] = $year_row['year'];
    }
    $years_result->free();
}

// Fetch classes
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_sql);
if (!$classes_result) {
    error_log("Error fetching classes: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    $error_msg = "Unable to fetch classes. Please try again later.";
    $classes = [];
} else {
    $classes = $classes_result->fetch_all(MYSQLI_ASSOC);
    $classes_result->free();
}

// Fetch current assignments for display
$assignments_sql = "SELECT sc.id, sc.user_id, sc.class_id, sc.academic_year, u.name as student_name, c.class_name 
                   FROM student_classes sc 
                   JOIN users u ON sc.user_id = u.id 
                   JOIN classes c ON sc.class_id = c.id 
                   WHERE u.role = 'student' AND u.status = 'active'
                   ORDER BY c.class_name, u.name";
$assignments_result = $conn->query($assignments_sql);
$grouped_assignments = [];
$print_assignments = [];
if (!$assignments_result) {
    error_log("Error fetching assignments: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    $error_msg = "Unable to fetch assignments. Please try again later.";
} else {
    while ($row = $assignments_result->fetch_assoc()) {
        $class_name = $row['class_name'];
        if (!isset($grouped_assignments[$class_name])) {
            $grouped_assignments[$class_name] = [];
            $print_assignments[$class_name] = [];
        }
        $grouped_assignments[$class_name][] = $row;
        $print_assignments[$class_name][] = $row;
    }
    $assignments_result->free();
}

// Initialize variables
$success_msg = $error_msg = "";
$selected_year = !empty($academic_years) ? $academic_years[0] : '';
$students_in_class = [];
$source_class_id = isset($_POST['source_class_id']) ? (int)$_POST['source_class_id'] : 0;
$source_academic_year = isset($_POST['source_academic_year']) ? trim($_POST['source_academic_year']) : '';

// Populate students_in_class if source_class_id and source_academic_year are set
if ($source_class_id > 0 && !empty($source_academic_year)) {
    $students_sql = "SELECT u.id, u.name 
                    FROM student_classes sc 
                    JOIN users u ON sc.user_id = u.id 
                    WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student' AND u.status = 'active'
                    ORDER BY u.name";
    if ($stmt = $conn->prepare($students_sql)) {
        $stmt->bind_param("is", $source_class_id, $source_academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $students_in_class[] = $row;
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare students query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        $error_msg = "Unable to fetch students for the selected class.";
    }
}

// Extract grade level from class name (e.g., "Grade 1" -> 1)
function getGradeLevel($class_name) {
    if (preg_match('/Grade\s*(\d+)/i', $class_name, $matches)) {
        return (int)$matches[1];
    }
    return 0; // Default if grade level not found
}

// Validate academic year format (YYYY/YYYY)
function validateAcademicYear($year) {
    return preg_match('/^\d{4}\/\d{4}$/', $year) && (int)substr($year, 0, 4) < (int)substr($year, 5, 4);
}

// Handle AJAX request for students in selected class
if (isset($_GET['action']) && $_GET['action'] === 'get_students' && isset($_GET['class_id']) && isset($_GET['academic_year'])) {
    $class_id = (int)$_GET['class_id'];
    $academic_year = trim($_GET['academic_year']);
    $response = ['students' => [], 'error' => ''];

    if ($class_id <= 0 || empty($academic_year)) {
        $response['error'] = 'Class ID and academic year are required.';
    } elseif (!validateAcademicYear($academic_year)) {
        $response['error'] = 'Invalid academic year format (YYYY/YYYY).';
    } else {
        $students_sql = "SELECT u.id, u.name 
                        FROM student_classes sc 
                        JOIN users u ON sc.user_id = u.id 
                        WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student' AND u.status = 'active'
                        ORDER BY u.name";
        if ($stmt = $conn->prepare($students_sql)) {
            $stmt->bind_param("is", $class_id, $academic_year);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $response['students'][] = $row;
            }
            $stmt->close();
        } else {
            $response['error'] = 'Database error: Unable to fetch students.';
            error_log("Failed to prepare students query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle form submission for class promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_class']) && isset($_POST['csrf_token'])) {
    // Validate CSRF token
    $csrf_valid = false;
    $csrf_check_query = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_stmt = $conn->prepare($csrf_check_query)) {
        $csrf_stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
        $csrf_stmt->execute();
        $csrf_result = $csrf_stmt->get_result();
        $csrf_valid = $csrf_result->num_rows > 0;
        $csrf_stmt->close();
        // Clean up used/expired tokens
        $cleanup_query = "DELETE FROM csrf_tokens WHERE user_id = ? AND (expires_at <= NOW() OR token = ?)";
        if ($cleanup_stmt = $conn->prepare($cleanup_query)) {
            $cleanup_stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
            $cleanup_stmt->execute();
            $cleanup_stmt->close();
        }
    }
    if (!$csrf_valid) {
        $error_msg = "Invalid CSRF token.";
        error_log("Invalid CSRF token for class promotion", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        logActivity($conn, $_SESSION['user_id'], 'csrf_error', "Invalid CSRF token attempted for class promotion");
    } else {
        $source_class_id = (int)($_POST['source_class_id'] ?? 0);
        $target_class_id = (int)($_POST['target_class_id'] ?? 0);
        $source_academic_year = trim($_POST['source_academic_year'] ?? '');
        $target_academic_year = trim($_POST['academic_year'] ?? '');

        // Validate inputs
        if ($source_class_id <= 0 || empty($source_academic_year) || empty($target_academic_year)) {
            $error_msg = "Please select source class and both academic years.";
        } elseif (!validateAcademicYear($source_academic_year) || !validateAcademicYear($target_academic_year)) {
            $error_msg = "Invalid academic year format. Use YYYY/YYYY (e.g., 2024/2025).";
        } elseif ($source_class_id === $target_class_id && $target_class_id !== 0 && $source_academic_year === $target_academic_year) {
            $error_msg = "Source and target class/academic year cannot be the same.";
        } else {
            // Validate classes
            $source_class_name = $target_class_name = '';
            $class_check_sql = "SELECT id, class_name FROM classes WHERE id = ?";
            if ($class_check_stmt = $conn->prepare($class_check_sql)) {
                // Check source class
                $class_check_stmt->bind_param("i", $source_class_id);
                $class_check_stmt->execute();
                $class_result = $class_check_stmt->get_result();
                if ($class_result->num_rows === 0) {
                    $error_msg = "Invalid source class selected.";
                } else {
                    $source_class = $class_result->fetch_assoc();
                    $source_class_name = $source_class['class_name'];
                }
                $class_check_stmt->close();
            } else {
                $error_msg = "System error: Unable to validate classes.";
                error_log("Failed to prepare class check query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
            }

            // Check target class if not graduating
            if ($target_class_id !== 0 && empty($error_msg)) {
                if ($class_check_stmt = $conn->prepare($class_check_sql)) {
                    $class_check_stmt->bind_param("i", $target_class_id);
                    $class_check_stmt->execute();
                    $class_result = $class_check_stmt->get_result();
                    if ($class_result->num_rows === 0) {
                        $error_msg = "Invalid target class selected.";
                    } else {
                        $target_class = $class_result->fetch_assoc();
                        $target_class_name = $target_class['class_name'];
                        // Validate grade level for promotion
                        $source_grade = getGradeLevel($source_class_name);
                        $target_grade = getGradeLevel($target_class_name);
                        if ($target_grade <= $source_grade && $source_grade != 0 && $target_grade != 0) {
                            $error_msg = "Promotion failed: $target_class_name is not a higher grade than $source_class_name.";
                        }
                    }
                    $class_check_stmt->close();
                } else {
                    $error_msg = "System error: Unable to validate classes.";
                    error_log("Failed to prepare class check query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                }
            }

            if (empty($error_msg)) {
                $conn->begin_transaction();
                try {
                    // Fetch students in the source class
                    $students_sql = "SELECT sc.user_id, u.name 
                                    FROM student_classes sc 
                                    JOIN users u ON sc.user_id = u.id 
                                    WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student' AND u.status = 'active'";
                    if ($students_stmt = $conn->prepare($students_sql)) {
                        $students_stmt->bind_param("is", $source_class_id, $source_academic_year);
                        $students_stmt->execute();
                        $students_result = $students_stmt->get_result();
                        $students = [];
                        while ($row = $students_result->fetch_assoc()) {
                            $students[] = $row;
                        }
                        $students_stmt->close();

                        if (empty($students)) {
                            throw new Exception("No active students found in $source_class_name for $source_academic_year.");
                        }

                        $success_count = 0;
                        $failed_promotions = [];
                        if ($target_class_id !== 0) {
                            // Prepare statements for checking and inserting
                            $insert_sql = "INSERT INTO student_classes (user_id, class_id, academic_year, created_at) VALUES (?, ?, ?, NOW())";
                            $check_sql = "SELECT id FROM student_classes WHERE user_id = ? AND academic_year = ?";
                            $insert_stmt = $conn->prepare($insert_sql);
                            $check_stmt = $conn->prepare($check_sql);

                            if (!$insert_stmt || !$check_stmt) {
                                throw new Exception("Failed to prepare query: " . $conn->error);
                            }

                            foreach ($students as $student) {
                                $user_id = $student['user_id'];
                                $student_name = $student['name'];
                                // Check if student is already assigned in the target academic year
                                $check_stmt->bind_param("is", $user_id, $target_academic_year);
                                $check_stmt->execute();
                                $check_result = $check_stmt->get_result();
                                if ($check_result->num_rows > 0) {
                                    $failed_promotions[] = "Student $student_name already assigned for $target_academic_year.";
                                    continue;
                                }

                                // Insert new assignment
                                $insert_stmt->bind_param("iis", $user_id, $target_class_id, $target_academic_year);
                                if ($insert_stmt->execute()) {
                                    $success_count++;
                                    logActivity($conn, $_SESSION['user_id'], 'promoted_student', "Promoted student $student_name (ID: $user_id) from $source_class_name to $target_class_name, Academic Year: $target_academic_year");
                                } else {
                                    $failed_promotions[] = "Failed to promote $student_name: " . $insert_stmt->error;
                                }
                            }

                            $insert_stmt->close();
                            $check_stmt->close();
                        } else {
                            // Graduating students: no new assignment, just log
                            foreach ($students as $student) {
                                $user_id = $student['user_id'];
                                $student_name = $student['name'];
                                $success_count++;
                                logActivity($conn, $_SESSION['user_id'], 'graduated_student', "Student $student_name (ID: $user_id) graduated from $source_class_name, Academic Year: $source_academic_year");
                            }
                        }

                        $conn->commit();
                        if ($success_count > 0) {
                            $success_msg = "$success_count student" . ($success_count > 1 ? "s" : "") . ($target_class_id === 0 ? " graduated" : " promoted to $target_class_name") . " successfully!";
                            if (!empty($failed_promotions)) {
                                $error_msg = "Some promotions failed: " . implode("; ", $failed_promotions);
                            }
                            $_POST = [];
                            // Refresh assignments
                            $assignments_result = $conn->query($assignments_sql);
                            $grouped_assignments = [];
                            $print_assignments = [];
                            if ($assignments_result) {
                                while ($row = $assignments_result->fetch_assoc()) {
                                    $class_name = $row['class_name'];
                                    if (!isset($grouped_assignments[$class_name])) {
                                        $grouped_assignments[$class_name] = [];
                                        $print_assignments[$class_name] = [];
                                    }
                                    $grouped_assignments[$class_name][] = $row;
                                    $print_assignments[$class_name][] = $row;
                                }
                                $assignments_result->free();
                            }
                            // Refresh students_in_class
                            if ($source_class_id > 0 && !empty($source_academic_year)) {
                                $students_sql = "SELECT u.id, u.name 
                                                FROM student_classes sc 
                                                JOIN users u ON sc.user_id = u.id 
                                                WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student' AND u.status = 'active'
                                                ORDER BY u.name";
                                if ($stmt = $conn->prepare($students_sql)) {
                                    $stmt->bind_param("is", $source_class_id, $source_academic_year);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    $students_in_class = [];
                                    while ($row = $result->fetch_assoc()) {
                                        $students_in_class[] = $row;
                                    }
                                    $stmt->close();
                                }
                            }
                        } else {
                            throw new Exception("No students were promoted: " . implode("; ", $failed_promotions));
                        }
                    } else {
                        throw new Exception("Failed to fetch students for $source_class_name: " . $conn->error);
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error promoting students: " . ($e->getCode() == 1062 ? "One or more students are already assigned for the selected academic year." : $e->getMessage());
                    error_log("Promotion error: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                    logActivity($conn, $_SESSION['user_id'], 'promotion_error', "Failed to promote students: " . $e->getMessage());
                }
            }
        }
    }
}

// Include header
try {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
} catch (Exception $e) {
    error_log("Failed to include admin_header.php: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to load page header. Please contact the administrator.");
}

// Ensure logs directory exists
$log_dir = $_SERVER['DOCUMENT_ROOT'] . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Bulk promote students in a class to the next class in Queen's Park Montessori School management system.">
    <meta name="keywords" content="Queen's Park Montessori, student promotion, bulk promotion, admin dashboard, school management">
    <meta name="author" content="Queen's Park Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Queen's Park Montessori Promote Learners">
    <meta property="og:description" content="Admin interface for bulk promoting students in a class to the next class in Queen's Park Montessori School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/promote_learners.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Queen's Park Montessori Promote Learners">
    <meta name="twitter:description" content="Admin interface for bulk promoting students in a class to the next class in Queen's Park Montessori School.">
    <title>Promote Learners - Queen's Park Montessori</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
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
        .class-card {
            transition: transform 0.2s;
            width: 100%;
            max-width: 350px;
            height: 400px;
            display: flex;
            flex-direction: column;
        }
        .class-card:hover {
            transform: translateY(-5px);
        }
        .class-card .card-body {
            flex: 1;
            overflow-y: auto;
            max-height: 300px;
            padding: 1rem;
        }
        .class-card .student-count {
            font-size: 0.9rem;
            font-weight: bold;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.3rem 0.6rem;
            border-radius: 12px;
        }
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
            .print-group-header { font-size: 14pt; font-weight: bold; margin: 20px 0 10px; color: #f97316; page-break-before: always; }
            .print-footer { text-align: center; margin-top: 20px; font-size: 10pt; color: #555; }
            @page { size: A4; margin: 20mm; }
        }
    </style>
</head>
<body class="min-h-screen bg-background text-text">
    <div class="content" id="content">
        <main class="container mx-auto p-4">
            <section class="bg-white dark:bg-gray-800 shadow-lg rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-2xl font-bold text-primary-500">📈 Promote Learners</h3>
                    <div class="space-x-4">
                        <a href="/admin/assign_class.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Assign Classes
                        </a>
                     
                        <a href="/admin/manage_student_classes.php" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-table mr-2"></i>View Assignments
                        </a>
                    </div>
                </div>
                <?php if (!empty($success_msg)) { ?>
                    <div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($success_msg); ?></div>
                <?php } ?>
                <?php if (!empty($error_msg)) { ?>
                    <div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($error_msg); ?></div>
                <?php } ?>
                <!-- Promotion Form -->
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg mb-6">
                    <h4 class="text-lg font-semibold text-primary-500 mb-4">Promote Class</h4>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4" onsubmit="return confirm('Are you sure you want to promote this class? This action cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div>
                            <label for="source_class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Class</label>
                            <select name="source_class_id" id="source_class_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                <option value="">Select Current Class</option>
                                <?php foreach ($classes as $class) { ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $source_class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label for="source_academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Academic Year</label>
                            <select name="source_academic_year" id="source_academic_year" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                <option value="">Select Current Academic Year</option>
                                <?php foreach ($academic_years as $year) { ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $source_academic_year === $year ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label for="target_class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Promote to Class</label>
                            <select name="target_class_id" id="target_class_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                <option value="">Select Target Class</option>
                                <?php foreach ($classes as $class) { ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo isset($_POST['target_class_id']) && $_POST['target_class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php } ?>
                                <option value="0">No Promotion (Graduating)</option>
                            </select>
                        </div>
                        <div>
                            <label for="academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Target Academic Year</label>
                            <select name="academic_year" id="academic_year" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                <option value="">Select Target Academic Year</option>
                                <?php foreach ($academic_years as $year) { ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo isset($_POST['academic_year']) && $_POST['academic_year'] === $year ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="md:col-span-2 text-center mt-4">
                            <button type="submit" name="promote_class" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-arrow-up mr-2"></i>Promote Class
                            </button>
                            <button type="button" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg ml-2" onclick="window.print()">
                                <i class="fas fa-print mr-2"></i>Print Assignments
                            </button>
                        </div>
                    </form>
                </div>
                <!-- Students in Selected Class -->
                <div class="class-card bg-white dark:bg-gray-800 shadow-lg rounded-lg">
                    <div class="card-header bg-gradient-to-r from-primary-500 to-primary-600 text-white p-4 rounded-t-lg flex justify-between items-center">
                        <h5 class="mb-0" id="class-card-title">
                            <?php
                            if ($source_class_id > 0) {
                                $class_name = array_column($classes, 'class_name', 'id')[$source_class_id] ?? 'Selected Class';
                                echo htmlspecialchars($class_name);
                            } else {
                                echo 'Select a Class';
                            }
                            ?>
                        </h5>
                        <span class="student-count" id="student-count"><?php echo count($students_in_class); ?> Students</span>
                    </div>
                    <div class="card-body">
                        <ul class="divide-y divide-gray-200 dark:divide-gray-600" id="student-list">
                            <?php if (empty($students_in_class)) { ?>
                                <li class="py-2 text-gray-700 dark:text-gray-400">Select a class and academic year to view students.</li>
                            <?php } else { ?>
                                <?php foreach ($students_in_class as $student) { ?>
                                    <li class="py-2">
                                        <span><?php echo htmlspecialchars($student['name']); ?></span>
                                    </li>
                                <?php } ?>
                            <?php } ?>
                        </ul>
                    </div>
                </div>
                <!-- Current Assignments -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
                    <?php if (empty($grouped_assignments)) { ?>
                        <div class="col-span-full">
                            <div class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-400 p-4 rounded-lg text-center">No assignments found.</div>
                        </div>
                    <?php } else { ?>
                        <?php foreach ($grouped_assignments as $class_name => $assignments) { ?>
                            <div class="class-card bg-white dark:bg-gray-800 shadow-lg rounded-lg">
                                <div class="card-header bg-gradient-to-r from-primary-500 to-primary-600 text-white p-4 rounded-t-lg flex justify-between items-center">
                                    <h5 class="mb-0"><?php echo htmlspecialchars($class_name); ?></h5>
                                    <span class="student-count"><?php echo count($assignments); ?> Students</span>
                                </div>
                                <div class="card-body">
                                    <ul class="divide-y divide-gray-200 dark:divide-gray-600">
                                        <?php foreach ($assignments as $assignment) { ?>
                                            <li class="py-2">
                                                <span>
                                                    <?php echo htmlspecialchars($assignment['student_name']); ?>
                                                    <small class="text-gray-500 dark:text-gray-400">(<?php echo htmlspecialchars($assignment['academic_year']); ?>)</small>
                                                </span>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </div>
                <!-- Print Section -->
                <div class="print-section">
                    <div class="print-header">
                        <?php
                        $logo_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/logo.png';
                        if (file_exists($logo_path)) {
                            echo '<img src="/assets/images/logo.png" alt="Queen\'s Park Montessori Logo">';
                        } else {
                            echo '<div style="font-size: 1rem; color: #555;">[School Logo]</div>';
                        }
                        ?>
                        <h2>Queen's Park Montessori</h2>
                        <p>P.O. Box 123, Accra, Ghana | info@qpmsedu.com</p>
                        <p>Student-Class Assignments - <?php echo date('F j, Y'); ?></p>
                    </div>
                    <?php foreach ($print_assignments as $class_name => $class_assignments) { ?>
                        <h3 class="print-group-header"><?php echo htmlspecialchars($class_name); ?> (<?php echo count($class_assignments); ?> Students)</h3>
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Academic Year</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($class_assignments as $assignment) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($assignment['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['academic_year']); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } ?>
                    <div class="print-footer">
                        <p>Generated by Queen's Park Montessori School Management System</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php 
    try {
        include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    } catch (Exception $e) {
        error_log("Failed to include admin_footer.php: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
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

        // AJAX for fetching students in selected class
        $(document).ready(function() {
            function updateStudentList() {
                const classId = $('#source_class_id').val();
                const academicYear = $('#source_academic_year').val();
                const container = $('#student-list');
                const title = $('#class-card-title');
                const count = $('#student-count');

                if (classId && academicYear) {
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_students',
                        type: 'GET',
                        data: { class_id: classId, academic_year: academicYear },
                        dataType: 'json',
                        success: function(response) {
                            container.empty();
                            count.text(response.students.length + ' Students');
                            if (response.error) {
                                container.append('<li class="py-2 text-gray-700 dark:text-gray-400">Error: ' + response.error + '</li>');
                                title.text('Selected Class');
                            } else if (response.students.length === 0) {
                                container.append('<li class="py-2 text-gray-700 dark:text-gray-400">No students found in this class.</li>');
                                title.text($('#source_class_id option:selected').text());
                            } else {
                                $.each(response.students, function(index, student) {
                                    container.append(`<li class="py-2"><span>${student.name}</span></li>`);
                                });
                                title.text($('#source_class_id option:selected').text());
                            }
                        },
                        error: function() {
                            container.empty();
                            container.append('<li class="py-2 text-gray-700 dark:text-gray-400">Error fetching students. Please try again.</li>');
                            title.text('Selected Class');
                            count.text('0 Students');
                        }
                    });
                } else {
                    container.empty();
                    container.append('<li class="py-2 text-gray-700 dark:text-gray-400">Select a class and academic year to view students.</li>');
                    title.text('Select a Class');
                    count.text('0 Students');
                }
            }

            $('#source_class_id, #source_academic_year').change(updateStudentList);

            // Trigger change event on page load if both fields are pre-selected
            if ($('#source_class_id').val() && $('#source_academic_year').val()) {
                updateStudentList();
            }
        });
    </script>
</body>
</html>