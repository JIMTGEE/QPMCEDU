<?php
ob_start();
session_start();

// Restrict to logged-in users
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_msg'] = "Please log in to access this page.";
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "Database connection failed. Please try again later.";
    header("Location: ../login.php");
    exit();
}

// User details
$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'teacher'; // Fallback to 'teacher' if role is unset
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];

// Generate or retrieve CSRF token
function generateCsrfToken($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $sql = "INSERT INTO csrf_tokens (user_id, token, expires_at) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("CSRF token prepare failed: " . $conn->error);
        return null;
    }
    $stmt->bind_param("iss", $user_id, $token, $expires_at);
    $stmt->execute();
    $stmt->close();
    return $token;
}

$csrf_token = generateCsrfToken($conn, $user_id);
if (!$csrf_token) {
    $_SESSION['error_msg'] = "Failed to generate session token. Please try again.";
    header("Location: dashboard.php");
    exit();
}

// Fetch user data
$user_sql = "SELECT name, email, phone, address, admission_date FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
if (!$user_stmt) {
    error_log("User query prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "Database error. Please try again later.";
    header("Location: dashboard.php");
    exit();
}
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

if (!$user) {
    // Log error
    $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                VALUES (?, 'Profile Access Error', 'User not found in database', ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("iss", $user_id, $ip_address, $user_agent);
    $log_stmt->execute();
    $log_stmt->close();
    
    $_SESSION['error_msg'] = "User profile not found. Please contact support.";
    header("Location: dashboard.php");
    exit();
}

// Initialize variables
$success_msg = $error_msg = "";
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    $token_sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $token_stmt = $conn->prepare($token_sql);
    $token_stmt->bind_param("is", $user_id, $submitted_token);
    $token_stmt->execute();
    $token_result = $token_stmt->get_result();
    if ($token_result->num_rows === 0) {
        $errors[] = "Invalid or expired session. Please try again.";
    } else {
        // Delete used token
        $delete_sql = "DELETE FROM csrf_tokens WHERE token = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("s", $submitted_token);
        $delete_stmt->execute();
        $delete_stmt->close();
    }
    $token_stmt->close();

    // Collect form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = $role !== 'student' ? trim($_POST['phone'] ?? '') : ($user['phone'] ?? '');
    $address = $role !== 'student' ? trim($_POST['address'] ?? '') : ($user['address'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if (empty($name)) {
        $errors[] = "Name is required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    } else {
        // Check email uniqueness
        $email_sql = "SELECT id FROM users WHERE email = ? AND id != ?";
        $email_stmt = $conn->prepare($email_sql);
        $email_stmt->bind_param("si", $email, $user_id);
        $email_stmt->execute();
        if ($email_stmt->get_result()->num_rows > 0) {
            $errors[] = "Email is already in use.";
        }
        $email_stmt->close();
    }
    if ($role !== 'student' && !empty($phone) && !preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
        $errors[] = "Invalid phone number format.";
    }
    if (!empty($new_password)) {
        if (strlen($new_password) < 8) {
            $errors[] = "New password must be at least 8 characters.";
        }
        if ($new_password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
        }
        // Verify current password
        $pass_sql = "SELECT password FROM users WHERE id = ?";
        $pass_stmt = $conn->prepare($pass_sql);
        $pass_stmt->bind_param("i", $user_id);
        $pass_stmt->execute();
        $pass_result = $pass_stmt->get_result()->fetch_assoc();
        if (!password_verify($current_password, $pass_result['password'] ?? '')) {
            $errors[] = "Current password is incorrect.";
        }
        $pass_stmt->close();
    }

    // Update profile if no errors
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $update_sql = "UPDATE users SET name = ?, email = ?, phone = ?, address = ?, updated_at = NOW()";
            $params = [$name, $email, $phone ?: null, $address ?: null];
            $types = "ssss";
            if (!empty($new_password)) {
                $update_sql .= ", password = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                $types .= "s";
            }
            $update_sql .= " WHERE id = ?";
            $params[] = $user_id;
            $types .= "i";

            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) {
                throw new Exception("Failed to prepare update statement: " . $conn->error);
            }
            $update_stmt->bind_param($types, ...$params);
            $update_stmt->execute();
            $update_stmt->close(); // Close inside try block

            // Log update
            $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                        VALUES (?, 'Profile Update', ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $description = "User updated profile: Name=$name, Email=$email" . 
                           ($role !== 'student' ? ", Phone=$phone, Address=$address" : "") . 
                           (!empty($new_password) ? ", Password changed" : "");
            $log_stmt->bind_param("isss", $user_id, $description, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();

            $conn->commit();
            $success_msg = "Profile updated successfully.";
            $user['name'] = $name;
            $user['email'] = $email;
            $user['phone'] = $phone;
            $user['address'] = $address;
            $_SESSION['user_name'] = $name; // Update session
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error updating profile. Please try again.";
            error_log("Profile update error: " . $e->getMessage());

            // Log error
            $log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
                        VALUES (?, 'Profile Update Error', ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $description = "Failed to update profile: " . $e->getMessage();
            $log_stmt->bind_param("isss", $user_id, $description, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();
        }
    } else {
        $error_msg = implode(" ", $errors);
    }
}

// Set SEO
$page_title = "Edit Profile - QueensPa School";
$page_description = "Update your profile details for QueensPa School teacher portal.";
$page_keywords = "QueensPa School, teacher portal, edit profile, user settings";

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/edit_user.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --orange-primary: #ff4500;
            --orange-hover: #e03e00;
            --orange-light: #ff6b00;
            --orange-bg: #fff3e0;
            --text-dark: #1a1a1a;
            --shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            --border-radius: 12px;
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, var(--orange-bg));
            color: var(--text-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 800px;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: var(--border-radius);
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-10px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
        }
        .card-header {
            background: linear-gradient(135deg, var(--orange-primary), var(--orange-light));
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            padding: 1.75rem;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }
        .btn-orange {
            background-color: var(--orange-primary) !important;
            border-color: var(--orange-primary) !important;
            border-radius: 8px;
            padding: 0.8rem 1.6rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-orange:hover {
            background-color: var(--orange-hover) !important;
            border-color: var(--orange-hover) !important;
            transform: scale(1.05);
        }
        .form-control {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 0.8rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus {
            border-color: var(--orange-primary);
            box-shadow: 0 0 0 4px rgba(255, 69, 0, 0.2);
            outline: none;
        }
        .alert {
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            align-items: center;
            animation: fadeIn 0.5s ease;
        }
        .form-label {
            font-weight: 500;
        }
        .read-only-field {
            background: #f8f9fa;
            border-color: #d1d5db;
            color: #6b7280;
        }
        @media (max-width: 767.98px) {
            .container {
                padding: 15px;
            }
            .btn-orange {
                font-size: 0.9rem;
                padding: 0.6rem 1.2rem;
            }
            .form-label {
                font-size: 0.9rem;
            }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="container my-5">
        <h1 class="text-center mb-4 animate__animated animate__fadeInDown" aria-label="Edit Profile">
            <i class="fas fa-user-edit me-2"></i>Edit Profile
        </h1>

        <div class="card shadow-lg animate__animated animate__zoomIn" role="region" aria-labelledby="profile-heading">
            <div class="card-header bg-gradient text-white">
                <h3 class="mb-0" id="profile-heading">Update Your Details</h3>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" aria-label="Edit Profile Form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="row g-4">
                        <div class="col-12">
                            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required aria-describedby="nameHelp" data-bs-toggle="tooltip" title="Your full name">
                            <div id="nameHelp" class="form-text">Enter your full name as it should appear in records.</div>
                        </div>
                        <div class="col-12">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required aria-describedby="emailHelp" data-bs-toggle="tooltip" title="Your contact email">
                            <div id="emailHelp" class="form-text">We'll use this for notifications and login.</div>
                        </div>
                        <?php if ($role !== 'student'): ?>
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" pattern="\+?[0-9]{10,15}" aria-describedby="phoneHelp" data-bs-toggle="tooltip" title="Your contact phone number (optional)">
                                <div id="phoneHelp" class="form-text">Optional, include country code if applicable.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" aria-describedby="addressHelp" data-bs-toggle="tooltip" title="Your physical address (optional)">
                                <div id="addressHelp" class="form-text">Optional, provide your current address.</div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label for="admission_date" class="form-label">Admission Date</label>
                            <input type="text" class="form-control read-only-field" id="admission_date" value="<?php echo htmlspecialchars($user['admission_date'] ?? 'N/A'); ?>" readonly aria-describedby="admissionHelp" data-bs-toggle="tooltip" title="Admission date cannot be edited">
                            <div id="admissionHelp" class="form-text">This date is set by the system and cannot be changed.</div>
                        </div>
                        <div class="col-12">
                            <h5 class="mt-4">Change Password (Optional)</h5>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" aria-describedby="currentPasswordHelp" data-bs-toggle="tooltip" title="Enter your current password if changing it">
                                    <div id="currentPasswordHelp" class="form-text">Required to change password.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" aria-describedby="newPasswordHelp" data-bs-toggle="tooltip" title="New password, minimum 8 characters">
                                    <div id="newPasswordHelp" class="form-text">Minimum 8 characters.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" aria-describedby="confirmPasswordHelp" data-bs-toggle="tooltip" title="Must match new password">
                                    <div id="confirmPasswordHelp" class="form-text">Must match new password.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-column flex-md-row mt-4">
                        <button type="submit" class="btn btn-orange" data-bs-toggle="tooltip" title="Save profile changes">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary" data-bs-toggle="tooltip" title="Return to dashboard">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(el => new bootstrap.Tooltip(el));

            // Auto-dismiss alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', (e) => {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const currentPassword = document.getElementById('current_password').value;
                if (newPassword || confirmPassword || currentPassword) {
                    if (!currentPassword) {
                        e.preventDefault();
                        alert('Current password is required to change your password.');
                        document.getElementById('current_password').classList.add('is-invalid');
                    } else if (newPassword.length < 8) {
                        e.preventDefault();
                        alert('New password must be at least 8 characters.');
                        document.getElementById('new_password').classList.add('is-invalid');
                    } else if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('Passwords do not match.');
                        document.getElementById('confirm_password').classList.add('is-invalid');
                    }
                }
            });
        });
    </script>
</body>
</html>