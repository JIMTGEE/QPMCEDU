<?php
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Queenest Paradise Montessori Complex - Christ Our Foundation, Integrity, Discipline and Excellence</title>
    
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
        
        .testimonial-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 20px 20px 60px #d1d9e6, -20px -20px 60px #ffffff;
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
        
        /* Timeline */
        .timeline-item {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(to bottom, #f97316, #ea580c);
            border-radius: 2px;
        }
        
        .timeline-dot {
            position: absolute;
            left: -10px;
            top: 0;
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.2);
        }
        
        .value-card {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.05) 0%, rgba(234, 88, 12, 0.02) 100%);
            border: 1px solid rgba(249, 115, 22, 0.1);
            transition: all 0.3s ease;
        }
        
        .value-card:hover {
            border-color: rgba(249, 115, 22, 0.3);
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.08) 0%, rgba(234, 88, 12, 0.04) 100%);
        }
        
        .leadership-card {
            position: relative;
            overflow: hidden;
            border: 2px solid transparent;
            background: linear-gradient(white, white) padding-box,
                        linear-gradient(135deg, transparent, rgba(249, 115, 22, 0.1), transparent) border-box;
        }
        
        .leadership-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, #f97316, #ea580c);
        }
        
        .stats-number {
            font-size: 3.5rem;
            font-weight: 900;
            background: linear-gradient(135deg, #f97316, #ea580c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
        }
        
        /* Placeholder for leadership images */
        .leader-img-placeholder {
            width: 128px;
            height: 128px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto 1rem;
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
                    <a href="#about" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        About
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="#history" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        History
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="#values" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Values
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="#leadership" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Leadership
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="#stats" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Stats
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
                    <a href="#about" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">About</a>
                    <a href="#history" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">History</a>
                    <a href="#values" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Values</a>
                    <a href="#leadership" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Leadership</a>
                    <a href="#stats" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Stats</a>
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
    <section class="pt-20 min-h-[60vh] relative overflow-hidden bg-gradient-dark">
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
                    <i class="fas fa-university mr-2 text-yellow-300"></i>
                    About Queenest Paradise
                </div>
                
                <!-- Main Heading -->
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight mb-6 font-display">
                    <span class="gradient-text">
                        Who We Are
                    </span>
                </h1>
                
                <!-- Motto -->
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 mb-8 max-w-3xl mx-auto animate-slide-in-left">
                    <div class="flex items-center justify-center">
                        <i class="fas fa-cross text-orange-300 text-2xl mr-4"></i>
                        <p class="text-xl text-orange-100 font-semibold italic">
                            "Christ Our Foundation, Integrity, Discipline, and Excellence"
                        </p>
                    </div>
                    <p class="text-lg text-orange-200 mt-2 font-medium">
                        A Christian-based institution since 2017
                    </p>
                </div>
                
                <!-- Description -->
                <p class="text-xl text-gray-300 leading-relaxed mb-10 max-w-4xl mx-auto">
                    Queenest Paradise Montessori Complex is a premier Christian-based institution dedicated to shaping bright futures through world-class education. 
                    Since our establishment in 2017, we have nurtured young minds and cultivated leadership through a legacy built on academic excellence and moral character.
                </p>
            </div>
        </div>
    </section>

    <!-- About Content Section -->
    <section id="about" class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Our Story &
                    <span class="section-title block gradient-text">
                        Identity
                    </span>
                </h2>
            </div>
            
            <div class="grid lg:grid-cols-2 gap-12 items-center mb-16">
                <div class="animate-fade-in-up">
                    <div class="bg-gradient-to-br from-orange-50 to-white p-8 rounded-2xl shadow-lg border-gradient">
                        <h3 class="text-2xl font-bold text-gray-900 mb-6">Who We Are</h3>
                        <p class="text-gray-600 leading-relaxed mb-6">
                            Queenest Paradise Montessori Complex, affectionately known as "QueensPa," is more than just a school—it's a nurturing Christian community 
                            where young minds blossom into responsible leaders. Founded in 2017, we have been committed to providing holistic education 
                            that balances academic rigor with Christian character development.
                        </p>
                        <p class="text-gray-600 leading-relaxed">
                            With modern facilities, experienced educators, and a dynamic learning environment, we strive to inspire, engage, and empower 
                            every student to reach their fullest potential in a world that values both knowledge and virtue.
                        </p>
                    </div>
                </div>
                
                <div class="relative animate-fade-in-up" style="animation-delay: 0.2s;">
                    <div class="relative overflow-hidden rounded-2xl shadow-2xl">
                        <img 
                            src="https://www.qpmsedu.com/queenest%20pic%20front.jpg" 
                            alt="Queenest Paradise Campus"
                            class="w-full h-[400px] object-cover transform hover:scale-105 transition-transform duration-700"
                            onerror="this.src='https://images.unsplash.com/photo-1523050854058-8df90110c9f1?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80'"
                        />
                        <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent"></div>
                        <div class="absolute bottom-4 left-4">
                            <span class="px-4 py-2 rounded-full text-sm font-semibold bg-orange-500 text-white">
                                <i class="fas fa-map-marker-alt mr-2"></i>Main Campus - Accra
                            </span>
                        </div>
                    </div>
                    
                    <!-- Floating Elements -->
                    <div class="absolute -top-6 -right-6 bg-gradient-primary rounded-xl p-5 shadow-2xl animate-bounce-slow">
                        <i class="fas fa-graduation-cap text-white text-2xl"></i>
                    </div>
                    
                    <div class="absolute -bottom-6 -left-6 bg-gradient-primary rounded-xl p-5 shadow-2xl animate-bounce-slow" style="animation-delay: 0.5s;">
                        <i class="fas fa-heart text-white text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- Academic Curriculum -->
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl p-8 mb-16 border-gradient">
                <div class="flex items-center mb-6">
                    <div class="bg-gradient-primary p-4 rounded-xl mr-4">
                        <i class="fas fa-book-open text-white text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900">Our Academic Curriculum</h3>
                        <p class="text-gray-600">Excellence in Education Since 2025</p>
                    </div>
                </div>
                <p class="text-gray-700 leading-relaxed text-lg">
                    In 2025, we proudly adopted the prestigious <strong>Oxford International Curriculum</strong>, enhancing our educational offerings 
                    with globally recognized standards that prepare students for success in an interconnected world. This curriculum, combined with our 
                    Christian foundation, provides a comprehensive educational experience that develops critical thinking, creativity, and moral integrity.
                </p>
            </div>
            
            <!-- Mission & Vision -->
            <div class="grid md:grid-cols-2 gap-8 mb-16">
                <div class="bg-gradient-primary rounded-2xl p-8 text-white card-hover animate-slide-in-left">
                    <div class="flex items-center mb-6">
                        <div class="bg-white/20 p-4 rounded-xl mr-4">
                            <i class="fas fa-bullseye text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold">Our Mission</h3>
                    </div>
                    <p class="text-white/90 leading-relaxed text-lg">
                        To raise responsible future leaders with the fear of God in a serene and supportive environment, 
                        equipping them with the knowledge, skills, and values to serve humanity and make positive contributions to society.
                    </p>
                </div>
                
                <div class="bg-gradient-dark rounded-2xl p-8 text-white card-hover animate-slide-in-right">
                    <div class="flex items-center mb-6">
                        <div class="bg-white/20 p-4 rounded-xl mr-4">
                            <i class="fas fa-eye text-2xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold">Our Vision</h3>
                    </div>
                    <p class="text-white/90 leading-relaxed text-lg">
                        To be the preferred Christian educational institution that offers quality education to learners with good potential 
                        in the service of God, mankind, the nation, and the world at large through innovative Montessori practices and the Oxford International Curriculum.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Core Values Section -->
    <section id="values" class="py-24 bg-gradient-to-b from-gray-50 to-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <div class="inline-flex items-center px-4 py-2 rounded-full bg-orange-100 text-orange-800 text-sm font-semibold mb-4">
                    <i class="fas fa-star mr-2"></i>OUR FOUNDATION
                </div>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Core
                    <span class="section-title block gradient-text">
                        Values
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Integrity, Discipline, and Excellence are the pillars upon which we build character and cultivate future leaders.
                </p>
            </div>
            
            <!-- Values Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-16">
                <?php
                $values = [
                    [
                        'icon' => 'fas fa-cross',
                        'title' => 'Christ Foundation',
                        'description' => 'We build our educational philosophy on Christian principles, fostering spiritual growth and moral integrity.',
                        'color' => 'from-orange-500 to-red-500'
                    ],
                    [
                        'icon' => 'fas fa-shield-alt',
                        'title' => 'Integrity',
                        'description' => 'We uphold honesty, transparency, and strong moral principles in all our actions and decisions.',
                        'color' => 'from-blue-500 to-indigo-600'
                    ],
                    [
                        'icon' => 'fas fa-clock',
                        'title' => 'Discipline',
                        'description' => 'We cultivate self-control, responsibility, and structured learning habits for lifelong success.',
                        'color' => 'from-green-500 to-teal-600'
                    ],
                    [
                        'icon' => 'fas fa-trophy',
                        'title' => 'Excellence',
                        'description' => 'We pursue the highest standards in academics, character development, and holistic education.',
                        'color' => 'from-purple-500 to-pink-600'
                    ]
                ];
                
                foreach ($values as $value) {
                ?>
                <div class="value-card rounded-2xl p-6 text-center card-hover">
                    <div class="mb-6">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r <?= $value['color'] ?> p-1">
                            <div class="w-full h-full bg-white rounded-full flex items-center justify-center">
                                <i class="<?= $value['icon'] ?> text-2xl gradient-text"></i>
                            </div>
                        </div>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-4"><?= $value['title'] ?></h3>
                    <p class="text-gray-600"><?= $value['description'] ?></p>
                </div>
                <?php } ?>
            </div>
            
            <!-- Additional Values -->
            <div class="bg-white rounded-2xl p-8 border-gradient shadow-lg">
                <h3 class="text-2xl font-bold text-center mb-8 gradient-text">Our Educational Philosophy</h3>
                <div class="grid md:grid-cols-3 gap-6">
                    <div class="text-center p-6">
                        <div class="text-4xl font-bold gradient-text mb-2">1</div>
                        <h4 class="font-bold text-lg mb-2">Child-Centered Learning</h4>
                        <p class="text-gray-600">Individualized attention to nurture each child's unique potential</p>
                    </div>
                    <div class="text-center p-6">
                        <div class="text-4xl font-bold gradient-text mb-2">2</div>
                        <h4 class="font-bold text-lg mb-2">Holistic Development</h4>
                        <p class="text-gray-600">Balancing academics, creativity, physical, and emotional growth</p>
                    </div>
                    <div class="text-center p-6">
                        <div class="text-4xl font-bold gradient-text mb-2">3</div>
                        <h4 class="font-bold text-lg mb-2">Global Standards</h4>
                        <p class="text-gray-600">Oxford International Curriculum for world-class education</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- History Timeline Section -->
    <section id="history" class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <div class="inline-flex items-center px-4 py-2 rounded-full bg-orange-100 text-orange-800 text-sm font-semibold mb-4">
                    <i class="fas fa-history mr-2"></i>OUR JOURNEY
                </div>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    School
                    <span class="section-title block gradient-text">
                        History
                    </span>
                </h2>
            </div>
            
            <!-- Timeline -->
            <div class="max-w-3xl mx-auto">
                <div class="space-y-12">
                    <?php
                    $timeline = [
                        [
                            'year' => '2017',
                            'title' => 'Foundation Year',
                            'description' => 'Queenest Paradise Montessori Complex was established as a Christian-based institution with a vision to provide quality Montessori education in Accra.',
                            'icon' => 'fas fa-seedling'
                        ],
                        [
                            'year' => '2018',
                            'title' => 'First Graduation Ceremony',
                            'description' => 'Celebrated our first graduating class, marking the beginning of our legacy of academic excellence.',
                            'icon' => 'fas fa-graduation-cap'
                        ],
                        [
                            'year' => '2020',
                            'title' => 'Multiple Graduation Ceremonies',
                            'description' => 'Successfully conducted numerous graduation ceremonies, each marking the achievements of our students and the dedication of our faculty.',
                            'icon' => 'fas fa-users-graduate'
                        ],
                        [
                            'year' => '2023',
                            'title' => 'Growth and Recognition',
                            'description' => 'Expanded our student body and gained recognition for outstanding academic performance and holistic Christian education.',
                            'icon' => 'fas fa-award'
                        ],
                        [
                            'year' => '2025',
                            'title' => 'Oxford International Curriculum',
                            'description' => 'Adopted the prestigious Oxford International Curriculum, enhancing our educational offerings with globally recognized standards.',
                            'icon' => 'fas fa-globe'
                        ],
                        [
                            'year' => 'Present',
                            'title' => 'Continuing Excellence',
                            'description' => 'Continuing our legacy of excellence with innovative programs, Christian values, and commitment to nurturing future leaders.',
                            'icon' => 'fas fa-rocket'
                        ]
                    ];
                    
                    foreach ($timeline as $item) {
                    ?>
                    <div class="timeline-item animate-fade-in-up">
                        <div class="timeline-dot"></div>
                        <div class="ml-8">
                            <div class="flex items-center mb-2">
                                <span class="inline-flex items-center px-4 py-1 rounded-full bg-orange-100 text-orange-800 font-semibold mr-4">
                                    <i class="<?= $item['icon'] ?> mr-2"></i><?= $item['year'] ?>
                                </span>
                                <h3 class="text-xl font-bold text-gray-900"><?= $item['title'] ?></h3>
                            </div>
                            <p class="text-gray-600"><?= $item['description'] ?></p>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section id="stats" class="py-24 bg-gradient-dark relative overflow-hidden">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-orange-500 to-transparent"></div>
            <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-orange-500 to-transparent"></div>
        </div>
        
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-6 font-display">
                    QueensPa
                    <span class="gradient-text block">
                        By Numbers
                    </span>
                </h2>
                <p class="text-xl text-gray-300 max-w-3xl mx-auto leading-relaxed">
                    Our impact and growth in numbers that tell the story of excellence and commitment since 2017.
                </p>
            </div>
            
            <!-- Stats Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mb-16">
                <div class="text-center p-8 counter-item rounded-2xl animate-fade-in-up">
                    <div class="stats-number count mb-2" data-target="550">550+</div>
                    <div class="text-lg font-semibold text-white">Students</div>
                    <p class="text-gray-300 text-sm mt-2">Nurtured Minds</p>
                </div>
                <div class="text-center p-8 counter-item rounded-2xl animate-fade-in-up" style="animation-delay: 0.1s;">
                    <div class="stats-number count mb-2" data-target="8">8+</div>
                    <div class="text-lg font-semibold text-white">Years</div>
                    <p class="text-gray-300 text-sm mt-2">Since 2017</p>
                </div>
                <div class="text-center p-8 counter-item rounded-2xl animate-fade-in-up" style="animation-delay: 0.2s;">
                    <div class="stats-number count mb-2" data-target="6">6+</div>
                    <div class="text-lg font-semibold text-white">Graduation</div>
                    <p class="text-gray-300 text-sm mt-2">Ceremonies</p>
                </div>
                <div class="text-center p-8 counter-item rounded-2xl animate-fade-in-up" style="animation-delay: 0.3s;">
                    <div class="stats-number count mb-2" data-target="52">52+</div>
                    <div class="text-lg font-semibold text-white">Faculty</div>
                    <p class="text-gray-300 text-sm mt-2">Dedicated Educators</p>
                </div>
            </div>
            
            <!-- Additional Stats -->
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 text-center">
                    <div class="text-3xl font-bold text-white mb-2">100%</div>
                    <div class="text-lg font-semibold text-white">Christian Foundation</div>
                    <p class="text-gray-300 text-sm mt-2">Values-based education since 2017</p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 text-center">
                    <div class="text-3xl font-bold text-white mb-2">2025</div>
                    <div class="text-lg font-semibold text-white">Oxford Curriculum</div>
                    <p class="text-gray-300 text-sm mt-2">Global standards implemented</p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 text-center">
                    <div class="text-3xl font-bold text-white mb-2">100%</div>
                    <div class="text-lg font-semibold text-white">Graduation Success</div>
                    <p class="text-gray-300 text-sm mt-2">Multiple successful ceremonies</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Leadership Section -->
    <section id="leadership" class="py-24 bg-gradient-to-b from-white to-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <div class="inline-flex items-center px-4 py-2 rounded-full bg-orange-100 text-orange-800 text-sm font-semibold mb-4">
                    <i class="fas fa-users mr-2"></i>LEADERSHIP TEAM
                </div>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-6 font-display">
                    Meet Our
                    <span class="section-title block gradient-text">
                        Leaders
                    </span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto leading-relaxed">
                    Guided by visionary leadership committed to Christian education and student success.
                </p>
            </div>
            
            <!-- Leadership Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php
                $leaders = [
                    [
                        'name' => 'Mrs. Grace Joan Kissi-Afare',
                        'position' => 'Founder & Director',
                        'initials' => 'GK',
                        'bio' => 'Visionary educator with 20+ years of experience in Montessori education. Established Queenest Paradise in 2017 with a Christian foundation.',
                        'quote' => 'Every child deserves an education that nurtures their unique potential in Christ.'
                    ],
                    [
                        'name' => 'Mr. Francis Adjei',
                        'position' => 'Academic Director',
                        'initials' => 'FA',
                        'bio' => 'M.Ed. in Curriculum Development. Leads the implementation of Oxford International Curriculum since 2025.',
                        'quote' => 'Excellence in education is a journey built on Christian values.'
                    ],
                    [
                        'name' => 'Mrs. Evelyn Frempong',
                        'position' => 'Head of Montessori',
                        'initials' => 'EF',
                        'bio' => 'Certified Montessori instructor with 15 years of early childhood Christian education expertise.',
                        'quote' => 'The classroom should be a haven of curiosity, discovery, and Christian love.'
                    ]
                ];
                
                foreach ($leaders as $leader) {
                ?>
                <div class="leadership-card rounded-2xl p-6 bg-white shadow-lg card-hover">
                    <div class="flex flex-col items-center text-center mb-6">
                        <!-- Placeholder for leader image -->
                        <div class="leader-img-placeholder">
                            <?= $leader['initials'] ?>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900"><?= $leader['name'] ?></h3>
                        <p class="text-orange-600 font-semibold"><?= $leader['position'] ?></p>
                    </div>
                    
                    <div class="mb-6">
                        <p class="text-gray-600 text-sm leading-relaxed"><?= $leader['bio'] ?></p>
                    </div>
                    
                    <div class="bg-orange-50 rounded-xl p-4 border-l-4 border-orange-500">
                        <p class="text-gray-700 italic">"<?= $leader['quote'] ?>"</p>
                    </div>
                    
                    <div class="mt-6 flex justify-center space-x-4">
                        <a href="#" class="text-gray-400 hover:text-orange-600 transition-colors">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-orange-600 transition-colors">
                            <i class="fas fa-envelope"></i>
                        </a>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-24 bg-gradient-primary relative overflow-hidden">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-20">
            <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-white/10 rounded-full blur-3xl floating-shape"></div>
            <div class="absolute bottom-1/4 right-1/4 w-64 h-64 bg-white/10 rounded-full blur-3xl floating-shape" style="animation-delay: 2s;"></div>
        </div>
        
        <div class="relative z-10 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-8 font-display">
                Join Our Legacy of
                <span class="text-white block">Excellence</span>
            </h2>
            
            <p class="text-xl text-white/90 mb-10 max-w-2xl mx-auto">
                Become part of a Christian community that values academic excellence, character development, and holistic growth. 
                Enroll your child today for the 2025/2026 academic year with our Oxford International Curriculum.
            </p>
            
            <div class="flex flex-col sm:flex-row gap-6 justify-center">
                <a href="admissions.php" class="bg-white text-orange-600 px-10 py-5 rounded-xl font-semibold text-lg inline-flex items-center justify-center group hover:bg-gray-50 hover:shadow-2xl transition-all duration-300">
                    <i class="fas fa-file-alt mr-3 text-xl group-hover:rotate-12 transition-transform"></i>
                    Start Application
                </a>
                <a href="contact.php" class="bg-white/20 backdrop-blur-sm text-white border border-white/40 px-10 py-5 rounded-xl font-semibold text-lg hover:bg-white/30 hover:shadow-xl transition-all duration-300 inline-flex items-center justify-center">
                    <i class="fas fa-calendar-alt mr-3 text-xl"></i>
                    Schedule Tour
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
                        Nurturing young minds with Christ as our foundation, building character through integrity, discipline, and excellence in education since 2017.
                        Proudly implementing the Oxford International Curriculum since 2025.
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
                        <li><a href="#about" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>About Us</a></li>
                        <li><a href="gallery.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Gallery</a></li>
                        <li><a href="news.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>News & Events</a></li>
                        <li><a href="admissions.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Admissions</a></li>
                        <li><a href="contact.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Contact Us</a></li>
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
                                <div class="text-gray-300 text-sm">+233 (0) 123-456-789</div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-envelope text-orange-500"></i>
                            <div>
                                <div class="font-semibold">Email Us</div>
                                <div class="text-gray-300 text-sm">info@queenspa.edu.gh</div>
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
                    <i class="fas fa-heart text-orange-500"></i> Established 2017 | Oxford International Curriculum since 2025 | Christ Our Foundation, Integrity, Discipline, and Excellence
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

        // Counter Animation
        const counters = document.querySelectorAll('.count');
        const speed = 200;

        const startCounter = (counter) => {
            const target = +counter.getAttribute('data-target');
            const count = +counter.innerText.replace('+', '');
            const increment = target / speed;
            
            if (count < target) {
                counter.innerText = Math.ceil(count + increment) + '+';
                setTimeout(() => startCounter(counter), 1);
            } else {
                counter.innerText = target + '+';
            }
        };

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    // Add animation class
                    entry.target.classList.add('animate-fade-in-up');
                    
                    // Start counters if they exist
                    if (entry.target.querySelectorAll('.count').length > 0) {
                        setTimeout(() => {
                            entry.target.querySelectorAll('.count').forEach(counter => {
                                if (!counter.hasAttribute('data-counted')) {
                                    counter.setAttribute('data-counted', 'true');
                                    startCounter(counter);
                                }
                            });
                        }, 300);
                    }
                    
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observe all sections
        document.querySelectorAll('section').forEach(section => {
            observer.observe(section);
        });

        // Newsletter Form
        document.querySelector('form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;
            if (email) {
                // Simulate successful subscription
                const button = this.querySelector('button');
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i>';
                button.classList.add('bg-green-500');
                
                setTimeout(() => {
                    alert('Thank you for subscribing to our newsletter!');
                    this.reset();
                    button.innerHTML = originalHTML;
                    button.classList.remove('bg-green-500');
                }, 1000);
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
    </script>
</body>
</html>
<?php
ob_end_flush();
?>