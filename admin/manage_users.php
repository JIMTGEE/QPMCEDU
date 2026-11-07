<?php
ob_start();
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
    error_log("Connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}

$success_msg = isset($_GET['success']) ? $_GET['success'] : "";
$error_msg = "";
$users = [];

$select_sql = "SELECT id, name, email, role, status, profile_picture, phone_number, national_id, admission_date, department, gender, phone, address, last_login, last_ip FROM users ORDER BY created_at DESC";
$result = mysqli_query($conn, $select_sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
} else {
    $error_msg = "Error fetching users: " . mysqli_error($conn);
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage users in QueensPa School, including adding, editing, viewing, and deleting user accounts.">
    <meta name="keywords" content="QueensPa School, user management, admin dashboard, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <!-- Open Graph -->
    <meta property="og:title" content="QueensPa School User Management">
    <meta property="og:description" content="Admin interface for managing user accounts in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/manage_users.php">
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School User Management">
    <meta name="twitter:description" content="Admin interface for managing user accounts in QueensPa School.">
    <title>Manage Users - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Custom Styles -->
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
        .btn-primary, .btn-success, .btn-warning, .btn-danger, .btn-info {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-success:hover, .btn-warning:hover, .btn-danger:hover, .btn-info:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
        }
        .table thead {
            background: var(--primary-color);
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .action-links .btn {
            min-width: 100px;
        }
        .profile-img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
        }
        @media (max-width: 767.98px) {
            .action-links {
                flex-direction: column;
                gap: 0.5rem;
            }
            .action-links .btn {
                width: 100%;
                min-width: auto;
            }
            .profile-img {
                width: 40px;
                height: 40px;
            }
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">👤 User Management Dashboard</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <p class="text-muted mb-0">All user data and activity at your fingertips.</p>
                        <a href="add_user.php" class="btn btn-success"><i class="fas fa-plus"></i> Add New User</a>
                    </div>
                    <!-- Action Links -->
                    <div class="d-flex flex-wrap gap-2 mb-3 action-links">
                        <a href="bulk_upload.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-upload"></i> Bulk Upload</a>
                        <a href="user_logs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chart-line"></i> View Logs</a>
                        <a href="notifications.php" class="btn btn-outline-info btn-sm"><i class="fas fa-bell"></i> Notifications</a>
                        <a href="user_permissions.php" class="btn btn-outline-dark btn-sm"><i class="fas fa-shield-alt"></i> Permissions</a>
                        <a href="user_settings.php" class="btn btn-outline-warning btn-sm"><i class="fas fa-cog"></i> Settings</a>
                    </div>
                    <!-- Users Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Profile Picture</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Gender</th>
                                    <th>Admission Date</th>
                                    <th>Phone</th>
                                    <th>Last Login</th>
                                    <th>IP Address</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="12" class="text-center text-muted">No users found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($user['id']) ?></td>
                                            <td>
                                                <img src="<?= $user['profile_picture'] ? '/' . htmlspecialchars($user['profile_picture']) : '/assets/images/default_profile.png' ?>" alt="Profile" class="profile-img">
                                            </td>
                                            <td>
                                                <a href="view_user.php?id=<?= $user['id'] ?>" class="text-decoration-none fw-semibold">
                                                    <?= htmlspecialchars($user['name']) ?>
                                                </a>
                                            </td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td><?= ucfirst(htmlspecialchars($user['role'])) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                    <?= ucfirst(htmlspecialchars($user['status'])) ?>
                                                </span>
                                            </td>
                                            <td><?= ucfirst(htmlspecialchars($user['gender'] ?? 'N/A')) ?></td>
                                            <td><?= htmlspecialchars($user['admission_date'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($user['phone'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($user['last_login'] ?? 'Never') ?></td>
                                            <td><?= htmlspecialchars($user['last_ip'] ?? 'N/A') ?></td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <a href="view_user.php?id=<?= $user['id'] ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> View</a>
                                                    <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                                    <a href="delete_user.php?id=<?= $user['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i> Delete</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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
</body>
</html>
<?php ob_end_flush(); ?>