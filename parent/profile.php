<?php
// profile.php - Upgraded Parent Profile Page for QPMS.com (Schema-Aligned)
// Adjustments: Use 'profile_picture' and 'phone_number' from users table,
// prepared statements, edit form with validation/CSRF, password change,
// profile pic upload (resize/security), consistent UI, alerts.
// Upgrades: Fix deprecation warnings (null handling for htmlspecialchars & strtotime),
// auto-update 'updated_at' on changes, improved validation (email uniqueness),
// refetch profile after updates for accuracy, better null coalescing,
// enhanced security (file validation), responsive UI tweaks.
// Latest: Added client-side image preview for upload, remove profile pic option,
// enhanced UI with current pic display in form, consistent orange theme.
// Fix: Corrected bind_param type string ("ssssi"), updated upload dir to /Uploads/profiles/
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
// === Security & Session Validation ===
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    header("Location: /parent/login_error.php?error=unauthorized");
    exit();
}
// Generate/Validate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
// === Fetch Parent Data (Schema-Aligned) ===
function fetchProfile($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id, name, email, phone_number, profile_picture, updated_at FROM users WHERE id = ? AND role = 'parent' AND status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc();
    $stmt->close();
    // Null coalescing for safety
    $profile['name'] = $profile['name'] ?? '';
    $profile['email'] = $profile['email'] ?? '';
    $profile['phone_number'] = $profile['phone_number'] ?? '';
    $profile['profile_picture'] = $profile['profile_picture'] ?? null;
    $profile['updated_at'] = $profile['updated_at'] ?? null;
    return $profile;
}
$profile = fetchProfile($conn, $_SESSION['user_id']);
if (!$profile || empty($profile['id'])) {
    header("Location: /parent/login_error.php?error=invalid_user");
    exit();
}
// === Handle Form Submission ===
$success_msg = $error_msg = '';
$upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token) {
    if (isset($_POST['change_password'])) {
        // Password change handled below
    } else {
        // Profile update
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');
        $remove_pic = isset($_POST['remove_picture']) ? 1 : 0;
        // Enhanced validation
        if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Please provide valid name and email.';
        } else {
            // Check email uniqueness (exclude self)
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_stmt->bind_param("si", $email, $_SESSION['user_id']);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error_msg = 'Email already in use by another account.';
                $check_stmt->close();
            } else {
                $check_stmt->close();
                // Handle profile pic upload or removal
                $profile_picture = $profile['profile_picture'];
                if ($remove_pic && $profile_picture) {
                    if (file_exists($upload_dir . $profile_picture)) {
                        unlink($upload_dir . $profile_picture);
                    }
                    $profile_picture = null;
                } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['profile_picture'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png'];
                    if (in_array($ext, $allowed_exts) && $file['size'] <= 2 * 1024 * 1024) {
                        // Security: Verify MIME type
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);
                        $allowed_mimes = ['image/jpeg', 'image/png'];
                        if (in_array($mime, $allowed_mimes)) {
                            $new_name = $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                            $target = $upload_dir . $new_name;
                            if (move_uploaded_file($file['tmp_name'], $target)) {
                                // Simple resize (using GD) - enhanced error handling
                                $img = null;
                                if ($ext === 'jpg' || $ext === 'jpeg') {
                                    $img = imagecreatefromjpeg($target);
                                } elseif ($ext === 'png') {
                                    $img = imagecreatefrompng($target);
                                }
                                if ($img) {
                                    $width = imagesx($img);
                                    $height = imagesy($img);
                                    $new_width = 200;
                                    $new_height = 200;
                                    $resized = imagecreatetruecolor($new_width, $new_height);
                                    // Preserve transparency for PNG
                                    if ($ext === 'png') {
                                        imagealphablending($resized, false);
                                        imagesavealpha($resized, true);
                                        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                                        imagefill($resized, 0, 0, $transparent);
                                    }
                                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                                    if ($ext === 'jpg' || $ext === 'jpeg') {
                                        imagejpeg($resized, $target, 90);
                                    } else {
                                        imagepng($resized, $target, 9);
                                    }
                                    imagedestroy($img);
                                    imagedestroy($resized);
                                    // Delete old pic
                                    if ($profile_picture && file_exists($upload_dir . $profile_picture)) {
                                        unlink($upload_dir . $profile_picture);
                                    }
                                    $profile_picture = $new_name;
                                } else {
                                    $error_msg = 'Image processing failed. Please try a different file.';
                                    unlink($target); // Cleanup
                                }
                            } else {
                                $error_msg = 'Upload failed. Check permissions.';
                            }
                        } else {
                            $error_msg = 'Invalid image type. Only JPG/PNG allowed.';
                        }
                    } else {
                        $error_msg = 'Invalid image file (JPG/PNG, <2MB).';
                    }
                }
                if (empty($error_msg)) {
                    // Update profile with updated_at - Fixed bind_param types to "ssssi"
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone_number = ?, profile_picture = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("ssssi", $name, $email, $phone_number, $profile_picture, $_SESSION['user_id']);
                    if ($stmt->execute()) {
                        $_SESSION['user_name'] = $name;
                        $success_msg = 'Profile updated successfully!';
                        // Refetch for accurate updated_at
                        $profile = fetchProfile($conn, $_SESSION['user_id']);
                        logActivity($conn, $_SESSION['user_id'], "Updated profile", "Name: $name, Email: $email");
                    } else {
                        $error_msg = 'Update failed. Try again.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}
// === Handle Password Change ===
if (isset($_POST['change_password']) && $_POST['csrf_token'] === $csrf_token) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    if (empty($current_pass) || empty($new_pass) || empty($confirm_pass) || $new_pass !== $confirm_pass || strlen($new_pass) < 8) {
        $error_msg = $error_msg ?: 'Password must be 8+ chars, match confirmation, and meet security standards.';
    } else {
        // Enhanced: Check password strength (basic)
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $new_pass)) {
            $error_msg = $error_msg ?: 'Password must include uppercase, lowercase, and a number.';
        } else {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $hash = $result->fetch_assoc()['password'] ?? '';
            $stmt->close();
            if (password_verify($current_pass, $hash)) {
                $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $new_hash, $_SESSION['user_id']);
                if ($stmt->execute()) {
                    $success_msg = ($success_msg ?: '') . ' Password changed successfully!';
                    logActivity($conn, $_SESSION['user_id'], "Changed password", "User ID: " . $_SESSION['user_id']);
                } else {
                    $error_msg = ($error_msg ?: '') . ' Password update failed.';
                }
                $stmt->close();
                // Refetch profile
                $profile = fetchProfile($conn, $_SESSION['user_id']);
            } else {
                $error_msg = ($error_msg ?: '') . ' Current password incorrect.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile | QPMS.com</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --qpms-orange: #ff6b00;
            --qpms-orange-dark: #e65c00;
            --qpms-white: #ffffff;
            --qpms-gray: #212529;
            --sidebar-width: 280px;
        }
        body { background: #f8f9fa; font-family: 'Inter', sans-serif; color: var(--qpms-gray); }
        .sidebar { width: var(--sidebar-width); position: fixed; top: 0; left: 0; height: 100vh; background: var(--qpms-white); box-shadow: 2px 0 10px rgba(0,0,0,0.05); z-index: 1000; overflow-y: auto; transition: transform 0.3s ease; }
        .sidebar .logo img { height: 44px; }
        .sidebar .nav-link { color: #555; padding: 0.75rem 1.5rem; border-radius: 0.5rem; margin: 0.25rem 1rem; font-weight: 500; transition: all 0.2s; }
        .sidebar .nav-link:hover { background: #fff4e6; color: var(--qpms-orange); }
        .sidebar .nav-link.active { background: var(--qpms-orange); color: white !important; font-weight: 600; }
        .sidebar .nav-link i { width: 20px; text-align: center; }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; transition: margin 0.3s; }
        .card { border: none; border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; }
        .card-header { background: var(--qpms-orange); color: white; font-weight: 600; padding: 1rem 1.5rem; }
        .btn-orange { background: var(--qpms-orange); border: none; color: white; font-weight: 500; border-radius: 0.75rem; }
        .btn-orange:hover { background: var(--qpms-orange-dark); }
        .profile-pic { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid var(--qpms-white); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .form-label { font-weight: 600; }
        .alert { border-radius: 0.75rem; border: none; }
        .current-pic { max-width: 100px; height: auto; border-radius: 50%; display: block; margin: 0 auto 1rem; }
        .preview-pic { max-width: 100px; height: auto; border-radius: 50%; display: none; margin: 0 auto 1rem; }
        .remove-pic-btn { background: #dc3545; border: none; color: white; border-radius: 0.5rem; padding: 0.25rem 0.5rem; font-size: 0.875rem; }
        .remove-pic-btn:hover { background: #c82333; }
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .sidebar.show { transform: translateX(0); }
            .profile-pic { width: 100px; height: 100px; }
        }
    </style>
</head>
<body>
<!-- Sidebar -->
<nav class="sidebar">
    <div class="p-4">
        <div class="d-flex align-items-center mb-4 logo">
            <img src="/assets/images/logo.png" alt="QPMS" style="height: 44px;">
            <h5 class="ms-3 mb-0 fw-bold" style="color: var(--qpms-orange);">QPMS Parent</h5>
        </div>
        <hr class="text-muted">
        <ul class="nav nav-pills flex-column">
            <li class="nav-item"><a href="/parent/dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="nav-item"><a href="/parent/fees.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Fees</a></li>
            <li class="nav-item"><a href="/parent/reports.php" class="nav-link"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li class="nav-item"><a href="/parent/behavior_comments.php" class="nav-link"><i class="fas fa-comment"></i> Behavior</a></li>
            <li class="nav-item"><a href="/parent/activities.php" class="nav-link"><i class="fas fa-calendar"></i> Activities</a></li>
            <li class="nav-item"><a href="/parent/assignments.php" class="nav-link"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li class="nav-item"><a href="/parent/exams.php" class="nav-link"><i class="fas fa-book"></i> Exams</a></li>
            <li class="nav-item"><a href="/parent/library.php" class="nav-link"><i class="fas fa-book-open"></i> Library</a></li>
            <li class="nav-item"><a href="/parent/wellness.php" class="nav-link"><i class="fas fa-heart"></i> Wellness</a></li>
            <li class="nav-item"><a href="/parent/profile.php" class="nav-link active"><i class="fas fa-user"></i> Profile</a></li>
            <li class="nav-item mt-4"><a href="/logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
</nav>
<!-- Main Content -->
<main class="main-content p-4">
    <!-- Top Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-secondary d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <h1 class="h4 mb-0 fw-bold">My Profile</h1>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="/parent/dashboard.php" class="btn btn-outline-secondary rounded-pill"><i class="fas fa-home"></i> Dashboard</a>
        </div>
    </div>
    <?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_msg ?? '') ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center">
        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_msg ?? '') ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <!-- Profile Card -->
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card h-100 text-center">
                <div class="card-body">
                    <img src="<?= $profile['profile_picture'] ? '/Uploads/profiles/' . htmlspecialchars($profile['profile_picture'] ?? '') : '/assets/images/default-profile.png' ?>" alt="Profile" class="profile-pic mb-3 mx-auto">
                    <h5 class="card-title"><?= htmlspecialchars($profile['name'] ?? '') ?></h5>
                    <p class="text-muted"><?= htmlspecialchars($profile['email'] ?? '') ?></p>
                    <p class="text-muted mb-0"><?= htmlspecialchars($profile['phone_number'] ?? '') ?></p>
                    <small class="text-muted">
                        Updated: <?= $profile['updated_at'] ? date('M d, Y', strtotime($profile['updated_at'])) : 'Not set' ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <!-- Edit Profile Form -->
            <div class="card">
                <div class="card-header"><i class="fas fa-edit me-2"></i> Edit Profile</div>
                <form method="POST" enctype="multipart/form-data" id="profileForm">
                    <div class="card-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($profile['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="phone_number" class="form-control" value="<?= htmlspecialchars($profile['phone_number'] ?? '') ?>" placeholder="+1 (123) 456-7890">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Profile Picture</label>
                                <?php if ($profile['profile_picture']): ?>
                                    <img src="/Uploads/profiles/<?= htmlspecialchars($profile['profile_picture']) ?>" alt="Current Profile Picture" class="current-pic mb-2">
                                    <button type="button" class="remove-pic-btn" id="removePicBtn"><i class="fas fa-trash me-1"></i>Remove Current Picture</button>
                                <?php endif; ?>
                                <input type="file" name="profile_picture" class="form-control" id="profilePicInput" accept="image/jpeg,image/png">
                                <img id="previewPic" class="preview-pic" alt="Preview">
                                <small class="text-muted d-block mt-1">JPG/PNG, max 2MB. Square crop recommended for best fit.</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-orange"><i class="fas fa-save me-2"></i>Update Profile</button>
                    </div>
                </form>
            </div>
            <!-- Change Password -->
            <div class="card mt-4">
                <div class="card-header"><i class="fas fa-lock me-2"></i> Change Password</div>
                <form method="POST">
                    <div class="card-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="change_password" value="1">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" minlength="8" required>
                                <small class="text-muted">8+ chars: uppercase, lowercase, number</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Confirm New</label>
                                <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-orange"><i class="fas fa-key me-2"></i>Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar Toggle
    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        document.querySelector('.sidebar').classList.toggle('show');
    });
    // Auto-dismiss alerts after 5s
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    // Profile Pic Preview & Remove
    const profilePicInput = document.getElementById('profilePicInput');
    const previewPic = document.getElementById('previewPic');
    const removePicBtn = document.getElementById('removePicBtn');
    profilePicInput?.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewPic.src = e.target.result;
                previewPic.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });
    removePicBtn?.addEventListener('click', function() {
        if (confirm('Are you sure you want to remove your current profile picture?')) {
            const formData = new FormData();
            formData.append('csrf_token', '<?= $csrf_token ?>');
            formData.append('remove_picture', '1');
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(response => response.text()).then(() => {
                location.reload();
            });
        }
    });
</script>
</body>
</html>