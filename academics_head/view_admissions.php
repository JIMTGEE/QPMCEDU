<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Access denied. You must be an admin to access this page.";
    header("Location: /login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Include log activity function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
}

$admin_id = $_SESSION['user_id'];

// Get application ID
$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($application_id <= 0) {
    $_SESSION['error_msg'] = "Invalid application ID.";
    header("Location: admissions.php");
    exit();
}

// Fetch application details
$sql = "SELECT a.*, c.class_name, d.name as department_name
        FROM admissions a
        LEFT JOIN classes c ON a.class_id = c.id
        LEFT JOIN departments d ON a.department_id = d.id
        WHERE a.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
$application = $result->fetch_assoc();
$stmt->close();

if (!$application) {
    $_SESSION['error_msg'] = "Application not found.";
    header("Location: admissions.php");
    exit();
}

// Fetch classes for dropdown
$classes = [];
$class_result = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name");
if ($class_result) {
    while ($row = $class_result->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Fetch departments for dropdown
$departments = [];
$dept_result = $conn->query("SELECT id, name FROM departments ORDER BY name");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Handle form submission
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $required_fields = ['full_name', 'email', 'student_phone', 'gender', 'address',
                        'father_name', 'father_email', 'father_phone',
                        'mother_name', 'mother_email', 'mother_phone',
                        'class_id', 'academic_year'];

    $missing_fields = [];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $missing_fields[] = $field;
        }
    }

    if (!empty($missing_fields)) {
        $error = "Please fill in all required fields: " . implode(', ', $missing_fields);
    } else {
        // Prepare data
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $student_phone = trim($_POST['student_phone']);
        $gender = $_POST['gender'];
        $address = trim($_POST['address']);
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $nationality = trim($_POST['nationality'] ?? '');
        $previous_school = trim($_POST['previous_school'] ?? '');
        $allergy = trim($_POST['allergy'] ?? '');
        $special_child = $_POST['special_child'] ?? 'no';

        $father_name = trim($_POST['father_name']);
        $father_email = trim($_POST['father_email']);
        $father_phone = trim($_POST['father_phone']);
        $father_national_id = trim($_POST['father_national_id'] ?? '');
        $father_occupation = trim($_POST['father_occupation'] ?? '');
        $father_office_location = trim($_POST['father_office_location'] ?? '');

        $mother_name = trim($_POST['mother_name']);
        $mother_email = trim($_POST['mother_email']);
        $mother_phone = trim($_POST['mother_phone']);
        $mother_national_id = trim($_POST['mother_national_id'] ?? '');
        $mother_occupation = trim($_POST['mother_occupation'] ?? '');
        $mother_office_location = trim($_POST['mother_office_location'] ?? '');

        $guardian_name = trim($_POST['guardian_name'] ?? '');
        $guardian_relationship = trim($_POST['guardian_relationship'] ?? '');
        $guardian_phone = trim($_POST['guardian_phone'] ?? '');
        $guardian_email = trim($_POST['guardian_email'] ?? '');
        $guardian_address = trim($_POST['guardian_address'] ?? '');
        $preferred_contact = $_POST['preferred_contact'] ?? 'father';

        $office_address = trim($_POST['office_address'] ?? '');
        $residential_address = trim($_POST['residential_address'] ?? '');

        $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');

        $class_id = (int)$_POST['class_id'];
        $academic_year = trim($_POST['academic_year']);
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $admission_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : null;

        // Build update query using simple concatenation to avoid bind_param issues
        $update_sql = "UPDATE admissions SET 
            full_name = '" . $conn->real_escape_string($full_name) . "',
            email = '" . $conn->real_escape_string($email) . "',
            student_phone = '" . $conn->real_escape_string($student_phone) . "',
            gender = '" . $conn->real_escape_string($gender) . "',
            address = '" . $conn->real_escape_string($address) . "',
            date_of_birth = " . ($date_of_birth ? "'" . $conn->real_escape_string($date_of_birth) . "'" : "NULL") . ",
            nationality = '" . $conn->real_escape_string($nationality) . "',
            previous_school = '" . $conn->real_escape_string($previous_school) . "',
            allergy = '" . $conn->real_escape_string($allergy) . "',
            special_child = '" . $conn->real_escape_string($special_child) . "',
            father_name = '" . $conn->real_escape_string($father_name) . "',
            father_email = '" . $conn->real_escape_string($father_email) . "',
            father_phone = '" . $conn->real_escape_string($father_phone) . "',
            father_national_id = '" . $conn->real_escape_string($father_national_id) . "',
            father_occupation = '" . $conn->real_escape_string($father_occupation) . "',
            father_office_location = '" . $conn->real_escape_string($father_office_location) . "',
            mother_name = '" . $conn->real_escape_string($mother_name) . "',
            mother_email = '" . $conn->real_escape_string($mother_email) . "',
            mother_phone = '" . $conn->real_escape_string($mother_phone) . "',
            mother_national_id = '" . $conn->real_escape_string($mother_national_id) . "',
            mother_occupation = '" . $conn->real_escape_string($mother_occupation) . "',
            mother_office_location = '" . $conn->real_escape_string($mother_office_location) . "',
            guardian_name = '" . $conn->real_escape_string($guardian_name) . "',
            guardian_relationship = '" . $conn->real_escape_string($guardian_relationship) . "',
            guardian_phone = '" . $conn->real_escape_string($guardian_phone) . "',
            guardian_email = '" . $conn->real_escape_string($guardian_email) . "',
            guardian_address = '" . $conn->real_escape_string($guardian_address) . "',
            preferred_contact = '" . $conn->real_escape_string($preferred_contact) . "',
            office_address = '" . $conn->real_escape_string($office_address) . "',
            residential_address = '" . $conn->real_escape_string($residential_address) . "',
            emergency_contact_name = '" . $conn->real_escape_string($emergency_contact_name) . "',
            emergency_contact_phone = '" . $conn->real_escape_string($emergency_contact_phone) . "',
            class_id = " . (int)$class_id . ",
            academic_year = '" . $conn->real_escape_string($academic_year) . "',
            department_id = " . ($department_id ? (int)$department_id : "NULL") . ",
            admission_date = " . ($admission_date ? "'" . $conn->real_escape_string($admission_date) . "'" : "NULL") . ",
            updated_at = NOW()
            WHERE id = " . (int)$application_id;

        if ($conn->query($update_sql)) {
            $success = true;
            if (function_exists('logActivity')) {
                logActivity($conn, $admin_id, "Edit Application", "Edited application #$application_id for $full_name");
            }

            // Refresh application data
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $application_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $application = $result->fetch_assoc();
            $stmt->close();
        } else {
            $error = "Failed to update application: " . $conn->error;
        }
    }
}

// Helper functions
function safe_value($value, $default = '') {
    return htmlspecialchars($value ?? $default);
}

function selected($value, $compare) {
    return $value == $compare ? 'selected' : '';
}

// Include admin header
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Application #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?> - QueensPa School</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .section-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1.25rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f97316;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-title i {
            color: #f97316;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }
        .form-group {
            margin-bottom: 0.75rem;
        }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #4b5563;
            margin-bottom: 0.25rem;
        }
        .form-group label .required {
            color: #ef4444;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }
    </style>
</head>
<body class="bg-gray-50">

<div class="container mx-auto px-4 py-6 max-w-6xl">
    <!-- Header -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-700 rounded-2xl shadow-lg p-6 mb-6">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="flex items-center mb-4 md:mb-0">
                <i class="fas fa-edit text-white text-3xl mr-3"></i>
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-white">Edit Application</h1>
                    <p class="text-blue-100 text-sm mt-1">Application #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?> - <?php echo safe_value($application['full_name']); ?></p>
                </div>
            </div>
            <div class="flex space-x-3">
                <a href="view_admissions.php?id=<?php echo $application_id; ?>" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg transition flex items-center">
                    <i class="fas fa-eye mr-2"></i> View
                </a>
                <a href="admissions.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg transition flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back
                </a>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success): ?>
        <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded flex items-center">
            <i class="fas fa-check-circle mr-2 text-green-500"></i> Application updated successfully!
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded flex items-center">
            <i class="fas fa-exclamation-circle mr-2 text-red-500"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Edit Form -->
    <form method="POST" action="">
        
        <!-- Student Information -->
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-user-graduate"></i> Student Information
            </h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="full_name" value="<?php echo safe_value($application['full_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" value="<?php echo safe_value($application['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone <span class="required">*</span></label>
                    <input type="text" name="student_phone" value="<?php echo safe_value($application['student_phone']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Gender <span class="required">*</span></label>
                    <select name="gender" required>
                        <option value="male" <?php echo selected($application['gender'], 'male'); ?>>Male</option>
                        <option value="female" <?php echo selected($application['gender'], 'female'); ?>>Female</option>
                        <option value="other" <?php echo selected($application['gender'], 'other'); ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth" value="<?php echo safe_value($application['date_of_birth']); ?>">
                </div>
                <div class="form-group">
                    <label>Nationality</label>
                    <input type="text" name="nationality" value="<?php echo safe_value($application['nationality']); ?>">
                </div>
                <div class="form-group">
                    <label>Previous School</label>
                    <input type="text" name="previous_school" value="<?php echo safe_value($application['previous_school']); ?>">
                </div>
                <div class="form-group">
                    <label>Allergies / Medical</label>
                    <input type="text" name="allergy" value="<?php echo safe_value($application['allergy']); ?>">
                </div>
                <div class="form-group">
                    <label>Special Needs</label>
                    <select name="special_child">
                        <option value="no" <?php echo selected($application['special_child'], 'no'); ?>>No</option>
                        <option value="yes" <?php echo selected($application['special_child'], 'yes'); ?>>Yes</option>
                    </select>
                </div>
            </div>
            <div class="form-group mt-3">
                <label>Address <span class="required">*</span></label>
                <textarea name="address" rows="2" required><?php echo safe_value($application['address']); ?></textarea>
            </div>
        </div>

        <!-- Father Information -->
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-male"></i> Father Information
            </h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="father_name" value="<?php echo safe_value($application['father_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="father_email" value="<?php echo safe_value($application['father_email']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone <span class="required">*</span></label>
                    <input type="text" name="father_phone" value="<?php echo safe_value($application['father_phone']); ?>" required>
                </div>
                <div class="form-group">
                    <label>National ID</label>
                    <input type="text" name="father_national_id" value="<?php echo safe_value($application['father_national_id']); ?>">
                </div>
                <div class="form-group">
                    <label>Occupation</label>
                    <input type="text" name="father_occupation" value="<?php echo safe_value($application['father_occupation']); ?>">
                </div>
                <div class="form-group">
                    <label>Office Location</label>
                    <input type="text" name="father_office_location" value="<?php echo safe_value($application['father_office_location']); ?>">
                </div>
            </div>
        </div>

        <!-- Mother Information -->
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-female"></i> Mother Information
            </h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="mother_name" value="<?php echo safe_value($application['mother_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="mother_email" value="<?php echo safe_value($application['mother_email']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone <span class="required">*</span></label>
                    <input type="text" name="mother_phone" value="<?php echo safe_value($application['mother_phone']); ?>" required>
                </div>
                <div class="form-group">
                    <label>National ID</label>
                    <input type="text" name="mother_national_id" value="<?php echo safe_value($application['mother_national_id']); ?>">
                </div>
                <div class="form-group">
                    <label>Occupation</label>
                    <input type="text" name="mother_occupation" value="<?php echo safe_value($application['mother_occupation']); ?>">
                </div>
                <div class="form-group">
                    <label>Office Location</label>
                    <input type="text" name="mother_office_location" value="<?php echo safe_value($application['mother_office_location']); ?>">
                </div>
            </div>
        </div>

        <!-- Guardian Information -->
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-user-shield"></i> Guardian Information (Optional)
            </h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="guardian_name" value="<?php echo safe_value($application['guardian_name']); ?>">
                </div>
                <div class="form-group">
                    <label>Relationship</label>
                    <input type="text" name="guardian_relationship" value="<?php echo safe_value($application['guardian_relationship']); ?>">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="guardian_phone" value="<?php echo safe_value($application['guardian_phone']); ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="guardian_email" value="<?php echo safe_value($application['guardian_email']); ?>">
                </div>
            </div>
            <div class="form-group mt-3">
                <label>Address</label>
                <textarea name="guardian_address" rows="2"><?php echo safe_value($application['guardian_address']); ?></textarea>
            </div>
            <div class="form-group mt-3">
                <label>Preferred Contact</label>
                <select name="preferred_contact">
                    <option value="father" <?php echo selected($application['preferred_contact'], 'father'); ?>>Father</option>
                    <option value="mother" <?php echo selected($application['preferred_contact'], 'mother'); ?>>Mother</option>
                    <option value="guardian" <?php echo selected($application['preferred_contact'], 'guardian'); ?>>Guardian</option>
                </select>
            </div>
        </div>

        <!-- Address Information -->
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-map-marker-alt"></i> Address Information
            </h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Office Address</label>
                    <textarea name="office_address" rows="2"><?php echo safe_value($application['office_address']); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Residential Address</label>
                    <textarea name="residential_address" rows="2"><?php echo safe_value($application['residential_address']); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Emergency Contact -->
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-phone-alt"></i> Emergency Contact
            </h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Contact Name</label>
                    <input type="text" name="emergency_contact_name" value="<?php echo safe_value($application['emergency_contact_name']); ?>">
                </div>
                <div class="form-group">
                    <label>Contact Phone</label>
                    <input type="text" name="emergency_contact_phone" value="<?php echo safe_value($application['emergency_contact_phone']); ?>">
                </div>
            </div>
        </div>

        <!-- Academic Information -->
        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-school"></i> Academic Information
            </h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Class <span class="required">*</span></label>
                    <select name="class_id" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo selected($application['class_id'], $class['id']); ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Academic Year <span class="required">*</span></label>
                    <input type="text" name="academic_year" value="<?php echo safe_value($application['academic_year']); ?>" required placeholder="2025/2026">
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <select name="department_id">
                        <option value="">None</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo selected($application['department_id'], $dept['id']); ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Admission Date</label>
                    <input type="date" name="admission_date" value="<?php echo safe_value($application['admission_date']); ?>">
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="flex justify-between items-center mt-6">
            <a href="view_admissions.php?id=<?php echo $application_id; ?>" class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition flex items-center">
                <i class="fas fa-times mr-2"></i> Cancel
            </a>
            <button type="submit" class="px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition flex items-center font-semibold">
                <i class="fas fa-save mr-2"></i> Save Changes
            </button>
        </div>
    </form>
</div>

<?php
mysqli_close($conn);
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
}
ob_end_flush();
?>