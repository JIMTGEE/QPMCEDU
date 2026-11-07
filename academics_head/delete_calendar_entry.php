<?php
session_start();

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Check if the database connection was successful
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Initialize messages
$success_msg = $error_msg = "";

// Check if an ID is provided in the URL
$calendar_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($calendar_id <= 0) {
    $error_msg = "Invalid calendar entry ID.";
} else {
    // Verify that the calendar entry exists
    $check_sql = "SELECT id, year, term_name FROM academic_calendar WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $calendar_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        $error_msg = "Calendar entry not found.";
    } else {
        $calendar_entry = $result->fetch_assoc();
        $year = $calendar_entry['year'];
        $term_name = $calendar_entry['term_name'];

        // Delete the calendar entry
        $delete_sql = "DELETE FROM academic_calendar WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $calendar_id);

        if ($delete_stmt->execute()) {
            // Log the action
            $user_id = $_SESSION['user_id'];
            $action = "Deleted Calendar Entry";
            $description = "Deleted calendar entry ID: $calendar_id, Year: $year, Term: $term_name";
            logActivity($conn, $user_id, $action, $description);

            $success_msg = "Calendar entry deleted successfully! Redirecting...";
            header("refresh:2;url=calendar.php");
        } else {
            $error_msg = "Error deleting calendar entry: " . $conn->error;
        }
        $delete_stmt->close();
    }
    $check_stmt->close();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Calendar Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #ffffff;
            color: #000000;
        }
        .sidebar {
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            background-color: #ff4500;
            padding-top: 20px;
        }
        .sidebar a {
            color: #ffffff;
            padding: 15px 20px;
            display: block;
            text-decoration: none;
        }
        .sidebar a:hover {
            background-color: #e03e00;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        .card-header {
            background-color: #ff4500;
            color: #ffffff;
        }
        .btn-primary {
            background-color: #ff4500;
            border-color: #ff4500;
        }
        .btn-primary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
        }
        .card {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .night-mode {
            background-color: #000000;
            color: #ffffff;
        }
        .night-mode .sidebar {
            background-color: #333333;
        }
        .night-mode .sidebar a {
            color: #ffffff;
        }
        .night-mode .sidebar a:hover {
            background-color: #555555;
        }
        .night-mode .card {
            background-color: #333333;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <div class="main-content">
        <div class="container mt-5 animate__animated animate__fadeIn">
            <h1 class="mb-4 text-center">🗑️ Delete Calendar Entry</h1>

            <!-- Feedback Messages -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="mb-0">Deletion Status</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success"><?php echo $success_msg; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger"><?php echo $error_msg; ?></div>
                        <a href="calendar.php" class="btn btn-secondary">Back to Calendar Settings</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleButton = document.createElement('button');
            toggleButton.textContent = 'Toggle Night Mode';
            toggleButton.className = 'btn btn-secondary position-fixed top-0 end-0 m-2';
            document.body.appendChild(toggleButton);

            const isNightMode = localStorage.getItem('nightMode') === 'true';
            if (isNightMode) {
                document.body.classList.add('night-mode');
            }

            toggleButton.addEventListener('click', () => {
                document.body.classList.toggle('night-mode');
                localStorage.setItem('nightMode', document.body.classList.contains('night-mode'));
            });
        });
    </script>
</body>
</html>

<?php
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
mysqli_close($conn);
?>