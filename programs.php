<?php
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Programs | Queenest Paradise Montessori Complex - Christ Our Foundation, Integrity, Discipline and Excellence</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Swiper -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css" />
    
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
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 30px 60px -12px rgba(249, 115, 22, 0.3);
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
        
        .program-card {
            position: relative;
            overflow: hidden;
        }
        
        .program-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, #f97316, #ea580c);
        }
        
        .category-tag {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
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
    </style>
</head>

<body class="min-h-screen bg-white text-gray-800">
    
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
                    <a href="programs.php" class="group text-orange-600 font-medium transition-all duration-300 relative">
                        Programs
                        <span class="absolute bottom-0 left-0 w-full h-0.5 bg-orange-600"></span>
                    </a>
                    <a href="gallery.php" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Gallery
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="news.php" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        News
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="testimonials.php" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Testimonials
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
                    <a href="programs.php" class="text-orange-600 font-medium text-lg transition-colors duration-300">Programs</a>
                    <a href="gallery.php" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Gallery</a>
                    <a href="news.php" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">News</a>
                    <a href="testimonials.php" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Testimonials</a>
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
    <section class="pt-32 pb-16 relative overflow-hidden bg-gradient-dark">
        <div class="absolute inset-0">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-orange-500/10 rounded-full blur-3xl floating-shape"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-red-500/10 rounded-full blur-3xl floating-shape" style="animation-delay: 2s;"></div>
        </div>
        
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <!-- Badge -->
                <div class="inline-flex items-center px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm border border-orange-400/30 text-orange-200 text-sm font-semibold mb-6">
                    <i class="fas fa-star mr-2 text-yellow-300"></i>
                    COMPREHENSIVE EDUCATIONAL PROGRAMS
                </div>
                
                <!-- Main Heading -->
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight mb-6 font-display">
                    Our
                    <span class="block gradient-text mt-2">
                        Extracurricular Programs
                    </span>
                </h1>
                
                <!-- Description -->
                <p class="text-xl text-gray-300 leading-relaxed mb-8 max-w-3xl mx-auto">
                    At QueensPa, we offer a diverse range of programs designed to nurture talents, develop skills, and foster holistic growth. 
                    From technology to arts, sports to leadership, we provide opportunities for every student to excel.
                </p>
                
                <!-- Stats -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 max-w-3xl mx-auto">
                    <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-white mb-1">12+</div>
                        <div class="text-sm text-gray-300">Programs</div>
                    </div>
                    <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-white mb-1">95%</div>
                        <div class="text-sm text-gray-300">Participation</div>
                    </div>
                    <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-white mb-1">18</div>
                        <div class="text-sm text-gray-300">Coaches & Instructors</div>
                    </div>
                    <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-white mb-1">50+</div>
                        <div class="text-sm text-gray-300">Annual Events</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Scroll Indicator -->
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2">
            <a href="#programs-grid" class="text-white animate-bounce">
                <i class="fas fa-chevron-down text-3xl"></i>
            </a>
        </div>
    </section>

    <!-- Programs Filter -->
    <section class="py-8 bg-gray-50 sticky top-20 z-30 border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap justify-center gap-4">
                <button class="filter-btn active px-6 py-2 rounded-full bg-orange-600 text-white font-semibold transition-all duration-300 hover:shadow-lg" data-filter="all">
                    All Programs
                </button>
                <button class="filter-btn px-6 py-2 rounded-full bg-white text-gray-700 border border-gray-300 font-semibold transition-all duration-300 hover:bg-gray-100 hover:border-gray-400" data-filter="academic">
                    Academic
                </button>
                <button class="filter-btn px-6 py-2 rounded-full bg-white text-gray-700 border border-gray-300 font-semibold transition-all duration-300 hover:bg-gray-100 hover:border-gray-400" data-filter="sports">
                    Sports
                </button>
                <button class="filter-btn px-6 py-2 rounded-full bg-white text-gray-700 border border-gray-300 font-semibold transition-all duration-300 hover:bg-gray-100 hover:border-gray-400" data-filter="arts">
                    Arts & Culture
                </button>
                <button class="filter-btn px-6 py-2 rounded-full bg-white text-gray-700 border border-gray-300 font-semibold transition-all duration-300 hover:bg-gray-100 hover:border-gray-400" data-filter="leadership">
                    Leadership
                </button>
                <button class="filter-btn px-6 py-2 rounded-full bg-white text-gray-700 border border-gray-300 font-semibold transition-all duration-300 hover:bg-gray-100 hover:border-gray-400" data-filter="community">
                    Community
                </button>
            </div>
        </div>
    </section>

    <!-- Programs Grid -->
    <section id="programs-grid" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php
                $programs = [
                    // From index page
                    [
                        'id' => 'programming',
                        'title' => 'Programming Club',
                        'icon' => 'fas fa-code',
                        'category' => ['academic'],
                        'desc' => 'Master coding skills with Python, JavaScript, and web development. Join our competitive programming team.',
                        'features' => ['Weekly Coding Sessions', 'Hackathons', 'Robotics Projects', 'App Development'],
                        'color' => 'from-blue-500 to-indigo-600',
                        'students' => '45+',
                        'age' => 'Grade 6-9',
                        'schedule' => 'Wednesdays 3-5 PM'
                    ],
                    [
                        'id' => 'media',
                        'title' => 'Media & Broadcasting',
                        'icon' => 'fas fa-film',
                        'category' => ['arts', 'academic'],
                        'desc' => 'Explore digital media production, photography, video editing, and school broadcasting.',
                        'features' => ['School TV Station', 'Photography Club', 'Video Production', 'Podcasting'],
                        'color' => 'from-purple-500 to-pink-600',
                        'students' => '38+',
                        'age' => 'Grade 4-9',
                        'schedule' => 'Thursdays 3-5 PM'
                    ],
                    [
                        'id' => 'ksiq',
                        'title' => 'KSIQ Program',
                        'icon' => 'fas fa-broom',
                        'category' => ['community', 'leadership'],
                        'desc' => 'Kids Sanitation Initiative promoting hygiene, environmental awareness, and community service.',
                        'features' => ['Hygiene Workshops', 'Recycling Projects', 'Community Clean-up', 'Environmental Clubs'],
                        'color' => 'from-green-500 to-teal-600',
                        'students' => '60+',
                        'age' => 'All Grades',
                        'schedule' => 'Fridays 2-4 PM'
                    ],
                    [
                        'id' => 'sports',
                        'title' => 'Sports Academy',
                        'icon' => 'fas fa-futbol',
                        'category' => ['sports'],
                        'desc' => 'Comprehensive sports training in football, basketball, athletics, and swimming.',
                        'features' => ['Inter-school Competitions', 'Professional Coaching', 'Fitness Training', 'Sports Scholarships'],
                        'color' => 'from-red-500 to-orange-600',
                        'students' => '80+',
                        'age' => 'All Grades',
                        'schedule' => 'Mon-Fri 3-6 PM'
                    ],
                    [
                        'id' => 'girls-guide',
                        'title' => 'Girls Guide & Scouts',
                        'icon' => 'fas fa-hands-helping',
                        'category' => ['leadership', 'community'],
                        'desc' => 'Leadership development, entrepreneurship skills, and community service programs.',
                        'features' => ['Leadership Camps', 'Entrepreneurship Workshops', 'Community Projects', 'Life Skills Training'],
                        'color' => 'from-pink-500 to-rose-600',
                        'students' => '55+',
                        'age' => 'Grade 3-9',
                        'schedule' => 'Tuesdays 3-5 PM'
                    ],
                    [
                        'id' => 'language',
                        'title' => 'Language & Culture',
                        'icon' => 'fas fa-language',
                        'category' => ['academic', 'arts'],
                        'desc' => 'Learn French, Chinese, and local languages while exploring diverse cultures.',
                        'features' => ['French Club', 'Chinese Language', 'Cultural Exchange', 'Debate Society'],
                        'color' => 'from-yellow-500 to-amber-600',
                        'students' => '42+',
                        'age' => 'Grade 4-9',
                        'schedule' => 'Mondays 3-5 PM'
                    ],
                    // New Programs
                    [
                        'id' => 'cadet',
                        'title' => 'Cadet Corps',
                        'icon' => 'fas fa-flag',
                        'category' => ['leadership', 'sports'],
                        'desc' => 'Military-style training program focusing on discipline, leadership, and physical fitness.',
                        'features' => ['Drill Training', 'Leadership Exercises', 'First Aid Training', 'Camping Trips'],
                        'color' => 'from-gray-600 to-gray-800',
                        'students' => '65+',
                        'age' => 'Grade 6-9',
                        'schedule' => 'Saturdays 8-12 AM'
                    ],
                    [
                        'id' => 'drama',
                        'title' => 'Drama & Theatre',
                        'icon' => 'fas fa-theater-masks',
                        'category' => ['arts'],
                        'desc' => 'Explore acting, stage production, script writing, and theatrical performances.',
                        'features' => ['Annual School Play', 'Acting Workshops', 'Stage Management', 'Public Speaking'],
                        'color' => 'from-red-500 to-purple-600',
                        'students' => '35+',
                        'age' => 'Grade 3-9',
                        'schedule' => 'Wednesdays 3-5 PM'
                    ],
                    [
                        'id' => 'choir',
                        'title' => 'Choir & Vocal Training',
                        'icon' => 'fas fa-music',
                        'category' => ['arts'],
                        'desc' => 'Develop vocal skills, learn music theory, and participate in school and community performances.',
                        'features' => ['Vocal Training', 'Music Theory', 'Performance Skills', 'Annual Concerts'],
                        'color' => 'from-indigo-500 to-purple-600',
                        'students' => '48+',
                        'age' => 'Grade 2-9',
                        'schedule' => 'Fridays 3-5 PM'
                    ],
                    [
                        'id' => 'music',
                        'title' => 'Music & Instrumentation',
                        'icon' => 'fas fa-guitar',
                        'category' => ['arts'],
                        'desc' => 'Learn to play various musical instruments including piano, guitar, drums, and traditional instruments.',
                        'features' => ['Instrument Training', 'Music Composition', 'Band Formation', 'Recording Sessions'],
                        'color' => 'from-amber-500 to-orange-600',
                        'students' => '40+',
                        'age' => 'Grade 4-9',
                        'schedule' => 'Thursdays 3-5 PM'
                    ],
                    [
                        'id' => 'debate',
                        'title' => 'Debate & Public Speaking',
                        'icon' => 'fas fa-microphone',
                        'category' => ['academic', 'leadership'],
                        'desc' => 'Develop critical thinking, research skills, and confident public speaking abilities.',
                        'features' => ['Debate Competitions', 'Speech Writing', 'Research Skills', 'Model UN'],
                        'color' => 'from-emerald-500 to-teal-600',
                        'students' => '30+',
                        'age' => 'Grade 6-9',
                        'schedule' => 'Mondays 3-5 PM'
                    ],
                    [
                        'id' => 'art',
                        'title' => 'Art & Creative Design',
                        'icon' => 'fas fa-palette',
                        'category' => ['arts'],
                        'desc' => 'Explore various art forms including painting, drawing, sculpture, and digital art.',
                        'features' => ['Art Exhibitions', 'Workshops', 'Portfolio Development', 'Competitions'],
                        'color' => 'from-pink-500 to-rose-600',
                        'students' => '45+',
                        'age' => 'All Grades',
                        'schedule' => 'Tuesdays 3-5 PM'
                    ]
                ];
                
                foreach ($programs as $program) {
                    $categoryClasses = implode(' ', $program['category']);
                ?>
                <div class="program-item program-card card-hover bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden" 
                     data-category="<?= $categoryClasses ?>">
                    <!-- Category Tag -->
                    <div class="category-tag">
                        <?php foreach ($program['category'] as $cat) { ?>
                        <span class="inline-block px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 mr-1 mb-1">
                            <?= ucfirst($cat) ?>
                        </span>
                        <?php } ?>
                    </div>
                    
                    <!-- Program Header -->
                    <div class="p-6">
                        <div class="flex items-start space-x-4 mb-4">
                            <div class="bg-gradient-to-r <?= $program['color'] ?> p-4 rounded-xl">
                                <i class="<?= $program['icon'] ?> text-white text-2xl"></i>
                            </div>
                            <div class="flex-grow">
                                <h3 class="text-xl font-bold text-gray-900"><?= $program['title'] ?></h3>
                                <div class="flex items-center mt-2 text-sm text-gray-600">
                                    <i class="fas fa-users mr-2"></i>
                                    <span class="mr-4"><?= $program['students'] ?> Students</span>
                                    <i class="fas fa-calendar mr-2"></i>
                                    <span><?= $program['schedule'] ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Description -->
                        <p class="text-gray-600 mb-4"><?= $program['desc'] ?></p>
                        
                        <!-- Features -->
                        <ul class="space-y-2 mb-6">
                            <?php foreach ($program['features'] as $feature) { ?>
                            <li class="flex items-center text-gray-700">
                                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                                <span class="text-sm"><?= $feature ?></span>
                            </li>
                            <?php } ?>
                        </ul>
                        
                        <!-- Program Info -->
                        <div class="flex justify-between items-center mb-6">
                            <div class="text-sm">
                                <div class="font-semibold text-gray-900">Age Group:</div>
                                <div class="text-gray-600"><?= $program['age'] ?></div>
                            </div>
                            <div class="text-sm text-right">
                                <div class="font-semibold text-gray-900">Schedule:</div>
                                <div class="text-gray-600"><?= $program['schedule'] ?></div>
                            </div>
                        </div>
                        
                        <!-- Action Button -->
                        <div class="text-center">
                            <button onclick="openProgramModal('<?= $program['id'] ?>')" 
                                    class="w-full btn-primary text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300">
                                <i class="fas fa-info-circle mr-2"></i>Learn More & Join
                            </button>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
            
            <!-- Empty State -->
            <div id="no-programs" class="hidden text-center py-16">
                <div class="text-gray-400 mb-6">
                    <i class="fas fa-search text-6xl mb-4"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-600 mb-4">No programs found</h3>
                <p class="text-gray-500 mb-8">Try selecting a different category or view all programs.</p>
                <button class="filter-btn reset-filter btn-primary text-white px-8 py-3 rounded-xl font-semibold">
                    Show All Programs
                </button>
            </div>
        </div>
    </section>

    <!-- Program Benefits -->
    <section class="py-20 bg-gradient-to-b from-gray-50 to-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Benefits of Our
                    <span class="section-title block gradient-text">
                        Programs
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Our extracurricular programs are designed to complement academic learning and provide students with essential life skills.
                </p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="bg-white rounded-2xl p-8 text-center shadow-lg border border-gray-100">
                    <div class="bg-gradient-primary inline-flex p-4 rounded-xl mb-6">
                        <i class="fas fa-brain text-white text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-4">Skill Development</h3>
                    <p class="text-gray-600">Practical skills that prepare students for future careers and personal growth.</p>
                </div>
                
                <div class="bg-white rounded-2xl p-8 text-center shadow-lg border border-gray-100">
                    <div class="bg-gradient-primary inline-flex p-4 rounded-xl mb-6">
                        <i class="fas fa-users text-white text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-4">Team Building</h3>
                    <p class="text-gray-600">Collaborative projects that enhance social skills and cooperation.</p>
                </div>
                
                <div class="bg-white rounded-2xl p-8 text-center shadow-lg border border-gray-100">
                    <div class="bg-gradient-primary inline-flex p-4 rounded-xl mb-6">
                        <i class="fas fa-trophy text-white text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-4">Achievement</h3>
                    <p class="text-gray-600">Regular competitions and recognition ceremonies to celebrate success.</p>
                </div>
                
                <div class="bg-white rounded-2xl p-8 text-center shadow-lg border border-gray-100">
                    <div class="bg-gradient-primary inline-flex p-4 rounded-xl mb-6">
                        <i class="fas fa-heart text-white text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-4">Well-being</h3>
                    <p class="text-gray-600">Activities that promote physical, mental, and emotional health.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How to Join -->
    <section class="py-20 bg-gradient-primary">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-6 font-display">
                    How to Join Our
                    <span class="block text-white">
                        Programs
                    </span>
                </h2>
                <p class="text-xl text-white/90 max-w-3xl mx-auto leading-relaxed">
                    Joining our extracurricular programs is simple and accessible for all students.
                </p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-8 text-center border border-white/20">
                    <div class="text-4xl font-bold text-white mb-4">01</div>
                    <h3 class="text-xl font-bold text-white mb-4">Browse Programs</h3>
                    <p class="text-white/80">Explore our diverse range of programs and find what interests you.</p>
                </div>
                
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-8 text-center border border-white/20">
                    <div class="text-4xl font-bold text-white mb-4">02</div>
                    <h3 class="text-xl font-bold text-white mb-4">Choose Your Program</h3>
                    <p class="text-white/80">Select one or more programs that match your interests and schedule.</p>
                </div>
                
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-8 text-center border border-white/20">
                    <div class="text-4xl font-bold text-white mb-4">03</div>
                    <h3 class="text-xl font-bold text-white mb-4">Get Approval</h3>
                    <p class="text-white/80">Submit your choices and get approval from parents and teachers.</p>
                </div>
                
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-8 text-center border border-white/20">
                    <div class="text-4xl font-bold text-white mb-4">04</div>
                    <h3 class="text-xl font-bold text-white mb-4">Start Learning</h3>
                    <p class="text-white/80">Attend your first session and begin your journey of growth and discovery.</p>
                </div>
            </div>
            
            <div class="text-center mt-16">
                <a href="admissions.php" class="bg-white text-orange-600 px-10 py-4 rounded-xl font-semibold text-lg inline-flex items-center group hover:shadow-2xl transition-all duration-300">
                    <i class="fas fa-file-alt mr-3 text-xl group-hover:rotate-12 transition-transform"></i>
                    Download Program Brochure
                    <i class="fas fa-download ml-3 group-hover:translate-y-1 transition-transform"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="py-20 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Frequently Asked
                    <span class="section-title block gradient-text">
                        Questions
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Find answers to common questions about our extracurricular programs.
                </p>
            </div>
            
            <div class="space-y-6">
                <div class="bg-gray-50 rounded-xl p-6">
                    <button class="faq-toggle w-full text-left flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">How many programs can a student join?</h3>
                        <i class="fas fa-chevron-down text-gray-500"></i>
                    </button>
                    <div class="faq-content mt-4 hidden">
                        <p class="text-gray-600">Students can join up to 3 programs per term to ensure they can balance their academic responsibilities with extracurricular activities. We recommend starting with 1-2 programs to find the right balance.</p>
                    </div>
                </div>
                
                <div class="bg-gray-50 rounded-xl p-6">
                    <button class="faq-toggle w-full text-left flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">Are there additional fees for these programs?</h3>
                        <i class="fas fa-chevron-down text-gray-500"></i>
                    </button>
                    <div class="faq-content mt-4 hidden">
                        <p class="text-gray-600">Most programs are included in the school fees. Some specialized programs with external coaches or special equipment may have minimal additional costs, which will be clearly communicated to parents beforehand.</p>
                    </div>
                </div>
                
                <div class="bg-gray-50 rounded-xl p-6">
                    <button class="faq-toggle w-full text-left flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">Can parents volunteer or help with programs?</h3>
                        <i class="fas fa-chevron-down text-gray-500"></i>
                    </button>
                    <div class="faq-content mt-4 hidden">
                        <p class="text-gray-600">Yes! We welcome parent volunteers who have skills or experience relevant to our programs. Please contact the program coordinator if you're interested in volunteering.</p>
                    </div>
                </div>
                
                <div class="bg-gray-50 rounded-xl p-6">
                    <button class="faq-toggle w-full text-left flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">What happens if a program is full?</h3>
                        <i class="fas fa-chevron-down text-gray-500"></i>
                    </button>
                    <div class="faq-content mt-4 hidden">
                        <p class="text-gray-600">We maintain a waiting list for popular programs and will notify parents if a spot becomes available. We also try to open additional sessions for high-demand programs.</p>
                    </div>
                </div>
                
                <div class="bg-gray-50 rounded-xl p-6">
                    <button class="faq-toggle w-full text-left flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">How are program instructors selected?</h3>
                        <i class="fas fa-chevron-down text-gray-500"></i>
                    </button>
                    <div class="faq-content mt-4 hidden">
                        <p class="text-gray-600">All our program instructors are carefully vetted professionals with expertise in their respective fields. They undergo background checks and receive training in child safety and educational best practices.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 bg-gradient-dark relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-orange-500 to-transparent"></div>
            <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-orange-500 to-transparent"></div>
        </div>
        
        <div class="relative z-10 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-8 font-display">
                Ready to Discover
                <span class="gradient-text block">Your Passion?</span>
            </h2>
            
            <p class="text-xl text-gray-300 mb-10 max-w-2xl mx-auto">
                Join one of our exciting programs and unlock your potential. Contact us today to learn more or schedule a visit.
            </p>
            
            <div class="flex flex-col sm:flex-row gap-6 justify-center">
                <a href="admissions.php" class="btn-primary text-white px-10 py-5 rounded-xl font-semibold text-lg inline-flex items-center justify-center group">
                    <i class="fas fa-calendar-check mr-3 text-xl group-hover:rotate-12 transition-transform"></i>
                    Schedule a Visit
                </a>
                <a href="contact.php" class="bg-white/10 backdrop-blur-sm text-white border border-white/30 px-10 py-5 rounded-xl font-semibold text-lg hover:bg-white/20 hover:shadow-xl transition-all duration-300 inline-flex items-center justify-center">
                    <i class="fas fa-phone-alt mr-3 text-xl"></i>
                    Contact Program Director
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-white pt-16 pb-8 relative">
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
                        Nurturing young minds with Christ as our foundation, building character through integrity, discipline, and excellence in education since 2017.
                    </p>
                    
                    <!-- Newsletter -->
                    <div class="mb-8">
                        <h4 class="font-semibold mb-4">Program Updates</h4>
                        <form class="flex gap-2">
                            <input 
                                type="email" 
                                placeholder="Your email for program updates" 
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
                    <h3 class="text-xl font-bold mb-6">Program Categories</h3>
                    <ul class="space-y-3">
                        <li><a href="#" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group" data-filter="academic">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Academic Programs</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group" data-filter="sports">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Sports Programs</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group" data-filter="arts">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Arts & Culture</a></li>
                        <li><a href="#" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group" data-filter="leadership">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Leadership Programs</a></li>
                    </ul>
                </div>
                
                <!-- Contact Info -->
                <div>
                    <h3 class="text-xl font-bold mb-6">Program Office</h3>
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-map-marker-alt text-orange-500 mt-1"></i>
                            <div>
                                <div class="font-semibold">Programs Department</div>
                                <div class="text-gray-300 text-sm">Main Campus, Accra</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-phone text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Program Inquiries</div>
                                <div class="text-gray-300 text-sm">+233552025642</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-envelope text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Email Programs</div>
                                <div class="text-gray-300 text-sm">programs@qpmsedu.com</div>
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
                        <a href="programs.php" class="text-gray-400 hover:text-white text-sm transition-colors">Programs</a>
                    </div>
                </div>
                <p class="text-gray-500 text-sm mt-4 text-center">
                    <i class="fas fa-heart text-orange-500"></i> Christ Our Foundation, Integrity, Discipline, and Excellence
                </p>
            </div>
        </div>
    </footer>

    <!-- Program Modals -->
    <div id="program-modals">
        <?php foreach ($programs as $program) { ?>
        <div id="modal-<?= $program['id'] ?>" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-white p-6 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-2xl font-bold text-gray-900"><?= $program['title'] ?></h3>
                    <button onclick="closeModal('<?= $program['id'] ?>')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                
                <div class="p-6">
                    <div class="grid md:grid-cols-3 gap-8 mb-8">
                        <div>
                            <div class="bg-gradient-to-r <?= $program['color'] ?> p-6 rounded-xl text-white text-center mb-4">
                                <i class="<?= $program['icon'] ?> text-4xl mb-4"></i>
                                <div class="text-2xl font-bold"><?= $program['students'] ?></div>
                                <div class="text-sm">Active Students</div>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <div class="font-semibold text-gray-900">Age Group:</div>
                                    <div class="text-gray-600"><?= $program['age'] ?></div>
                                </div>
                                <div>
                                    <div class="font-semibold text-gray-900">Schedule:</div>
                                    <div class="text-gray-600"><?= $program['schedule'] ?></div>
                                </div>
                                <div>
                                    <div class="font-semibold text-gray-900">Categories:</div>
                                    <div class="flex flex-wrap gap-2 mt-2">
                                        <?php foreach ($program['category'] as $cat) { ?>
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">
                                            <?= ucfirst($cat) ?>
                                        </span>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="md:col-span-2">
                            <h4 class="text-xl font-bold text-gray-900 mb-4">Program Overview</h4>
                            <p class="text-gray-600 mb-6"><?= $program['desc'] ?></p>
                            
                            <h4 class="text-xl font-bold text-gray-900 mb-4">What You'll Learn</h4>
                            <ul class="space-y-3 mb-8">
                                <?php foreach ($program['features'] as $feature) { ?>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                                    <span class="text-gray-700"><?= $feature ?></span>
                                </li>
                                <?php } ?>
                            </ul>
                            
                            <div class="bg-gray-50 rounded-xl p-6 mb-8">
                                <h4 class="text-lg font-bold text-gray-900 mb-4">Requirements</h4>
                                <ul class="space-y-2 text-gray-600">
                                    <li><i class="fas fa-check text-green-500 mr-2"></i>Current Queenest Paradise student</li>
                                    <li><i class="fas fa-check text-green-500 mr-2"></i>Parental consent form</li>
                                    <li><i class="fas fa-check text-green-500 mr-2"></i>Medical clearance (for sports programs)</li>
                                    <li><i class="fas fa-check text-green-500 mr-2"></i>Commitment to attend 80% of sessions</li>
                                </ul>
                            </div>
                            
                            <div class="flex gap-4">
                                <a href="admissions.php" class="btn-primary text-white px-8 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300 flex-1 text-center">
                                    <i class="fas fa-user-plus mr-2"></i>Join Program
                                </a>
                                <button onclick="closeModal('<?= $program['id'] ?>')" class="bg-gray-100 text-gray-700 px-8 py-3 rounded-xl font-semibold hover:bg-gray-200 transition-all duration-300 flex-1">
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>

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

        // Filter Programs
        const filterButtons = document.querySelectorAll('.filter-btn');
        const programItems = document.querySelectorAll('.program-item');
        const noProgramsMessage = document.getElementById('no-programs');
        
        filterButtons.forEach(button => {
            button.addEventListener('click', () => {
                // Update active button
                filterButtons.forEach(btn => {
                    btn.classList.remove('active', 'bg-orange-600', 'text-white');
                    btn.classList.add('bg-white', 'text-gray-700', 'border', 'border-gray-300');
                });
                
                button.classList.add('active', 'bg-orange-600', 'text-white');
                button.classList.remove('bg-white', 'text-gray-700', 'border', 'border-gray-300');
                
                const filter = button.getAttribute('data-filter');
                
                // Filter programs
                let visibleCount = 0;
                programItems.forEach(item => {
                    if (filter === 'all' || item.getAttribute('data-category').includes(filter)) {
                        item.style.display = 'block';
                        visibleCount++;
                        setTimeout(() => {
                            item.style.opacity = '1';
                            item.style.transform = 'translateY(0)';
                        }, 10);
                    } else {
                        item.style.opacity = '0';
                        item.style.transform = 'translateY(20px)';
                        setTimeout(() => {
                            item.style.display = 'none';
                        }, 300);
                    }
                });
                
                // Show/hide no programs message
                if (visibleCount === 0) {
                    noProgramsMessage.classList.remove('hidden');
                } else {
                    noProgramsMessage.classList.add('hidden');
                }
            });
        });

        // FAQ Toggle
        document.querySelectorAll('.faq-toggle').forEach(button => {
            button.addEventListener('click', () => {
                const content = button.nextElementSibling;
                const icon = button.querySelector('i');
                
                content.classList.toggle('hidden');
                icon.classList.toggle('fa-chevron-down');
                icon.classList.toggle('fa-chevron-up');
            });
        });

        // Program Modal Functions
        function openProgramModal(programId) {
            const modal = document.getElementById(`modal-${programId}`);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(programId) {
            const modal = document.getElementById(`modal-${programId}`);
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = 'auto';
        }

        // Close modal on outside click
        document.querySelectorAll('#program-modals > div').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Category filter from footer
        document.querySelectorAll('footer a[data-filter]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const filter = link.getAttribute('data-filter');
                const button = document.querySelector(`.filter-btn[data-filter="${filter}"]`);
                if (button) button.click();
                
                // Scroll to programs section
                document.getElementById('programs-grid').scrollIntoView({ behavior: 'smooth' });
            });
        });

        // Newsletter form
        document.querySelector('form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;
            if (email) {
                const button = this.querySelector('button');
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i>';
                button.classList.add('bg-green-500');
                
                setTimeout(() => {
                    alert('Thank you for subscribing to program updates!');
                    this.reset();
                    button.innerHTML = originalHTML;
                    button.classList.remove('bg-green-500');
                }, 1000);
            }
        });

        // Animate cards on scroll
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

        // Observe all program cards
        document.querySelectorAll('.program-item').forEach(card => {
            observer.observe(card);
        });

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
    </script>
</body>
</html>
<?php
ob_end_flush();
?>