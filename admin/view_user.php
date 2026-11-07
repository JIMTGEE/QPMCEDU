<?php
ob_start();
session_start();

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/error_log.txt');

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to view_user.php by user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
    header("Location: /login.php");
    exit();
}

// Include database connection and logging function
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Verify database connection
if (!isset($conn) || !$conn) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    die("Database connection failed. Please try again later.");
}

$success_msg = $error_msg = "";
$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    error_log("Invalid user ID provided: $user_id");
    $error_msg = "Invalid user ID.";
} else {
    try {
        // Fetch user details with advisor and department names
        $sql = "SELECT u.id, u.name, u.email, u.role, u.status, u.phone, u.gender, u.address, 
                       u.admission_date, u.last_login, u.last_ip, u.national_id, u.force_password_change, 
                       u.advisor_id, u.department_id, u.profile_picture, 
                       d.name AS department_name, a.name AS advisor_name
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN users a ON u.advisor_id = a.id
                WHERE u.id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Log query result for debugging
        error_log("Querying user_id: $user_id, Result: " . json_encode($user));

        if (!$user) {
            error_log("User not found for ID: $user_id");
            $error_msg = "User not found or no data available for ID: $user_id.";
        } else {
            // Log activity
            logActivity($conn, $_SESSION['user_id'], "Viewed User Profile", "Viewed user: {$user['name']} (ID: $user_id)");

            // Normalize profile picture path
            if (!empty($user['profile_picture'])) {
                $user['profile_picture'] = str_replace(['uploads/profiles/', 'Uploads/profiles/'], 'Uploads/profiles/', $user['profile_picture']);
                $user['profile_picture'] = str_replace('.jfif', '.jpg', $user['profile_picture']);
                $user['profile_picture'] = preg_replace('/^\/+/', '/', '/' . ltrim($user['profile_picture'], '/'));
                $file_path = $_SERVER['DOCUMENT_ROOT'] . $user['profile_picture'];
                if (!file_exists($file_path) || filesize($file_path) < 100) {
                    error_log("Profile picture not found or invalid for user_id: {$user['id']}, path: {$user['profile_picture']}");
                    $user['profile_picture'] = '/assets/images/default_profile.png';
                } else {
                    $image_info = @getimagesize($file_path);
                    if ($image_info === false || !in_array($image_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF])) {
                        error_log("Invalid image file for user_id: {$user['id']}, path: {$user['profile_picture']}");
                        $user['profile_picture'] = '/assets/images/default_profile.png';
                    }
                }
            } else {
                $user['profile_picture'] = '/assets/images/default_profile.png';
            }

            // Ensure role is set
            $user['role'] = isset($user['role']) ? $user['role'] : 'unknown';
            $user['force_password_change'] = isset($user['force_password_change']) ? $user['force_password_change'] : 0;

            // Fetch classes for students
            $classes = [];
            if ($user['role'] === 'student') {
                $sql = "SELECT c.id, c.class_name, sc.academic_year, d.name AS department_name
                        FROM student_classes sc
                        JOIN classes c ON sc.class_id = c.id
                        JOIN departments d ON c.department_id = d.id
                        WHERE sc.user_id = ? AND sc.academic_year = '2024/2025'";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $classes[] = $row;
                }
                $stmt->close();
                error_log("Student classes for user_id: $user_id, Result: " . json_encode($classes));
            }

            // Fetch subjects for teachers
            $subjects = [];
            if ($user['role'] === 'teacher') {
                $sql = "SELECT s.id, s.subject_name, s.subject_code, d.name AS department_name
                        FROM subjects s
                        JOIN departments d ON s.department_id = d.id
                        WHERE s.teacher_id = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $subjects[] = $row;
                }
                $stmt->close();
                error_log("Teacher subjects for user_id: $user_id, Result: " . json_encode($subjects));
            }

            // Fetch linked students for parents
            $linked_students = [];
            if ($user['role'] === 'parent') {
                $sql = "SELECT u.id, u.name, u.email
                        FROM parent_student_mapping psm
                        JOIN users u ON psm.student_id = u.id
                        WHERE psm.parent_id = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $linked_students[] = $row;
                }
                $stmt->close();
                error_log("Linked students for parent user_id: $user_id, Result: " . json_encode($linked_students));
            }

            // Fetch recent user logs with names instead of IDs
            $logs = [];
            $sql = "SELECT ul.action, ul.description, ul.ip_address, ul.user_agent, ul.created_at,
                           u.name AS user_name, c.class_name
                    FROM user_logs ul
                    LEFT JOIN users u ON ul.user_id = u.id
                    LEFT JOIN classes c ON ul.description LIKE CONCAT('%Class ID: ', c.id, '%')
                    WHERE ul.user_id = ?
                    ORDER BY ul.created_at DESC
                    LIMIT 10";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                // Replace IDs with names in description
                if ($row['class_name'] && preg_match('/Class ID: \d+/', $row['description'], $matches)) {
                    $row['description'] = str_replace($matches[0], "Class: {$row['class_name']}", $row['description']);
                }
                if ($row['user_name'] && preg_match('/User ID: \d+/', $row['description'], $matches)) {
                    $row['description'] = str_replace($matches[0], "User: {$row['user_name']}", $row['description']);
                }
                $logs[] = $row;
            }
            $stmt->close();
            error_log("Activity logs for user_id: $user_id, Result: " . json_encode($logs));
        }
    } catch (Exception $e) {
        error_log("Error fetching user data for user_id: $user_id, Error: " . $e->getMessage());
        $error_msg = "Error loading user data: " . htmlspecialchars($e->getMessage());
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View user details in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, user profile, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School User Profile">
    <meta property="og:description" content="Admin interface for viewing user details in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/view_user.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School User Profile">
    <meta name="twitter:description" content="Admin interface for viewing user details in QueensPa School.">
    <title>User Profile - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            cursor: pointer;
        }
        .table-responsive {
            max-height: 300px;
            overflow-y: auto;
            border-radius: 8px;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) #f8f9fa;
        }
        .table th, .table td {
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table th {
            background-color: #f1f1f1;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(255, 69, 0, 0.1);
        }
        .profile-img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid var(--primary-color);
            transition: transform 0.3s ease;
        }
        .profile-img:hover {
            transform: scale(1.1);
        }
        .user-info {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1rem;
            font-size: 1.1rem;
        }
        .user-info dt {
            font-weight: bold;
            color: #333;
        }
        .user-info dd {
            color: #6c757d;
        }
        .no-data {
            color: #6c757d;
            font-style: italic;
            text-align: center;
        }
        .btn {
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        @media (max-width: 768px) {
            .user-info {
                grid-template-columns: 1fr;
            }
            .table-responsive {
                max-height: 200px;
            }
            .table th, .table td {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="content">
        <main class="container mx-auto my-6 px-4 sm:px-6 lg:px-8 max-w-7xl">
            <h2 class="text-2xl font-semibold mb-6 animate__animated animate__fadeIn">
                <i class="fas fa-user-circle mr-2"></i>User Profile
            </h2>
            <?php if ($error_msg): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded animate__animated animate__shakeX" role="alert">
                    <?php echo htmlspecialchars($error_msg); ?>
                    <button type="button" class="float-right text-red-700 hover:text-red-900" onclick="this.parentElement.remove()">✕</button>
                </div>
            <?php elseif (isset($user) && is_array($user)): ?>
                <div class="card animate__animated animate__fadeIn">
                    <div class="card-header px-6 py-4 flex justify-between items-center">
                        <h3 class="mb-0 text-lg font-semibold"><?php echo htmlspecialchars($user['name'] ?? 'Unknown User'); ?></h3>
                        <span class="badge bg-<?php echo (isset($user['status']) && $user['status'] === 'active') ? 'green-500' : 'gray-500'; ?> text-white px-2 py-1 rounded">
                            <?php echo ucfirst(htmlspecialchars($user['status'] ?? 'inactive')); ?>
                        </span>
                    </div>
                    <div class="card-body p-6">
                        <div class="text-center mb-6">
                            <img src="<?php echo htmlspecialchars($user['profile_picture']) . '?v=' . time(); ?>" 
                                 alt="Profile Picture" class="profile-img mx-auto" loading="lazy">
                        </div>
                        <dl class="user-info">
                            <dt>Name</dt>
                            <dd><?php echo htmlspecialchars($user['name'] ?? 'N/A'); ?></dd>
                            <dt>Email</dt>
                            <dd><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></dd>
                            <dt>Role</dt>
                            <dd><?php echo ucfirst(htmlspecialchars($user['role'] ?? 'N/A')); ?></dd>
                            <dt>Status</dt>
                            <dd><?php echo ucfirst(htmlspecialchars($user['status'] ?? 'N/A')); ?></dd>
                            <dt>Phone</dt>
                            <dd><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></dd>
                            <dt>Gender</dt>
                            <dd><?php echo ucfirst(htmlspecialchars($user['gender'] ?? 'N/A')); ?></dd>
                            <dt>Address</dt>
                            <dd><?php echo htmlspecialchars($user['address'] ?? 'N/A'); ?></dd>
                            <dt>Department</dt>
                            <dd><?php echo htmlspecialchars($user['department_name'] ?? 'None'); ?></dd>
                            <dt>National ID</dt>
                            <dd><?php echo htmlspecialchars($user['national_id'] ?? 'N/A'); ?></dd>
                            <dt>Admission Date</dt>
                            <dd><?php echo htmlspecialchars($user['admission_date'] ?? 'N/A'); ?></dd>
                            <dt>Advisor</dt>
                            <dd><?php echo htmlspecialchars($user['advisor_name'] ?? 'None'); ?></dd>
                            <dt>Force Password Change</dt>
                            <dd><?php echo isset($user['force_password_change']) && $user['force_password_change'] ? 'Yes' : 'No'; ?></dd>
                            <dt>Last Login</dt>
                            <dd><?php echo htmlspecialchars($user['last_login'] ?? 'Never'); ?></dd>
                            <dt>Last IP</dt>
                            <dd><?php echo htmlspecialchars($user['last_ip'] ?? 'N/A'); ?></dd>
                        </dl>
                        <div class="flex flex-wrap gap-3 justify-center mt-6">
                            <a href="/admin/edit_user.php?id=<?php echo (int)($user['id'] ?? 0); ?>" 
                               class="bg-orange-500 hover:bg-orange-600 text-white font-semibold py-2 px-4 rounded flex items-center">
                                <i class="fas fa-edit mr-2"></i>Edit User
                            </a>
                            <a href="/admin/delete_user.php?id=<?php echo (int)($user['id'] ?? 0); ?>" 
                               class="bg-red-500 hover:bg-red-600 text-white font-semibold py-2 px-4 rounded flex items-center"
                               onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                <i class="fas fa-trash mr-2"></i>Delete User
                            </a>
                            <a href="/admin/user_management.php" 
                               class="bg-gray-500 hover:bg-gray-600 text-white font-semibold py-2 px-4 rounded flex items-center">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Users
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (isset($user['role']) && $user['role'] === 'student' && !empty($classes)): ?>
                    <div class="card animate__animated animate__fadeIn" id="classes-card">
                        <div class="card-header px-6 py-4 flex justify-between items-center" data-bs-toggle="collapse" data-bs-target="#classes-collapse" aria-expanded="true">
                            <h4 class="mb-0 text-base font-semibold">Enrolled Classes</h4>
                            <span class="text-sm"><?php echo count($classes); ?> Class<?php echo count($classes) !== 1 ? 'es' : ''; ?> <i class="fas fa-chevron-down"></i></span>
                        </div>
                        <div class="card-body p-6 collapse show" id="classes-collapse">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered w-full text-sm">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-2">Class Name</th>
                                            <th class="px-3 py-2">Department</th>
                                            <th class="px-3 py-2">Academic Year</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($classes as $class): ?>
                                            <tr>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($class['class_name'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($class['department_name'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($class['academic_year'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($user['role']) && $user['role'] === 'teacher' && !empty($subjects)): ?>
                    <div class="card animate__animated animate__fadeIn" id="subjects-card">
                        <div class="card-header px-6 py-4 flex justify-between items-center" data-bs-toggle="collapse" data-bs-target="#subjects-collapse" aria-expanded="true">
                            <h4 class="mb-0 text-base font-semibold">Taught Subjects</h4>
                            <span class="text-sm"><?php echo count($subjects); ?> Subject<?php echo count($subjects) !== 1 ? 's' : ''; ?> <i class="fas fa-chevron-down"></i></span>
                        </div>
                        <div class="card-body p-6 collapse show" id="subjects-collapse">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered w-full text-sm">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-2">Subject Name</th>
                                            <th class="px-3 py-2">Subject Code</th>
                                            <th class="px-3 py-2">Department</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subjects as $subject): ?>
                                            <tr>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($subject['subject_name'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($subject['subject_code'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($subject['department_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($user['role']) && $user['role'] === 'parent' && !empty($linked_students)): ?>
                    <div class="card animate__animated animate__fadeIn" id="students-card">
                        <div class="card-header px-6 py-4 flex justify-between items-center" data-bs-toggle="collapse" data-bs-target="#students-collapse" aria-expanded="true">
                            <h4 class="mb-0 text-base font-semibold">Linked Students</h4>
                            <span class="text-sm"><?php echo count($linked_students); ?> Student<?php echo count($linked_students) !== 1 ? 's' : ''; ?> <i class="fas fa-chevron-down"></i></span>
                        </div>
                        <div class="card-body p-6 collapse show" id="students-collapse">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered w-full text-sm">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-2">Student Name</th>
                                            <th class="px-3 py-2">Email</th>
                                            <th class="px-3 py-2">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($linked_students as $student): ?>
                                            <tr>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($student['name'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2">
                                                    <a href="/admin/view_user.php?id=<?php echo (int)($student['id'] ?? 0); ?>" 
                                                       class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-1 px-2 rounded text-xs">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card animate__animated animate__fadeIn" id="logs-card">
                    <div class="card-header px-6 py-4 flex justify-between items-center" data-bs-toggle="collapse" data-bs-target="#logs-collapse" aria-expanded="true">
                        <h4 class="mb-0 text-base font-semibold">Recent Activity Logs</h4>
                        <span class="text-sm"><?php echo count($logs); ?> Log<?php echo count($logs) !== 1 ? 's' : ''; ?> <i class="fas fa-chevron-down"></i></span>
                    </div>
                    <div class="card-body p-6 collapse show" id="logs-collapse">
                        <?php if (empty($logs)): ?>
                            <p class="no-data">No activity logs found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered w-full text-sm">
                                    <thead>
                                        <tr>
                                            <th class="px-3 py-2">Action</th>
                                            <th class="px-3 py-2">Description</th>
                                            <th class="px-3 py-2">IP Address</th>
                                            <th class="px-3 py-2">User Agent</th>
                                            <th class="px-3 py-2">Timestamp</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($log['action'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($log['description'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($log['user_agent'] ?? 'N/A'); ?></td>
                                                <td class="px-3 py-2"><?php echo htmlspecialchars($log['created_at'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded animate__animated animate__shakeX" role="alert">
                    No user data available for ID: <?php echo htmlspecialchars($user_id); ?>.
                    <button type="button" class="float-right text-red-700 hover:text-red-900" onclick="this.parentElement.remove()">✕</button>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <?php $conn->close(); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Toggle collapse icon
            const headers = document.querySelectorAll('.card-header');
            headers.forEach(header => {
                header.addEventListener('click', () => {
                    const icon = header.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('fa-chevron-down');
                        icon.classList.toggle('fa-chevron-up');
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>