<?php
session_start();

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error'] = "You must be logged in as an administrator to access this page.";
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

// Initialize variables
$success_msg = $error_msg = "";
$id = null;
$row = null;

// Validate and get ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "No valid calendar entry selected.";
    header("Location: calendar.php");
    exit();
}

$id = intval($_GET['id']);

// Get existing calendar entry data
$sql = "SELECT * FROM academic_calendar WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Calendar entry not found.";
    header("Location: calendar.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate inputs
    $year = trim($_POST['year'] ?? '');
    $term_name = trim($_POST['term_name'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    
    // Initialize validation array
    $errors = [];
    
    // Validate required fields
    if (empty($year)) $errors[] = "Academic year is required.";
    if (empty($term_name)) $errors[] = "Term name is required.";
    if (empty($start_date)) $errors[] = "Start date is required.";
    if (empty($end_date)) $errors[] = "End date is required.";
    
    // Validate year format (YYYY/YYYY)
    if (!empty($year) && !preg_match('/^\d{4}\/\d{4}$/', $year)) {
        $errors[] = "Please enter the year in format YYYY/YYYY (e.g., 2025/2026).";
    }
    
    // Validate year range
    if (!empty($year) && preg_match('/^\d{4}\/\d{4}$/', $year)) {
        list($start_year, $end_year) = explode('/', $year);
        $start_year = (int)$start_year;
        $end_year = (int)$end_year;
        
        if ($start_year < 2000 || $end_year > 2100) {
            $errors[] = "Academic year must be between 2000/2001 and 2099/2100.";
        } elseif ($end_year != $start_year + 1) {
            $errors[] = "The second year must be exactly one year after the first (e.g., 2024/2025).";
        }
    }
    
    // Validate dates
    if (!empty($start_date) && !empty($end_date)) {
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
        
        if ($start_timestamp === false) {
            $errors[] = "Invalid start date format.";
        }
        if ($end_timestamp === false) {
            $errors[] = "Invalid end date format.";
        }
        
        if ($start_timestamp && $end_timestamp) {
            if ($end_timestamp <= $start_timestamp) {
                $errors[] = "End date must be after start date.";
            }
            
            // Ensure term is at least 1 day long (more realistic validation)
            if (($end_timestamp - $start_timestamp) < 86400) {
                $errors[] = "Term must be at least one day long.";
            }
        }
    }
    
    // If no validation errors, check for overlaps and update
    if (empty($errors)) {
        // Check for overlapping terms (same year and term, different dates)
        $check_sql = "SELECT id FROM academic_calendar 
                      WHERE id != ? 
                      AND year = ? 
                      AND term_name = ? 
                      AND (
                          (start_date <= ? AND end_date >= ?) 
                          OR (start_date <= ? AND end_date >= ?)
                          OR (? BETWEEN start_date AND end_date)
                          OR (? BETWEEN start_date AND end_date)
                      )";
        
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param(
            "issssssss", 
            $id, $year, $term_name, 
            $start_date, $start_date, 
            $end_date, $end_date,
            $start_date, $end_date
        );
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $errors[] = "This term overlaps with an existing term in the same academic year.";
        }
        $check_stmt->close();
        
        // If still no errors, proceed with update
        if (empty($errors)) {
            // Get old values for logging
            $old_year = $row['year'];
            $old_term = $row['term_name'];
            $old_start = $row['start_date'];
            $old_end = $row['end_date'];
            
            // Update the calendar entry
            $update_sql = "UPDATE academic_calendar 
                           SET year = ?, term_name = ?, start_date = ?, end_date = ?, 
                               updated_at = NOW() 
                           WHERE id = ?";
            
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssssi", $year, $term_name, $start_date, $end_date, $id);
            
            if ($update_stmt->execute()) {
                // Log the action with detailed changes
                $user_id = $_SESSION['user_id'];
                $action = "Updated Academic Calendar Entry";
                
                // Create detailed description of changes
                $changes = [];
                if ($old_year !== $year) $changes[] = "Year: $old_year → $year";
                if ($old_term !== $term_name) $changes[] = "Term: $old_term → $term_name";
                if ($old_start !== $start_date) $changes[] = "Start: $old_start → $start_date";
                if ($old_end !== $end_date) $changes[] = "End: $old_end → $end_date";
                
                $description = "ID: $id. Changes: " . (!empty($changes) ? implode(", ", $changes) : "No changes");
                logActivity($conn, $user_id, $action, $description);
                
                $_SESSION['success'] = "Academic calendar entry updated successfully!";
                header("Location: calendar.php");
                exit();
            } else {
                $errors[] = "Database error: " . $conn->error;
            }
            $update_stmt->close();
        }
    }
    
    // Display validation errors
    if (!empty($errors)) {
        $error_msg = "<ul class='mb-0'>";
        foreach ($errors as $error) {
            $error_msg .= "<li>" . htmlspecialchars($error) . "</li>";
        }
        $error_msg .= "</ul>";
    }
    
    // Update row with submitted values for form re-population
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $row['year'] = $year;
        $row['term_name'] = $term_name;
        $row['start_date'] = $start_date;
        $row['end_date'] = $end_date;
    }
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Academic Calendar Entry - Administration</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --secondary-color: #6c757d;
            --background-light: #ffffff;
            --background-dark: #121212;
            --text-light: #000000;
            --text-dark: #ffffff;
            --card-light: #ffffff;
            --card-dark: #1e1e1e;
            --sidebar-light: #ff4500;
            --sidebar-dark: #1a1a1a;
        }
        
        [data-bs-theme="light"] {
            --bg-color: var(--background-light);
            --text-color: var(--text-light);
            --card-bg: var(--card-light);
            --sidebar-bg: var(--sidebar-light);
        }
        
        [data-bs-theme="dark"] {
            --bg-color: var(--background-dark);
            --text-color: var(--text-dark);
            --card-bg: var(--card-dark);
            --sidebar-bg: var(--sidebar-dark);
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }
        
        .card {
            background-color: var(--card-bg);
            border: 1px solid rgba(0,0,0,0.125);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
        }
        
        .card-header {
            background-color: var(--primary-color) !important;
            color: white;
            border-bottom: none;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .form-control, .form-select {
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid #dee2e6;
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--card-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 69, 0, 0.25);
        }
        
        .alert {
            border: none;
            border-left: 4px solid transparent;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: #28a745;
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: #dc3545;
        }
        
        /* Theme toggle button */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .theme-toggle:hover {
            background-color: var(--primary-hover);
            transform: scale(1.1);
        }
        
        .form-label {
            font-weight: 500;
        }
        
        .required::after {
            content: " *";
            color: #dc3545;
        }
        
        /* Date validation indicators */
        .date-status {
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .date-valid {
            color: #198754;
        }
        
        .date-invalid {
            color: #dc3545;
        }
        
        /* Loading overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        
        .loading-overlay.active {
            display: flex;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid mt-4 animate__animated animate__fadeIn">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="calendar.php">Academic Calendar</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Entry</li>
                </ol>
            </nav>
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2 mb-0">
                    <i class="bi bi-calendar3 me-2"></i>Edit Academic Calendar Entry
                </h1>
                <a href="calendar.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to Calendar
                </a>
            </div>
            
            <!-- Display Messages -->
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Edit Form -->
            <?php if ($row): ?>
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h3 class="card-title mb-0">
                                    <i class="bi bi-pencil-square me-2"></i>
                                    Edit Entry #<?php echo $id; ?>
                                </h3>
                                <p class="text-white-50 mb-0 small">
                                    Last updated: <?php echo date('M j, Y', strtotime($row['updated_at'] ?? $row['created_at'])); ?>
                                </p>
                            </div>
                            <div class="card-body">
                                <form id="editCalendarForm" method="POST" action="" novalidate>
                                    <!-- Current Year/Status Badge -->
                                    <div class="row mb-4">
                                        <div class="col">
                            <span class="badge bg-info">
                                <i class="bi bi-info-circle me-1"></i>
                                Current: <?php echo htmlspecialchars($row['year'] . ' - ' . $row['term_name']); ?>
                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- Academic Year -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="year" class="form-label required">Academic Year</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="year" 
                                                   name="year" 
                                                   value="<?php echo htmlspecialchars($row['year']); ?>" 
                                                   pattern="^\d{4}\/\d{4}$"
                                                   title="Format: YYYY/YYYY (e.g., 2024/2025)"
                                                   required>
                                            <div class="form-text">
                                                Format: YYYY/YYYY (e.g., 2024/2025)
                                            </div>
                                            <div id="yearFeedback" class="date-status"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Term Name -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="term_name" class="form-label required">Term Name</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="term_name" 
                                                   name="term_name" 
                                                   value="<?php echo htmlspecialchars($row['term_name']); ?>" 
                                                   required>
                                            <div class="form-text">
                                                Example: "Term 1", "Semester 2", "Summer Session"
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Date Range -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="start_date" class="form-label required">Start Date</label>
                                            <input type="date" 
                                                   class="form-control" 
                                                   id="start_date" 
                                                   name="start_date" 
                                                   value="<?php echo htmlspecialchars($row['start_date']); ?>" 
                                                   required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="end_date" class="form-label required">End Date</label>
                                            <input type="date" 
                                                   class="form-control" 
                                                   id="end_date" 
                                                   name="end_date" 
                                                   value="<?php echo htmlspecialchars($row['end_date']); ?>" 
                                                   required>
                                        </div>
                                        <div class="col-12 mt-2">
                                            <div id="dateRangeFeedback" class="date-status"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Duration Calculation -->
                                    <div class="row mb-4">
                                        <div class="col">
                                            <div class="alert alert-info py-2">
                                                <i class="bi bi-clock me-2"></i>
                                                <span id="durationDisplay">
                                                    <?php 
                                                    $start = new DateTime($row['start_date']);
                                                    $end = new DateTime($row['end_date']);
                                                    $interval = $start->diff($end);
                                                    echo "Duration: " . $interval->days . " days";
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Form Actions -->
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-check-circle me-1"></i>Update Entry
                                            </button>
                                            <a href="calendar.php" class="btn btn-outline-secondary ms-2">
                                                Cancel
                                            </a>
                                        </div>
                                        <a href="calendar.php?action=delete&id=<?php echo $id; ?>" 
                                           class="btn btn-outline-danger"
                                           onclick="return confirm('Are you sure you want to delete this calendar entry? This action cannot be undone.');">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Help Card -->
                        <div class="card">
                            <div class="card-header bg-light text-dark">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-question-circle me-2"></i>Guidelines
                                </h5>
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li>Academic year must follow the format: YYYY/YYYY</li>
                                    <li>The second year must be exactly one year after the first</li>
                                    <li>Terms cannot overlap within the same academic year</li>
                                    <li>Ensure dates follow the institution's academic calendar structure</li>
                                    <li>Changes are logged for audit purposes</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Theme Toggle Button -->
    <button class="theme-toggle" id="themeToggle">
        <i class="bi bi-moon" id="themeIcon"></i>
    </button>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;
            const loadingOverlay = document.getElementById('loadingOverlay');
            
            // Theme Toggle
            const currentTheme = localStorage.getItem('theme') || 'light';
            htmlElement.setAttribute('data-bs-theme', currentTheme);
            updateThemeIcon(currentTheme);
            
            themeToggle.addEventListener('click', () => {
                const newTheme = htmlElement.getAttribute('data-bs-theme') === 'light' ? 'dark' : 'light';
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                updateThemeIcon(newTheme);
            });
            
            function updateThemeIcon(theme) {
                themeIcon.className = theme === 'light' ? 'bi bi-moon' : 'bi bi-sun';
            }
            
            // Form Validation
            const form = document.getElementById('editCalendarForm');
            const yearInput = document.getElementById('year');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const yearFeedback = document.getElementById('yearFeedback');
            const dateRangeFeedback = document.getElementById('dateRangeFeedback');
            const durationDisplay = document.getElementById('durationDisplay');
            
            // Year validation
            yearInput.addEventListener('input', validateYear);
            
            // Date range validation and duration calculation
            startDateInput.addEventListener('change', validateDates);
            endDateInput.addEventListener('change', validateDates);
            
            function validateYear() {
                const yearPattern = /^\d{4}\/\d{4}$/;
                const value = yearInput.value.trim();
                
                if (!yearPattern.test(value)) {
                    yearFeedback.textContent = 'Invalid format. Use YYYY/YYYY';
                    yearFeedback.className = 'date-status date-invalid';
                    return false;
                }
                
                const [startYear, endYear] = value.split('/').map(Number);
                
                if (startYear < 2000 || endYear > 2100) {
                    yearFeedback.textContent = 'Year must be between 2000/2001 and 2099/2100';
                    yearFeedback.className = 'date-status date-invalid';
                    return false;
                }
                
                if (endYear !== startYear + 1) {
                    yearFeedback.textContent = 'Second year must be exactly one year after first';
                    yearFeedback.className = 'date-status date-invalid';
                    return false;
                }
                
                yearFeedback.textContent = '✓ Valid academic year format';
                yearFeedback.className = 'date-status date-valid';
                return true;
            }
            
            function validateDates() {
                const startDate = new Date(startDateInput.value);
                const endDate = new Date(endDateInput.value);
                
                if (!startDateInput.value || !endDateInput.value) {
                    dateRangeFeedback.textContent = '';
                    durationDisplay.textContent = 'Please select both dates';
                    return;
                }
                
                // Calculate duration
                const diffTime = Math.abs(endDate - startDate);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                // Validate date order
                if (endDate <= startDate) {
                    dateRangeFeedback.textContent = 'End date must be after start date';
                    dateRangeFeedback.className = 'date-status date-invalid';
                    durationDisplay.textContent = `Invalid: End date must be after start date`;
                } else {
                    dateRangeFeedback.textContent = '✓ Valid date range';
                    dateRangeFeedback.className = 'date-status date-valid';
                    durationDisplay.textContent = `Duration: ${diffDays} days (${Math.floor(diffDays/7)} weeks, ${diffDays % 7} days)`;
                }
            }
            
            // Form submission with loading indicator
            form.addEventListener('submit', function(e) {
                if (!validateYear()) {
                    e.preventDefault();
                    yearInput.focus();
                    return;
                }
                
                if (!startDateInput.value || !endDateInput.value) {
                    e.preventDefault();
                    alert('Please select both start and end dates');
                    return;
                }
                
                const startDate = new Date(startDateInput.value);
                const endDate = new Date(endDateInput.value);
                
                if (endDate <= startDate) {
                    e.preventDefault();
                    alert('End date must be after start date');
                    return;
                }
                
                // Show loading overlay
                loadingOverlay.classList.add('active');
                
                // Auto-hide loading after 10 seconds (safety net)
                setTimeout(() => {
                    loadingOverlay.classList.remove('active');
                }, 10000);
            });
            
            // Initialize validation on page load
            validateYear();
            validateDates();
            
            // Prevent accidental navigation
            window.addEventListener('beforeunload', function(e) {
                const formData = new FormData(form);
                let hasChanges = false;
                
                formData.forEach((value, key) => {
                    const originalValue = form.elements[key]?.defaultValue;
                    if (value !== originalValue && value !== '') {
                        hasChanges = true;
                    }
                });
                
                if (hasChanges) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                    return e.returnValue;
                }
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