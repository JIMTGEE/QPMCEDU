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

// Initialize variables for feedback
$success_msg = $error_msg = "";

// Check if an ID is provided to delete
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $error_msg = "Error: No valid calendar entry selected.";
    header("refresh:2;url=calendar.php");
} else {
    $id = intval($_GET['id']);

    // Check if the calendar entry exists
    $check_sql = "SELECT year, term_name FROM academic_calendar WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $row = $check_result->fetch_assoc();

    if (!$row) {
        $error_msg = "Calendar entry not found.";
        header("refresh:2;url=calendar.php");
    } else {
        // Store entry details for logging
        $year = $row['year'];
        $term_name = $row['term_name'];

        // Check for dependencies (e.g., teacher evaluations)
        $dep_sql = "SELECT COUNT(*) as count FROM teacher_evaluations WHERE term_id = ?";
        $dep_stmt = $conn->prepare($dep_sql);
        $dep_stmt->bind_param("i", $id);
        $dep_stmt->execute();
        $dep_result = $dep_stmt->get_result();
        $dep_row = $dep_result->fetch_assoc();

        if ($dep_row['count'] > 0) {
            $error_msg = "Cannot delete this calendar entry because it is associated with {$dep_row['count']} teacher evaluations.";
            header("refresh:3;url=calendar.php");
        } else {
            // Delete the calendar entry using prepared statements
            $delete_sql = "DELETE FROM academic_calendar WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $id);

            if ($delete_stmt->execute()) {
                // Log the action
                $user_id = $_SESSION['user_id'];
                $action = "Deleted Academic Calendar Entry";
                $description = "ID: $id, Year: $year, Term: $term_name";
                logActivity($conn, $user_id, $action, $description);

                $success_msg = "Academic calendar entry deleted successfully! Redirecting...";
                header("refresh:2;url=calendar.php");
            } else {
                $error_msg = "Error deleting calendar entry: " . $conn->error;
                header("refresh:3;url=calendar.php");
            }
            $delete_stmt->close();
        }
        $dep_stmt->close();
    }
    $check_stmt->close();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Academic Calendar Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Embedded CSS for custom styling -->
    <style>
        body {
            background-color: #ffffff; /* White */
            color: #000000; /* Black */
        }
        .card-header {
            background-color: #ff4500; /* Orange */
            color: #ffffff; /* White */
        }
        .card {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        /* Night mode styles */
        .night-mode {
            background-color: #000000; /* Black */
            color: #ffffff; /* White */
        }
        .night-mode .card {
            background-color: #333333; /* Dark gray */
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="container mt-5 animate__animated animate__fadeIn">
        <h2 class="mb-4">📅 Delete Academic Calendar Entry</h2>

        <!-- Display success or error messages -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="mb-0">Processing Deletion</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success"><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger"><?php echo $error_msg; ?></div>
                <?php endif; ?>
                <a href="calendar.php" class="btn btn-secondary">Back to Calendar Management</a>
            </div>
        </div>
    </div>

    <!-- Embedded JS for night/day mode toggle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Night/Day mode toggle
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
// Include the admin footer
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';

// Close the database connection
mysqli_close($conn);
?>