<?php
ob_start();
session_start();
// Restrict access to logged-in users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
// Include database connection
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
// Get student ID from URL
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) {
    die("Invalid student ID.");
}
// Fetch student information from multiple tables
$student_data = [];
$error_msg = '';

// 1. Fetch basic user information
$user_query = $conn->prepare("
    SELECT u.*,
           d.name AS department_name,
           a.name AS advisor_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN users a ON u.advisor_id = a.id
    WHERE u.id = ? AND u.role = 'student'
");
if (!$user_query) {
    $error_msg = "Error preparing user query: " . $conn->error;
} else {
    $user_query->bind_param("i", $student_id);
    $user_query->execute();
    $user_result = $user_query->get_result();
   
    if ($user_result->num_rows === 0) {
        $error_msg = "Student not found.";
    } else {
        $student_data['user'] = $user_result->fetch_assoc();
    }
    $user_query->close();
}

// 2. Fetch detailed student information from students table
if (empty($error_msg)) {
    $student_query = $conn->prepare("
        SELECT * FROM students
        WHERE user_id = ?
    ");
   
    if ($student_query) {
        $student_query->bind_param("i", $student_id);
        $student_query->execute();
        $student_result = $student_query->get_result();
        $student_data['details'] = $student_result->fetch_assoc();
        $student_query->close();
    }
}

// 3. Get current class from student_classes table
if (empty($error_msg)) {
    $class_query = $conn->prepare("
        SELECT sc.*, c.class_name
        FROM student_classes sc
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE sc.user_id = ?
        ORDER BY sc.created_at DESC
        LIMIT 1
    ");
   
    if (!$class_query) {
        $class_query = $conn->prepare("
            SELECT sc.*, c.classname AS class_name
            FROM student_classes sc
            LEFT JOIN classes c ON sc.class_id = c.id
            WHERE sc.user_id = ?
            ORDER BY sc.created_at DESC
            LIMIT 1
        ");
    }
   
    if ($class_query) {
        $class_query->bind_param("i", $student_id);
        $class_query->execute();
        $class_result = $class_query->get_result();
        $student_data['current_class'] = $class_result->fetch_assoc();
        $class_query->close();
    }
}

// 4. Fetch parent information
if (empty($error_msg)) {
    $parent_query = $conn->prepare("
        SELECT u.*
        FROM parent_student_mapping psm
        JOIN users u ON psm.parent_id = u.id
        WHERE psm.student_id = ?
    ");
   
    if ($parent_query) {
        $parent_query->bind_param("i", $student_id);
        $parent_query->execute();
        $parent_result = $parent_query->get_result();
        $student_data['parents'] = [];
        while ($row = $parent_result->fetch_assoc()) {
            $student_data['parents'][] = $row;
        }
        $parent_query->close();
    }
}

// 5. Fetch admission information
if (empty($error_msg)) {
    $admission_query = $conn->prepare("
        SELECT * FROM admissions
        WHERE email = ? OR full_name = ?
        ORDER BY created_at DESC LIMIT 1
    ");
   
    if ($admission_query) {
        $student_email = $student_data['user']['email'] ?? '';
        $student_name = $student_data['user']['name'] ?? '';
        $admission_query->bind_param("ss", $student_email, $student_name);
        $admission_query->execute();
        $admission_result = $admission_query->get_result();
        $student_data['admission'] = $admission_result->fetch_assoc();
        $admission_query->close();
    }
}

// Helper function to format date
function formatDate($date) {
    if (empty($date) || $date == '0000-00-00') return 'N/A';
    return date('F j, Y', strtotime($date));
}

// Helper function to get profile picture
function getProfilePicture($picture) {
    if (empty($picture) || $picture === '0') {
        return '/assets/images/default_profile.png';
    }
   
    $clean_path = str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $picture);
    $clean_path = ltrim($clean_path, '/');
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean_path;
   
    if (file_exists($full_path) && filesize($full_path) > 100) {
        return '/Uploads/profiles/' . $clean_path;
    }
   
    return '/assets/images/default_profile.png';
}

// Get the class name to display
$class_name = 'Not assigned';
if (!empty($student_data['current_class']['class_name'])) {
    $class_name = $student_data['current_class']['class_name'];
} elseif (!empty($student_data['current_class']['class_id'])) {
    $class_name = 'Class ID: ' . $student_data['current_class']['class_id'];
}

// Get academic year
$academic_year = $student_data['details']['academic_year'] ??
                 $student_data['current_class']['academic_year'] ?? 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile - <?php echo htmlspecialchars($student_data['user']['name'] ?? 'Student'); ?></title>
   
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
   
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
   
    <style>
        :root {
            --orange-primary: #FF6B35;
            --orange-dark: #E55A2B;
            --orange-light: #FF8C5A;
            --orange-pale: #FFF1EB;
            --orange-gradient: linear-gradient(135deg, #FF6B35 0%, #FF8C5A 100%);
            --orange-gradient-dark: linear-gradient(135deg, #E55A2B 0%, #FF6B35 100%);
            --bg-main: #FFF5F0;
            --bg-card: #FFFFFF;
            --text-dark: #1a1a1a;
            --text-medium: #4a4a4a;
            --text-light: #6b7280;
            --border-color: #FFE0D0;
            --shadow-sm: 0 1px 3px rgba(255, 107, 53, 0.1);
            --shadow-md: 0 4px 6px rgba(255, 107, 53, 0.1);
            --shadow-lg: 0 10px 25px rgba(255, 107, 53, 0.15);
            --shadow-xl: 0 20px 50px rgba(255, 107, 53, 0.2);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-main);
            padding: 20px;
            line-height: 1.6;
            color: var(--text-dark);
            min-height: 100vh;
        }

        /* Print styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }
           
            .no-print {
                display: none !important;
            }
           
            .student-document {
                box-shadow: none !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
           
            .page-break {
                page-break-before: always;
            }
           
            @page {
                size: A4;
                margin: 15mm;
            }

            .stat-card, .info-value {
                background: #f8f9fa !important;
            }
        }

        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Top Navigation */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
            font-size: 14px;
        }

        .breadcrumb a {
            color: var(--orange-primary);
            text-decoration: none;
            transition: var(--transition);
        }

        .breadcrumb a:hover {
            color: var(--orange-dark);
            text-decoration: underline;
        }

        .breadcrumb span {
            color: var(--text-light);
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--orange-gradient);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover {
            background: var(--orange-gradient-dark);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: white;
            color: var(--orange-primary);
            border: 2px solid var(--orange-primary);
        }

        .btn-outline:hover {
            background: var(--orange-pale);
            border-color: var(--orange-dark);
            color: var(--orange-dark);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        /* Student Document */
        .student-document {
            background: var(--bg-card);
            max-width: 210mm;
            margin: 0 auto;
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-xl);
        }

        /* Document Header */
        .document-header {
            background: var(--orange-gradient);
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .document-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 50%);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .school-badge {
            position: relative;
            z-index: 1;
        }

        .school-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
        }

        .school-icon i {
            font-size: 36px;
            color: white;
        }

        .school-name {
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1;
        }

        .document-title {
            font-size: 14px;
            opacity: 0.9;
            color: white;
            text-transform: uppercase;
            letter-spacing: 3px;
            position: relative;
            z-index: 1;
        }

        /* Quick Stats Bar */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            padding: 20px 30px;
            background: var(--orange-pale);
            border-bottom: 1px solid var(--border-color);
        }

        .stat-card {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            font-size: 24px;
            color: var(--orange-primary);
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-dark);
        }

        /* Student Header */
        .student-header {
            display: flex;
            align-items: center;
            padding: 30px;
            gap: 25px;
            background: linear-gradient(to bottom, white, var(--orange-pale));
            border-bottom: 1px solid var(--border-color);
        }

        .student-photo {
            width: 140px;
            height: 140px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: var(--shadow-lg);
            position: relative;
        }

        .student-photo::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border: 3px solid var(--orange-primary);
            border-radius: var(--radius-lg);
        }

        .student-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-info-main {
            flex: 1;
        }

        .student-name {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .student-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: var(--text-light);
        }

        .meta-item i {
            color: var(--orange-primary);
            font-size: 14px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .status-inactive {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .status-graduated {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .status-suspended {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        /* Content Sections */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 0;
        }

        .content-section {
            padding: 30px;
            border-bottom: 1px solid var(--border-color);
        }

        .content-section.full-width {
            grid-column: 1 / -1;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--orange-primary);
        }

        .section-icon {
            width: 40px;
            height: 40px;
            background: var(--orange-pale);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--orange-primary);
            font-size: 18px;
            flex-shrink: 0;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Info Grid */
        .info-display {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .info-display.single-column {
            grid-template-columns: 1fr;
        }

        .info-group {
            display: flex;
            flex-direction: column;
        }

        .info-group.full-width {
            grid-column: 1 / -1;
        }

        .info-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-label i {
            font-size: 12px;
            color: var(--orange-primary);
        }

        .info-value {
            font-size: 14px;
            color: var(--text-dark);
            padding: 10px 14px;
            background: var(--orange-pale);
            border-radius: var(--radius-sm);
            min-height: 40px;
            display: flex;
            align-items: center;
            word-break: break-word;
            border: 1px solid transparent;
            transition: var(--transition);
        }

        .info-value:hover {
            border-color: var(--orange-primary);
            background: white;
        }

        .info-value.empty {
            color: #9ca3af;
            font-style: italic;
        }

        .info-value.highlight {
            background: var(--orange-primary);
            color: white;
            font-weight: 500;
        }

        /* Parent Cards */
        .parent-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
        }

        .parent-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .parent-card:hover {
            border-color: var(--orange-primary);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .parent-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .parent-avatar {
            width: 40px;
            height: 40px;
            background: var(--orange-pale);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--orange-primary);
            font-size: 18px;
        }

        .parent-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 15px;
        }

        .parent-details {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .parent-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-medium);
        }

        .parent-detail i {
            color: var(--orange-primary);
            width: 16px;
            text-align: center;
        }

        /* Admission Card */
        .admission-card {
            background: white;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .admission-header {
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--orange-pale);
        }

        .admission-status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .admission-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .admission-accepted {
            background: #d1fae5;
            color: #065f46;
        }

        .admission-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .admission-cancelled {
            background: #e5e7eb;
            color: #374151;
        }

        .admission-body {
            padding: 20px;
        }

        /* Footer */
        .document-footer {
            background: var(--orange-pale);
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: var(--text-light);
            border-top: 1px solid var(--border-color);
        }

        .document-footer p {
            margin-bottom: 4px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--orange-light);
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* Error State */
        .error-state {
            text-align: center;
            padding: 60px 30px;
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
        }

        .error-icon {
            font-size: 64px;
            color: #ef4444;
            margin-bottom: 20px;
        }

        .error-state h2 {
            color: #ef4444;
            margin-bottom: 10px;
        }

        .error-state p {
            color: var(--text-light);
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .student-header {
                flex-direction: column;
                text-align: center;
            }

            .student-meta {
                justify-content: center;
            }

            .student-name {
                font-size: 24px;
            }

            .info-display {
                grid-template-columns: 1fr;
            }

            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
                padding: 15px;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .content-section {
                padding: 20px;
            }

            .document-header {
                padding: 30px 20px;
            }

            .school-name {
                font-size: 22px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .quick-stats {
                grid-template-columns: 1fr;
            }

            .student-photo {
                width: 100px;
                height: 100px;
            }

            .action-buttons {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .student-document {
            animation: fadeInUp 0.5s ease-out;
        }

        .content-section {
            animation: fadeInUp 0.5s ease-out;
            animation-fill-mode: both;
        }

        .content-section:nth-child(1) { animation-delay: 0.1s; }
        .content-section:nth-child(2) { animation-delay: 0.2s; }
        .content-section:nth-child(3) { animation-delay: 0.3s; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Top Navigation -->
        <div class="top-nav no-print">
            <div class="breadcrumb">
                <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span>/</span>
                <a href="user_management.php">Students</a>
                <span>/</span>
                <span><?php echo htmlspecialchars($student_data['user']['name'] ?? 'Profile'); ?></span>
            </div>
            <div class="action-buttons" style="display: flex; gap: 10px;">
                <a href="user_management.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="edit_student.php?id=<?php echo $student_id; ?>" class="btn btn-outline">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <?php if ($error_msg): ?>
            <div class="error-state">
                <i class="fas fa-exclamation-triangle error-icon"></i>
                <h2>Error Loading Profile</h2>
                <p><?php echo $error_msg; ?></p>
                <a href="user_management.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Students
                </a>
            </div>
        <?php else: ?>
            <!-- Student Document -->
            <div class="student-document">
                <!-- Document Header -->
                <div class="document-header">
                    <div class="school-badge">
                        <div class="school-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="school-name">Queenest Paradise Montessori Complex</div>
                        <div class="document-title">Official Student Profile</div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="quick-stats">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-label">Student ID</div>
                        <div class="stat-value">#<?php echo $student_id; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-label">Academic Year</div>
                        <div class="stat-value"><?php echo htmlspecialchars($academic_year); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-door-open"></i>
                        </div>
                        <div class="stat-label">Class</div>
                        <div class="stat-value"><?php echo htmlspecialchars($class_name); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-venus-mars"></i>
                        </div>
                        <div class="stat-label">Gender</div>
                        <div class="stat-value"><?php echo ucfirst($student_data['user']['gender'] ?? 'N/A'); ?></div>
                    </div>
                </div>

                <!-- Student Header -->
                <div class="student-header">
                    <div class="student-photo">
                        <img src="<?php echo getProfilePicture($student_data['user']['profile_picture']); ?>"
                             alt="<?php echo htmlspecialchars($student_data['user']['name']); ?>">
                    </div>
                    <div class="student-info-main">
                        <div class="student-name"><?php echo htmlspecialchars($student_data['user']['name']); ?></div>
                        <div class="student-meta">
                            <span class="meta-item">
                                <i class="fas fa-envelope"></i>
                                <?php echo htmlspecialchars($student_data['user']['email']); ?>
                            </span>
                            <span class="meta-item">
                                <i class="fas fa-phone"></i>
                                <?php echo !empty($student_data['user']['phone']) ? htmlspecialchars($student_data['user']['phone']) : 'N/A'; ?>
                            </span>
                            <span class="meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php 
                                $address = !empty($student_data['user']['address']) && $student_data['user']['address'] != '0' 
                                    ? $student_data['user']['address'] 
                                    : ($student_data['details']['residential_address'] ?? 'N/A');
                                echo htmlspecialchars($address);
                                ?>
                            </span>
                        </div>
                        <?php
                        $status = strtolower($student_data['user']['status'] ?? 'active');
                        $valid_statuses = ['active', 'inactive', 'graduated', 'suspended'];
                        $status_class = in_array($status, $valid_statuses) ? $status : 'active';
                        ?>
                        <span class="status-badge status-<?php echo $status_class; ?>">
                            <i class="fas fa-circle" style="font-size: 8px;"></i>
                            <?php echo ucfirst($student_data['user']['status'] ?? 'Active'); ?>
                        </span>
                    </div>
                </div>

                <!-- Content Sections -->
                <div class="content-grid">
                    <!-- Personal Information -->
                    <div class="content-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="section-title">Personal Information</div>
                        </div>
                        <div class="info-display">
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-calendar"></i> Date of Birth</span>
                                <span class="info-value"><?php echo formatDate($student_data['details']['date_of_birth'] ?? null); ?></span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-flag"></i> Nationality</span>
                                <span class="info-value <?php echo empty($student_data['details']['nationality']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student_data['details']['nationality']) ? htmlspecialchars($student_data['details']['nationality']) : 'Not provided'; ?>
                                </span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-id-card"></i> National ID</span>
                                <span class="info-value <?php echo empty($student_data['user']['national_id']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student_data['user']['national_id']) ? htmlspecialchars($student_data['user']['national_id']) : 'Not provided'; ?>
                                </span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-calendar-check"></i> Admission Date</span>
                                <span class="info-value"><?php echo formatDate($student_data['user']['admission_date'] ?? null); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Academic Information -->
                    <div class="content-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="section-title">Academic Information</div>
                        </div>
                        <div class="info-display">
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-users"></i> Current Class</span>
                                <span class="info-value highlight"><?php echo htmlspecialchars($class_name); ?></span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-building"></i> Department</span>
                                <span class="info-value">
                                    <?php echo !empty($student_data['user']['department_name']) 
                                        ? htmlspecialchars($student_data['user']['department_name']) 
                                        : (!empty($student_data['user']['department']) ? htmlspecialchars($student_data['user']['department']) : 'N/A'); ?>
                                </span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-user-tie"></i> Advisor</span>
                                <span class="info-value <?php echo empty($student_data['user']['advisor_name']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student_data['user']['advisor_name']) ? htmlspecialchars($student_data['user']['advisor_name']) : 'Not assigned'; ?>
                                </span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-school"></i> Previous School</span>
                                <span class="info-value <?php echo empty($student_data['details']['previous_school']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student_data['details']['previous_school']) ? htmlspecialchars($student_data['details']['previous_school']) : 'Not provided'; ?>
                                </span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-clock"></i> Account Created</span>
                                <span class="info-value"><?php echo formatDate($student_data['user']['created_at'] ?? null); ?></span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-sign-in-alt"></i> Last Login</span>
                                <span class="info-value">
                                    <?php echo !empty($student_data['user']['last_login']) ? date('F j, Y g:i A', strtotime($student_data['user']['last_login'])) : 'Never'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Health Information -->
                    <div class="content-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <div class="section-title">Health Information</div>
                        </div>
                        <div class="info-display">
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-allergies"></i> Allergies</span>
                                <?php
                                $allergy = $student_data['details']['allergy'] ?? '';
                                $no_allergy_values = ['NO', 'No', 'NONE', 'None', 'no', 'none', '', ' '];
                                $has_allergy = !in_array(trim($allergy), $no_allergy_values);
                                ?>
                                <span class="info-value <?php echo !$has_allergy ? 'empty' : ''; ?>" 
                                      style="<?php echo $has_allergy ? 'border-color: #ef4444; background: #fef2f2;' : ''; ?>">
                                    <?php if ($has_allergy): ?>
                                        <i class="fas fa-exclamation-triangle" style="color: #ef4444; margin-right: 8px;"></i>
                                    <?php endif; ?>
                                    <?php echo $has_allergy ? htmlspecialchars($allergy) : 'No known allergies'; ?>
                                </span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-child"></i> Special Child</span>
                                <?php $special = $student_data['details']['special_child'] ?? 'no'; ?>
                                <span class="info-value" style="<?php echo strtolower($special) == 'yes' ? 'border-color: #f59e0b; background: #fffbeb;' : ''; ?>">
                                    <?php if (strtolower($special) == 'yes'): ?>
                                        <i class="fas fa-star" style="color: #f59e0b; margin-right: 8px;"></i>
                                    <?php endif; ?>
                                    <span style="color: <?php echo strtolower($special) == 'yes' ? '#d97706' : '#059669'; ?>; font-weight: 600;">
                                        <?php echo strtoupper($special); ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="content-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-phone-alt"></i>
                            </div>
                            <div class="section-title">Emergency Contact</div>
                        </div>
                        <div class="info-display">
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-user"></i> Contact Name</span>
                                <span class="info-value <?php echo empty($student_data['details']['emergency_contact_name']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student_data['details']['emergency_contact_name']) ? htmlspecialchars($student_data['details']['emergency_contact_name']) : 'Not provided'; ?>
                                </span>
                            </div>
                            <div class="info-group">
                                <span class="info-label"><i class="fas fa-phone"></i> Contact Phone</span>
                                <span class="info-value <?php echo empty($student_data['details']['emergency_contact_phone']) ? 'empty' : ''; ?>">
                                    <?php echo !empty($student_data['details']['emergency_contact_phone']) ? htmlspecialchars($student_data['details']['emergency_contact_phone']) : 'Not provided'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Parent/Guardian Information -->
                    <div class="content-section full-width">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="section-title">Parent / Guardian Information</div>
                        </div>
                        <?php if (!empty($student_data['parents'])): ?>
                            <div class="parent-grid">
                                <?php foreach ($student_data['parents'] as $parent): ?>
                                    <div class="parent-card">
                                        <div class="parent-header">
                                            <div class="parent-avatar">
                                                <i class="fas fa-user-circle"></i>
                                            </div>
                                            <div class="parent-name"><?php echo htmlspecialchars($parent['name'] ?? 'Parent'); ?></div>
                                        </div>
                                        <div class="parent-details">
                                            <div class="parent-detail">
                                                <i class="fas fa-envelope"></i>
                                                <span><?php echo htmlspecialchars($parent['email'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="parent-detail">
                                                <i class="fas fa-phone"></i>
                                                <span><?php echo htmlspecialchars($parent['phone'] ?? 'N/A'); ?></span>
                                            </div>
                                            <?php if (!empty($parent['address']) && $parent['address'] != '0'): ?>
                                            <div class="parent-detail">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <span><?php echo htmlspecialchars($parent['address']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($parent['phone_number'])): ?>
                                            <div class="parent-detail">
                                                <i class="fas fa-mobile-alt"></i>
                                                <span><?php echo htmlspecialchars($parent['phone_number']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-user-friends"></i>
                                <p>No parent or guardian information available for this student.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Admission Information -->
                    <?php if (!empty($student_data['admission'])): ?>
                    <div class="content-section full-width page-break">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="section-title">Admission Information</div>
                        </div>
                        <div class="admission-card">
                            <div class="admission-header">
                                <span style="font-weight: 600; color: var(--text-dark);">
                                    Application Details
                                </span>
                                <span class="admission-status-badge admission-<?php echo strtolower($student_data['admission']['status'] ?? 'pending'); ?>">
                                    <?php echo ucfirst($student_data['admission']['status'] ?? 'Pending'); ?>
                                </span>
                            </div>
                            <div class="admission-body">
                                <div class="info-display">
                                    <div class="info-group">
                                        <span class="info-label"><i class="fas fa-calendar-plus"></i> Application Date</span>
                                        <span class="info-value"><?php echo formatDate($student_data['admission']['created_at'] ?? null); ?></span>
                                    </div>
                                    <div class="info-group">
                                        <span class="info-label"><i class="fas fa-door-open"></i> Applied For Class</span>
                                        <span class="info-value"><?php echo htmlspecialchars($student_data['admission']['class_id'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="info-group">
                                        <span class="info-label"><i class="fas fa-calendar"></i> Academic Year</span>
                                        <span class="info-value"><?php echo htmlspecialchars($student_data['admission']['academic_year'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="info-group">
                                        <span class="info-label"><i class="fas fa-user-check"></i> Accepted By</span>
                                        <span class="info-value <?php echo empty($student_data['admission']['accepted_by']) ? 'empty' : ''; ?>">
                                            <?php echo !empty($student_data['admission']['accepted_by']) ? 'Admin ID: ' . $student_data['admission']['accepted_by'] : 'Pending approval'; ?>
                                        </span>
                                    </div>
                                    <div class="info-group">
                                        <span class="info-label"><i class="fas fa-clock"></i> Accepted At</span>
                                        <span class="info-value <?php echo empty($student_data['admission']['accepted_at']) ? 'empty' : ''; ?>">
                                            <?php echo !empty($student_data['admission']['accepted_at']) ? date('F j, Y g:i A', strtotime($student_data['admission']['accepted_at'])) : 'N/A'; ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($student_data['admission']['admin_notes'])): ?>
                                    <div class="info-group full-width">
                                        <span class="info-label"><i class="fas fa-sticky-note"></i> Admin Notes</span>
                                        <span class="info-value"><?php echo nl2br(htmlspecialchars($student_data['admission']['admin_notes'])); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Document Footer -->
                <div class="document-footer">
                    <p><strong>Queenest Paradise Montessori Complex</strong> | Official Student Profile</p>
                    <p>Generated on <?php echo date('F j, Y \a\t g:i A'); ?> | Ref: STUDENT-<?php echo $student_id; ?>-<?php echo date('Ymd'); ?></p>
                    <p style="font-size: 10px; opacity: 0.7;">This is an official document. For verification, please contact the school administration.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Smooth scroll and print functionality
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('print') === '1') {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        });

        // Add hover effects for info values
        document.querySelectorAll('.info-value').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(2px)';
            });
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });
    </script>
</body>
</html>
<?php
if (isset($conn)) {
    $conn->close();
}
ob_end_flush();
?>