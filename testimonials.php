<?php
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Testimonials - Queenest Paradise Montessori Complex</title>
    
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
        
        .floating-shape {
            animation: float 6s ease-in-out infinite;
        }
        
        .pulse-ring {
            animation: pulse 2s infinite;
        }
        
        .swiper-slide {
            height: auto;
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }
        
        .swiper-slide-active {
            opacity: 1;
        }
        
        .testimonial-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 20px 20px 60px #d1d9e6, -20px -20px 60px #ffffff;
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
        
        .counter-item {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.1) 0%, rgba(234, 88, 12, 0.05) 100%);
            border: 1px solid rgba(249, 115, 22, 0.2);
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
        
        /* Testimonial spotlight effect */
        .spotlight-card {
            position: relative;
            overflow: hidden;
        }
        
        .spotlight-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(249, 115, 22, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        .spotlight-card:hover::before {
            opacity: 1;
        }
        
        /* Quote marks */
        .quote-icon {
            font-size: 4rem;
            opacity: 0.1;
            line-height: 1;
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
                    <a href="index.php#home" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
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
                    <a href="index.php#news" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        News
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="index.php#programs" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Programs
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="testimonials.php" class="group text-orange-600 font-medium transition-all duration-300 relative">
                        Testimonials
                        <span class="absolute bottom-0 left-0 w-full h-0.5 bg-orange-600"></span>
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
                    <a href="index.php#home" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Home</a>
                    <a href="about.php" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">About</a>
                    <a href="index.php#gallery" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Gallery</a>
                    <a href="index.php#news" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">News</a>
                    <a href="index.php#programs" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Programs</a>
                    <a href="testimonials.php" class="text-orange-600 font-medium text-lg">Testimonials</a>
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
        <!-- Animated Background -->
        <div class="absolute inset-0">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-orange-500/10 rounded-full blur-3xl floating-shape"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-red-500/10 rounded-full blur-3xl floating-shape" style="animation-delay: 2s;"></div>
            <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-80 h-80 bg-orange-500/5 rounded-full blur-3xl floating-shape" style="animation-delay: 1s;"></div>
            
            <!-- Grid Pattern -->
            <div class="absolute inset-0" style="background-image: url('data:image/svg+xml,%3Csvg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="none" fill-rule="evenodd"%3E%3Cg fill="%23f97316" fill-opacity="0.05"%3E%3Cpath d="M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"></div>
        </div>
        
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <div class="inline-flex items-center px-6 py-3 rounded-full bg-white/10 backdrop-blur-sm border border-orange-400/30 text-orange-200 text-sm font-semibold mb-6">
                    <i class="fas fa-comments mr-2 text-yellow-300"></i>
                    VOICES FROM OUR COMMUNITY
                </div>
                
                <!-- Main Heading -->
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight mb-6 font-display">
                    What They Say
                    <span class="block gradient-text mt-2">
                        About QueensPa
                    </span>
                </h1>
                
                <!-- Motto -->
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 mb-8 border border-white/20 max-w-2xl mx-auto">
                    <div class="flex items-center justify-center">
                        <i class="fas fa-cross text-orange-300 text-2xl mr-4"></i>
                        <p class="text-xl text-orange-100 font-semibold italic">
                            "Christ Our Foundation, Integrity, Discipline, and Excellence"
                        </p>
                    </div>
                </div>
                
                <!-- Description -->
                <p class="text-xl text-gray-300 leading-relaxed mb-8 max-w-3xl mx-auto">
                    Hear from our students, parents, and alumni about their transformative experiences at Queenest Paradise Montessori Complex. Discover how our Christ-centered education shapes character, builds leaders, and creates lifelong learners.
                </p>
                
                <!-- Stats -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 max-w-2xl mx-auto">
                    <div class="counter-item rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-white mb-1">100%</div>
                        <div class="text-sm text-gray-300">Satisfied Parents</div>
                    </div>
                    <div class="counter-item rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-white mb-1">5.0</div>
                        <div class="text-sm text-gray-300">Average Rating</div>
                    </div>
                    <div class="counter-item rounded-xl p-4 text-center">
                        <div class="text-2xl font-bold text-white mb-1">50+</div>
                        <div class="text-sm text-gray-300">Testimonials</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Scroll Indicator -->
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2">
            <a href="#testimonials" class="text-white animate-bounce">
                <i class="fas fa-chevron-down text-3xl"></i>
            </a>
        </div>
    </section>

    <!-- Featured Testimonials Section -->
    <section id="testimonials" class="py-20 bg-gradient-to-b from-gray-50 to-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Featured
                    <span class="section-title block gradient-text">
                        Testimonials
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Read what our students and their families have to say about their journey at Queenest Paradise Montessori Complex.
                </p>
            </div>
            
            <!-- Testimonials Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 mb-20">
                <?php
                $featuredTestimonials = [
                    [
                        'name' => 'Blessing Animwaa',
                        'role' => 'Grade 8 Student | Girls Guide',
                        'image' => '',
                        'text' => 'QueensPa has transformed my learning experience. The teachers are incredibly supportive, and the interactive lessons make every subject exciting. Being part of the Girls Guide program has taught me leadership skills and confidence that I\'ll carry throughout my life.',
                        'full_story' => 'When I joined Queenest Paradise in Grade 6, I was shy and struggled with mathematics. The teachers here noticed my potential and provided personalized attention. Through the Girls Guide program, I learned public speaking, community service, and entrepreneurship. Today, I\'m the class prefect and have won two academic awards. The holistic education approach here truly develops both the mind and character.',
                        'rating' => 5,
                        'program' => 'Girls Guide',
                        'date' => 'March 2024',
                        'achievements' => ['Class Prefect', 'Academic Excellence Award', 'Girls Guide Leadership Badge']
                    ],
                    [
                        'name' => 'Lawrence Adom',
                        'role' => 'Sports Captain | Basketball Team',
                        'image' => '',
                        'text' => 'The Sports Academy helped me develop both athletic skills and leadership qualities. We\'ve won multiple inter-school competitions, but more importantly, we\'ve learned teamwork and discipline.',
                        'full_story' => 'As Sports Captain for two consecutive years, I\'ve witnessed how Queenest Paradise balances academics and sports perfectly. The coaches are professional athletes who teach us not just how to play, but how to be team players. Our basketball team has won the regional championship three times. The school\'s emphasis on discipline has helped me maintain excellent grades while excelling in sports. The facilities here are top-notch, and the support from teachers is incredible.',
                        'rating' => 5,
                        'program' => 'Sports Academy',
                        'date' => 'February 2024',
                        'achievements' => ['Sports Captain', 'Regional Basketball Champion', 'Most Valuable Player Award']
                    ]
                ];
                
                foreach ($featuredTestimonials as $index => $testimonial) {
                ?>
                <div class="spotlight-card testimonial-card rounded-3xl p-8 card-hover animate-fade-in-up" style="animation-delay: <?= $index * 0.2 ?>s">
                    <!-- Quote Icon -->
                    <div class="text-right mb-4">
                        <i class="fas fa-quote-right quote-icon text-orange-500"></i>
                    </div>
                    
                    <!-- Header -->
                    <div class="flex items-start space-x-6 mb-8">
                        <div class="relative">
                            <img 
                                src="<?= $testimonial['image'] ?>" 
                                alt="<?= $testimonial['name'] ?>"
                                class="w-20 h-20 rounded-2xl object-cover border-4 border-white shadow-xl"
                            >
                            <div class="absolute -bottom-2 -right-2 w-10 h-10 bg-gradient-primary rounded-full flex items-center justify-center shadow-lg">
                                <i class="fas fa-star text-white text-sm"></i>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-2xl font-bold text-gray-900 mb-1"><?= $testimonial['name'] ?></h3>
                            <p class="text-gray-600 mb-2"><?= $testimonial['role'] ?></p>
                            
                            <!-- Rating -->
                            <div class="flex mb-3">
                                <?php for ($i = 0; $i < $testimonial['rating']; $i++) { ?>
                                <i class="fas fa-star text-yellow-400 mr-1"></i>
                                <?php } ?>
                            </div>
                            
                            <!-- Program Badge -->
                            <span class="inline-block px-4 py-1 bg-orange-100 text-orange-800 rounded-full text-sm font-semibold">
                                <i class="fas fa-tag mr-1"></i><?= $testimonial['program'] ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Quote -->
                    <div class="mb-8">
                        <p class="text-gray-700 text-lg leading-relaxed italic mb-6">"<?= $testimonial['text'] ?>"</p>
                        
                        <!-- Full Story (Collapsible) -->
                        <div id="story-<?= $index ?>" class="hidden">
                            <p class="text-gray-600 mb-6"><?= $testimonial['full_story'] ?></p>
                            
                            <!-- Achievements -->
                            <div class="bg-orange-50 rounded-xl p-4 mb-4">
                                <h4 class="font-bold text-gray-900 mb-3 flex items-center">
                                    <i class="fas fa-trophy text-orange-500 mr-2"></i>
                                    Notable Achievements
                                </h4>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($testimonial['achievements'] as $achievement) { ?>
                                    <span class="px-3 py-1 bg-white text-gray-800 rounded-full text-sm border border-orange-200">
                                        <?= $achievement ?>
                                    </span>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Toggle Button -->
                        <button 
                            onclick="toggleStory(<?= $index ?>)" 
                            class="text-orange-600 font-semibold text-sm hover:text-orange-700 transition-colors flex items-center group"
                            id="toggle-btn-<?= $index ?>"
                        >
                            Read Full Story
                            <i class="fas fa-chevron-down ml-2 group-hover:translate-y-1 transition-transform"></i>
                        </button>
                    </div>
                    
                    <!-- Footer -->
                    <div class="pt-6 border-t border-gray-100 flex justify-between items-center">
                        <div class="flex items-center text-gray-500">
                            <i class="far fa-calendar-alt mr-2"></i>
                            <span class="text-sm"><?= $testimonial['date'] ?></span>
                        </div>
                        <div class="flex space-x-3">
                            <button class="text-gray-400 hover:text-orange-500 transition-colors">
                                <i class="far fa-thumbs-up"></i>
                            </button>
                            <button class="text-gray-400 hover:text-orange-500 transition-colors">
                                <i class="far fa-share-square"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
            
            <!-- Testimonial Categories -->
            <div class="mb-20">
                <h3 class="text-2xl font-bold text-gray-900 mb-8 text-center">Browse by Category</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php
                    $categories = [
                        ['name' => 'Students', 'icon' => 'fas fa-user-graduate', 'count' => 12],
                        ['name' => 'Parents', 'icon' => 'fas fa-users', 'count' => 8],
                        ['name' => 'Alumni', 'icon' => 'fas fa-graduation-cap', 'count' => 15],
                        ['name' => 'Faculty', 'icon' => 'fas fa-chalkboard-teacher', 'count' => 5]
                    ];
                    
                    foreach ($categories as $category) {
                    ?>
                    <a href="#" class="bg-white border border-gray-200 rounded-xl p-4 text-center hover:shadow-lg hover:border-orange-300 transition-all duration-300 group">
                        <div class="bg-orange-100 w-12 h-12 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-orange-500 transition-colors">
                            <i class="<?= $category['icon'] ?> text-orange-600 group-hover:text-white text-xl"></i>
                        </div>
                        <div class="font-semibold text-gray-900"><?= $category['name'] ?></div>
                        <div class="text-sm text-gray-500"><?= $category['count'] ?> testimonials</div>
                    </a>
                    <?php } ?>
                </div>
            </div>
            
            <!-- Submit Testimonial CTA -->
            <div class="bg-gradient-primary rounded-3xl p-8 text-white text-center">
                <h3 class="text-3xl font-bold mb-4">Share Your QueensPa Experience</h3>
                <p class="text-white/90 mb-8 max-w-2xl mx-auto">
                    Have a story to share about your journey with us? We'd love to hear how Queenest Paradise has impacted your life or your child's education.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <button onclick="openTestimonialForm()" class="bg-white text-orange-600 px-8 py-4 rounded-xl font-semibold text-lg hover:bg-gray-100 transition-all duration-300">
                        <i class="fas fa-edit mr-2"></i>Submit Your Testimonial
                    </button>
                    <a href="contact.php" class="bg-white/20 backdrop-blur-sm text-white border border-white/30 px-8 py-4 rounded-xl font-semibold text-lg hover:bg-white/30 transition-all duration-300">
                        <i class="fas fa-envelope mr-2"></i>Contact Us
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Video Testimonials Section -->
    <section class="py-20 bg-gradient-to-b from-white to-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Video
                    <span class="section-title block gradient-text">
                        Testimonials
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Watch our community members share their QueensPa stories in their own words.
                </p>
            </div>
            
            <!-- Video Placeholder -->
            <div class="bg-gray-900 rounded-3xl overflow-hidden shadow-2xl mb-12">
                <div class="aspect-w-16 aspect-h-9 relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-orange-500/20 to-orange-600/20 flex items-center justify-center">
                        <div class="text-center p-8">
                            <div class="w-20 h-20 bg-gradient-primary rounded-full flex items-center justify-center mx-auto mb-6 animate-pulse-slow">
                                <i class="fas fa-play text-white text-2xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-white mb-2">Coming Soon</h3>
                            <p class="text-gray-300">Video testimonials from our QueensPa family</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Video Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="text-4xl font-bold text-gray-900 mb-2">100+</div>
                    <div class="text-gray-600">Hours of Footage</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold text-gray-900 mb-2">50+</div>
                    <div class="text-gray-600">Video Stories</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold text-gray-900 mb-2">10K+</div>
                    <div class="text-gray-600">Total Views</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonial Submission Form (Hidden) -->
    <div id="testimonialForm" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-white border-b border-gray-200 p-6 flex justify-between items-center rounded-t-3xl">
                <h3 class="text-2xl font-bold text-gray-900">Share Your Story</h3>
                <button onclick="closeTestimonialForm()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
            
            <form class="p-6 space-y-6">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Your Name *</label>
                    <input type="text" required class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Your Role *</label>
                    <select class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        <option value="">Select your role</option>
                        <option value="student">Student</option>
                        <option value="parent">Parent/Guardian</option>
                        <option value="alumni">Alumni</option>
                        <option value="faculty">Faculty Member</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Your Testimonial *</label>
                    <textarea rows="6" required class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent" placeholder="Share your experience at QueensPa..."></textarea>
                </div>
                
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Rating</label>
                    <div class="flex space-x-2">
                        <?php for ($i = 1; $i <= 5; $i++) { ?>
                        <button type="button" class="text-2xl text-gray-300 hover:text-yellow-400" onclick="setRating(<?= $i ?>)">
                            <i class="far fa-star" id="star-<?= $i ?>"></i>
                        </button>
                        <?php } ?>
                    </div>
                    <input type="hidden" name="rating" id="ratingInput" value="0">
                </div>
                
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Program/Activity (Optional)</label>
                    <input type="text" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent" placeholder="e.g., Girls Guide, Sports Academy, etc.">
                </div>
                
                <div class="pt-6 border-t border-gray-200">
                    <button type="submit" class="w-full btn-primary text-white px-8 py-4 rounded-xl font-semibold text-lg">
                        <i class="fas fa-paper-plane mr-2"></i>Submit Testimonial
                    </button>
                </div>
            </form>
        </div>
    </div>

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
                        Nurturing young minds with Christ as our foundation, building character through integrity, discipline, and excellence in education since 2017.
                    </p>
                    
                    <!-- Newsletter -->
                    <div class="mb-8">
                        <h4 class="font-semibold mb-4">Subscribe to Newsletter</h4>
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
                        <li><a href="index.php#home" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Home</a></li>
                        <li><a href="about.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>About Us</a></li>
                        <li><a href="index.php#gallery" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Gallery</a></li>
                        <li><a href="index.php#news" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>News & Events</a></li>
                        <li><a href="testimonials.php" class="text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500"></i>Testimonials</a></li>
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
                                <div class="text-gray-300 text-sm">Accra, Ghana</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-phone text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Call Us</div>
                                <div class="text-gray-300 text-sm">+233552025642</div>
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

        // Toggle Full Story
        function toggleStory(index) {
            const story = document.getElementById('story-' + index);
            const button = document.getElementById('toggle-btn-' + index);
            const icon = button.querySelector('i');
            
            if (story.classList.contains('hidden')) {
                story.classList.remove('hidden');
                button.innerHTML = 'Show Less <i class="fas fa-chevron-up ml-2"></i>';
            } else {
                story.classList.add('hidden');
                button.innerHTML = 'Read Full Story <i class="fas fa-chevron-down ml-2"></i>';
            }
        }

        // Testimonial Form Functions
        function openTestimonialForm() {
            document.getElementById('testimonialForm').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeTestimonialForm() {
            document.getElementById('testimonialForm').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Rating System
        let currentRating = 0;
        function setRating(rating) {
            currentRating = rating;
            document.getElementById('ratingInput').value = rating;
            
            // Update star display
            for (let i = 1; i <= 5; i++) {
                const star = document.getElementById('star-' + i);
                if (i <= rating) {
                    star.classList.remove('far');
                    star.classList.add('fas');
                    star.classList.add('text-yellow-400');
                } else {
                    star.classList.remove('fas');
                    star.classList.add('far');
                    star.classList.remove('text-yellow-400');
                }
            }
        }

        // Form Submission
        document.querySelector('#testimonialForm form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Simulate form submission
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...';
            submitBtn.disabled = true;
            
            setTimeout(() => {
                alert('Thank you for sharing your story! Your testimonial has been submitted for review.');
                closeTestimonialForm();
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                this.reset();
                setRating(0);
            }, 1500);
        });

        // Close form when clicking outside
        document.getElementById('testimonialForm').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTestimonialForm();
            }
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

        // Animate elements on scroll
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

        // Observe all testimonial cards
        document.querySelectorAll('.spotlight-card').forEach(card => {
            observer.observe(card);
        });
    </script>
</body>
</html>