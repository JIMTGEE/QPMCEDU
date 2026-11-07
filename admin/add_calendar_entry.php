<?php
session_start();

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include the database connection and logging function
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Check if the database connection was successful
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Initialize variables for form feedback
$success_msg = $error_msg = "";

// If the form is submitted, add the new calendar entry
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get and sanitize form data
    $year = $_POST['year'] ?? '';
    $term_name = $_POST['term_name'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    // Validate inputs
    if (empty($year) || empty($term_name) || empty($start_date) || empty($end_date)) {
        $error_msg = "All fields are required.";
    } else {
        // Validate year format (YYYY/YYYY)
        if (!preg_match('/^\d{4}\/\d{4}$/', $year)) {
            $error_msg = "Please enter the year in the format YYYY/YYYY (e.g., 2025/2026).";
        } else {
            // Split the year to validate the range
            list($start_year, $end_year) = explode('/', $year);
            $start_year = (int)$start_year;
            $end_year = (int)$end_year;

            if ($start_year < 2000 || $end_year > 2100 || $end_year != $start_year + 1) {
                $error_msg = "Please enter a valid academic year between 2000/2001 and 2099/2100, where the second year is the first year plus 1.";
            } elseif (strtotime($end_date) <= strtotime($start_date)) {
                $error_msg = "End date must be after start date.";
            } else {
                // Check for overlapping terms in the same year
                $check_sql = "SELECT id FROM academic_calendar WHERE year = ? AND term_name = ? AND (
                    (start_date <= ? AND end_date >= ?) OR 
                    (start_date <= ? AND end_date >= ?)
                )";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ssssss", $year, $term_name, $start_date, $start_date, $end_date, $end_date);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $error_msg = "This term overlaps with an existing term in the same year.";
                } else {
                    // Insert the new calendar entry using prepared statements
                    $insert_sql = "INSERT INTO academic_calendar (year, term_name, start_date, end_date) VALUES (?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("ssss", $year, $term_name, $start_date, $end_date);

                    if ($insert_stmt->execute()) {
                        // Log the action
                        $user_id = $_SESSION['user_id'];
                        $action = "Added Academic Calendar Entry";
                        $description = "Year: $year, Term: $term_name, Start: $start_date, End: $end_date";
                        logActivity($conn, $user_id, $action, $description);

                        $success_msg = "Academic calendar entry added successfully! Redirecting...";
                        header("refresh:2;url=calendar.php");
                        $_POST = array();
                    } else {
                        $error_msg = "Error adding calendar entry: " . $conn->error;
                    }
                    $insert_stmt->close();
                }
                $check_stmt->close();
            }
        }
    }
}

// Include admin header template
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Add a new academic calendar entry in the admin portal">
    <title>Add Academic Calendar Entry | Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --secondary-color: #6c757d;
            --secondary-hover: #5a6268;
            --dark-bg: #212529;
            --dark-card: #343a40;
            --light-bg: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        body {
            background: linear-gradient(135deg, var(--light-bg), #ffffff);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }

        .sidebar {
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            background: var(--primary-color);
            padding-top: 1rem;
            box-shadow: var(--shadow);
            transition: background 0.3s;
        }

        .sidebar a {
            color: #fff;
            padding: 1rem;
            display: block;
            text-decoration: none;
            transition: background 0.2s;
        }

        .sidebar a:hover {
            background: var(--primary-hover);
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: var(--shadow);
            background: #fff;
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            background: var(--primary-color);
            color: #fff;
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: var(--secondary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            transition: background 0.2s;
        }

        .btn-secondary:hover {
            background: var(--secondary-hover);
        }

        .form-control {
            border-radius: 8px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .form-label {
            font-weight: 500;
        }

        .alert {
            border-radius: 8px;
        }

        .night-mode {
            background: linear-gradient(135deg, var(--dark-bg), #343a40);
            color: #fff;
        }

        .night-mode .sidebar {
            background: var(--dark-card);
        }

        .night-mode .card {
            background: var(--dark-card);
            color: #fff;
        }

        .night-mode .form-control {
            background: #495057;
            color: #fff;
            border: 1px solid #6c757d;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <header class="d-flex justify-content-between align-items-center mb-4 animate__animated animate__fadeInDown">
                <h1 class="display-5 fw-bold">📅 Add Academic Calendar Entry</h1>
                <button id="nightModeToggle" class="btn btn-secondary">🌙 Night Mode</button>
            </header>

            <!-- Success/Error Messages -->
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success animate__animated animate__bounceIn"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger animate__animated animate__shakeX"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <!-- Form Card -->
            <div class="card animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">New Calendar Entry</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label for="year" class="form-label">Academic Year (e.g., 2025/2026)</label>
                            <input type="text" class="form-control" id="year" name="year" placeholder="YYYY/YYYY" required aria-describedby="yearHelp">
                            <div id="yearHelp" class="form-text">Enter in YYYY/YYYY format.</div>
                            <div class="invalid-feedback">Please enter a valid academic year.</div>
                        </div>
                        <div class="mb-3">
                            <label for="term_name" class="form-label">Term</label>
                            <input type="text" class="form-control" id="term_name" name="term_name" placeholder="e.g., Term 1" required>
                            <div class="invalid-feedback">Please enter the term name.</div>
                        </div>
                        <div class="mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                            <div class="invalid-feedback">Please select a start date.</div>
                        </div>
                        <div class="mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required>
                            <div class="invalid-feedback">Please select an end date.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Add Entry</button>
                            <a href="calendar.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS and Custom Script -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form Validation
        (function () {
            'use strict';
            const forms = document.querySelectorAll('.needs-validation');
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    form.classList.add('was-validated');
                }, false);
            });
        })();

        // Night Mode Toggle
        document.getElementById('nightModeToggle').addEventListener('click', () => {
            document.body.classList.toggle('night-mode');
            const isNightMode = document.body.classList.contains('night-mode');
            localStorage.setItem('nightMode', isNightMode);
            document.getElementById('nightModeToggle').textContent = isNightMode ? '☀️ Day Mode' : '🌙 Night Mode';
        });

        // Apply Night Mode on Load
        if (localStorage.getItem('nightMode') === 'true') {
            document.body.classList.add('night-mode');
            document.getElementById('nightModeToggle').textContent = '☀️ Day Mode';
        }
    </script>
</body>
</html>

<?php
  // Include admin footer template
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
mysqli_close($conn);
?>