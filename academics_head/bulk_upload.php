<?php
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Handle file upload and process CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];

    if (($handle = fopen($file, 'r')) !== false) {
        fgetcsv($handle); // skip header row
        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            $name = $conn->real_escape_string($data[0]);
            $email = $conn->real_escape_string($data[1]);
            $password = password_hash($data[2], PASSWORD_DEFAULT);
            $role = $conn->real_escape_string($data[3]);
            $status = $conn->real_escape_string($data[4]);
            $gender = $conn->real_escape_string($data[5]);
            $phone = $conn->real_escape_string($data[6]);
            $address = $conn->real_escape_string($data[7]);
            $department = $conn->real_escape_string($data[8]);
            $dob = $conn->real_escape_string($data[9]);
            $admission_date = $conn->real_escape_string($data[10]);

            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, gender, phone, address, department, dob, admission_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssss", $name, $email, $password, $role, $status, $gender, $phone, $address, $department, $dob, $admission_date);
            $stmt->execute();
        }
        fclose($handle);
        echo '<div class="alert alert-success">Users successfully uploaded.</div>';
    } else {
        echo '<div class="alert alert-danger">Failed to read the CSV file.</div>';
    }
}
?>

<div class="container mt-5">
    <h2>Bulk Upload Users</h2>
    <p>Upload a CSV file to import users into the system.</p>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label for="csv_file">Choose CSV File:</label>
            <input type="file" name="csv_file" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary mt-3">Upload Users</button>
    </form>
    <p class="text-muted mt-3">CSV columns must be in this order: Name, Email, Password, Role, Status, Gender, Phone, Address, Department, DOB, Admission Date.</p>
</div>

<?php
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
?>
