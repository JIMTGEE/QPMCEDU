<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

// Initialize variables
$error = '';
$success = '';
$email = '';
$attempts = isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] : 0;
$lockout_time = isset($_SESSION['lockout_time']) ? $_SESSION['lockout_time'] : 0;
$current_time = time();

// Rate limiting and security checks
$max_attempts = 5;
$lockout_duration = 300; // 5 minutes in seconds

// ── QR Code Auto-fill (from launchcards.php scan) ────────────────────────────
$qr_prefill_email = '';
$qr_prefill_pwd   = '';
$qr_scanned       = false;
$auto_login       = false;

$param_email = $_GET['email'] ?? $_GET['qpm_email'] ?? '';
$param_pass  = $_GET['pass']  ?? $_GET['qpm_pwd']   ?? '';
$param_auto  = $_GET['auto']  ?? '0';

if (!empty($param_email)) {
    $qr_prefill_email = htmlspecialchars(strip_tags(trim($param_email)), ENT_QUOTES, 'UTF-8');
    $qr_prefill_pwd   = htmlspecialchars(strip_tags(trim($param_pass)), ENT_QUOTES, 'UTF-8');
    $qr_scanned       = true;
    $email            = htmlspecialchars(strip_tags(trim($param_email)));
    if ($param_auto === '1' && !empty($qr_prefill_pwd)) {
        $auto_login = true;
    }
}

// First, check what columns exist in the users table
$available_columns = [];
$columns_check = $conn->query("SHOW COLUMNS FROM users");
if ($columns_check) {
    while ($column = $columns_check->fetch_assoc()) {
        $available_columns[] = $column['Field'];
    }
}

$has_new_columns    = in_array('account_locked',  $available_columns);
$has_avatar         = in_array('avatar',          $available_columns);
$has_status         = in_array('status',          $available_columns);
$has_failed_attempts= in_array('failed_attempts', $available_columns);
$has_login_count    = in_array('login_count',     $available_columns);
$has_remember_token = in_array('remember_token',  $available_columns);

// ── Auto-login processing (from QR scan) ─────────────────────────────────────
if ($auto_login && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_POST = [
        'email'    => $param_email,
        'password' => $param_pass,
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_POST['csrf_token'] = $_SESSION['csrf_token'];
}

// Check if user is locked out (session-based)
if ($attempts >= $max_attempts && ($current_time - $lockout_time) < $lockout_duration) {
    $remaining_time = $lockout_duration - ($current_time - $lockout_time);
    $minutes = floor($remaining_time / 60);
    $seconds = $remaining_time % 60;
    $error = "Too many failed attempts. Please try again in {$minutes}m {$seconds}s.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']) ? true : false;

    if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please refresh the page and try again.";
    } else {
        $select_fields = ['id', 'name', 'email', 'password', 'role'];
        if ($has_avatar)       $select_fields[] = 'avatar';
        if ($has_new_columns)  { $select_fields[] = 'account_locked'; $select_fields[] = 'lock_until'; $select_fields[] = 'failed_attempts'; }

        $status_condition = $has_status ? " AND status = 'active'" : "";
        $select_sql = "SELECT " . implode(', ', $select_fields) . " FROM users WHERE email = ?" . $status_condition;
        $stmt = $conn->prepare($select_sql);

        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();

            if ($user) {
                if ($has_new_columns && isset($user['account_locked']) && $user['account_locked']) {
                    if (isset($user['lock_until']) && $user['lock_until']) {
                        $lock_until = strtotime($user['lock_until']);
                        if ($lock_until > time()) {
                            $remaining = ceil(($lock_until - time()) / 60);
                            $error = "Account temporarily locked. Please try again in {$remaining} minutes.";
                        } else {
                            $unlock_stmt = $conn->prepare(
                                "UPDATE users SET account_locked = 0, lock_until = NULL" .
                                ($has_failed_attempts ? ", failed_attempts = 0" : "") . " WHERE id = ?"
                            );
                            $unlock_stmt->bind_param('i', $user['id']);
                            $unlock_stmt->execute();
                        }
                    }
                }

                if (empty($error) && password_verify($password, $user['password'])) {
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['user_id']        = $user['id'];
                    $_SESSION['user_role']      = $user['role'];
                    $_SESSION['user_name']      = $user['name'];
                    $_SESSION['user_email']     = $user['email'];
                    $_SESSION['last_activity']  = time();

                    if ($has_avatar && isset($user['avatar']) && !empty($user['avatar'])) {
                        $_SESSION['user_avatar'] = $user['avatar'];
                    }

                    session_regenerate_id(true);

                    if ($remember_me && $has_remember_token) {
                        $token  = bin2hex(random_bytes(32));
                        $expiry = time() + (30 * 24 * 60 * 60);
                        setcookie('remember_token', $token, [
                            'expires'  => $expiry,
                            'path'     => '/',
                            'domain'   => '',
                            'secure'   => true,
                            'httponly' => true,
                            'samesite' => 'Strict'
                        ]);
                        $token_expiry = date('Y-m-d H:i:s', $expiry);
                        $update_token = $conn->prepare("UPDATE users SET remember_token = ?, token_expiry = ? WHERE id = ?");
                        $update_token->bind_param('ssi', $token, $token_expiry, $user['id']);
                        $update_token->execute();
                    }

                    $last_login = date('Y-m-d H:i:s');
                    $last_ip    = $_SERVER['REMOTE_ADDR'];
                    $user_agent = $_SERVER['HTTP_USER_AGENT'];

                    if ($has_new_columns) {
                        if ($has_login_count) {
                            $update = $conn->prepare("UPDATE users SET last_login = ?, last_ip = ?, user_agent = ?, login_count = login_count + 1, failed_attempts = 0 WHERE id = ?");
                            $update->bind_param('sssi', $last_login, $last_ip, $user_agent, $user['id']);
                        } else {
                            $update = $conn->prepare("UPDATE users SET last_login = ?, last_ip = ?, user_agent = ? WHERE id = ?");
                            $update->bind_param('sssi', $last_login, $last_ip, $user_agent, $user['id']);
                        }
                    } else {
                        if (in_array('last_ip', $available_columns)) {
                            $update = $conn->prepare("UPDATE users SET last_login = ?, last_ip = ? WHERE id = ?");
                            $update->bind_param('ssi', $last_login, $last_ip, $user['id']);
                        } else {
                            $update = $conn->prepare("UPDATE users SET last_login = ? WHERE id = ?");
                            $update->bind_param('si', $last_login, $user['id']);
                        }
                    }
                    if ($update) $update->execute();

                    logLoginAttempt($conn, $user['id'], $last_ip, $user_agent, true);

                    $success      = "Login successful! Redirecting to your dashboard...";
                    $redirect_url = getDashboardURL($user['role']);
                    $redirect_delay = $auto_login ? 500 : 1500;

                    echo '<script>setTimeout(function(){window.location.href="' . $redirect_url . '";}, ' . $redirect_delay . ');</script>';

                } elseif (empty($error)) {
                    $error = handleFailedLogin($conn, $user, $email, $has_new_columns, $has_failed_attempts, $max_attempts);
                    logLoginAttempt($conn, $user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], false);
                }
            } else {
                $error = "Invalid email or password.";
                $_SESSION['login_attempts'] = isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] + 1 : 1;
            }
        } else {
            $error = "Database error. Please try again later.";
            error_log("Failed to prepare statement: " . $conn->error);
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function handleFailedLogin($conn, $user, $email, $has_new_columns, $has_failed_attempts, $max_attempts) {
    $_SESSION['login_attempts'] = isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] + 1 : 1;
    $_SESSION['lockout_time']   = time();
    $error = '';
    if ($has_new_columns && $user) {
        $attempts = $_SESSION['login_attempts'];
        if ($has_failed_attempts) {
            $update_attempts = $conn->prepare("UPDATE users SET failed_attempts = failed_attempts + 1, last_failed_attempt = NOW() WHERE id = ?");
            $update_attempts->bind_param('i', $user['id']);
            $update_attempts->execute();
        }
        if ($attempts >= $max_attempts) {
            $lock_until  = date('Y-m-d H:i:s', time() + 300);
            $lock_account = $conn->prepare("UPDATE users SET account_locked = 1, lock_until = ? WHERE id = ?");
            $lock_account->bind_param('si', $lock_until, $user['id']);
            $lock_account->execute();
            $error = "Too many failed attempts. Your account has been locked for 5 minutes.";
        } else {
            $remaining = $max_attempts - $attempts;
            $error = "Invalid email or password. {$remaining} attempts remaining.";
        }
    } else {
        $remaining = $max_attempts - $_SESSION['login_attempts'];
        $error = "Invalid email or password. {$remaining} attempts remaining.";
    }
    return $error;
}

function logLoginAttempt($conn, $user_id, $ip, $user_agent, $success) {
    try {
        $log_exists = $conn->query("SHOW TABLES LIKE 'login_logs'");
        if ($log_exists && $log_exists->num_rows > 0) {
            $columns_check = $conn->query("SHOW COLUMNS FROM login_logs");
            $log_columns   = [];
            if ($columns_check) {
                while ($col = $columns_check->fetch_assoc()) $log_columns[] = $col['Field'];
            }
            $successInt = $success ? 1 : 0;
            if (in_array('login_time', $log_columns)) {
                $log_stmt = $conn->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, success, login_time) VALUES (?, ?, ?, ?, NOW())");
                $log_stmt->bind_param('issi', $user_id, $ip, $user_agent, $successInt);
            } else {
                $log_stmt = $conn->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, ?)");
                $log_stmt->bind_param('issi', $user_id, $ip, $user_agent, $successInt);
            }
            if ($log_stmt) $log_stmt->execute();
        }
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
    }
}

function getDashboardURL($role) {
    $dashboards = [
        'admin'   => '/admin/dashboard.php',
        'teacher' => '/teacher/dashboard.php',
        'student' => '/student/dashboard.php',
        'parent'  => '/parent/dashboard.php'
    ];
    return isset($dashboards[$role]) ? $dashboards[$role] : '/dashboard.php';
}

function getGreeting() {
    $hour = date('H');
    if ($hour < 12) return 'Good Morning';
    if ($hour < 18) return 'Good Afternoon';
    return 'Good Evening';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login | QueensPa School Portal</title>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <style>
        @keyframes gradientShift { 0%{background-position:0% 50%} 50%{background-position:100% 50%} 100%{background-position:0% 50%} }
        @keyframes float         { 0%,100%{transform:translateY(0) rotate(0deg)} 50%{transform:translateY(-20px) rotate(2deg)} }
        @keyframes slideUp       { from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }
        @keyframes shake         { 0%,100%{transform:translateX(0)} 10%,30%,50%,70%,90%{transform:translateX(-5px)} 20%,40%,60%,80%{transform:translateX(5px)} }
        @keyframes pulse         { 0%,100%{opacity:1} 50%{opacity:.5} }
        @keyframes toastIn       { from{transform:translateY(-20px);opacity:0} to{transform:translateY(0);opacity:1} }
        @keyframes scannerPulse  { 0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.4)} 50%{box-shadow:0 0 0 15px rgba(245,158,11,0)} }

        .animate-shake      { animation:shake   .5s ease-in-out; }
        .animate-slide-up   { animation:slideUp .6s ease-out; }
        .animate-float      { animation:float   8s  ease-in-out infinite; }
        .animate-gradient   { background-size:200% 200%; animation:gradientShift 15s ease infinite; }
        .animate-pulse-slow { animation:pulse   3s  ease-in-out infinite; }
        .qr-toast           { animation:toastIn .4s ease-out; }
        .scanner-pulse      { animation:scannerPulse 2s infinite; }

        ::-webkit-scrollbar              { width:8px; height:8px; }
        ::-webkit-scrollbar-track        { background:#f1f1f1; border-radius:10px; }
        ::-webkit-scrollbar-thumb        { background:#f59e0b; border-radius:10px; }
        ::-webkit-scrollbar-thumb:hover  { background:#d97706; }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-box-shadow:0 0 0px 1000px #f9fafb inset;
            transition:background-color 5000s ease-in-out 0s;
        }
        .field-qr-filled { border-color:#f59e0b !important; background-color:#fffbeb !important; }

        /* Scanner modal */
        .scanner-overlay { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.85); display:flex; align-items:center; justify-content:center; padding:20px; }
        .scanner-overlay.hidden { display:none; }
        .scanner-modal  { background:#fff; border-radius:20px; width:100%; max-width:500px; overflow:hidden; box-shadow:0 25px 50px rgba(0,0,0,.3); }
        .scanner-header { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; }
        .scanner-header h3 { font-size:1.1rem; font-weight:600; display:flex; align-items:center; gap:8px; }
        .scanner-close  { background:rgba(255,255,255,.2); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; font-size:1.2rem; transition:all .2s; display:flex; align-items:center; justify-content:center; }
        .scanner-close:hover { background:rgba(255,255,255,.3); }
        .scanner-body   { padding:20px; }
        #qr-reader      { border-radius:12px; overflow:hidden; border:2px solid #e5e7eb; }
        #qr-reader video { border-radius:10px; }
        .scanner-status { text-align:center; margin-top:12px; font-size:.85rem; color:#6b7280; display:flex; align-items:center; justify-content:center; gap:6px; }
        .scanner-status.success { color:#059669; }
        .scanner-status.error   { color:#dc2626; }
        .scanner-hint   { display:flex; align-items:center; gap:12px; padding:12px 16px; background:#fef3c7; border-radius:10px; margin-top:12px; font-size:.82rem; color:#92400e; }
        .scanner-hint i { font-size:1.5rem; flex-shrink:0; }

        .scan-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:#fff; border:2px solid #f59e0b; color:#f59e0b; border-radius:12px; font-weight:600; font-size:.9rem; cursor:pointer; transition:all .3s; }
        .scan-btn:hover { background:#fef3c7; border-color:#d97706; color:#d97706; transform:translateY(-1px); }
    </style>
</head>
<body class="min-h-screen font-['Inter'] bg-gradient-to-br from-amber-50 via-orange-50 to-yellow-50 flex items-center justify-center p-4">

    <!-- Animated Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 left-0 w-full h-full bg-gradient-to-br from-amber-100/30 via-orange-100/20 to-yellow-100/30 animate-gradient"></div>
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-gradient-to-r from-amber-200/40 to-orange-200/40 rounded-full blur-3xl animate-float"></div>
        <div class="absolute top-1/3 -left-40 w-96 h-96 bg-gradient-to-r from-yellow-200/30 to-amber-200/30 rounded-full blur-3xl animate-float" style="animation-delay:-3s;"></div>
        <div class="absolute bottom-40 right-1/4 w-64 h-64 bg-gradient-to-r from-orange-200/30 to-amber-200/30 rounded-full blur-3xl animate-float" style="animation-delay:-6s;"></div>
    </div>

    <!-- QR Scan Toast -->
    <?php if ($qr_scanned): ?>
    <div id="qrToast"
         class="qr-toast fixed top-5 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3
                bg-white border-2 border-amber-400 shadow-xl rounded-2xl px-5 py-3 text-sm font-medium text-amber-800"
         role="status" aria-live="polite">
        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-amber-100 text-amber-600 flex-shrink-0">
            <i class="fas fa-qrcode text-base"></i>
        </span>
        <span>
            <strong>QR Code detected!</strong>
            <?= $auto_login ? 'Logging you in automatically...' : 'Credentials pre-filled — just tap <em>Sign In</em>.' ?>
        </span>
        <button onclick="document.getElementById('qrToast').remove()"
                class="ml-2 text-amber-400 hover:text-amber-700 transition-colors" aria-label="Dismiss">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Main Container -->
    <div class="w-full max-w-md relative z-10 animate-slide-up">

        <!-- ── BRAND LOGO above card ───────────────────────────────────── -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center gap-3 px-5 py-3 bg-white/85 backdrop-blur-lg rounded-2xl shadow-lg border border-white/60">
                <img
                    src="assets/images/logo.png"
                    alt="Queenest Paradise Montessori Complex"
                    class="h-12 w-auto object-contain"
                    onerror="this.style.display='none'; document.getElementById('logo-fallback').style.display='flex';"
                />
                <!-- Fallback if logo file is missing -->
                <span id="logo-fallback" style="display:none;"
                      class="items-center justify-center w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 text-white text-xl">
                    <i class="fas fa-graduation-cap"></i>
                </span>
                <div class="text-left leading-tight">
                    <div class="text-base font-bold text-gray-800">Queenest Paradise</div>
                    <div class="text-[10px] font-semibold text-gray-500 tracking-widest uppercase">Montessori Complex</div>
                </div>
            </div>
        </div>

        <!-- Login Card -->
        <div class="bg-white/90 backdrop-blur-xl rounded-3xl shadow-2xl overflow-hidden border border-white/50">

            <!-- ── Card Header ─────────────────────────────────────────── -->
            <div class="bg-gradient-to-r from-amber-600 via-orange-500 to-amber-500 p-6 text-center relative overflow-hidden">
                <div class="absolute inset-0 bg-white/10"></div>
                <div class="relative z-10">

                    <!-- LOGO inside header (replaces the graduation cap icon) -->
                    <div class="flex items-center justify-center mx-auto mb-4
                                w-24 h-24 rounded-2xl bg-white/20 backdrop-blur shadow-lg p-2">
                        <img
                            src="assets/images/logo.png"
                            alt="QueensPa Logo"
                            class="w-full h-full object-contain"
                            onerror="this.style.display='none'; document.getElementById('header-logo-fallback').style.display='flex';"
                        />
                        <!-- Fallback -->
                        <span id="header-logo-fallback" style="display:none;"
                              class="items-center justify-center w-full h-full text-white text-4xl">
                            <i class="fas fa-graduation-cap"></i>
                        </span>
                    </div>

                    <h1 class="text-2xl font-bold text-white mb-1">Welcome to QueensPa</h1>
                    <p class="text-amber-100 text-sm"><?php echo getGreeting(); ?>! Please sign in to continue</p>
                </div>

                <!-- Security badge -->
                <div class="absolute top-4 right-4 flex items-center space-x-2">
                    <div class="flex items-center bg-white/20 px-3 py-1.5 rounded-full backdrop-blur">
                        <i class="fas fa-shield-alt text-white text-xs mr-2"></i>
                        <span class="text-white text-xs font-medium">SSL SECURE</span>
                    </div>
                    <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                </div>

                <!-- Time -->
                <div class="absolute bottom-3 left-4 text-white/70 text-xs">
                    <i class="far fa-clock mr-1"></i><?php echo date('h:i A'); ?>
                </div>

                <?php if ($qr_scanned): ?>
                <div class="absolute bottom-3 right-4 flex items-center gap-1 bg-white/20 px-2 py-1 rounded-full text-white text-xs backdrop-blur">
                    <i class="fas fa-qrcode"></i>
                    <span><?= $auto_login ? 'Auto-login' : 'QR Login' ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Form Container ──────────────────────────────────────── -->
            <div class="p-8">

                <!-- Alerts -->
                <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-gradient-to-r from-red-50 to-amber-50 border border-red-200 flex items-start animate-shake" role="alert">
                    <i class="fas fa-exclamation-circle text-red-500 text-xl mt-1 mr-3 flex-shrink-0"></i>
                    <div class="text-red-700 flex-1">
                        <p class="font-semibold">Authentication Failed</p>
                        <p class="text-sm mt-1"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                    <button type="button" class="text-red-400 hover:text-red-600 transition-colors ml-2" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="mb-6 p-4 rounded-xl bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 flex items-start" role="status">
                    <i class="fas fa-check-circle text-green-500 text-xl mt-1 mr-3 flex-shrink-0"></i>
                    <div class="text-green-700 flex-1">
                        <p class="font-semibold">Login Successful</p>
                        <p class="text-sm mt-1"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                    <div class="ml-3 w-6 h-6 border-2 border-green-200 border-t-green-600 rounded-full animate-spin"></div>
                </div>
                <?php endif; ?>

                <!-- QR Scan Button -->
                <div class="mb-6 text-center">
                    <button type="button" id="scanQrBtn" class="scan-btn scanner-pulse">
                        <i class="fas fa-qrcode text-lg"></i>
                        <span>Scan QR Code to Login</span>
                    </button>
                    <p class="text-xs text-gray-400 mt-2">or enter credentials manually below</p>
                </div>

                <!-- Divider -->
                <div class="relative mb-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-200"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-4 bg-white/80 text-gray-400">Login Form</span>
                    </div>
                </div>

                <!-- Login Form -->
                <form method="post" action="" id="loginForm" class="space-y-6" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- Email -->
                    <div class="group">
                        <label for="email" class="block text-gray-700 font-medium mb-2 text-sm flex items-center">
                            <i class="fas fa-envelope text-amber-500 mr-2 text-sm"></i>
                            Email Address <span class="text-red-500 ml-1">*</span>
                            <?php if ($qr_scanned): ?>
                            <span class="ml-auto flex items-center gap-1 text-xs text-amber-600 font-semibold">
                                <i class="fas fa-qrcode"></i> QR filled
                            </span>
                            <?php endif; ?>
                        </label>
                        <div class="relative">
                            <input type="email" name="email" id="email" required autocomplete="email"
                                   placeholder="you@example.com"
                                   value="<?php echo htmlspecialchars($email); ?>"
                                   class="w-full pl-12 pr-4 py-3.5 bg-gray-50 border-2 border-gray-200 rounded-xl
                                          focus:outline-none focus:border-amber-400 focus:bg-white
                                          transition-all duration-300 text-gray-900
                                          <?= $qr_scanned ? 'field-qr-filled' : '' ?>"
                                   aria-describedby="emailHelp">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-amber-500 transition-colors">
                                <i class="fas fa-user-circle text-lg"></i>
                            </div>
                        </div>
                        <p id="emailHelp" class="text-xs text-gray-500 mt-2 flex items-center">
                            <i class="fas fa-info-circle mr-1"></i>Enter your registered school email address
                        </p>
                    </div>

                    <!-- Password -->
                    <div class="group">
                        <div class="flex justify-between items-center mb-2">
                            <label for="password" class="text-gray-700 font-medium text-sm flex items-center">
                                <i class="fas fa-lock text-amber-500 mr-2"></i>
                                Password <span class="text-red-500 ml-1">*</span>
                                <?php if ($qr_scanned): ?>
                                <span class="ml-2 flex items-center gap-1 text-xs text-amber-600 font-semibold">
                                    <i class="fas fa-qrcode"></i> QR filled
                                </span>
                                <?php endif; ?>
                            </label>
                            <a href="forgot_password.php" class="text-sm text-amber-600 hover:text-amber-800 transition-colors flex items-center">
                                <i class="fas fa-question-circle mr-1"></i>Forgot?
                            </a>
                        </div>
                        <div class="relative">
                            <input type="password" name="password" id="password" required autocomplete="current-password"
                                   placeholder="Enter your password"
                                   class="w-full pl-12 pr-12 py-3.5 bg-gray-50 border-2 border-gray-200 rounded-xl
                                          focus:outline-none focus:border-amber-400 focus:bg-white
                                          transition-all duration-300 text-gray-900
                                          <?= $qr_scanned ? 'field-qr-filled' : '' ?>">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-focus-within:text-amber-500 transition-colors">
                                <i class="fas fa-key text-lg"></i>
                            </div>
                            <div class="absolute right-4 top-1/2 -translate-y-1/2">
                                <button type="button" id="togglePassword"
                                        class="text-gray-400 hover:text-amber-600 transition-colors focus:outline-none"
                                        aria-label="Toggle password visibility">
                                    <i class="fas fa-eye text-lg"></i>
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center mt-4">
                            <input type="checkbox" name="remember_me" id="remember_me"
                                   class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-gray-300 rounded cursor-pointer">
                            <label for="remember_me" class="ml-2 text-sm text-gray-700 cursor-pointer select-none">
                                Keep me signed in
                            </label>
                        </div>
                    </div>

                    <!-- Security Notice -->
                    <div class="bg-gradient-to-r from-gray-50 to-amber-50/30 p-4 rounded-xl border border-gray-200">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-amber-100 to-orange-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-lock text-amber-600"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900">Secure Connection</p>
                                <p class="text-xs text-gray-600">Your data is encrypted with 256-bit SSL</p>
                            </div>
                            <div class="flex space-x-1 flex-shrink-0">
                                <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                                <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse-slow"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="submit" id="submitBtn"
                            class="w-full bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600
                                   text-white font-semibold py-3.5 rounded-xl transition-all duration-300
                                   transform hover:-translate-y-1 hover:shadow-xl active:translate-y-0
                                   disabled:opacity-50 disabled:cursor-not-allowed"
                            aria-label="Sign in">
                        <span class="flex items-center justify-center">
                            <?php if ($qr_scanned): ?>
                            <i class="fas fa-qrcode mr-2"></i>
                            <?php else: ?>
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            <?php endif; ?>
                            <span id="btnText"><?= $qr_scanned ? 'Sign In with QR Code' : 'Sign In to Portal' ?></span>
                            <span id="btnLoader" class="hidden ml-2"><i class="fas fa-spinner fa-spin"></i></span>
                        </span>
                    </button>

                    <!-- Quick Links -->
                    <div class="flex justify-center space-x-4 text-xs text-gray-500 pt-4">
                        <a href="#" class="hover:text-amber-600 transition-colors flex items-center"><i class="fas fa-question-circle mr-1"></i>Help</a>
                        <span>•</span>
                        <a href="#" class="hover:text-amber-600 transition-colors flex items-center"><i class="fas fa-shield-alt mr-1"></i>Privacy</a>
                        <span>•</span>
                        <a href="#" class="hover:text-amber-600 transition-colors flex items-center"><i class="fas fa-file-contract mr-1"></i>Terms</a>
                    </div>
                </form>

                <!-- Footer -->
                <div class="text-center pt-6 border-t border-gray-200 mt-6">
                    <p class="text-xs text-gray-500 flex items-center justify-center">
                        <i class="far fa-copyright mr-1"></i>
                        <?php echo date('Y'); ?> QueensPa School. All rights reserved.
                    </p>
                </div>
            </div>
        </div><!-- /.login-card -->
    </div>

    <!-- QR Scanner Modal -->
    <div id="scannerOverlay" class="scanner-overlay hidden">
        <div class="scanner-modal animate-slide-up">
            <div class="scanner-header">
                <h3><i class="fas fa-qrcode"></i> Scan Launch Card QR</h3>
                <button class="scanner-close" onclick="closeScanner()" aria-label="Close scanner">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="scanner-body">
                <div id="qr-reader"></div>
                <div id="scannerStatus" class="scanner-status">
                    <i class="fas fa-camera"></i>
                    <span>Position the QR code in front of your camera</span>
                </div>
                <div class="scanner-hint">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <span>Point your camera at the <strong>QR code</strong> on your launch card. The login form will be filled automatically.</span>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        const el = {
            toggle:        document.getElementById('togglePassword'),
            password:      document.getElementById('password'),
            form:          document.getElementById('loginForm'),
            submitBtn:     document.getElementById('submitBtn'),
            btnText:       document.getElementById('btnText'),
            btnLoader:     document.getElementById('btnLoader'),
            email:         document.getElementById('email'),
            scanBtn:       document.getElementById('scanQrBtn'),
            overlay:       document.getElementById('scannerOverlay'),
            scannerStatus: document.getElementById('scannerStatus'),
        };

        let html5QrCode = null;

        // QR prefill from URL params
        (function applyQRPrefill() {
            const params = new URLSearchParams(window.location.search);
            const pwd  = params.get('pass') || params.get('qpm_pwd');
            const auto = params.get('auto') || '0';

            if (pwd && el.password) {
                el.password.value = pwd;
                el.password.classList.add('field-qr-filled');
            }

            if (auto === '1' && el.email.value && pwd) {
                setTimeout(() => {
                    if (el.submitBtn && el.btnText && el.btnLoader) {
                        el.submitBtn.disabled = true;
                        el.btnText.textContent = 'Auto-logging in…';
                        el.btnLoader.classList.remove('hidden');
                    }
                    el.form.submit();
                }, 800);
                window.history.replaceState({}, document.title, window.location.pathname);
                return;
            }

            if (pwd || params.get('email') || params.get('qpm_email')) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            const toast = document.getElementById('qrToast');
            if (toast) {
                setTimeout(() => {
                    toast.style.transition = 'opacity .5s';
                    toast.style.opacity = '0';
                    setTimeout(() => { if (toast.parentElement) toast.remove(); }, 500);
                }, 8000);
            }
        })();

        // Scanner
        function openScanner() {
            el.overlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            if (!html5QrCode) html5QrCode = new Html5Qrcode("qr-reader");
            html5QrCode.start(
                { facingMode: "environment" },
                { fps:10, qrbox:{width:250,height:250}, aspectRatio:1.0 },
                onScanSuccess, onScanFailure
            ).then(() => {
                updateScannerStatus('scanning', 'Camera active — point at QR code');
            }).catch(() => {
                updateScannerStatus('error', '⚠️ Camera access denied. Please check permissions.');
            });
        }

        function closeScanner() {
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => html5QrCode.clear()).catch(() => {});
            }
            el.overlay.classList.add('hidden');
            document.body.style.overflow = '';
            updateScannerStatus('idle', '');
        }

        function onScanSuccess(decodedText) {
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => html5QrCode.clear()).catch(() => {});
            }
            updateScannerStatus('success', '✅ QR Code detected! Logging in...');
            try {
                const url   = new URL(decodedText);
                const email = url.searchParams.get('email') || url.searchParams.get('qpm_email') || '';
                const pass  = url.searchParams.get('pass')  || url.searchParams.get('qpm_pwd')   || '';
                const auto  = url.searchParams.get('auto')  || '0';

                if (email && pass) {
                    el.email.value = email;
                    el.email.classList.add('field-qr-filled');
                    el.password.value = pass;
                    el.password.classList.add('field-qr-filled');
                    setTimeout(() => {
                        closeScanner();
                        showQRToast(email, auto === '1');
                        if (auto === '1') {
                            setTimeout(() => {
                                if (el.submitBtn && el.btnText && el.btnLoader) {
                                    el.submitBtn.disabled = true;
                                    el.btnText.textContent = 'Auto-logging in…';
                                    el.btnLoader.classList.remove('hidden');
                                }
                                el.form.submit();
                            }, 600);
                        }
                    }, 500);
                } else {
                    updateScannerStatus('error', '⚠️ Invalid QR code. Not a QueensPa launch card.');
                    setTimeout(() => restartScanner(), 1500);
                }
            } catch (e) {
                updateScannerStatus('error', '⚠️ Could not read QR code. Please try again.');
                setTimeout(() => restartScanner(), 1500);
            }
        }

        function onScanFailure() {}

        function restartScanner() {
            if (html5QrCode && !html5QrCode.isScanning) {
                html5QrCode.start(
                    { facingMode: "environment" },
                    { fps:10, qrbox:{width:250,height:250}, aspectRatio:1.0 },
                    onScanSuccess, onScanFailure
                ).then(() => updateScannerStatus('scanning', 'Camera active — point at QR code')).catch(() => {});
            }
        }

        function updateScannerStatus(type, message) {
            if (!el.scannerStatus) return;
            el.scannerStatus.className = 'scanner-status';
            const icons = { scanning:'fas fa-sync-alt fa-spin text-amber-500', success:'fas fa-check-circle', error:'fas fa-exclamation-circle', default:'fas fa-camera' };
            if (type === 'success') el.scannerStatus.classList.add('success');
            if (type === 'error')   el.scannerStatus.classList.add('error');
            el.scannerStatus.innerHTML = `<i class="${icons[type] || icons.default}"></i><span>${message}</span>`;
        }

        function showQRToast(email, isAuto) {
            const existing = document.getElementById('qrToast');
            if (existing) existing.remove();
            const toast = document.createElement('div');
            toast.id = 'qrToast';
            toast.className = 'qr-toast fixed top-5 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 bg-white border-2 border-amber-400 shadow-xl rounded-2xl px-5 py-3 text-sm font-medium text-amber-800';
            toast.setAttribute('role', 'status');
            toast.innerHTML = `
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-amber-100 text-amber-600 flex-shrink-0">
                    <i class="fas fa-qrcode text-base"></i>
                </span>
                <span><strong>QR Code detected!</strong> ${isAuto ? 'Logging you in automatically...' : 'Credentials pre-filled — just tap <em>Sign In</em>.'}</span>
                <button class="ml-2 text-amber-400 hover:text-amber-700" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                if (toast.parentElement) { toast.style.transition = 'opacity .5s'; toast.style.opacity = '0'; setTimeout(() => { if (toast.parentElement) toast.remove(); }, 500); }
            }, 8000);
        }

        // Event listeners
        if (el.scanBtn)  el.scanBtn.addEventListener('click', openScanner);
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !el.overlay.classList.contains('hidden')) closeScanner(); });
        if (el.overlay)  el.overlay.addEventListener('click', e => { if (e.target === el.overlay) closeScanner(); });

        if (el.toggle && el.password) {
            el.toggle.addEventListener('click', function () {
                const type = el.password.getAttribute('type') === 'password' ? 'text' : 'password';
                el.password.setAttribute('type', type);
                const icon = this.querySelector('i');
                if (icon) icon.className = type === 'password' ? 'fas fa-eye text-lg' : 'fas fa-eye-slash text-lg';
                this.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
            });
        }

        if (el.form) {
            el.form.addEventListener('submit', function (e) {
                if (!el.email.checkValidity()) { e.preventDefault(); showFieldError(el.email, 'Please enter a valid email address'); return; }
                if (!el.password.value)        { e.preventDefault(); showFieldError(el.password, 'Please enter your password'); return; }
                if (el.submitBtn && el.btnText && el.btnLoader) {
                    el.submitBtn.disabled = true;
                    el.btnText.textContent = 'Authenticating…';
                    el.btnLoader.classList.remove('hidden');
                }
            });
        }

        if (el.email) {
            el.email.addEventListener('blur', function () {
                const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value);
                if (this.value && !ok) showFieldError(this, 'Please enter a valid email address');
                else if (this.value)   clearFieldError(this);
            });
        }

        function showFieldError(field, message) {
            clearFieldError(field);
            field.classList.add('border-red-400');
            const div = document.createElement('p');
            div.className = 'text-red-500 text-xs mt-1 flex items-center';
            div.id = field.id + 'Error';
            div.innerHTML = `<i class="fas fa-exclamation-circle mr-1"></i>${message}`;
            field.parentElement.appendChild(div);
        }

        function clearFieldError(field) {
            field.classList.remove('border-red-400');
            const existing = document.getElementById(field.id + 'Error');
            if (existing) existing.remove();
        }

        if (el.submitBtn) el.submitBtn.addEventListener('dblclick', e => e.preventDefault());
        window.addEventListener('pageshow', e => { if (e.persisted) window.location.reload(); });
        document.querySelectorAll('[role="alert"]').forEach(alert => {
            setTimeout(() => { if (alert.parentElement) { alert.style.transition = 'opacity .5s'; alert.style.opacity = '0'; setTimeout(() => { if (alert.parentElement) alert.remove(); }, 500); } }, 10000);
        });

        <?php if ($auto_login): ?>
        document.addEventListener('DOMContentLoaded', function() {
            if (el.submitBtn && el.btnText && el.btnLoader) {
                el.submitBtn.disabled = true;
                el.btnText.textContent = 'Auto-logging in…';
                el.btnLoader.classList.remove('hidden');
            }
        });
        <?php endif; ?>
    })();
    </script>
</body>
</html>