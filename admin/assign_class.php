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
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
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

// Validate academic year format (YYYY/YYYY) - defined early so it's available right away
function validateAcademicYear($year) {
    return preg_match('/^\d{4}\/\d{4}$/', $year) && (int)substr($year, 0, 4) < (int)substr($year, 5, 4);
}

// Validate user_id exists in users table
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin' AND status = 'active'";
if ($user_check_stmt = $conn->prepare($user_check_sql)) {
    $user_check_stmt->bind_param("i", $_SESSION['user_id']);
    $user_check_stmt->execute();
    $user_check_result = $user_check_stmt->get_result();
    if ($user_check_result->num_rows === 0) {
        error_log("Invalid or inactive admin user_id: " . $_SESSION['user_id'], 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        logActivity($conn, $_SESSION['user_id'] ?? 0, 'access_denied', "Attempted to access assign_class.php without valid admin credentials");
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
logActivity($conn, $_SESSION['user_id'], 'page_access', "Admin accessed assign_class.php, IP: $ip_address, User Agent: $user_agent");

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

// FIX: figure out which academic year is actually "current" (academic_years.is_active),
// and which year the assignments panel should be filtered to. Without this, every
// historical student_classes row (every year a student was ever assigned) got shown
// at once, so a promoted/reassigned student appeared listed under both their old and
// new class.
$current_academic_year = '';
$active_year_sql = "SELECT year_label FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1";
$active_year_result = $conn->query($active_year_sql);
if ($active_year_result && $active_year_result->num_rows > 0) {
    $current_academic_year = $active_year_result->fetch_assoc()['year_label'];
    $active_year_result->free();
} elseif (!empty($academic_years)) {
    $current_academic_year = $academic_years[0];
}

$view_academic_year = $current_academic_year;
if (isset($_GET['view_year']) && validateAcademicYear($_GET['view_year'])) {
    $view_academic_year = trim($_GET['view_year']);
} elseif (isset($_POST['view_year']) && validateAcademicYear($_POST['view_year'])) {
    $view_academic_year = trim($_POST['view_year']);
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

// Fetch all active students
$students_sql = "SELECT id, name FROM users WHERE role = 'student' AND status = 'active' ORDER BY name";
$students_result = $conn->query($students_sql);
if (!$students_result) {
    error_log("Error fetching students: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    $error_msg = "Unable to fetch students. Please try again later.";
    $students = [];
} else {
    $students = $students_result->fetch_all(MYSQLI_ASSOC);
    $students_result->free();
}

// FIX: assignments for display are now filtered to a single academic_year (via prepared
// statement) instead of pulling every year at once. Reused as a helper so every
// refresh point below (assign / change / remove) stays consistent.
function fetchAssignments($conn, $academic_year) {
    $grouped = [];
    $flat = [];
    $sql = "SELECT sc.id, sc.user_id, sc.class_id, sc.academic_year, u.name as student_name, c.class_name 
            FROM student_classes sc 
            JOIN users u ON sc.user_id = u.id 
            JOIN classes c ON sc.class_id = c.id 
            WHERE u.role = 'student' AND u.status = 'active' AND sc.academic_year = ?
            ORDER BY c.class_name, u.name";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $class_name = $row['class_name'];
            if (!isset($grouped[$class_name])) {
                $grouped[$class_name] = [];
            }
            $grouped[$class_name][] = $row;
            $flat[] = $row;
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare assignments query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }
    return [$grouped, $flat];
}

$error_msg = '';
if (empty($view_academic_year)) {
    $grouped_assignments = [];
    $print_assignments = [];
    $error_msg = "No active academic year is configured. Set one in Academic Years, or pick a year to view below.";
} else {
    [$grouped_assignments, $flat_assignments] = fetchAssignments($conn, $view_academic_year);
    $print_assignments = $grouped_assignments;
}

// Initialize variables
$success_msg = "";
$success_details = [];
$failed_details = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_student'])) {
        $user_ids = isset($_POST['user_ids']) && !empty($_POST['user_ids'][0]) ? array_map('intval', explode(',', $_POST['user_ids'][0])) : [];
        $class_id = (int)($_POST['class_id'] ?? 0);
        $academic_year = trim($_POST['academic_year'] ?? '');

        // Validate inputs
        if (empty($user_ids)) {
            $error_msg = "Please select at least one student.";
        } elseif (empty($class_id)) {
            $error_msg = "Please select a class.";
        } elseif (empty($academic_year)) {
            $error_msg = "Please select an academic year.";
        } elseif (!validateAcademicYear($academic_year)) {
            $error_msg = "Invalid academic year format. Use YYYY/YYYY (e.g., 2024/2025).";
        } else {
            // Validate class_id exists
            $class_check_sql = "SELECT id FROM classes WHERE id = ?";
            if ($class_check_stmt = $conn->prepare($class_check_sql)) {
                $class_check_stmt->bind_param("i", $class_id);
                $class_check_stmt->execute();
                if ($class_check_stmt->get_result()->num_rows === 0) {
                    $error_msg = "Invalid class selected.";
                    $class_check_stmt->close();
                } else {
                    $class_check_stmt->close();
                    $conn->begin_transaction();
                    try {
                        $success_count = 0;
                        $insert_sql = "INSERT INTO student_classes (user_id, class_id, academic_year, created_at) VALUES (?, ?, ?, NOW())";
                        $check_sql = "SELECT sc.id, c.class_name FROM student_classes sc JOIN classes c ON sc.class_id = c.id WHERE sc.user_id = ? AND sc.academic_year = ?";
                        $insert_stmt = $conn->prepare($insert_sql);
                        $check_stmt = $conn->prepare($check_sql);

                        if (!$insert_stmt || !$check_stmt) {
                            throw new Exception("Failed to prepare query: " . $conn->error);
                        }

                        // Map user IDs to names for detailed feedback
                        $student_names = array_column($students, 'name', 'id');

                        foreach ($user_ids as $user_id) {
                            if ($user_id <= 0) {
                                $failed_details[] = "Invalid user ID: $user_id";
                                continue;
                            }

                            if (!isset($student_names[$user_id])) {
                                $failed_details[] = "Student ID $user_id not found or not active.";
                                continue;
                            }

                            // Check if student is already assigned
                            $check_stmt->bind_param("is", $user_id, $academic_year);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            if ($check_result->num_rows > 0) {
                                $row = $check_result->fetch_assoc();
                                $existing_class = $row['class_name'];
                                $failed_details[] = "Student " . htmlspecialchars($student_names[$user_id]) . " (ID: $user_id) already assigned to class '$existing_class' for $academic_year.";
                                continue;
                            }

                            // Insert assignment
                            $insert_stmt->bind_param("iis", $user_id, $class_id, $academic_year);
                            if ($insert_stmt->execute()) {
                                $success_count++;
                                $success_details[] = "Student " . htmlspecialchars($student_names[$user_id]) . " (ID: $user_id) assigned successfully.";
                                // Log action
                                $admin_id = $_SESSION['user_id'];
                                $action = "Assigned Student to Class";
                                $description = "Student ID: $user_id, Class ID: $class_id, Academic Year: $academic_year";
                                logActivity($conn, $admin_id, $action, $description);
                            } else {
                                $failed_details[] = "Failed to assign student " . htmlspecialchars($student_names[$user_id]) . " (ID: $user_id): " . $insert_stmt->error;
                            }
                        }

                        $insert_stmt->close();
                        $check_stmt->close();

                        // FIX: view the year that was just assigned into, and re-fetch with the
                        // year-filtered helper instead of the old unfiltered query.
                        $view_academic_year = $academic_year;
                        [$grouped_assignments, $flat_assignments] = fetchAssignments($conn, $view_academic_year);
                        $print_assignments = $grouped_assignments;

                        $conn->commit();
                        if ($success_count > 0) {
                            $success_msg = "$success_count student" . ($success_count > 1 ? "s" : "") . " assigned to class successfully!";
                        }
                        if (!empty($failed_details)) {
                            $error_msg = "Some assignments failed.";
                        } elseif ($success_count === 0) {
                            $error_msg = "No students were assigned.";
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error_msg = "Error assigning students: " . ($e->getCode() == 1062 ? "Database conflict detected." : $e->getMessage());
                        error_log("Assignment error for class_id $class_id, academic_year $academic_year: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                        $failed_details[] = $e->getMessage();
                    }
                }
            } else {
                error_log("Failed to prepare class check query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                $error_msg = "System error: Unable to validate class.";
            }
        }
    } elseif (isset($_POST['change_class'])) {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        $new_class_id = (int)($_POST['new_class_id'] ?? 0);
        $academic_year = trim($_POST['academic_year'] ?? '');

        // Validate inputs
        if (empty($assignment_id)) {
            $error_msg = "Invalid assignment ID.";
        } elseif (empty($new_class_id)) {
            $error_msg = "Please select a new class.";
        } elseif (empty($academic_year)) {
            $error_msg = "Please select an academic year.";
        } elseif (!validateAcademicYear($academic_year)) {
            $error_msg = "Invalid academic year format. Use YYYY/YYYY (e.g., 2024/2025).";
        } else {
            $conn->begin_transaction();
            try {
                // Validate new_class_id exists
                $class_check_sql = "SELECT id FROM classes WHERE id = ?";
                if ($class_check_stmt = $conn->prepare($class_check_sql)) {
                    $class_check_stmt->bind_param("i", $new_class_id);
                    $class_check_stmt->execute();
                    if ($class_check_stmt->get_result()->num_rows === 0) {
                        throw new Exception("Invalid new class selected.");
                    }
                    $class_check_stmt->close();
                } else {
                    throw new Exception("Failed to prepare class check query: " . $conn->error);
                }

                // Check if student is already assigned in the target academic year
                $check_sql = "SELECT sc.id, c.class_name FROM student_classes sc JOIN classes c ON sc.class_id = c.id 
                              WHERE sc.user_id = (SELECT user_id FROM student_classes WHERE id = ?) AND sc.academic_year = ? AND sc.id != ?";
                if ($check_stmt = $conn->prepare($check_sql)) {
                    $check_stmt->bind_param("isi", $assignment_id, $academic_year, $assignment_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    if ($check_result->num_rows > 0) {
                        $row = $check_result->fetch_assoc();
                        $existing_class = $row['class_name'];
                        throw new Exception("Student is already assigned to class '$existing_class' for $academic_year.");
                    }
                    $check_stmt->close();
                } else {
                    throw new Exception("Failed to prepare assignment check query: " . $conn->error);
                }

                // Update assignment
                $update_sql = "UPDATE student_classes SET class_id = ?, academic_year = ? WHERE id = ?";
                if ($update_stmt = $conn->prepare($update_sql)) {
                    $update_stmt->bind_param("isi", $new_class_id, $academic_year, $assignment_id);
                    $update_stmt->execute();
                    if ($update_stmt->affected_rows === 0) {
                        throw new Exception("No assignment found with ID: $assignment_id");
                    }
                    $update_stmt->close();

                    // Log action
                    $admin_id = $_SESSION['user_id'];
                    $action = "Changed Student Class Assignment";
                    $description = "Assignment ID: $assignment_id, New Class ID: $new_class_id, Academic Year: $academic_year";
                    logActivity($conn, $admin_id, $action, $description);

                    // FIX: view the year the assignment was moved to, and re-fetch filtered.
                    $view_academic_year = $academic_year;
                    [$grouped_assignments, $flat_assignments] = fetchAssignments($conn, $view_academic_year);
                    $print_assignments = $grouped_assignments;

                    $conn->commit();
                    $success_msg = "Student class updated successfully!";
                    $_POST = [];
                } else {
                    throw new Exception("Failed to prepare update query: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error updating class: " . $e->getMessage();
                error_log("Update class error for assignment_id $assignment_id: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
            }
        }
    } elseif (isset($_POST['remove_assignment'])) {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        if ($assignment_id <= 0) {
            $error_msg = "Invalid assignment ID.";
        } else {
            $conn->begin_transaction();
            try {
                // Delete assignment
                $delete_sql = "DELETE FROM student_classes WHERE id = ?";
                if ($delete_stmt = $conn->prepare($delete_sql)) {
                    $delete_stmt->bind_param("i", $assignment_id);
                    $delete_stmt->execute();
                    if ($delete_stmt->affected_rows === 0) {
                        throw new Exception("No assignment found with ID: $assignment_id");
                    }
                    $delete_stmt->close();

                    // Log action
                    $admin_id = $_SESSION['user_id'];
                    $action = "Removed Student Class Assignment";
                    $description = "Assignment ID: $assignment_id";
                    logActivity($conn, $admin_id, $action, $description);

                    // FIX: re-fetch with the filtered helper, keeping whatever year is being viewed.
                    [$grouped_assignments, $flat_assignments] = fetchAssignments($conn, $view_academic_year);
                    $print_assignments = $grouped_assignments;

                    $conn->commit();
                    $success_msg = "Assignment removed successfully!";
                    $_POST = [];
                } else {
                    throw new Exception("Failed to prepare delete query: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error removing assignment: " . $e->getMessage();
                error_log("Remove assignment error for assignment_id $assignment_id: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
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
    <meta name="description" content="Assign, change, or remove student-class assignments in Queen's Park Montessori School management system.">
    <meta name="keywords" content="Queen's Park Montessori, student class assignments, admin dashboard, school management">
    <meta name="author" content="Queen's Park Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Queen's Park Montessori Assign Classes">
    <meta property="og:description" content="Admin interface for managing student-class assignments in Queen's Park Montessori School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmcedu.com/admin/assign_class.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Queen's Park Montessori Assign Classes">
    <meta name="twitter:description" content="Admin interface for managing student-class assignments in Queen's Park Montessori School.">
    <title>Assign Classes - Queen's Park Montessori</title>
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
        .student-select-modal {
            max-height: 400px;
            overflow-y: auto;
        }
        .student-checkbox:checked + label {
            background-color: var(--primary-500);
            color: white;
        }
        .error-details, .success-details {
            max-height: 150px;
            overflow-y: auto;
            padding: 0.5rem;
            border-radius: 0.375rem;
        }
        .error-details {
            background-color: #fee2e2;
        }
        .success-details {
            background-color: #d1fae5;
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
                    <h3 class="text-2xl font-bold text-primary-500">📚 Assign Classes to Students</h3>
                    <div class="space-x-4">
                        <a href="/admin/manage_student_classes.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-list mr-2"></i>View Assignments
                        </a>
                        <a href="/admin/promote_learners.php" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-up mr-2"></i>Promote Learners
                        </a>
                        <a href="/admin/archives.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-down mr-2"></i>Archives
                        </a>
                    </div>
                </div>
                <?php if (!empty($success_msg)) { ?>
                    <div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4 animate-pulse" role="alert">
                        <?php echo htmlspecialchars($success_msg); ?>
                        <?php if (!empty($success_details)) { ?>
                            <div class="success-details mt-2">
                                <ul class="list-disc pl-5">
                                    <?php foreach ($success_details as $detail) { ?>
                                        <li><?php echo htmlspecialchars($detail); ?></li>
                                    <?php } ?>
                                </ul>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
                <?php if (!empty($error_msg)) { ?>
                    <div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4 animate-pulse" role="alert">
                        <?php echo htmlspecialchars($error_msg); ?>
                        <?php if (!empty($failed_details)) { ?>
                            <div class="error-details mt-2">
                                <ul class="list-disc pl-5">
                                    <?php foreach ($failed_details as $detail) { ?>
                                        <li><?php echo htmlspecialchars($detail); ?></li>
                                    <?php } ?>
                                </ul>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
                <!-- Assign Students Form -->
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg mb-6">
                    <h4 class="text-lg font-semibold text-primary-500 mb-4">Assign Students to Class</h4>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="student_search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Students</label>
                            <input type="text" id="student_search" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" placeholder="Click to select students" readonly>
                            <input type="hidden" name="user_ids[]" id="selected_student_ids">
                        </div>
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Class</label>
                            <select name="class_id" id="class_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class) { ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo isset($_POST['class_id']) && $_POST['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label for="academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Academic Year</label>
                            <select name="academic_year" id="academic_year" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year) { ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo (isset($_POST['academic_year']) && $_POST['academic_year'] === $year) || (!isset($_POST['academic_year']) && $year === $current_academic_year) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year); ?><?php echo $year === $current_academic_year ? ' (current)' : ''; ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="md:col-span-3 text-center mt-4">
                            <button type="submit" name="assign_student" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-plus mr-2"></i>Assign Students
                            </button>
                        </div>
                    </form>
                </div>
                <!-- Current Assignments -->
                <div class="flex flex-wrap justify-between items-center mb-3 gap-2">
                    <h4 class="text-lg font-semibold text-primary-500">Current Assignments</h4>
                    <div class="flex items-center gap-3">
                        <form method="GET" class="flex items-center gap-2">
                            <label for="view_year" class="text-sm text-gray-700 dark:text-gray-300">Viewing Academic Year:</label>
                            <select name="view_year" id="view_year" onchange="this.form.submit()" class="border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                                <?php foreach ($academic_years as $year) { ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $view_academic_year === $year ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year); ?><?php echo $year === $current_academic_year ? ' (current)' : ''; ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </form>
                        <button class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg" onclick="window.print()">
                            <i class="fas fa-print mr-2"></i>Print Assignments
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php if (empty($grouped_assignments)) { ?>
                        <div class="col-span-full">
                            <div class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 p-4 rounded-lg text-center">No assignments found for <?php echo htmlspecialchars($view_academic_year); ?>.</div>
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
                                            <li class="py-2 flex justify-between items-center">
                                                <span>
                                                    <?php echo htmlspecialchars($assignment['student_name']); ?>
                                                    <small class="text-gray-500 dark:text-gray-400">(<?php echo htmlspecialchars($assignment['academic_year']); ?>)</small>
                                                </span>
                                                <div class="flex space-x-2">
                                                    <button onclick="openChangeClassModal(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['student_name']); ?>', '<?php echo htmlspecialchars($assignment['academic_year']); ?>', <?php echo $assignment['class_id']; ?>)" class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded-lg">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to remove this assignment?');" class="inline">
                                                        <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                        <button type="submit" name="remove_assignment" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded-lg">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
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
                        <p>Student-Class Assignments (<?php echo htmlspecialchars($view_academic_year); ?>) - <?php echo date('F j, Y'); ?></p>
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
                <!-- Student Selection Modal -->
                <div id="studentSelectModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center hidden">
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
                        <h4 class="text-lg font-semibold text-primary-500 mb-4">Select Students</h4>
                        <div class="flex justify-between items-center mb-4">
                            <input type="text" id="student_search_input" class="w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" placeholder="Search students...">
                            <span id="selected_count" class="ml-4 text-sm text-gray-500 dark:text-gray-400">0 selected</span>
                        </div>
                        <div class="mb-4">
                            <input type="checkbox" id="select_all" class="mr-2">
                            <label for="select_all" class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All</label>
                        </div>
                        <div class="student-select-modal">
                            <?php if (empty($students)) { ?>
                                <p class="text-gray-500 dark:text-gray-400">No students available</p>
                            <?php } else { ?>
                                <ul class="divide-y divide-gray-200 dark:divide-gray-600">
                                    <?php foreach ($students as $student) { ?>
                                        <li class="py-2">
                                            <input type="checkbox" id="student_<?php echo $student['id']; ?>" class="student-checkbox hidden" value="<?php echo $student['id']; ?>" data-name="<?php echo htmlspecialchars($student['name']); ?>">
                                            <label for="student_<?php echo $student['id']; ?>" class="block cursor-pointer p-2 rounded hover:bg-gray-100 dark:hover:bg-gray-700"><?php echo htmlspecialchars($student['name']); ?></label>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button type="button" onclick="closeStudentSelectModal()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">Cancel</button>
                            <button type="button" onclick="saveSelectedStudents()" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">Save Selection</button>
                        </div>
                    </div>
                </div>
                <!-- Change Class Modal -->
                <div id="changeClassModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center hidden">
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
                        <h4 class="text-lg font-semibold text-primary-500 mb-4">Change Class Assignment</h4>
                        <form method="POST">
                            <input type="hidden" name="assignment_id" id="modal_assignment_id">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student</label>
                                <p id="modal_student_name" class="mt-1 text-gray-900 dark:text-gray-100"></p>
                            </div>
                            <div class="mb-4">
                                <label for="new_class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">New Class</label>
                                <select name="new_class_id" id="new_class_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                    <option value="">Select New Class</option>
                                    <?php foreach ($classes as $class) { ?>
                                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label for="modal_academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Academic Year</label>
                                <select name="academic_year" id="modal_academic_year" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                    <option value="">Select Academic Year</option>
                                    <?php foreach ($academic_years as $year) { ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="flex justify-end space-x-2">
                                <button type="button" onclick="closeChangeClassModal()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">Cancel</button>
                                <button type="submit" name="change_class" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">Save Changes</button>
                            </div>
                        </form>
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

        // Student selection modal functions
        function openStudentSelectModal() {
            document.getElementById('studentSelectModal').classList.remove('hidden');
            document.getElementById('student_search_input').focus();
            updateSelectedCount();
        }

        function closeStudentSelectModal() {
            document.getElementById('studentSelectModal').classList.add('hidden');
            document.getElementById('student_search_input').value = '';
            filterStudents('');
        }

        function saveSelectedStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            const selectedNames = Array.from(checkboxes).map(cb => cb.getAttribute('data-name'));
            document.getElementById('selected_student_ids').value = selectedIds.join(',');
            document.getElementById('student_search').value = selectedNames.join(', ') || 'Click to select students';
            closeStudentSelectModal();
        }

        function filterStudents(query) {
            const students = document.querySelectorAll('.student-select-modal li');
            students.forEach(student => {
                const name = student.querySelector('label').textContent.toLowerCase();
                student.style.display = name.includes(query.toLowerCase()) ? 'block' : 'none';
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            document.getElementById('selected_count').textContent = `${checkboxes.length} selected`;
        }

        $(document).ready(function() {
            $('#student_search').on('click', openStudentSelectModal);
            $('#student_search_input').on('input', function() {
                filterStudents($(this).val());
            });
            $('#select_all').on('change', function() {
                const isChecked = this.checked;
                document.querySelectorAll('.student-checkbox').forEach(cb => {
                    if (cb.parentElement.style.display !== 'none') {
                        cb.checked = isChecked;
                    }
                });
                updateSelectedCount();
            });
            $('.student-checkbox').on('change', updateSelectedCount);
        });

        // Change class modal functions
        function openChangeClassModal(assignmentId, studentName, academicYear, classId) {
            document.getElementById('modal_assignment_id').value = assignmentId;
            document.getElementById('modal_student_name').textContent = studentName;
            document.getElementById('modal_academic_year').value = academicYear;
            document.getElementById('new_class_id').value = classId;
            document.getElementById('changeClassModal').classList.remove('hidden');
        }

        function closeChangeClassModal() {
            document.getElementById('changeClassModal').classList.add('hidden');
        }
    </script>
</body>
</html>