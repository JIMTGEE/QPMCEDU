<?php
session_start();
require_once __DIR__ . '/config/db.php';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch classes from database with proper ordering
$classes = [];
$class_result = $conn->query("SELECT id, class_name FROM classes ORDER BY 
    FIELD(class_name, 
        'Esther Foundation',
        'Sheba One', 'Sheba Two',
        'Yaasant One', 'Yaasant Two',
        'Cleopatra One', 'Cleopatra Two',
        'Nefertiti One', 'Nefertiti Two',
        'Malika One', 'Malika Two',
        'Makeda One', 'Makeda Two',
        'Majesty'
    )");
while ($row = $class_result->fetch_assoc()) {
    $classes[] = $row;
}

// Class display mapping
$class_display_names = [
    'Esther Foundation' => 'Esther Foundation (Creche)',
    'Sheba One' => 'Sheba One (Nursery 1)',
    'Sheba Two' => 'Sheba Two (Nursery 2)',
    'Yaasant One' => 'Yaasant One (Kg 1)',
    'Yaasant Two' => 'Yaasant Two (Kg 2)',
    'Cleopatra One' => 'Cleopatra One (Basic 1)',
    'Cleopatra Two' => 'Cleopatra Two (Basic 2)',
    'Nefertiti One' => 'Nefertiti One (Basic 3)',
    'Nefertiti Two' => 'Nefertiti Two (Basic 4)',
    'Malika One' => 'Malika One (Basic 5)',
    'Malika Two' => 'Malika Two (Basic 6)',
    'Makeda One' => 'Makeda One (Basic 7)',
    'Makeda Two' => 'Makeda Two (Basic 8)',
    'Majesty' => 'Majesty (Basic 9)'
];

// Fetch academic years
$academic_years = [];
$year_result = $conn->query("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
if ($year_result->num_rows > 0) {
    while ($row = $year_result->fetch_assoc()) {
        $academic_years[] = $row['year'];
    }
} else {
    // Fallback academic years
    $current_year = date('Y');
    $academic_years = [
        ($current_year - 1) . '/' . $current_year,
        $current_year . '/' . ($current_year + 1),
        ($current_year + 1) . '/' . ($current_year + 2)
    ];
}

$success_msg = '';
$error_msg = '';
$form_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid security token. Please refresh the page and try again.";
    } else {
        // Collect and sanitize form data
        $form_data = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'student_phone' => trim($_POST['student_phone'] ?? ''),
            'gender' => trim($_POST['gender'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'date_of_birth' => trim($_POST['date_of_birth'] ?? ''),
            'nationality' => trim($_POST['nationality'] ?? ''),
            'previous_school' => trim($_POST['previous_school'] ?? ''),
            'allergy' => trim($_POST['allergy'] ?? ''),
            'special_child' => $_POST['special_child'] ?? 'no',
            // Father information
            'father_name' => trim($_POST['father_name'] ?? ''),
            'father_email' => trim($_POST['father_email'] ?? ''),
            'father_phone' => trim($_POST['father_phone'] ?? ''),
            'father_national_id' => trim($_POST['father_national_id'] ?? ''),
            'father_occupation' => trim($_POST['father_occupation'] ?? ''),
            'father_office_location' => trim($_POST['father_office_location'] ?? ''),
            // Mother information
            'mother_name' => trim($_POST['mother_name'] ?? ''),
            'mother_email' => trim($_POST['mother_email'] ?? ''),
            'mother_phone' => trim($_POST['mother_phone'] ?? ''),
            'mother_national_id' => trim($_POST['mother_national_id'] ?? ''),
            'mother_occupation' => trim($_POST['mother_occupation'] ?? ''),
            'mother_office_location' => trim($_POST['mother_office_location'] ?? ''),
            // Guardian information
            'guardian_name' => trim($_POST['guardian_name'] ?? ''),
            'guardian_relationship' => trim($_POST['guardian_relationship'] ?? ''),
            'guardian_phone' => trim($_POST['guardian_phone'] ?? ''),
            'guardian_email' => trim($_POST['guardian_email'] ?? ''),
            'guardian_address' => trim($_POST['guardian_address'] ?? ''),
            'preferred_contact' => trim($_POST['preferred_contact'] ?? 'father'),
            'office_address' => trim($_POST['office_address'] ?? ''),
            'residential_address' => trim($_POST['residential_address'] ?? ''),
            'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
            'emergency_contact_phone' => trim($_POST['emergency_contact_phone'] ?? ''),
            'class_id' => (int)($_POST['class_id'] ?? 0),
            'academic_year' => trim($_POST['academic_year'] ?? ''),
            'how_hear' => trim($_POST['how_hear'] ?? ''),
            'additional_info' => trim($_POST['additional_info'] ?? ''),
            'consent' => isset($_POST['consent'])
        ];
        
        // Validation
        $errors = [];
        
        // Required fields
        if (empty($form_data['full_name'])) $errors[] = "Student's full name is required.";
        if (empty($form_data['email'])) $errors[] = "Student's email is required.";
        if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid student email format.";
        if (empty($form_data['student_phone'])) $errors[] = "Student's phone number is required.";
        if (empty($form_data['gender'])) $errors[] = "Gender is required.";
        if (empty($form_data['address'])) $errors[] = "Address is required.";
        if (empty($form_data['class_id']) || $form_data['class_id'] <= 0) $errors[] = "Please select a grade/class.";
        if (empty($form_data['academic_year'])) $errors[] = "Academic year is required.";
        if (!$form_data['consent']) $errors[] = "You must agree to the terms and conditions.";
        
        // At least one parent/guardian contact required
        if (empty($form_data['father_name']) && empty($form_data['mother_name']) && empty($form_data['guardian_name'])) {
            $errors[] = "Please provide at least one parent/guardian name.";
        }
        
        // Optional field validation
        if (!empty($form_data['student_phone']) && !preg_match('/^\+?\d{8,15}$/', $form_data['student_phone'])) {
            $errors[] = "Invalid student phone number format.";
        }
        if (!empty($form_data['father_phone']) && !preg_match('/^\+?\d{8,15}$/', $form_data['father_phone'])) {
            $errors[] = "Invalid father's phone number format.";
        }
        if (!empty($form_data['mother_phone']) && !preg_match('/^\+?\d{8,15}$/', $form_data['mother_phone'])) {
            $errors[] = "Invalid mother's phone number format.";
        }
        if (!empty($form_data['emergency_contact_phone']) && !preg_match('/^\+?\d{8,15}$/', $form_data['emergency_contact_phone'])) {
            $errors[] = "Invalid emergency contact phone number format.";
        }
        if (!empty($form_data['date_of_birth']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_data['date_of_birth'])) {
            $errors[] = "Invalid date of birth format.";
        }
        
        // Check if email already exists in admissions
        if (empty($errors)) {
            $check_stmt = $conn->prepare("SELECT id FROM admissions WHERE email = ?");
            $check_stmt->bind_param("s", $form_data['email']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                $errors[] = "An application with this email already exists. Please use a different email or contact admissions.";
            }
            $check_stmt->close();
        }
        
        // If no errors, insert into database
        if (empty($errors)) {
            // Prepare admin_notes with additional info
            $admin_notes = "";
            if (!empty($form_data['how_hear'])) {
                $admin_notes .= "How heard about us: " . $form_data['how_hear'] . "\n";
            }
            if (!empty($form_data['additional_info'])) {
                $admin_notes .= "Additional information: " . $form_data['additional_info'] . "\n";
            }
            $admin_notes = !empty($admin_notes) ? trim($admin_notes) : null;
            
            $stmt = $conn->prepare("INSERT INTO admissions (
                full_name, email, student_phone, gender, address, date_of_birth, nationality,
                previous_school, allergy, special_child,
                father_name, father_email, father_phone, father_national_id, father_occupation, father_office_location,
                mother_name, mother_email, mother_phone, mother_national_id, mother_occupation, mother_office_location,
                guardian_name, guardian_relationship, guardian_phone, guardian_email, guardian_address, preferred_contact,
                office_address, residential_address, emergency_contact_name, emergency_contact_phone,
                class_id, academic_year, admin_notes, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            
            $stmt->bind_param(
                "ssssssssssssssssssssssssssssssssiss",
                $form_data['full_name'],
                $form_data['email'],
                $form_data['student_phone'],
                $form_data['gender'],
                $form_data['address'],
                $form_data['date_of_birth'],
                $form_data['nationality'],
                $form_data['previous_school'],
                $form_data['allergy'],
                $form_data['special_child'],
                $form_data['father_name'],
                $form_data['father_email'],
                $form_data['father_phone'],
                $form_data['father_national_id'],
                $form_data['father_occupation'],
                $form_data['father_office_location'],
                $form_data['mother_name'],
                $form_data['mother_email'],
                $form_data['mother_phone'],
                $form_data['mother_national_id'],
                $form_data['mother_occupation'],
                $form_data['mother_office_location'],
                $form_data['guardian_name'],
                $form_data['guardian_relationship'],
                $form_data['guardian_phone'],
                $form_data['guardian_email'],
                $form_data['guardian_address'],
                $form_data['preferred_contact'],
                $form_data['office_address'],
                $form_data['residential_address'],
                $form_data['emergency_contact_name'],
                $form_data['emergency_contact_phone'],
                $form_data['class_id'],
                $form_data['academic_year'],
                $admin_notes
            );
            
            if ($stmt->execute()) {
                $application_id = $conn->insert_id;
                $_SESSION['success_msg'] = "Application submitted successfully! Your application ID is QPMS-" . date('Ymd') . "-" . $application_id . ". We will contact you within 3-5 business days.";
                header("Location: admissions.php?success=1");
                exit();
            } else {
                $error_msg = "Database error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_msg = implode("<br>", $errors);
        }
    }
}

// Check for success message in session
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admissions - Queenest Paradise Montessori Complex</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'Poppins', 'ui-sans-serif', 'system-ui'],
                        'poppins': ['Poppins', 'sans-serif'],
                        'display': ['Poppins', 'Inter', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#fff7ed',
                            100: '#ffedd5',
                            200: '#fed7aa',
                            300: '#fdba74',
                            400: '#fb923c',
                            500: '#f97316',
                            600: '#ea580c',
                            700: '#c2410c',
                            800: '#9a3412',
                            900: '#7c2d12',
                        }
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'fade-in': 'fadeIn 0.8s ease-out',
                        'float': 'float 6s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%': { opacity: '0', transform: 'translateY(30px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' }
                        }
                    },
                    backgroundImage: {
                        'gradient-primary': 'linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%)',
                        'gradient-dark': 'linear-gradient(135deg, #0f172a 0%, #1e293b 100%)',
                    }
                }
            }
        }
    </script>
    
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        .section-title {
            position: relative;
            display: inline-block;
            font-family: 'Poppins', sans-serif;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(to right, #f97316, #ea580c);
            border-radius: 2px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.7s;
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);
            transform: translateY(-3px);
            box-shadow: 0 15px 30px -5px rgba(249, 115, 22, 0.5);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
            outline: none;
        }
        
        .step-indicator {
            transition: all 0.3s ease;
        }
        
        .step-indicator.active {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: white;
            transform: scale(1.1);
        }
        
        .page-load {
            animation: fadeIn 1s ease-out;
        }
        
        .success-checkmark {
            animation: fadeIn 0.5s ease-out, float 2s ease-in-out infinite;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, #f97316, #ea580c);
            border-radius: 4px;
        }
        
        /* Smooth transitions */
        .parent-card {
            transition: all 0.3s ease;
        }
        
        .parent-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body class="min-h-screen bg-gradient-to-b from-gray-50 to-white page-load">
    
    <!-- Navigation Bar -->
    <nav class="fixed w-full bg-white/95 backdrop-blur-lg z-50 shadow-xl border-b border-gray-100/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <!-- Logo -->
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <div class="bg-gradient-primary p-3 rounded-2xl shadow-2xl">
                            <i class="fas fa-graduation-cap text-white text-2xl"></i>
                        </div>
                        <div class="absolute -top-2 -right-2 w-4 h-4 bg-green-500 rounded-full border-2 border-white"></div>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 font-display">Queenest Paradise</h1>
                        <p class="text-xs text-gray-600 font-semibold tracking-wider">MONTESSORI COMPLEX</p>
                        <span class="text-xs bg-orange-100 text-orange-800 px-2 py-0.5 rounded-full font-medium">QueensPa</span>
                    </div>
                </div>
                
                <!-- Desktop Menu -->
                <div class="hidden lg:flex items-center space-x-8">
                    <a href="index.php" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Home
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="about.php" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        About
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="index.php#gallery" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Gallery
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="index.php#programs" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Programs
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <div class="flex items-center space-x-4">
                        <a href="login.php" class="btn-primary text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300">
                            <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                        </a>
                        <a href="admissions.php" class="bg-white text-orange-600 border-2 border-orange-600 px-6 py-3 rounded-xl font-semibold hover:bg-orange-50 hover:shadow-lg transition-all duration-300">
                            <i class="fas fa-edit mr-2"></i>Apply Now
                        </a>
                    </div>
                </div>
                
                <!-- Mobile menu button -->
                <button id="mobile-menu-button" class="lg:hidden text-gray-700 hover:text-orange-600 transition-colors duration-300">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
            </div>
            
            <!-- Mobile Menu -->
            <div id="mobile-menu" class="lg:hidden hidden py-6 border-t border-gray-100 bg-white/95 backdrop-blur-lg">
                <div class="flex flex-col space-y-6">
                    <a href="index.php" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Home</a>
                    <a href="about.php" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">About</a>
                    <a href="index.php#gallery" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Gallery</a>
                    <a href="index.php#programs" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Programs</a>
                    <div class="pt-4 space-y-4">
                        <a href="login.php" class="block btn-primary text-white px-6 py-4 rounded-xl font-semibold text-center hover:shadow-lg transition-all duration-300">
                            <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                        </a>
                        <a href="admissions.php" class="block bg-white text-orange-600 border-2 border-orange-600 px-6 py-4 rounded-xl font-semibold text-center hover:bg-orange-50 transition-all duration-300">
                            <i class="fas fa-edit mr-2"></i>Apply Now
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="pt-32 pb-20 relative overflow-hidden bg-gradient-dark">
        <div class="absolute inset-0">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-orange-500/10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-red-500/10 rounded-full blur-3xl"></div>
        </div>
        
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center animate-fade-in-up">
                <div class="inline-flex items-center px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm border border-orange-400/30 text-orange-200 text-sm font-semibold mb-6">
                    <i class="fas fa-door-open mr-2 text-yellow-300"></i>
                    Admissions <?php echo date('Y') . '/' . (date('Y') + 1); ?>
                </div>
                
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight mb-6 font-display">
                    Join the
                    <span class="block gradient-text">
                        QueensPa Family
                    </span>
                </h1>
                
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 mb-8 border border-white/20 max-w-3xl mx-auto">
                    <div class="flex items-center justify-center">
                        <i class="fas fa-cross text-orange-300 text-2xl mr-4"></i>
                        <p class="text-xl text-orange-100 font-semibold italic">
                            "Christ Our Foundation, Integrity, Discipline, and Excellence"
                        </p>
                    </div>
                </div>
                
                <p class="text-xl text-gray-300 leading-relaxed mb-10 max-w-3xl mx-auto">
                    Begin your child's journey towards academic excellence and character development. Our admissions process is designed to understand each student's unique potential.
                </p>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <section class="py-16 pb-24">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-3 gap-12">
                <!-- Left Sidebar - Info -->
                <div class="lg:col-span-1">
                    <div class="sticky top-32 space-y-8">
                        <!-- Important Dates -->
                        <div class="bg-white rounded-2xl p-6 shadow-xl border border-gray-100">
                            <h3 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-calendar-alt text-orange-500 mr-3"></i>
                                Important Dates
                            </h3>
                            <div class="space-y-4">
                                <div class="flex items-start">
                                    <div class="bg-orange-100 text-orange-800 font-bold px-3 py-1 rounded-lg mr-4">Now</div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Applications Open</h4>
                                        <p class="text-sm text-gray-600">Limited spaces available</p>
                                    </div>
                                </div>
                                <div class="flex items-start">
                                    <div class="bg-orange-100 text-orange-800 font-bold px-3 py-1 rounded-lg mr-4">Ongoing</div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Campus Tours</h4>
                                        <p class="text-sm text-gray-600">Schedule your visit</p>
                                    </div>
                                </div>
                                <div class="flex items-start">
                                    <div class="bg-orange-100 text-orange-800 font-bold px-3 py-1 rounded-lg mr-4">Rolling</div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Admissions Decisions</h4>
                                        <p class="text-sm text-gray-600">Within 3-5 business days</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Requirements -->
                        <div class="bg-white rounded-2xl p-6 shadow-xl border border-gray-100">
                            <h3 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-clipboard-check text-orange-500 mr-3"></i>
                                What You'll Need
                            </h3>
                            <ul class="space-y-3">
                                <li class="flex items-center text-gray-700"><i class="fas fa-check-circle text-green-500 mr-3"></i> Student's birth certificate</li>
                                <li class="flex items-center text-gray-700"><i class="fas fa-check-circle text-green-500 mr-3"></i> Previous school reports (if applicable)</li>
                                <li class="flex items-center text-gray-700"><i class="fas fa-check-circle text-green-500 mr-3"></i> Parent/Guardian ID</li>
                                <li class="flex items-center text-gray-700"><i class="fas fa-check-circle text-green-500 mr-3"></i> Medical/Immunization records</li>
                                <li class="flex items-center text-gray-700"><i class="fas fa-check-circle text-green-500 mr-3"></i> Recent passport photograph</li>
                            </ul>
                        </div>
                        
                        <!-- Contact Info -->
                        <div class="bg-gradient-primary rounded-2xl p-6 text-white">
                            <h3 class="text-xl font-bold mb-6 flex items-center"><i class="fas fa-headset mr-3"></i> Need Help?</h3>
                            <p class="mb-6">Our admissions team is here to guide you through the process.</p>
                            <div class="space-y-4">
                                <div class="flex items-center"><i class="fas fa-phone mr-3 text-lg"></i><span class="font-semibold">+233 (0) 123-456-789</span></div>
                                <div class="flex items-center"><i class="fas fa-clock mr-3 text-lg"></i><span class="font-semibold">Mon-Fri: 7:30 AM - 4:30 PM</span></div>
                                <div class="flex items-center"><i class="fas fa-map-marker-alt mr-3 text-lg"></i><span class="font-semibold">Accra Campus</span></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Form Area -->
                <div class="lg:col-span-2">
                    <?php if (!empty($success_msg)): ?>
                    <!-- Success Message -->
                    <div class="bg-white rounded-2xl p-8 shadow-xl border border-gray-100 animate-fade-in-up">
                        <div class="text-center">
                            <div class="inline-flex items-center justify-center w-24 h-24 bg-gradient-primary rounded-full mb-8 success-checkmark">
                                <i class="fas fa-check text-white text-4xl"></i>
                            </div>
                            <h2 class="text-3xl font-bold text-gray-900 mb-4">Application Submitted Successfully!</h2>
                            <p class="text-xl text-gray-600 mb-8 max-w-2xl mx-auto"><?php echo htmlspecialchars($success_msg); ?></p>
                            <div class="space-y-4">
                                <div class="bg-orange-50 border border-orange-200 rounded-xl p-6">
                                    <h3 class="font-bold text-gray-900 mb-2 flex items-center"><i class="fas fa-database text-orange-500 mr-3"></i>Application Saved Successfully</h3>
                                    <ul class="space-y-2 text-gray-700">
                                        <li class="flex items-start"><i class="fas fa-check text-green-500 mr-2 mt-1"></i><span>Your application has been saved to our secure database</span></li>
                                        <li class="flex items-start"><i class="fas fa-check text-green-500 mr-2 mt-1"></i><span>Our admissions team will review your application</span></li>
                                        <li class="flex items-start"><i class="fas fa-check text-green-500 mr-2 mt-1"></i><span>We'll contact you at the provided email within 3-5 business days</span></li>
                                    </ul>
                                </div>
                                <div class="flex flex-col sm:flex-row gap-4 pt-6">
                                    <a href="index.php" class="btn-primary text-white px-8 py-4 rounded-xl font-semibold text-lg text-center"><i class="fas fa-home mr-2"></i>Back to Home</a>
                                    <a href="admissions.php" class="bg-white text-orange-600 border-2 border-orange-600 px-8 py-4 rounded-xl font-semibold text-lg hover:bg-orange-50 transition-all duration-300 text-center"><i class="fas fa-plus mr-2"></i>New Application</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    
                    <!-- Application Form -->
                    <div id="applicationForm" class="bg-white rounded-2xl p-8 shadow-xl border border-gray-100 animate-fade-in-up">
                        <!-- Progress Steps -->
                        <div class="mb-10">
                            <h2 class="text-3xl font-bold text-gray-900 mb-2">Online Application Form</h2>
                            <p class="text-gray-600 mb-8">Complete the form below to start your child's journey with us.</p>
                            
                            <?php if (!empty($error_msg)): ?>
                            <div class="bg-red-50 border border-red-200 text-red-700 px-6 py-4 rounded-xl mb-6">
                                <div class="flex items-center"><i class="fas fa-exclamation-circle mr-3 text-xl"></i><span><?php echo $error_msg; ?></span></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Form -->
                        <form method="POST" action="" class="space-y-8" id="admissionForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            
                            <!-- Student Information -->
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 mb-6 pb-4 border-b border-gray-200 flex items-center">
                                    <span class="flex items-center justify-center w-8 h-8 bg-orange-100 text-orange-800 rounded-full mr-3">1</span>
                                    Student Information
                                </h3>
                                
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="full_name" class="block text-sm font-semibold text-gray-900 mb-2">Student's Full Name <span class="text-red-500">*</span></label>
                                        <input type="text" id="full_name" name="full_name" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Enter student's full name" value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>">
                                    </div>
                                    
                                    <div>
                                        <label for="email" class="block text-sm font-semibold text-gray-900 mb-2">Student's Email <span class="text-red-500">*</span></label>
                                        <input type="email" id="email" name="email" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="student.email@example.com" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                                    </div>
                                    
                                    <div>
                                        <label for="student_phone" class="block text-sm font-semibold text-gray-900 mb-2">Student's Phone Number <span class="text-red-500">*</span></label>
                                        <input type="tel" id="student_phone" name="student_phone" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="+233 XX XXX XXXX" value="<?php echo htmlspecialchars($form_data['student_phone'] ?? ''); ?>">
                                    </div>
                                    
                                    <div>
                                        <label for="gender" class="block text-sm font-semibold text-gray-900 mb-2">Gender <span class="text-red-500">*</span></label>
                                        <select id="gender" name="gender" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300">
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo (isset($form_data['gender']) && $form_data['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo (isset($form_data['gender']) && $form_data['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo (isset($form_data['gender']) && $form_data['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="date_of_birth" class="block text-sm font-semibold text-gray-900 mb-2">Date of Birth</label>
                                        <input type="date" id="date_of_birth" name="date_of_birth" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>">
                                    </div>
                                    
                                    <div>
                                        <label for="nationality" class="block text-sm font-semibold text-gray-900 mb-2">Nationality</label>
                                        <input type="text" id="nationality" name="nationality" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="e.g., Ghanaian" value="<?php echo htmlspecialchars($form_data['nationality'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="md:col-span-2">
                                        <label for="address" class="block text-sm font-semibold text-gray-900 mb-2">Residential Address <span class="text-red-500">*</span></label>
                                        <textarea id="address" name="address" rows="2" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Enter complete residential address"><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="md:col-span-2">
                                        <label for="previous_school" class="block text-sm font-semibold text-gray-900 mb-2">Previous School (if applicable)</label>
                                        <input type="text" id="previous_school" name="previous_school" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Name of previous school" value="<?php echo htmlspecialchars($form_data['previous_school'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="md:col-span-2">
                                        <label for="allergy" class="block text-sm font-semibold text-gray-900 mb-2">Allergies / Medical Conditions</label>
                                        <textarea id="allergy" name="allergy" rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Please list any allergies or medical conditions"><?php echo htmlspecialchars($form_data['allergy'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-900 mb-2">Special Needs / Considerations</label>
                                        <div class="flex space-x-6">
                                            <label class="flex items-center"><input type="radio" name="special_child" value="no" class="mr-2 text-orange-600 focus:ring-orange-500" <?php echo (!isset($form_data['special_child']) || $form_data['special_child'] == 'no') ? 'checked' : ''; ?>> <span>No</span></label>
                                            <label class="flex items-center"><input type="radio" name="special_child" value="yes" class="mr-2 text-orange-600 focus:ring-orange-500" <?php echo (isset($form_data['special_child']) && $form_data['special_child'] == 'yes') ? 'checked' : ''; ?>> <span>Yes</span></label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Parent/Guardian Information - Father & Mother -->
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 mb-6 pb-4 border-b border-gray-200 flex items-center">
                                    <span class="flex items-center justify-center w-8 h-8 bg-orange-100 text-orange-800 rounded-full mr-3">2</span>
                                    Parent/Guardian Information
                                </h3>
                                
                                <!-- Father's Information Card -->
                                <div class="parent-card bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 mb-6">
                                    <div class="flex items-center mb-4">
                                        <div class="bg-blue-600 p-2 rounded-full mr-3">
                                            <i class="fas fa-male text-white text-sm"></i>
                                        </div>
                                        <h4 class="text-lg font-bold text-gray-900">Father's Information</h4>
                                    </div>
                                    
                                    <div class="grid md:grid-cols-2 gap-5">
                                        <div>
                                            <label for="father_name" class="block text-sm font-semibold text-gray-900 mb-2">Father's Full Name</label>
                                            <input type="text" id="father_name" name="father_name" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Enter father's full name" value="<?php echo htmlspecialchars($form_data['father_name'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="father_email" class="block text-sm font-semibold text-gray-900 mb-2">Father's Email</label>
                                            <input type="email" id="father_email" name="father_email" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="father@example.com" value="<?php echo htmlspecialchars($form_data['father_email'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="father_phone" class="block text-sm font-semibold text-gray-900 mb-2">Father's Phone Number</label>
                                            <input type="tel" id="father_phone" name="father_phone" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="+233 XX XXX XXXX" value="<?php echo htmlspecialchars($form_data['father_phone'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="father_national_id" class="block text-sm font-semibold text-gray-900 mb-2">Father's National ID</label>
                                            <input type="text" id="father_national_id" name="father_national_id" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Ghana Card / Passport" value="<?php echo htmlspecialchars($form_data['father_national_id'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="father_occupation" class="block text-sm font-semibold text-gray-900 mb-2">Father's Occupation</label>
                                            <input type="text" id="father_occupation" name="father_occupation" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Occupation" value="<?php echo htmlspecialchars($form_data['father_occupation'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="father_office_location" class="block text-sm font-semibold text-gray-900 mb-2">Father's Office Location</label>
                                            <input type="text" id="father_office_location" name="father_office_location" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Office location / city" value="<?php echo htmlspecialchars($form_data['father_office_location'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Mother's Information Card -->
                                <div class="parent-card bg-gradient-to-r from-pink-50 to-rose-50 rounded-xl p-6 mb-6">
                                    <div class="flex items-center mb-4">
                                        <div class="bg-pink-600 p-2 rounded-full mr-3">
                                            <i class="fas fa-female text-white text-sm"></i>
                                        </div>
                                        <h4 class="text-lg font-bold text-gray-900">Mother's Information</h4>
                                    </div>
                                    
                                    <div class="grid md:grid-cols-2 gap-5">
                                        <div>
                                            <label for="mother_name" class="block text-sm font-semibold text-gray-900 mb-2">Mother's Full Name</label>
                                            <input type="text" id="mother_name" name="mother_name" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Enter mother's full name" value="<?php echo htmlspecialchars($form_data['mother_name'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="mother_email" class="block text-sm font-semibold text-gray-900 mb-2">Mother's Email</label>
                                            <input type="email" id="mother_email" name="mother_email" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="mother@example.com" value="<?php echo htmlspecialchars($form_data['mother_email'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="mother_phone" class="block text-sm font-semibold text-gray-900 mb-2">Mother's Phone Number</label>
                                            <input type="tel" id="mother_phone" name="mother_phone" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="+233 XX XXX XXXX" value="<?php echo htmlspecialchars($form_data['mother_phone'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="mother_national_id" class="block text-sm font-semibold text-gray-900 mb-2">Mother's National ID</label>
                                            <input type="text" id="mother_national_id" name="mother_national_id" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Ghana Card / Passport" value="<?php echo htmlspecialchars($form_data['mother_national_id'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="mother_occupation" class="block text-sm font-semibold text-gray-900 mb-2">Mother's Occupation</label>
                                            <input type="text" id="mother_occupation" name="mother_occupation" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Occupation" value="<?php echo htmlspecialchars($form_data['mother_occupation'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="mother_office_location" class="block text-sm font-semibold text-gray-900 mb-2">Mother's Office Location</label>
                                            <input type="text" id="mother_office_location" name="mother_office_location" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Office location / city" value="<?php echo htmlspecialchars($form_data['mother_office_location'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Guardian Information Card (Optional) -->
                                <div class="parent-card bg-gradient-to-r from-gray-50 to-slate-50 rounded-xl p-6">
                                    <div class="flex items-center mb-4">
                                        <div class="bg-gray-600 p-2 rounded-full mr-3">
                                            <i class="fas fa-user-shield text-white text-sm"></i>
                                        </div>
                                        <h4 class="text-lg font-bold text-gray-900">Alternate Guardian (Optional)</h4>
                                        <span class="ml-3 text-xs text-gray-500 bg-gray-200 px-2 py-1 rounded-full">If different from parents</span>
                                    </div>
                                    
                                    <div class="grid md:grid-cols-2 gap-5">
                                        <div>
                                            <label for="guardian_name" class="block text-sm font-semibold text-gray-900 mb-2">Guardian's Full Name</label>
                                            <input type="text" id="guardian_name" name="guardian_name" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Enter guardian's full name" value="<?php echo htmlspecialchars($form_data['guardian_name'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="guardian_relationship" class="block text-sm font-semibold text-gray-900 mb-2">Relationship to Student</label>
                                            <input type="text" id="guardian_relationship" name="guardian_relationship" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="e.g., Aunt, Uncle, Grandparent" value="<?php echo htmlspecialchars($form_data['guardian_relationship'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="guardian_phone" class="block text-sm font-semibold text-gray-900 mb-2">Guardian's Phone</label>
                                            <input type="tel" id="guardian_phone" name="guardian_phone" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="+233 XX XXX XXXX" value="<?php echo htmlspecialchars($form_data['guardian_phone'] ?? ''); ?>">
                                        </div>
                                        
                                        <div>
                                            <label for="guardian_email" class="block text-sm font-semibold text-gray-900 mb-2">Guardian's Email</label>
                                            <input type="email" id="guardian_email" name="guardian_email" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="guardian@example.com" value="<?php echo htmlspecialchars($form_data['guardian_email'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="md:col-span-2">
                                            <label for="guardian_address" class="block text-sm font-semibold text-gray-900 mb-2">Guardian's Address</label>
                                            <textarea id="guardian_address" name="guardian_address" rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Guardian's residential address"><?php echo htmlspecialchars($form_data['guardian_address'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Preferred Contact -->
                                <div class="mt-6">
                                    <label class="block text-sm font-semibold text-gray-900 mb-3">Preferred Contact Person</label>
                                    <div class="flex flex-wrap gap-4">
                                        <label class="flex items-center px-4 py-2 bg-blue-50 rounded-lg cursor-pointer hover:bg-blue-100 transition-colors">
                                            <input type="radio" name="preferred_contact" value="father" class="mr-2 text-orange-600" <?php echo (!isset($form_data['preferred_contact']) || $form_data['preferred_contact'] == 'father') ? 'checked' : ''; ?>>
                                            <i class="fas fa-male text-blue-600 mr-2"></i> Father
                                        </label>
                                        <label class="flex items-center px-4 py-2 bg-pink-50 rounded-lg cursor-pointer hover:bg-pink-100 transition-colors">
                                            <input type="radio" name="preferred_contact" value="mother" class="mr-2 text-orange-600" <?php echo (isset($form_data['preferred_contact']) && $form_data['preferred_contact'] == 'mother') ? 'checked' : ''; ?>>
                                            <i class="fas fa-female text-pink-600 mr-2"></i> Mother
                                        </label>
                                        <label class="flex items-center px-4 py-2 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition-colors">
                                            <input type="radio" name="preferred_contact" value="guardian" class="mr-2 text-orange-600" <?php echo (isset($form_data['preferred_contact']) && $form_data['preferred_contact'] == 'guardian') ? 'checked' : ''; ?>>
                                            <i class="fas fa-user-shield text-gray-600 mr-2"></i> Guardian
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Address Information -->
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 mb-6 pb-4 border-b border-gray-200 flex items-center">
                                    <span class="flex items-center justify-center w-8 h-8 bg-orange-100 text-orange-800 rounded-full mr-3">3</span>
                                    Address Information
                                </h3>
                                
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="residential_address" class="block text-sm font-semibold text-gray-900 mb-2">Residential Address</label>
                                        <textarea id="residential_address" name="residential_address" rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Parent residential address"><?php echo htmlspecialchars($form_data['residential_address'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div>
                                        <label for="office_address" class="block text-sm font-semibold text-gray-900 mb-2">Office Address</label>
                                        <textarea id="office_address" name="office_address" rows="2" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Office address"><?php echo htmlspecialchars($form_data['office_address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Emergency Contact -->
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 mb-6 pb-4 border-b border-gray-200 flex items-center">
                                    <span class="flex items-center justify-center w-8 h-8 bg-orange-100 text-orange-800 rounded-full mr-3">4</span>
                                    Emergency Contact
                                </h3>
                                
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="emergency_contact_name" class="block text-sm font-semibold text-gray-900 mb-2">Emergency Contact Name</label>
                                        <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Full name" value="<?php echo htmlspecialchars($form_data['emergency_contact_name'] ?? ''); ?>">
                                    </div>
                                    
                                    <div>
                                        <label for="emergency_contact_phone" class="block text-sm font-semibold text-gray-900 mb-2">Emergency Contact Phone</label>
                                        <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="+233 XX XXX XXXX" value="<?php echo htmlspecialchars($form_data['emergency_contact_phone'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Academic Placement -->
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 mb-6 pb-4 border-b border-gray-200 flex items-center">
                                    <span class="flex items-center justify-center w-8 h-8 bg-orange-100 text-orange-800 rounded-full mr-3">5</span>
                                    Academic Placement
                                </h3>
                                
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="class_id" class="block text-sm font-semibold text-gray-900 mb-2">Grade / Class Applying For <span class="text-red-500">*</span></label>
                                        <select id="class_id" name="class_id" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300">
                                            <option value="">Select Grade Level</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['id']; ?>" <?php echo (isset($form_data['class_id']) && $form_data['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                                    <?php echo isset($class_display_names[$class['class_name']]) ? $class_display_names[$class['class_name']] : $class['class_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="academic_year" class="block text-sm font-semibold text-gray-900 mb-2">Academic Year <span class="text-red-500">*</span></label>
                                        <select id="academic_year" name="academic_year" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300">
                                            <option value="">Select Academic Year</option>
                                            <?php foreach ($academic_years as $year): ?>
                                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo (isset($form_data['academic_year']) && $form_data['academic_year'] == $year) ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Information -->
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 mb-6 pb-4 border-b border-gray-200 flex items-center">
                                    <span class="flex items-center justify-center w-8 h-8 bg-orange-100 text-orange-800 rounded-full mr-3">6</span>
                                    Additional Information
                                </h3>
                                
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="how_hear" class="block text-sm font-semibold text-gray-900 mb-2">How did you hear about us?</label>
                                        <select id="how_hear" name="how_hear" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300">
                                            <option value="">Select option</option>
                                            <option value="Referral" <?php echo (isset($form_data['how_hear']) && $form_data['how_hear'] == 'Referral') ? 'selected' : ''; ?>>Referral</option>
                                            <option value="Social Media" <?php echo (isset($form_data['how_hear']) && $form_data['how_hear'] == 'Social Media') ? 'selected' : ''; ?>>Social Media</option>
                                            <option value="Website" <?php echo (isset($form_data['how_hear']) && $form_data['how_hear'] == 'Website') ? 'selected' : ''; ?>>Website</option>
                                            <option value="Advertisement" <?php echo (isset($form_data['how_hear']) && $form_data['how_hear'] == 'Advertisement') ? 'selected' : ''; ?>>Advertisement</option>
                                            <option value="Open Day" <?php echo (isset($form_data['how_hear']) && $form_data['how_hear'] == 'Open Day') ? 'selected' : ''; ?>>Open Day</option>
                                            <option value="Other" <?php echo (isset($form_data['how_hear']) && $form_data['how_hear'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="md:col-span-2">
                                        <label for="additional_info" class="block text-sm font-semibold text-gray-900 mb-2">Additional Information or Special Requests</label>
                                        <textarea id="additional_info" name="additional_info" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300" placeholder="Please share any additional information that might be helpful for our admissions team..."><?php echo htmlspecialchars($form_data['additional_info'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Consent -->
                            <div class="bg-orange-50 border border-orange-200 rounded-xl p-6">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0"><i class="fas fa-info-circle text-orange-600 text-xl mt-1 mr-3"></i></div>
                                    <div>
                                        <h4 class="font-bold text-gray-900 mb-3">Important Information</h4>
                                        <ul class="space-y-2 text-gray-700 mb-4">
                                            <li class="flex items-start"><i class="fas fa-check text-green-500 mr-2 mt-1"></i><span>This application does not guarantee admission</span></li>
                                            <li class="flex items-start"><i class="fas fa-check text-green-500 mr-2 mt-1"></i><span>All applications are reviewed by our admissions committee</span></li>
                                            <li class="flex items-start"><i class="fas fa-check text-green-500 mr-2 mt-1"></i><span>Required documents must be presented during assessment</span></li>
                                            <li class="flex items-start"><i class="fas fa-check text-green-500 mr-2 mt-1"></i><span>You'll be contacted within 3-5 business days</span></li>
                                        </ul>
                                        
                                        <div class="flex items-start">
                                            <input type="checkbox" id="consent" name="consent" required class="mt-1 mr-3 h-5 w-5 text-orange-600 focus:ring-orange-500 border-gray-300 rounded">
                                            <label for="consent" class="text-sm text-gray-700">I confirm that all information provided is accurate to the best of my knowledge. I understand that this is an application for admission and final enrollment is subject to approval by the school's admissions committee. <span class="text-red-500">*</span></label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="pt-6">
                                <button type="submit" class="btn-primary text-white w-full py-4 rounded-xl font-semibold text-lg hover:shadow-xl transition-all duration-300">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit Application
                                </button>
                                <p class="text-center text-gray-500 text-sm mt-4">Your information is secure and will only be used for admissions purposes.</p>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Process Section -->
    <section class="py-16 bg-gradient-to-b from-white to-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">Our Admissions <span class="section-title block gradient-text">Process</span></h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">A transparent and supportive journey from application to enrollment.</p>
            </div>
            
            <div class="grid md:grid-cols-4 gap-8">
                <div class="text-center">
                    <div class="bg-gradient-primary w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg"><i class="fas fa-file-alt text-white text-2xl"></i></div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">1. Submit Application</h3>
                    <p class="text-gray-600">Complete the online form with required information</p>
                </div>
                
                <div class="text-center">
                    <div class="bg-gradient-primary w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg"><i class="fas fa-calendar-check text-white text-2xl"></i></div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">2. Schedule Assessment</h3>
                    <p class="text-gray-600">We'll contact you to arrange an evaluation</p>
                </div>
                
                <div class="text-center">
                    <div class="bg-gradient-primary w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg"><i class="fas fa-user-graduate text-white text-2xl"></i></div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">3. Student Assessment</h3>
                    <p class="text-gray-600">Age-appropriate evaluation and interaction</p>
                </div>
                
                <div class="text-center">
                    <div class="bg-gradient-primary w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg"><i class="fas fa-award text-white text-2xl"></i></div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">4. Enrollment</h3>
                    <p class="text-gray-600">Complete documentation and begin learning journey</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer (keep existing footer code) -->
    <footer class="bg-gray-900 text-white pt-16 pb-8 relative">
        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-orange-500 via-orange-600 to-orange-500"></div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12">
                <div class="lg:col-span-2">
                    <div class="flex items-center space-x-4 mb-8">
                        <div class="bg-gradient-primary p-4 rounded-2xl shadow-2xl"><i class="fas fa-graduation-cap text-white text-3xl"></i></div>
                        <div><h2 class="text-2xl font-bold font-display">Queenest Paradise</h2><p class="text-lg text-gray-300 font-semibold">MONTESSORI COMPLEX</p><span class="inline-block mt-2 px-3 py-1 bg-orange-500 text-white text-sm font-semibold rounded-full">QueensPa</span></div>
                    </div>
                    <p class="text-gray-300 mb-8 max-w-xl">Nurturing young minds with Christ as our foundation, building character through integrity, discipline, and excellence in education since 2017.</p>
                </div>
                
                <div>
                    <h3 class="text-xl font-bold mb-6">Quick Links</h3>
                    <ul class="space-y-3">
                        <li><a href="index.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group"><i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Home</a></li>
                        <li><a href="about.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group"><i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>About Us</a></li>
                        <li><a href="index.php#programs" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group"><i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Programs</a></li>
                        <li><a href="admissions.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group"><i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Admissions</a></li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="text-xl font-bold mb-6">Contact Info</h3>
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3"><i class="fas fa-map-marker-alt text-orange-500 mt-1"></i><div><div class="font-semibold">Main Campus</div><div class="text-gray-300 text-sm">Accra, Ghana</div></div></div>
                        <div class="flex items-center space-x-3"><i class="fas fa-phone text-orange-500"></i><div><div class="font-semibold">Admissions Office</div><div class="text-gray-300 text-sm">+233 (0) 123-456-789</div></div></div>
                        <div class="flex items-center space-x-3"><i class="fas fa-clock text-orange-500"></i><div><div class="font-semibold">Office Hours</div><div class="text-gray-300 text-sm">Mon-Fri: 7:30 AM - 4:30 PM</div></div></div>
                    </div>
                </div>
            </div>
            
            <div class="border-t border-gray-800 pt-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <p class="text-gray-400 text-sm mb-4 md:mb-0">&copy; <?php echo date('Y'); ?> Queenest Paradise Montessori Complex. All Rights Reserved.</p>
                    <div class="flex space-x-6"><a href="privacy.php" class="text-gray-400 hover:text-white text-sm transition-colors">Privacy Policy</a><a href="terms.php" class="text-gray-400 hover:text-white text-sm transition-colors">Terms of Service</a></div>
                </div>
                <p class="text-gray-500 text-sm mt-4 text-center"><i class="fas fa-heart text-orange-500"></i> Christ Our Foundation, Integrity, Discipline, and Excellence</p>
            </div>
        </div>
    </footer>

    <!-- Back to Top -->
    <button id="backToTop" class="fixed bottom-8 right-8 bg-gradient-primary text-white p-4 rounded-full shadow-2xl hover:shadow-3xl transition-all duration-300 opacity-0 z-40 group"><i class="fas fa-chevron-up text-lg group-hover:-translate-y-1 transition-transform"></i></button>

    <!-- Scripts -->
    <script>
        // Mobile Menu
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        if (mobileMenuButton) {
            mobileMenuButton.addEventListener('click', () => { mobileMenu.classList.toggle('hidden'); const icon = mobileMenuButton.querySelector('i'); icon.classList.toggle('fa-bars'); icon.classList.toggle('fa-times'); });
        }
        document.querySelectorAll('#mobile-menu a').forEach(link => { link.addEventListener('click', () => { mobileMenu.classList.add('hidden'); if (mobileMenuButton) { mobileMenuButton.querySelector('i').classList.replace('fa-times', 'fa-bars'); } }); });

        // Back to Top
        const backToTop = document.getElementById('backToTop');
        window.addEventListener('scroll', () => { if (window.scrollY > 500) { backToTop.classList.remove('opacity-0'); backToTop.classList.add('opacity-100'); } else { backToTop.classList.remove('opacity-100'); backToTop.classList.add('opacity-0'); } });
        if (backToTop) { backToTop.addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); }); }

        // Form validation
        const form = document.getElementById('admissionForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const requiredFields = form.querySelectorAll('[required]');
                let valid = true;
                requiredFields.forEach(field => { if (!field.value.trim()) { valid = false; field.classList.add('border-red-500'); } else { field.classList.remove('border-red-500'); } });
                const consentCheckbox = form.querySelector('#consent');
                if (consentCheckbox && !consentCheckbox.checked) { valid = false; alert('Please accept the terms and conditions to proceed.'); }
                if (!valid) { e.preventDefault(); alert('Please fill in all required fields marked with *'); }
            });
        }

        // Date restriction for DOB
        const dobField = document.getElementById('date_of_birth');
        if (dobField) { const today = new Date(); const maxDate = new Date(today.getFullYear() - 2, today.getMonth(), today.getDate()); const minDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate()); dobField.max = maxDate.toISOString().split('T')[0]; dobField.min = minDate.toISOString().split('T')[0]; }

        // Add scroll effect to header
        window.addEventListener('scroll', () => { const header = document.querySelector('nav'); if (window.scrollY > 100) { header.classList.add('shadow-2xl'); header.classList.add('bg-white/98'); } else { header.classList.remove('shadow-2xl'); header.classList.remove('bg-white/98'); } });
    </script>
</body>
</html>