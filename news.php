<?php
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News & Events - Queenest Paradise Montessori Complex</title>
    
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
    
    <!-- Reuse the same Tailwind config and styles from index.php -->
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
        
        .news-category-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            z-index: 10;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }
        
        .news-date {
            position: absolute;
            bottom: 15px;
            right: 15px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
        }
        
        .read-time {
            font-size: 12px;
            color: #6b7280;
        }
        
        .news-content img {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            margin: 20px 0;
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
    </style>
</head>

<body class="min-h-screen bg-white text-gray-800 page-load">
    
    <!-- Navigation Bar - Same as index.php but with News highlighted -->
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
                    <a href="news.php" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        News
                        <span class="absolute bottom-0 left-0 w-full h-0.5 bg-orange-600 transition-all duration-300"></span>
                    </a>
                    <a href="index.php#programs" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
                        Programs
                        <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-orange-600 group-hover:w-full transition-all duration-300"></span>
                    </a>
                    <a href="index.php#testimonials" class="group text-gray-700 hover:text-orange-600 font-medium transition-all duration-300 relative">
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
                    <a href="index.php#gallery" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Gallery</a>
                    <a href="news.php" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">News</a>
                    <a href="index.php#programs" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Programs</a>
                    <a href="index.php#testimonials" class="text-gray-700 hover:text-orange-600 font-medium text-lg transition-colors duration-300">Testimonials</a>
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
    <section class="pt-32 pb-20 bg-gradient-dark relative overflow-hidden">
        <!-- Animated Background -->
        <div class="absolute inset-0">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-orange-500/10 rounded-full blur-3xl floating-shape"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-red-500/10 rounded-full blur-3xl floating-shape" style="animation-delay: 2s;"></div>
        </div>
        
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-white mb-6 font-display animate-fade-in-up">
                School
                <span class="gradient-text block">News & Events</span>
            </h1>
            <p class="text-xl text-gray-300 max-w-3xl mx-auto mb-8 animate-fade-in-up" style="animation-delay: 0.2s;">
                Stay updated with the latest achievements, events, and developments at Queenest Paradise Montessori Complex.
            </p>
            <div class="inline-flex items-center px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm border border-orange-400/30 text-orange-200 text-sm font-semibold animate-fade-in-up" style="animation-delay: 0.4s;">
                <i class="fas fa-newspaper mr-2"></i>
                Latest Updates from QueensPa
            </div>
        </div>
    </section>

    <!-- News Categories -->
    <section class="py-12 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap justify-center gap-4">
                <button class="px-6 py-3 bg-orange-500 text-white font-semibold rounded-full shadow-lg hover:shadow-xl transition-all duration-300">
                    <i class="fas fa-newspaper mr-2"></i>All News
                </button>
                <button class="px-6 py-3 bg-white text-gray-700 font-semibold rounded-full shadow hover:shadow-md hover:bg-orange-50 hover:text-orange-600 transition-all duration-300">
                    <i class="fas fa-calendar-alt mr-2"></i>Events
                </button>
                <button class="px-6 py-3 bg-white text-gray-700 font-semibold rounded-full shadow hover:shadow-md hover:bg-orange-50 hover:text-orange-600 transition-all duration-300">
                    <i class="fas fa-trophy mr-2"></i>Achievements
                </button>
                <button class="px-6 py-3 bg-white text-gray-700 font-semibold rounded-full shadow hover:shadow-md hover:bg-orange-50 hover:text-orange-600 transition-all duration-300">
                    <i class="fas fa-graduation-cap mr-2"></i>Academic
                </button>
                <button class="px-6 py-3 bg-white text-gray-700 font-semibold rounded-full shadow hover:shadow-md hover:bg-orange-50 hover:text-orange-600 transition-all duration-300">
                    <i class="fas fa-users mr-2"></i>Community
                </button>
            </div>
        </div>
    </section>

    <!-- News Grid -->
    <section class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- HOW TO ADD MORE NEWS:
                1. Copy the article HTML structure below (the div with class "news-article")
                2. Update the following:
                   - category-badge class (bg-[color] for different categories)
                   - date
                   - title
                   - excerpt
                   - image URL
                   - Read time
                   - Author
                3. Add your full content in the "news-content" div
                4. Add more articles as needed
            -->
            
            <!-- Featured News -->
            <div class="mb-16">
                <h2 class="text-3xl font-bold text-gray-900 mb-8 flex items-center">
                    <i class="fas fa-star text-orange-500 mr-3"></i>Featured Stories
                </h2>
                <div class="bg-gradient-primary rounded-3xl overflow-hidden shadow-2xl">
                    <div class="grid lg:grid-cols-2 gap-8 p-8">
                        <div>
                            <div class="flex items-center mb-4">
                                <span class="bg-white/20 text-white px-4 py-2 rounded-full text-sm font-semibold mr-4">
                                    FEATURED EVENT
                                </span>
                                <span class="text-white/80">
                                    <i class="far fa-calendar-alt mr-2"></i>May 15-17, 2026
                                </span>
                            </div>
                            <h3 class="text-3xl font-bold text-white mb-6">Annual Science & Technology Fair 2026</h3>
                            <p class="text-white/90 mb-8 text-lg">
                                Join us for our biggest event of the year featuring innovative student projects, robotics demonstrations, and guest speakers from leading tech industries. This year's theme: "Innovating for a Sustainable Future".
                            </p>
                            <div class="flex items-center space-x-6 text-white/80 mb-6">
                                <div class="flex items-center">
                                    <i class="fas fa-map-marker-alt mr-2"></i>
                                    <span>Main Campus Auditorium</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-clock mr-2"></i>
                                    <span>9:00 AM - 4:00 PM</span>
                                </div>
                            </div>
                            <a href="#featured-article" class="btn-primary text-white px-8 py-4 rounded-xl font-semibold text-lg inline-flex items-center group">
                                <i class="fas fa-book-open mr-3 group-hover:rotate-12 transition-transform"></i>
                                Read Full Story
                                <i class="fas fa-arrow-right ml-3 group-hover:translate-x-2 transition-transform"></i>
                            </a>
                        </div>
                        <div class="relative">
                            <img 
                                src="https://www.qpmsedu.com/queenest%20pic%20front.jpg" 
                                alt="Science Fair 2026"
                                class="w-full h-full object-cover rounded-2xl shadow-2xl"
                                onerror="this.src='https://images.unsplash.com/photo-1532094349884-543bc11b234d?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80'"
                            />
                            <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent rounded-2xl"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- All News Grid -->
            <h2 class="text-3xl font-bold text-gray-900 mb-8">Latest News</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-16">
                <?php
                // SAMPLE NEWS DATA - YOU CAN ADD MORE ITEMS HERE
                $newsItems = [

                    [
                        'id' => 3,
                        'category' => 'Academic',
                        'category_color' => 'bg-purple-500',
                        'date' => 'March 5, 2026',
                        'title' => 'New Coding Curriculum Launched for Elementary Students',
                        'excerpt' => 'Introducing computational thinking and basic programming concepts to students from Grade 4 onwards.',
                        'image' => '',
                        'read_time' => '5 min read',
                        'author' => 'Mr. Jim'
                    ],

                    [
                        'id' => 6,
                        'category' => 'Announcement',
                        'category_color' => 'bg-indigo-500',
                        'date' => 'February 15, 2026',
                        'title' => 'Admissions Open for 2025/206 Academic Year',
                        'excerpt' => 'Early bird registration now open. Limited spaces available for all grade levels.',
                        'image' => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80',
                        'read_time' => '2 min read',
                        'author' => 'Admissions Office'
                    ]
                ];
                
                foreach ($newsItems as $news) {
                ?>
                <!-- News Article Card -->
                <div class="news-article bg-white rounded-2xl overflow-hidden shadow-lg card-hover border-gradient">
                    <div class="relative h-56 overflow-hidden">
                        <img 
                            src="<?= $news['image'] ?>" 
                            alt="<?= $news['title'] ?>"
                            class="w-full h-full object-cover transition-transform duration-500 hover:scale-110"
                            onerror="this.src='https://images.unsplash.com/photo-1523050854058-8df90110c9f1?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80'"
                        >
                        <div class="news-category-badge <?= $news['category_color'] ?>">
                            <?= $news['category'] ?>
                        </div>
                        <div class="news-date">
                            <?= $news['date'] ?>
                        </div>
                    </div>
                    <div class="p-6">
                        <h3 class="text-xl font-bold text-gray-900 mb-3"><?= $news['title'] ?></h3>
                        <p class="text-gray-600 mb-4"><?= $news['excerpt'] ?></p>
                        
                        <div class="flex items-center justify-between text-sm text-gray-500 mb-4">
                            <div class="flex items-center">
                                <i class="far fa-clock mr-2"></i>
                                <span class="read-time"><?= $news['read_time'] ?></span>
                            </div>
                            <div class="flex items-center">
                                <i class="far fa-user mr-2"></i>
                                <span><?= $news['author'] ?></span>
                            </div>
                        </div>
                        
                        <button onclick="showArticle(<?= $news['id'] ?>)" class="text-orange-600 font-semibold hover:text-orange-700 transition-colors flex items-center group">
                            Read Full Article
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                        </button>
                    </div>
                </div>
                <?php } ?>
            </div>

            <!-- Pagination -->
            <div class="flex justify-center items-center space-x-2">
                <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button class="px-4 py-2 bg-orange-500 text-white rounded-lg font-semibold">1</button>
                <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">2</button>
                <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">3</button>
                <span class="px-2">...</span>
                <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">10</button>
                <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </section>

    <!-- Article Detail Modal -->
    <div id="articleModal" class="fixed inset-0 bg-black/80 z-50 hidden overflow-y-auto">
        <div class="min-h-screen px-4 py-20">
            <div class="max-w-4xl mx-auto bg-white rounded-2xl shadow-2xl">
                <!-- Modal Header -->
                <div class="relative">
                    <button onclick="closeArticle()" class="absolute top-4 right-4 bg-white/90 backdrop-blur-sm text-gray-800 p-3 rounded-full hover:bg-white hover:shadow-lg transition-all duration-300 z-10">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                    <img 
                        id="modalImage"
                        src="" 
                        alt=""
                        class="w-full h-96 object-cover rounded-t-2xl"
                    >
                    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-6">
                        <div class="flex items-center flex-wrap gap-4 mb-2">
                            <span id="modalCategory" class="px-4 py-2 rounded-full text-sm font-semibold text-white"></span>
                            <span class="text-white/80">
                                <i class="far fa-calendar-alt mr-2"></i>
                                <span id="modalDate"></span>
                            </span>
                        </div>
                        <h2 id="modalTitle" class="text-3xl font-bold text-white mb-2"></h2>
                        <div class="flex items-center text-white/80">
                            <i class="far fa-user mr-2"></i>
                            <span id="modalAuthor" class="mr-4"></span>
                            <i class="far fa-clock mr-2"></i>
                            <span id="modalReadTime"></span>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Content -->
                <div class="p-8">
                    <div id="modalContent" class="news-content prose prose-lg max-w-none">
                        <!-- Article content will be loaded here dynamically -->
                    </div>
                    
                    <!-- Social Sharing -->
                    <div class="mt-12 pt-8 border-t border-gray-200">
                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Share this article</h4>
                        <div class="flex space-x-3">
                            <button class="bg-blue-500 text-white p-3 rounded-lg hover:bg-blue-600 transition-colors">
                                <i class="fab fa-facebook-f"></i>
                            </button>
                            <button class="bg-blue-400 text-white p-3 rounded-lg hover:bg-blue-500 transition-colors">
                                <i class="fab fa-twitter"></i>
                            </button>
                            <button class="bg-pink-500 text-white p-3 rounded-lg hover:bg-pink-600 transition-colors">
                                <i class="fab fa-instagram"></i>
                            </button>
                            <button class="bg-green-500 text-white p-3 rounded-lg hover:bg-green-600 transition-colors">
                                <i class="fab fa-whatsapp"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Featured Article (Displayed on page) -->

                    

    <!-- CTA Section -->
    <section class="py-20 bg-gradient-dark relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-orange-500 to-transparent"></div>
            <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-orange-500 to-transparent"></div>
        </div>
        
        <div class="relative z-10 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl font-bold text-white mb-8 font-display">
                Stay Updated
                <span class="gradient-text block">With QueensPa News</span>
            </h2>
            
            <p class="text-xl text-gray-300 mb-10 max-w-2xl mx-auto">
                Never miss an important update. Subscribe to our newsletter for the latest news, events, and achievements.
            </p>
            
            <form class="max-w-xl mx-auto flex gap-4 mb-12">
                <input 
                    type="email" 
                    placeholder="Enter your email address" 
                    class="flex-grow px-6 py-4 rounded-xl bg-white/10 backdrop-blur-sm border border-white/30 text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent"
                    required
                >
                <button type="submit" class="btn-primary text-white px-8 py-4 rounded-xl font-semibold text-lg">
                    Subscribe
                </button>
            </form>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="text-3xl font-bold text-white mb-2">50+</div>
                    <div class="text-gray-300">News Articles</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-white mb-2">24+</div>
                    <div class="text-gray-300">Annual Events</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-white mb-2">100%</div>
                    <div class="text-gray-300">Community Engagement</div>
                </div>
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
                    </p>
                </div>
                
                <!-- Quick Links -->
                <div>
                    <h3 class="text-xl font-bold mb-6">Quick Links</h3>
                    <ul class="space-y-3">
                        <li><a href="index.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Home</a></li>
                        <li><a href="news.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>News</a></li>
                        <li><a href="gallery.php" class="text-gray-300 hover:text-white hover:text-orange-300 transition-colors duration-300 flex items-center group">
                            <i class="fas fa-chevron-right text-xs mr-2 text-orange-500 group-hover:translate-x-1 transition-transform"></i>Gallery</a></li>
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
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Back to Top -->
    <button id="backToTop" class="fixed bottom-8 right-8 bg-gradient-primary text-white p-4 rounded-full shadow-2xl hover:shadow-3xl transition-all duration-300 opacity-0 z-40 group">
        <i class="fas fa-chevron-up text-lg group-hover:-translate-y-1 transition-transform"></i>
    </button>

    <!-- Scripts -->
    <script>
        // News articles data - YOU CAN ADD MORE ARTICLES HERE
        const newsArticles = {

            2: {
                title: "Career Day 2026: Connecting Students with Industry Leaders",
                category: "Event",
                categoryColor: "bg-blue-500",
                date: "March 10, 2026",
                author: "Ms. Grace Mensah",
                readTime: "4 min read",
                image: "https://images.unsplash.com/photo-1523240795612-9a054b0db644?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80",
                content: `
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">Career Day Success</h3>
                    <p class="mb-4">Our annual Career Day event was a tremendous success, featuring professionals from diverse fields who shared valuable insights with our students about various career paths.</p>
                    
                    <h4 class="text-xl font-bold text-gray-900 mt-6 mb-3">Featured Professionals</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-white border border-gray-200 rounded-xl p-5">
                            <div class="font-bold text-lg mb-2">Dr. Nana Ama Boateng</div>
                            <div class="text-gray-600 mb-3">Medical Doctor, Korle-Bu Teaching Hospital</div>
                            <p class="text-sm">Shared insights into medical careers and the importance of STEM education in healthcare.</p>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-xl p-5">
                            <div class="font-bold text-lg mb-2">Mr. Kofi Asare</div>
                            <div class="text-gray-600 mb-3">Software Engineer, Google Africa</div>
                            <p class="text-sm">Discussed the future of technology and opportunities in the tech industry.</p>
                        </div>
                    </div>
                    
                    <h4 class="text-xl font-bold text-gray-900 mt-6 mb-3">Student Feedback</h4>
                    <p class="mb-4">Students found the sessions incredibly inspiring. Grade 11 student, Yaa Asantewaa, shared:</p>
                    <blockquote class="border-l-4 border-orange-500 pl-4 italic text-gray-700 mb-6">
                        "Hearing from real professionals helped me understand what it takes to succeed in different fields. I'm now more determined to pursue my dream of becoming an engineer."
                    </blockquote>
                    
                    <h4 class="text-xl font-bold text-gray-900 mt-6 mb-3">Interactive Sessions</h4>
                    <p class="mb-4">The event featured:</p>
                    <ul class="list-disc pl-6 space-y-2 mb-6">
                        <li>Panel discussions on emerging careers</li>
                        <li>One-on-one mentorship sessions</li>
                        <li>Resume building workshops</li>
                        <li>Mock interviews</li>
                        <li>Industry-specific breakout sessions</li>
                    </ul>
                    
                    <div class="bg-gray-50 rounded-xl p-6">
                        <h5 class="font-bold text-gray-900 mb-3">Looking Ahead</h5>
                        <p>Based on this year's success, we plan to make Career Day a bi-annual event and establish an alumni mentorship program to provide ongoing guidance to our students.</p>
                    </div>
                `
            }
            // Add more articles here following the same structure
            // 3: { ... }, 4: { ... }, etc.
        };

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

        // Smooth Scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                
                if (href.startsWith('#') && href !== '#articleModal') {
                    e.preventDefault();
                    const target = document.querySelector(href);
                    if (target) {
                        window.scrollTo({
                            top: target.offsetTop - 100,
                            behavior: 'smooth'
                        });
                    }
                }
            });
        });

        // Article Modal Functions
        function showArticle(articleId) {
            const article = newsArticles[articleId];
            if (!article) return;
            
            // Set modal content
            document.getElementById('modalImage').src = article.image;
            document.getElementById('modalCategory').textContent = article.category;
            document.getElementById('modalCategory').className = `px-4 py-2 rounded-full text-sm font-semibold text-white ${article.categoryColor}`;
            document.getElementById('modalDate').textContent = article.date;
            document.getElementById('modalTitle').textContent = article.title;
            document.getElementById('modalAuthor').textContent = article.author;
            document.getElementById('modalReadTime').textContent = article.readTime;
            document.getElementById('modalContent').innerHTML = article.content;
            
            // Show modal
            document.getElementById('articleModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeArticle() {
            document.getElementById('articleModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeArticle();
            }
        });

        // Close modal on background click
        document.getElementById('articleModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('articleModal')) {
                closeArticle();
            }
        });

        // Newsletter form submission
        document.querySelector('form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;
            if (email) {
                const button = this.querySelector('button');
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i> Subscribed!';
                button.classList.add('bg-green-500');
                
                setTimeout(() => {
                    this.reset();
                    button.innerHTML = originalText;
                    button.classList.remove('bg-green-500');
                }, 3000);
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

        // Animate cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-in-up');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observe news cards
        document.querySelectorAll('.news-article').forEach(card => {
            observer.observe(card);
        });
    </script>
</body>
</html>