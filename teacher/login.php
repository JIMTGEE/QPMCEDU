<?php
// login.php
// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'teacher') {
    header("Location: https://qpmsedu.com/teacher/dashboard.php");
    exit();
}

// Database connection
$host = 'localhost';
$db = 'qpmseduc_qpms_db';
$user = 'qpmseduc_user';
$pass = 'YOUR_ACTUAL_PASSWORD'; // Replace with your actual password

try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        $error_message = "Database connection failed: " . $conn->connect_error;
        error_log($error_message, 3, '/home/qpmseduc/public_html/teacher/logs/error.log');
        throw new Exception($error_message);
    }
} catch (Exception $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        // Sanitize email
        $email = $conn->real_escape_string($email);

        // Query to check teacher credentials
        $sql = "SELECT id, email, password FROM teachers WHERE email = ?";
        if (!$stmt = $conn->prepare($sql)) {
            $error_message = "Prepare failed: " . $conn->error;
            error_log($error_message, 3, '/home/qpmseduc/public_html/teacher/logs/error.log');
            die("Server error: Query preparation failed.");
        }
        $stmt->bind_param("s", $email);
        if (!$stmt->execute()) {
            $error_message = "Execute failed: " . $stmt->error;
            error_log($error_message, 3, '/home/qpmseduc/public_html/teacher/logs/error.log');
            die("Server error: Query execution failed.");
        }
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $teacher = $result->fetch_assoc();
            // Verify hashed password (assuming passwords are hashed)
            if (password_verify($password, $teacher['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $teacher['id'];
                $_SESSION['role'] = 'teacher';
                $_SESSION['user_email'] = $teacher['email'];
                // Regenerate session ID for security
                session_regenerate_id(true);
                header("Location: https://qpmsedu.com/teacher/dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Login - QPMS Teachers Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            max-width: 400px;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2 class="text-center mb-4">Teacher Login</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>