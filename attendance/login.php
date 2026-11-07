<?php
// /attendance/login.php
// Gate Access Login - Clean & Fixed Version

// Start session first (very important)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database
require_once __DIR__ . '/../config/db.php';

// ============================================
// ALREADY LOGGED IN? → Redirect to scanner
// ============================================
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && isset($_SESSION['logged_in'])) {
    
    $stmt = $conn->prepare("SELECT id, role, status FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user && $user['status'] === 'active' && in_array($user['role'], ['admin', 'teacher', 'security', 'head_academics'])) {
        header('Location: /attendance/index.php');
        exit();
    } else {
        // Invalid session → clear it
        session_unset();
        session_destroy();
        session_start();
    }
}

// ============================================
// HANDLE LOGIN FORM
// ============================================
$loginError = '';
$email = isset($_COOKIE['attendance_email']) ? $_COOKIE['attendance_email'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $loginError = 'Please enter both email and password.';
    } else {
        
        $stmt = $conn->prepare("SELECT id, name, email, role, password, status, profile_picture FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user) {
            $loginError = 'Invalid email or password.';
        } 
        elseif (!password_verify($password, $user['password'])) {
            $loginError = 'Invalid email or password.';
        } 
        elseif ($user['status'] !== 'active') {
            $loginError = 'Your account is inactive. Please contact the administrator.';
        } 
        elseif (!in_array($user['role'], ['admin', 'teacher', 'security', 'head_academics'])) {
            $loginError = 'Access denied. Only Admin, Teacher, Security, and Head Academics can use the gate scanner.';
        } 
        else {
            // ===== LOGIN SUCCESS =====
            
            // Clear any old session data
            session_regenerate_id(true);
            
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['name']       = $user['name'];
            $_SESSION['logged_in']  = true;
            
            // Remember me
            if ($remember) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                // Check if table exists
                $tableCheck = $conn->query("SHOW TABLES LIKE 'auto_login_tokens'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    // Delete old tokens for this user
                    $stmt = $conn->prepare("DELETE FROM auto_login_tokens WHERE user_id = ?");
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                    
                    // Insert new token
                    $stmt = $conn->prepare("INSERT INTO auto_login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                    $stmt->bind_param("iss", $user['id'], $token, $expires);
                    $stmt->execute();
                    
                    setcookie('attendance_remember', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                }
                
                setcookie('attendance_email', $email, time() + (30 * 24 * 60 * 60), '/');
            }
            
            // Redirect to scanner
            header('Location: /attendance/index.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gate Access Login | QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .gradient-bg {
            background: linear-gradient(135deg, #fef3c7 0%, #fed7aa 50%, #fdba74 100%);
        }
        .card-shadow {
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
        }
        .input-focus:focus {
            box-shadow: 0 0 0 3px rgba(251, 146, 60, 0.3);
            border-color: #fb923c;
        }
        .btn-gradient {
            background: linear-gradient(135deg, #f97316, #ea580c);
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(249, 115, 22, 0.4);
        }
        .btn-gradient:active {
            transform: translateY(0);
        }
        .shake {
            animation: shake 0.5s ease;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-10px); }
            40% { transform: translateX(10px); }
            60% { transform: translateX(-5px); }
            80% { transform: translateX(5px); }
        }
        .fade-in {
            animation: fadeIn 0.6s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .pulse-dot {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .toggle-eye {
            cursor: pointer;
            transition: color 0.3s;
        }
        .toggle-eye:hover {
            color: #f97316;
        }
    </style>
</head>
<body class="min-h-screen gradient-bg flex items-center justify-center p-4">

    <div class="w-full max-w-md fade-in">
        <!-- Logo & Header -->
        <div class="text-center mb-8">
            <div class="inline-block bg-white rounded-2xl p-4 shadow-lg mb-4">
                <i class="fas fa-gate text-4xl text-orange-500"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Gate Access System</h1>
            <p class="text-gray-600 mt-1">QueensPa School • Secure Entry Control</p>
            <div class="flex items-center justify-center mt-2 space-x-2">
                <span class="w-2 h-2 bg-green-500 rounded-full pulse-dot"></span>
                <span class="text-sm text-gray-500">Secure • Authorized Access Only</span>
            </div>
        </div>

        <!-- Login Card -->
        <div class="bg-white rounded-2xl card-shadow overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-gray-800">
                        <i class="fas fa-lock text-orange-500 mr-2"></i> Sign In
                    </h2>
                    <span class="text-xs bg-orange-100 text-orange-600 px-3 py-1 rounded-full">Staff Only</span>
                </div>
                <p class="text-sm text-gray-500 mt-1">Enter your credentials to access the gate scanner</p>
            </div>

            <div class="p-6">
                <!-- Error Message -->
                <?php if (!empty($loginError)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shake" id="errorMessage">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo htmlspecialchars($loginError); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" autocomplete="on">
                    <div class="mb-5">
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-envelope text-orange-500 mr-1"></i> Email Address
                        </label>
                        <input type="email" id="email" name="email" 
                               placeholder="your@email.com" 
                               value="<?php echo htmlspecialchars($email); ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl input-focus transition duration-200 bg-gray-50 focus:bg-white"
                               required autofocus>
                    </div>

                    <div class="mb-5">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-key text-orange-500 mr-1"></i> Password
                        </label>
                        <div class="relative">
                            <input type="password" id="password" name="password" 
                                   placeholder="Enter your password"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl input-focus transition duration-200 bg-gray-50 focus:bg-white pr-12"
                                   required>
                            <button type="button" id="togglePassword" 
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 toggle-eye">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mb-6">
                        <label class="flex items-center text-sm text-gray-600 cursor-pointer">
                            <input type="checkbox" name="remember" 
                                   <?php echo !empty($email) ? 'checked' : ''; ?>
                                   class="w-4 h-4 text-orange-500 rounded border-gray-300 focus:ring-orange-500 focus:ring-2 cursor-pointer">
                            <span class="ml-2">Remember me</span>
                        </label>
                        <a href="#" onclick="alert('Please contact the administrator for password reset.'); return false;" 
                           class="text-sm text-orange-500 hover:text-orange-600 font-medium">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" class="w-full btn-gradient text-white font-semibold py-3 px-4 rounded-xl transition duration-300 flex items-center justify-center" id="loginBtn" name="login">
                        <span class="btn-text"><i class="fas fa-sign-in-alt mr-2"></i> Sign In</span>
                        <span class="spinner hidden"><i class="fas fa-spinner fa-spin"></i> Signing in...</span>
                    </button>
                </form>

                <!-- Info -->
                <div class="mt-6 p-4 bg-orange-50 rounded-xl border border-orange-200">
                    <p class="text-xs text-gray-600 flex items-start">
                        <i class="fas fa-info-circle text-orange-500 mt-0.5 mr-2"></i>
                        <span>Access is restricted to staff members with appropriate permissions. 
                        If you're having trouble logging in, please contact the IT department.</span>
                    </p>
                </div>
            </div>

            <div class="border-t border-gray-100 p-4 bg-gray-50">
                <div class="flex items-center justify-center space-x-4 text-xs text-gray-500">
                    <span class="flex items-center">
                        <i class="fas fa-shield-alt text-orange-500 mr-1"></i>
                        <span>Secure Login</span>
                    </span>
                    <span>•</span>
                    <span>&copy; <?php echo date('Y'); ?> QueensPa School</span>
                </div>
            </div>
        </div>

        <p class="text-center text-xs text-gray-500 mt-6">
            <i class="fas fa-arrow-left mr-1"></i>
            <a href="/" class="hover:text-orange-500 transition">Back to School Website</a>
        </p>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            this.querySelector('i').className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
        });

        // Form submission loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.querySelector('.btn-text').classList.add('hidden');
            btn.querySelector('.spinner').classList.remove('hidden');
            btn.disabled = true;
        });
    </script>
</body>
</html>