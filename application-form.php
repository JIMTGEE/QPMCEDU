<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

include $_SERVER['DOCUMENT_ROOT'] . '/queenspa/config/db.php';
include $_SERVER['DOCUMENT_ROOT'] . 'templates/header.php';  <!-- Public Header -->

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone_number = $_POST['phone_number'];
    $date_of_birth = $_POST['date_of_birth'];
    $gender = $_POST['gender'];
    $address = $_POST['address'];
    $department = $_POST['department'];
    $position_applied = $_POST['position_applied'];

    // Insert the application into the database
    $query = "INSERT INTO application_form (first_name, last_name, email, phone_number, date_of_birth, gender, address, department, position_applied) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssssssss", $first_name, $last_name, $email, $phone_number, $date_of_birth, $gender, $address, $department, $position_applied);
    if ($stmt->execute()) {
        echo "Application submitted successfully!";
    } else {
        echo "Error: " . $conn->error;
    }
}
?>

<div class="container mt-5">
    <h2>Submit Application</h2>
    <form method="POST">
        <div class="form-group">
            <label for="first_name">First Name</label>
            <input type="text" class="form-control" id="first_name" name="first_name" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name</label>
            <input type="text" class="form-control" id="last_name" name="last_name" required>
        </div>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" class="form-control" id="email" name="email" required>
        </div>
        <div class="form-group">
            <label for="phone_number">Phone Number</label>
            <input type="text" class="form-control" id="phone_number" name="phone_number" required>
        </div>
        <div class="form-group">
            <label for="date_of_birth">Date of Birth</label>
            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required>
        </div>
        <div class="form-group">
            <label for="gender">Gender</label>
            <select class="form-control" id="gender" name="gender" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <div class="form-group">
            <label for="address">Address</label>
            <textarea class="form-control" id="address" name="address" rows="3"></textarea>
        </div>
        <div class="form-group">
            <label for="department">Department</label>
            <select class="form-control" id="department" name="department" required>
                <option value="Pre-School">Pre-School</option>
                <option value="Lower Primary">Lower Primary</option>
                <option value="Upper Primary">Upper Primary</option>
                <option value="JHS">JHS</option>
            </select>
        </div>
        <div class="form-group">
            <label for="position_applied">Position Applied</label>
            <input type="text" class="form-control" id="position_applied" name="position_applied" required>
        </div>
        <button type="submit" class="btn btn-primary">Submit Application</button>
    </form>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . 'templates/footer.php'; ?>  <!-- Footer -->
