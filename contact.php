<?php
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Queenest Paradise Montessori Complex - Get in Touch</title>
    
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
                        },
                        secondary: {
                            50: '#fefce8',
                            100: '#fef9c3',
                            500: '#eab308',
                            600: '#ca8a04',
                        },
                        accent: {
                            500: '#8b5cf6',
                            600: '#7c3aed',
                        },
                        dark: {
                            900: '#0f172a',
                            800: '#1e293b',
                        }
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'fade-in': 'fadeIn 0.8s ease-out',
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-slow': 'pulse 3s ease-in-out infinite',
                        'slide-in-left': 'slideInLeft 0.8s ease-out',
                        'slide-in-right': 'slideInRight 0.8s ease-out',
                        'bounce-slow': 'bounce 2s infinite',
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
                            '50%': { transform: 'translateY(-20px)' }
                        },
                        slideInLeft: {
                            '0%': { opacity: '0', transform: 'translateX(-50px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' }
                        },
                        slideInRight: {
                            '0%': { opacity: '0', transform: 'translateX(50px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' }
                        }
                    },
                    backgroundImage: {
                        'gradient-radial': 'radial-gradient(var(--tw-gradient-stops))',
                        'gradient-primary': 'linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%)',
                        'gradient-dark': 'linear-gradient(135deg, #0f172a 0%, #1e293b 100%)',
                    }
                }
            }
        }
    </script>
    
    <style>
        html {
            scroll-behavior: smooth;
            scroll-padding-top: 80px;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
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
        
        .card-hover {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px -12px rgba(249, 115, 22, 0.2);
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
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 30px -5px rgba(249, 115, 22, 0.5);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .border-gradient {
            border: 2px solid transparent;
            background: linear-gradient(white, white) padding-box,
                        linear-gradient(135deg, #f97316, #ea580c) border-box;
        }
        
        .contact-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 10px 10px 30px #d1d9e6, -10px -10px 30px #ffffff;
        }
        
        .form-input {
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
        }
        
        .form-input:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 12px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, #f97316, #ea580c);
            border-radius: 10px;
            border: 3px solid #f1f5f9;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, #ea580c, #c2410c);
        }
        
        /* Page load animation */
        .page-load {
            animation: fadeIn 1s ease-out;
        }
        
        /* Floating animation */
        .floating-shape {
            animation: float 6s ease-in-out infinite;
        }
        
        .map-container {
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .info-card {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.05) 0%, rgba(234, 88, 12, 0.02) 100%);
            border: 1px solid rgba(249, 115, 22, 0.1);
        }
    </style>
</head>

<body class="min-h-screen bg-white text-gray-800 page-load">
    
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
                    <a href="#contact" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Contact
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="#locations" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Locations
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="#faq" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        FAQ
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
                    <a href="#contact" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Contact</a>
                    <a href="#locations" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Locations</a>
                    <a href="#faq" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">FAQ</a>
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
    <section class="pt-20 min-h-[50vh] relative overflow-hidden bg-gradient-dark" id="contact">
        <!-- Animated Background -->
        <div class="absolute inset-0">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-orange-500/10 rounded-full blur-3xl floating-shape"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-red-500/10 rounded-full blur-3xl floating-shape" style="animation-delay: 2s;"></div>
            
            <!-- Grid Pattern -->
            <div class="absolute inset-0" style="background-image: url('data:image/svg+xml,%3Csvg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="none" fill-rule="evenodd"%3E%3Cg fill="%23f97316" fill-opacity="0.05"%3E%3Cpath d="M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"></div>
        </div>
        
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-16">
            <div class="text-center">
                <!-- Badge -->
                <div class="inline-flex items-center px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm border border-orange-400/30 text-orange-200 text-sm font-semibold mb-8">
                    <i class="fas fa-headset mr-2 text-yellow-300"></i>
                    WE'RE HERE TO HELP
                </div>
                
                <!-- Main Heading -->
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight mb-6 font-display">
                    Get in
                    <span class="gradient-text">
                        Touch
                    </span>
                </h1>
                
                <!-- Subtitle -->
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 mb-8 max-w-3xl mx-auto animate-slide-in-left">
                    <p class="text-xl text-orange-100 font-semibold">
                        We're here to answer your questions, hear your concerns, and welcome your feedback. Let's connect.
                    </p>
                </div>
                
                <!-- Description -->
                <p class="text-xl text-gray-300 leading-relaxed mb-10 max-w-4xl mx-auto">
                    Whether you're a prospective parent, current student, or community member, we're committed to providing excellent service and support.
                </p>
            </div>
        </div>
    </section>

    <!-- Contact Information Section -->
    <section class="py-16 bg-gradient-to-b from-white to-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Contact
                    <span class="section-title block gradient-text">
                        Information
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Reach out to us through any of these channels. We're always happy to assist you.
                </p>
            </div>
            
            <!-- Contact Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-16">
                <?php
                $contactInfo = [
                    [
                        'icon' => 'fas fa-phone',
                        'title' => 'Phone',
                        'details' => ['+233 (0) 24 123 4567', '+233 (0) 20 987 6543'],
                        'description' => 'Call us during office hours',
                        'color' => 'from-green-500 to-teal-600',
                        'action' => 'tel:+233241234567'
                    ],
                    [
                        'icon' => 'fas fa-envelope',
                        'title' => 'Email',
                        'details' => ['info@queenspa.edu.gh', 'admissions@queenspa.edu.gh'],
                        'description' => 'Email us anytime',
                        'color' => 'from-blue-500 to-indigo-600',
                        'action' => 'mailto:info@queenspa.edu.gh'
                    ],
                    [
                        'icon' => 'fas fa-map-marker-alt',
                        'title' => 'Address',
                        'details' => ['Brovary Street', 'Greater Accra, Ghana'],
                        'description' => 'Main Campus Location',
                        'color' => 'from-orange-500 to-red-600',
                        'action' => '#map'
                    ],
                    [
                        'icon' => 'fas fa-clock',
                        'title' => 'Hours',
                        'details' => ['Mon-Fri: 7:30 AM - 4:30 PM', 'Sat: 9:00 AM - 1:00 PM'],
                        'description' => 'Office Hours',
                        'color' => 'from-purple-500 to-pink-600',
                        'action' => '#hours'
                    ]
                ];
                
                foreach ($contactInfo as $info) {
                ?>
                <a href="<?= $info['action'] ?>" class="contact-card rounded-2xl p-8 text-center card-hover no-underline">
                    <div class="mb-6">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r <?= $info['color'] ?> p-1">
                            <div class="w-full h-full bg-white rounded-full flex items-center justify-center">
                                <i class="<?= $info['icon'] ?> text-2xl gradient-text"></i>
                            </div>
                        </div>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-4"><?= $info['title'] ?></h3>
                    <div class="space-y-2 mb-4">
                        <?php foreach ($info['details'] as $detail) { ?>
                        <p class="text-gray-700 font-medium"><?= $detail ?></p>
                        <?php } ?>
                    </div>
                    <p class="text-gray-500 text-sm"><?= $info['description'] ?></p>
                </a>
                <?php } ?>
            </div>
            
            <!-- Emergency Contact -->
            <div class="bg-gradient-primary rounded-2xl p-8 text-white mb-16">
                <div class="flex flex-col md:flex-row items-center justify-between">
                    <div class="mb-6 md:mb-0">
                        <h3 class="text-2xl font-bold mb-2">Emergency Contact</h3>
                        <p class="text-white/90">For urgent matters outside office hours</p>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold mb-1">+233 (0) 55 123 4567</div>
                        <p class="text-white/80">24/7 Emergency Line</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Form Section -->
    <section class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Send Us a
                    <span class="section-title block gradient-text">
                        Message
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Whether you have a question, concern, or suggestion, we'd love to hear from you.
                </p>
            </div>
            
            <div class="grid lg:grid-cols-2 gap-12">
                <!-- Contact Form -->
                <div class="animate-fade-in-up">
                    <div class="bg-white rounded-2xl p-8 shadow-xl border-gradient">
                        <form action="send-message.php" method="POST" class="space-y-6">
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">
                                        Full Name *
                                    </label>
                                    <input 
                                        type="text" 
                                        id="name" 
                                        name="name" 
                                        required 
                                        class="w-full px-4 py-3 rounded-xl form-input focus:outline-none focus:ring-2 focus:ring-orange-500"
                                        placeholder="John Doe"
                                    >
                                </div>
                                <div>
                                    <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">
                                        Email Address *
                                    </label>
                                    <input 
                                        type="email" 
                                        id="email" 
                                        name="email" 
                                        required 
                                        class="w-full px-4 py-3 rounded-xl form-input focus:outline-none focus:ring-2 focus:ring-orange-500"
                                        placeholder="john@example.com"
                                    >
                                </div>
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-semibold text-gray-700 mb-2">
                                    Phone Number
                                </label>
                                <input 
                                    type="tel" 
                                    id="phone" 
                                    name="phone" 
                                    class="w-full px-4 py-3 rounded-xl form-input focus:outline-none focus:ring-2 focus:ring-orange-500"
                                    placeholder="+233 24 123 4567"
                                >
                            </div>
                            
                            <div>
                                <label for="subject" class="block text-sm font-semibold text-gray-700 mb-2">
                                    Subject *
                                </label>
                                <select 
                                    id="subject" 
                                    name="subject" 
                                    required 
                                    class="w-full px-4 py-3 rounded-xl form-input focus:outline-none focus:ring-2 focus:ring-orange-500"
                                >
                                    <option value="">Select a subject</option>
                                    <option value="Admissions Inquiry">Admissions Inquiry</option>
                                    <option value="Academic Information">Academic Information</option>
                                    <option value="Extracurricular Programs">Extracurricular Programs</option>
                                    <option value="School Tour">Schedule a School Tour</option>
                                    <option value="General Inquiry">General Inquiry</option>
                                    <option value="Feedback">Feedback/Suggestions</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="message" class="block text-sm font-semibold text-gray-700 mb-2">
                                    Message *
                                </label>
                                <textarea 
                                    id="message" 
                                    name="message" 
                                    rows="6" 
                                    required 
                                    class="w-full px-4 py-3 rounded-xl form-input focus:outline-none focus:ring-2 focus:ring-orange-500 resize-none"
                                    placeholder="Please type your message here..."
                                ></textarea>
                            </div>
                            
                            <div class="flex items-center">
                                <input 
                                    type="checkbox" 
                                    id="newsletter" 
                                    name="newsletter" 
                                    class="h-5 w-5 text-orange-600 rounded focus:ring-orange-500"
                                >
                                <label for="newsletter" class="ml-2 text-sm text-gray-600">
                                    Subscribe to our newsletter for updates and announcements
                                </label>
                            </div>
                            
                            <button type="submit" class="btn-primary text-white px-8 py-4 rounded-xl font-semibold text-lg w-full group">
                                <i class="fas fa-paper-plane mr-2 group-hover:rotate-12 transition-transform"></i>
                                Send Message
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="animate-fade-in-up" style="animation-delay: 0.2s;">
                    <div class="space-y-8">
                        <!-- Quick Response -->
                        <div class="info-card rounded-2xl p-8">
                            <div class="flex items-start space-x-4">
                                <div class="bg-orange-100 p-4 rounded-xl">
                                    <i class="fas fa-bolt text-orange-600 text-2xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900 mb-2">Quick Response</h3>
                                    <p class="text-gray-600">
                                        We typically respond to all inquiries within 24 hours during business days.
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Departments -->
                        <div class="info-card rounded-2xl p-8">
                            <h3 class="text-xl font-bold text-gray-900 mb-4">Contact Specific Departments</h3>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 bg-orange-50 rounded-xl">
                                    <div>
                                        <div class="font-semibold text-gray-900">Admissions</div>
                                        <div class="text-gray-600 text-sm">admissions@qpmsedu.com</div>
                                    </div>
                                    <a href="mailto:admissions@queenspa.edu.gh" class="text-orange-600 hover:text-orange-700">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                </div>
                                <div class="flex items-center justify-between p-4 bg-orange-50 rounded-xl">
                                    <div>
                                        <div class="font-semibold text-gray-900">Academic Office</div>
                                        <div class="text-gray-600 text-sm">academics@qpmsedu.com</div>
                                    </div>
                                    <a href="mailto:academics@queenspa.edu.gh" class="text-orange-600 hover:text-orange-700">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                </div>
                                <div class="flex items-center justify-between p-4 bg-orange-50 rounded-xl">
                                    <div>
                                        <div class="font-semibold text-gray-900">Accounts</div>
                                        <div class="text-gray-600 text-sm">accounts@qpmsedu.com</div>
                                    </div>
                                    <a href="mailto:accounts@queenspa.edu.gh" class="text-orange-600 hover:text-orange-700">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Social Media -->
                        <div class="info-card rounded-2xl p-8">
                            <h3 class="text-xl font-bold text-gray-900 mb-4">Connect on Social Media</h3>
                            <p class="text-gray-600 mb-4">Follow us for updates, events, and school news.</p>
                            <div class="flex space-x-4">
                                <a href="#" class="flex-1 bg-gray-800 text-white p-4 rounded-xl text-center hover:bg-orange-600 transition-colors duration-300">
                                    <i class="fab fa-facebook-f text-xl"></i>
                                    <div class="text-sm mt-2">Facebook</div>
                                </a>
                                <a href="#" class="flex-1 bg-gray-800 text-white p-4 rounded-xl text-center hover:bg-orange-600 transition-colors duration-300">
                                    <i class="fab fa-twitter text-xl"></i>
                                    <div class="text-sm mt-2">Twitter</div>
                                </a>
                                <a href="#" class="flex-1 bg-gray-800 text-white p-4 rounded-xl text-center hover:bg-orange-600 transition-colors duration-300">
                                    <i class="fab fa-instagram text-xl"></i>
                                    <div class="text-sm mt-2">Instagram</div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Map Section -->
    <section class="py-16 bg-gradient-to-b from-gray-50 to-white" id="map">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Find Us on the
                    <span class="section-title block gradient-text">
                        Map
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Visit our main campus in Accra or explore our locations.
                </p>
            </div>
            
            <!-- Map Container -->
            <div class="map-container mb-12 animate-fade-in-up">
                <iframe 
                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1214.9085876475253!2d-0.3454873610670387!3d5.696396323542537!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0xfdfa1398b92695b%3A0x443baffc361646dd!2sQueen&#39;s%20Paradise%20Montessori%20School!5e0!3m2!1sen!2sgh!4v1746097335585!5m2!1sen!2sgh" 
                    width="100%" 
                    height="500" 
                    style="border:0;" 
                    allowfullscreen="" 
                    loading="lazy" 
                    referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
            </div>
            
            <!-- Location Details -->
            <div class="grid md:grid-cols-2 gap-8">
                <div class="bg-white rounded-2xl p-8 shadow-lg border-gradient">
                    <h3 class="text-2xl font-bold text-gray-900 mb-6">Main Campus</h3>
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-map-marker-alt text-orange-500 mt-1"></i>
                            <div>
                                <div class="font-semibold">Address</div>
                                <div class="text-gray-600">Brovary Street, Greater Accra, Ghana</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-clock text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Visiting Hours</div>
                                <div class="text-gray-600">Monday to Friday: 8:00 AM - 3:00 PM</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-car text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Parking</div>
                                <div class="text-gray-600">Ample parking available on campus</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl p-8 shadow-lg border-gradient">
                    <h3 class="text-2xl font-bold text-gray-900 mb-6">Schedule a Tour</h3>
                    <p class="text-gray-600 mb-6">
                        Interested in visiting our campus? Schedule a personalized tour to see our facilities and meet our staff.
                    </p>
                    <a href="#tour-form" class="btn-primary text-white px-8 py-4 rounded-xl font-semibold text-lg inline-flex items-center group">
                        <i class="fas fa-calendar-alt mr-2 group-hover:rotate-12 transition-transform"></i>
                        Book Campus Tour
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="py-16 bg-white" id="faq">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Frequently Asked
                    <span class="section-title block gradient-text">
                        Questions
                    </span>
                </h2>
                <p class="text-xl text-gray-600 leading-relaxed">
                    Find quick answers to common questions about admissions, programs, and more.
                </p>
            </div>
            
            <!-- FAQ Accordion -->
            <div class="space-y-6">
                <?php
                $faqs = [
                    [
                        'question' => 'What are the school hours?',
                        'answer' => 'School hours are from 7:00 AM to 4:090 PM for regular classes.'
                    ],
                    [
                        'question' => 'How do I apply for admission?',
                        'answer' => 'You can apply online through our admissions portal or visit our campus to pick up an application form. Applications are accepted year-round for available slots.'
                    ],
                    [
                        'question' => 'What is the student-teacher ratio?',
                        'answer' => 'We maintain a low student-teacher ratio of 15:1 to ensure personalized attention and quality learning experiences.'
                    ],
                    [
                        'question' => 'Do you offer scholarships?',
                        'answer' => 'Yes, we offer merit-based scholarships and financial aid for qualifying students. Please contact our admissions office for more information.'
                    ],
                    [
                        'question' => 'What safety measures are in place?',
                        'answer' => 'Our campus features 24/7 security, CCTV surveillance, controlled access points, and regular safety drills to ensure student safety.'
                    ]
                ];
                
                foreach ($faqs as $index => $faq) {
                ?>
                <div class="border border-gray-200 rounded-2xl overflow-hidden">
                    <button class="faq-question w-full px-8 py-6 text-left flex justify-between items-center bg-white hover:bg-gray-50 transition-colors duration-300" data-index="<?= $index ?>">
                        <span class="text-lg font-semibold text-gray-900"><?= $faq['question'] ?></span>
                        <i class="fas fa-chevron-down text-orange-500 transform transition-transform duration-300"></i>
                    </button>
                    <div class="faq-answer px-8 py-6 bg-gray-50 hidden">
                        <p class="text-gray-600 leading-relaxed"><?= $faq['answer'] ?></p>
                    </div>
                </div>
                <?php } ?>
            </div>
            
            <!-- Still Have Questions -->
            <div class="text-center mt-12">
                <p class="text-xl text-gray-600 mb-8">
                    Still have questions? We're here to help!
                </p>
                <a href="#contact" class="btn-primary text-white px-10 py-4 rounded-xl font-semibold text-lg inline-flex items-center group">
                    <i class="fas fa-question-circle mr-3 group-hover:rotate-12 transition-transform"></i>
                    Contact Support
                </a>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-16 bg-gradient-primary relative overflow-hidden">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-20">
            <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-white/10 rounded-full blur-3xl floating-shape"></div>
            <div class="absolute bottom-1/4 right-1/4 w-64 h-64 bg-white/10 rounded-full blur-3xl floating-shape" style="animation-delay: 2s;"></div>
        </div>
        
        <div class="relative z-10 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-8 font-display">
                Ready to Connect?
                <span class="text-white block">We're Here for You</span>
            </h2>
            
            <p class="text-xl text-white/90 mb-10 max-w-2xl mx-auto">
                Whether you're ready to enroll, have questions about our programs, or just want to learn more, 
                our team is ready to assist you every step of the way.
            </p>
            
            <div class="flex flex-col sm:flex-row gap-6 justify-center">
                <a href="admissions.php" class="bg-white text-orange-600 px-10 py-5 rounded-xl font-semibold text-lg inline-flex items-center justify-center group hover:bg-gray-50 hover:shadow-2xl transition-all duration-300">
                    <i class="fas fa-file-alt mr-3 text-xl group-hover:rotate-12 transition-transform"></i>
                    Start Application
                </a>
                <a href="tel:+233241234567" class="bg-white/20 backdrop-blur-sm text-white border border-white/40 px-10 py-5 rounded-xl font-semibold text-lg hover:bg-white/30 hover:shadow-xl transition-all duration-300 inline-flex items-center justify-center">
                    <i class="fas fa-phone-alt mr-3 text-xl"></i>
                    Call Now
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-white pt-16 pb-8 relative">
        <!-- Top Pattern -->
        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-orange-500 via-orange-600 to-orange-500"></div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 mb-12">
                <!-- Brand Info -->
                <div class="lg:col-span-2">
                    <div class="flex items-center space-x-4 mb-8">
                        <div class="bg-gradient-primary p-4 rounded-2xl shadow-2xl">
                            <i class="fas fa-graduation-cap text-white text-3xl"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold font-display">Queenest Paradise</h2>
                            <p class="text-lg text-gray-300 font-semibold">MONTESSORI COMPLEX</p>
                            <span class="inline-block mt-2 px-3 py-1 bg-orange-500 text-white text-sm font-semibold rounded-full">QueensPa</span>
                        </div>
                    </div>
                    <p class="text-gray-300 mb-8 max-w-xl">
                        Nurturing young minds with Christ as our foundation, building character through integrity, discipline, and excellence in education since 2010.
                    </p>
                    
                    <!-- Newsletter -->
                    <div class="mb-8">
                        <h4 class="font-semibold mb-4">Stay Updated</h4>
                        <form class="flex gap-2">
                            <input 
                                type="email" 
                                placeholder="Your email" 
                                class="flex-grow px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent"
                            >
                            <button type="submit" class="bg-gradient-primary text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all duration-300">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div>
                    <h3 class="text-xl font-bold mb-6">Quick Links</h3>
                    <ul class="space-y-3">
                        <li><a href="index.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Home</a></li>
                        <li><a href="about.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>About Us</a></li>
                        <li><a href="gallery.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Gallery</a></li>
                        <li><a href="#contact" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Contact</a></li>
                        <li><a href="admissions.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Admissions</a></li>
                    </ul>
                </div>
                
                <!-- Contact Info -->
                <div>
                    <h3 class="text-xl font-bold mb-6">Contact Info</h3>
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-map-marker-alt text-orange-500 mt-1"></i>
                            <div>
                                <div class="font-semibold">Main Campus</div>
                                <div class="text-gray-300 text-sm">Brovary Street, Accra, Ghana</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-phone text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Call Us</div>
                                <div class="text-gray-300 text-sm">+233 (0) 24 123 4567</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-envelope text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Email Us</div>
                                <div class="text-gray-300 text-sm">info@qpmsedu.com</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-clock text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Office Hours</div>
                                <div class="text-gray-300 text-sm">Mon-Fri: 7:30 AM - 4:30 PM</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Social Links -->
                    <div class="mt-8">
                        <h4 class="font-semibold mb-4">Follow Us</h4>
                        <div class="flex space-x-3">
                            <a href="#" class="bg-gray-800 p-3 rounded-lg hover:bg-orange-600 transition-colors duration-300">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                            <a href="#" class="bg-gray-800 p-3 rounded-lg hover:bg-orange-600 transition-colors duration-300">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="#" class="bg-gray-800 p-3 rounded-lg hover:bg-orange-600 transition-colors duration-300">
                                <i class="fab fa-instagram"></i>
                            </a>
                            <a href="#" class="bg-gray-800 p-3 rounded-lg hover:bg-orange-600 transition-colors duration-300">
                                <i class="fab fa-youtube"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Copyright -->
            <div class="border-t border-gray-800 pt-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <p class="text-gray-400 text-sm mb-4 md:mb-0">
                        &copy; <?= date('Y') ?> Queenest Paradise Montessori Complex. All Rights Reserved.
                    </p>
                    <div class="flex space-x-6">
                        <a href="privacy.php" class="text-gray-400 hover:text-white text-sm transition-colors">Privacy Policy</a>
                        <a href="terms.php" class="text-gray-400 hover:text-white text-sm transition-colors">Terms of Service</a>
                        <a href="sitemap.php" class="text-gray-400 hover:text-white text-sm transition-colors">Sitemap</a>
                    </div>
                </div>
                <p class="text-gray-500 text-sm mt-4 text-center">
                    <i class="fas fa-heart text-orange-500"></i> Christ Our Foundation, Integrity, Discipline, and Excellence
                </p>
            </div>
        </div>
    </footer>

    <!-- Back to Top -->
    <button id="backToTop" class="fixed bottom-8 right-8 bg-gradient-primary text-white p-4 rounded-full shadow-2xl hover:shadow-3xl transition-all duration-300 opacity-0 z-40 group">
        <i class="fas fa-chevron-up text-lg group-hover:-translate-y-1 transition-transform"></i>
    </button>

    <!-- Scripts -->
    <script>
        // Mobile Menu
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
            const icon = mobileMenuButton.querySelector('i');
            icon.classList.toggle('fa-bars');
            icon.classList.toggle('fa-times');
        });

        // Close mobile menu when clicking links
        document.querySelectorAll('#mobile-menu a').forEach(link => {
            link.addEventListener('click', () => {
                mobileMenu.classList.add('hidden');
                mobileMenuButton.querySelector('i').classList.replace('fa-times', 'fa-bars');
            });
        });

        // Back to Top
        const backToTop = document.getElementById('backToTop');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 500) {
                backToTop.classList.remove('opacity-0');
                backToTop.classList.add('opacity-100');
            } else {
                backToTop.classList.remove('opacity-100');
                backToTop.classList.add('opacity-0');
            }
        });

        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Smooth Scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const target = document.querySelector(targetId);
                if (target) {
                    window.scrollTo({
                        top: target.offsetTop - 80,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // FAQ Accordion
        document.querySelectorAll('.faq-question').forEach(button => {
            button.addEventListener('click', () => {
                const answer = button.nextElementSibling;
                const icon = button.querySelector('i');
                
                // Toggle current answer
                answer.classList.toggle('hidden');
                icon.classList.toggle('rotate-180');
                
                // Optional: Close other FAQs
                document.querySelectorAll('.faq-question').forEach(otherButton => {
                    if (otherButton !== button) {
                        const otherAnswer = otherButton.nextElementSibling;
                        const otherIcon = otherButton.querySelector('i');
                        otherAnswer.classList.add('hidden');
                        otherIcon.classList.remove('rotate-180');
                    }
                });
            });
        });

        // Form Validation
        const contactForm = document.querySelector('form');
        if (contactForm) {
            contactForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Simple validation
                const name = this.querySelector('#name').value;
                const email = this.querySelector('#email').value;
                const subject = this.querySelector('#subject').value;
                const message = this.querySelector('#message').value;
                
                if (!name || !email || !subject || !message) {
                    alert('Please fill in all required fields.');
                    return;
                }
                
                // Email validation
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    alert('Please enter a valid email address.');
                    return;
                }
                
                // If validation passes, submit form
                this.submit();
            });
        }

        // Add scroll effect to header
        window.addEventListener('scroll', () => {
            const header = document.querySelector('nav');
            if (window.scrollY > 100) {
                header.classList.add('shadow-2xl');
                header.classList.add('bg-white/98');
            } else {
                header.classList.remove('shadow-2xl');
                header.classList.remove('bg-white/98');
            }
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-in-up');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observe all sections
        document.querySelectorAll('section').forEach(section => {
            observer.observe(section);
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>