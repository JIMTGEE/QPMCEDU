<?php
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queenest Paradise Montessori Complex - Christ Our Foundation, Integrity, Discipline and Excellence</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800;900&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Swiper -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css" />
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'display': ['Playfair Display', 'Georgia', 'serif'],
                        'body': ['DM Sans', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        brand: {
                            50:  '#fff7ed',
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
                        dark: {
                            900: '#0c0f1a',
                            800: '#141827',
                            700: '#1e2538',
                        }
                    },
                    animation: {
                        'float':       'float 7s ease-in-out infinite',
                        'float-slow':  'float 10s ease-in-out infinite',
                        'fade-up':     'fadeUp 0.7s ease-out both',
                        'fade-in':     'fadeIn 1s ease-out both',
                        'slide-left':  'slideLeft 0.8s ease-out both',
                        'slide-right': 'slideRight 0.8s ease-out both',
                        'pulse-ring':  'pulseRing 2.5s cubic-bezier(0.4,0,0.6,1) infinite',
                    },
                    keyframes: {
                        float:      { '0%,100%': {transform:'translateY(0)'},    '50%': {transform:'translateY(-18px)'} },
                        fadeUp:     { '0%': {opacity:'0',transform:'translateY(32px)'}, '100%': {opacity:'1',transform:'translateY(0)'} },
                        fadeIn:     { '0%': {opacity:'0'}, '100%': {opacity:'1'} },
                        slideLeft:  { '0%': {opacity:'0',transform:'translateX(-40px)'}, '100%': {opacity:'1',transform:'translateX(0)'} },
                        slideRight: { '0%': {opacity:'0',transform:'translateX(40px)'},  '100%': {opacity:'1',transform:'translateX(0)'} },
                        pulseRing:  { '0%,100%': {opacity:'1'}, '50%': {opacity:'.4'} },
                    },
                }
            }
        }
    </script>
    
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 82px;
        }

        body {
            font-family: 'DM Sans', ui-sans-serif, system-ui;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            background: #fff;
            color: #1e293b;
        }

        /* ── Gradient helpers ─────────────────────────────────── */
        .grad-brand  { background: linear-gradient(135deg,#f97316,#ea580c,#c2410c); }
        .grad-dark   { background: linear-gradient(135deg,#0c0f1a,#1e2538); }
        .grad-text {
            background: linear-gradient(135deg,#f97316,#ea580c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Nav ──────────────────────────────────────────────── */
        #main-nav {
            transition: background 0.35s, box-shadow 0.35s;
        }
        #main-nav.scrolled {
            background: rgba(255,255,255,0.97) !important;
            box-shadow: 0 4px 32px rgba(0,0,0,0.10);
        }
        .nav-link {
            position: relative;
            font-weight: 500;
            color: #374151;
            transition: color 0.25s;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -2px; left: 0;
            width: 0; height: 2px;
            background: linear-gradient(90deg,#f97316,#ea580c);
            border-radius: 2px;
            transition: width 0.3s;
        }
        .nav-link:hover { color: #ea580c; }
        .nav-link:hover::after { width: 100%; }

        /* ── Buttons ──────────────────────────────────────────── */
        .btn-primary {
            background: linear-gradient(135deg,#f97316,#ea580c);
            color: #fff;
            font-weight: 600;
            border-radius: 0.75rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .btn-primary::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg,#ea580c,#c2410c);
            opacity: 0;
            transition: opacity 0.3s;
        }
        .btn-primary:hover::before { opacity: 1; }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 12px 28px -4px rgba(234,88,12,.45); }
        .btn-primary span { position: relative; z-index: 1; }

        .btn-outline {
            border: 2px solid #ea580c;
            color: #ea580c;
            font-weight: 600;
            border-radius: 0.75rem;
            background: transparent;
            transition: background 0.3s, color 0.3s, transform 0.3s, box-shadow 0.3s;
        }
        .btn-outline:hover {
            background: #ea580c; color: #fff;
            transform: translateY(-3px);
            box-shadow: 0 12px 28px -4px rgba(234,88,12,.35);
        }

        /* ── Section heading rule ─────────────────────────────── */
        .section-rule {
            display: block;
            width: 72px; height: 4px;
            margin: 14px auto 0;
            background: linear-gradient(90deg,#f97316,#ea580c);
            border-radius: 2px;
        }

        /* ── Cards ────────────────────────────────────────────── */
        .card-lift {
            transition: transform 0.4s cubic-bezier(.4,0,.2,1), box-shadow 0.4s;
        }
        .card-lift:hover {
            transform: translateY(-10px) scale(1.015);
            box-shadow: 0 28px 56px -12px rgba(249,115,22,.25);
        }
        .program-card-accent::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(90deg,#f97316,#ea580c);
            border-radius: 1rem 1rem 0 0;
        }

        /* ── Floating bg orbs ─────────────────────────────────── */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
        }

        /* ── Gallery swiper tweaks ────────────────────────────── */
        .gallery-swiper .swiper-slide { opacity: .55; transition: opacity .35s; }
        .gallery-swiper .swiper-slide-active { opacity: 1; }
        .swiper-button-next, .swiper-button-prev {
            background: rgba(255,255,255,.92);
            border-radius: 50%;
            width: 48px !important; height: 48px !important;
            box-shadow: 0 4px 16px rgba(0,0,0,.15);
        }
        .swiper-button-next::after, .swiper-button-prev::after {
            font-size: 16px !important;
            color: #ea580c;
            font-weight: 900;
        }

        /* ── Testimonial card ─────────────────────────────────── */
        .testimonial-card {
            background: linear-gradient(145deg,#fff,#f8fafc);
            box-shadow: 14px 14px 40px #d8dde6, -8px -8px 24px #fff;
        }

        /* ── Counter items ────────────────────────────────────── */
        .counter-item {
            background: rgba(249,115,22,.08);
            border: 1px solid rgba(249,115,22,.2);
            border-radius: 1rem;
        }

        /* ── Scrollbar ────────────────────────────────────────── */
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(#f97316,#ea580c);
            border-radius: 8px;
            border: 3px solid #f1f5f9;
        }

        /* ── Typewriter ───────────────────────────────────────── */
        @keyframes blink { 0%,100%{border-color:transparent} 50%{border-color:#f97316} }
        .typewriter {
            display: inline-block;
            overflow: hidden;
            white-space: nowrap;
            border-right: 3px solid #f97316;
            animation: blink .75s step-end infinite;
        }

        /* ── Back-to-top ──────────────────────────────────────── */
        #backToTop {
            opacity: 0;
            pointer-events: none;
            transition: opacity .35s, transform .35s;
        }
        #backToTop.visible {
            opacity: 1;
            pointer-events: auto;
        }
    </style>
</head>

<body>

<!-- ════════════════════════════════════════════════════════════
     NAVIGATION
═══════════════════════════════════════════════════════════════ -->
<nav id="main-nav" class="fixed w-full z-50 bg-white/95 backdrop-blur-lg border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">

            <!-- ── LOGO ── -->
            <a href="#home" class="flex items-center space-x-3 group flex-shrink-0">
                <img
                    src="assets/images/logo.png"
                    alt="Queenest Paradise Montessori Complex Logo"
                    class="h-14 w-auto object-contain transition-transform duration-300 group-hover:scale-105"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                />
                <!-- Fallback icon if logo is missing -->
                <div class="hidden items-center justify-center w-14 h-14 grad-brand rounded-2xl shadow-lg">
                    <i class="fas fa-graduation-cap text-white text-2xl"></i>
                </div>
                <div class="leading-tight">
                    <div class="text-lg font-bold text-gray-900 font-display tracking-tight">Queenest Paradise</div>
                    <div class="text-[10px] font-semibold text-gray-500 tracking-widest uppercase">Montessori Complex</div>
                    <span class="inline-block mt-0.5 px-2 py-0.5 bg-orange-100 text-orange-700 text-[10px] font-bold rounded-full tracking-wide">QueensPa</span>
                </div>
            </a>

            <!-- ── Desktop Menu ── -->
            <div class="hidden lg:flex items-center space-x-7">
                <a href="#home"         class="nav-link text-sm">Home</a>
                <a href="about.php"     class="nav-link text-sm">About</a>
                <a href="#gallery"      class="nav-link text-sm">Gallery</a>
                <a href="#news"         class="nav-link text-sm">News</a>
                <a href="#programs"     class="nav-link text-sm">Programs</a>
                <a href="#testimonials" class="nav-link text-sm">Testimonials</a>

                <a href="login_form.php"
                   class="btn-primary px-5 py-2.5 text-sm flex items-center gap-2">
                    <span><i class="fas fa-sign-in-alt"></i> Sign In</span>
                </a>
                <a href="admissions.php"
                   class="btn-outline px-5 py-2.5 text-sm flex items-center gap-2">
                    <i class="fas fa-edit"></i> Apply Now
                </a>
            </div>

            <!-- ── Mobile Burger ── -->
            <button id="burger" class="lg:hidden text-gray-700 hover:text-orange-600 transition-colors" aria-label="Open menu">
                <i id="burger-icon" class="fas fa-bars text-2xl"></i>
            </button>
        </div>

        <!-- ── Mobile Menu ── -->
        <div id="mobile-menu" class="lg:hidden hidden pb-6 border-t border-gray-100">
            <div class="flex flex-col space-y-4 pt-5">
                <a href="#home"         class="text-gray-700 hover:text-orange-600 font-medium transition-colors">Home</a>
                <a href="about.php"     class="text-gray-700 hover:text-orange-600 font-medium transition-colors">About</a>
                <a href="#gallery"      class="text-gray-700 hover:text-orange-600 font-medium transition-colors">Gallery</a>
                <a href="#news"         class="text-gray-700 hover:text-orange-600 font-medium transition-colors">News</a>
                <a href="#programs"     class="text-gray-700 hover:text-orange-600 font-medium transition-colors">Programs</a>
                <a href="#testimonials" class="text-gray-700 hover:text-orange-600 font-medium transition-colors">Testimonials</a>
                <div class="flex flex-col gap-3 pt-2">
                    <a href="login_form.php"  class="btn-primary px-6 py-3 text-center text-sm"><span><i class="fas fa-sign-in-alt mr-2"></i>Sign In</span></a>
                    <a href="admissions.php"  class="btn-outline px-6 py-3 text-center text-sm"><i class="fas fa-edit mr-2"></i>Apply Now</a>
                </div>
            </div>
        </div>
    </div>
</nav>


<!-- ════════════════════════════════════════════════════════════
     HERO
═══════════════════════════════════════════════════════════════ -->
<section id="home" class="relative min-h-screen flex items-center grad-dark overflow-hidden pt-20">

    <!-- Background orbs -->
    <div class="orb w-[520px] h-[520px] bg-orange-500/10 top-1/4 left-[-10%] animate-float"></div>
    <div class="orb w-[400px] h-[400px] bg-red-600/10  bottom-1/4 right-[-8%] animate-float-slow"></div>
    <!-- Grid texture -->
    <div class="absolute inset-0 opacity-[0.04]"
         style="background-image:radial-gradient(circle,#f97316 1px,transparent 1px);background-size:36px 36px;"></div>

    <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24 w-full">
        <div class="grid lg:grid-cols-2 gap-14 items-center">

            <!-- Left -->
            <div class="text-center lg:text-left animate-fade-up">
                <!-- Badge -->
                <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/10 border border-orange-400/30 text-orange-200 text-sm font-semibold mb-8">
                    <i class="fas fa-crown text-yellow-300"></i> Premier Montessori Education Since 2017
                </span>

                <h1 class="font-display text-5xl sm:text-6xl lg:text-7xl font-bold text-white leading-[1.1] mb-6">
                    Welcome to<br>
                    <span class="grad-text typewriter">Queenspa</span>
                </h1>

                <!-- Motto -->
                <div class="flex items-center justify-center lg:justify-start gap-4 bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl px-6 py-4 mb-8">
                    <i class="fas fa-cross text-orange-300 text-xl flex-shrink-0"></i>
                    <p class="text-orange-100 font-semibold italic text-lg leading-snug">
                        "Christ Our Foundation, Integrity, Discipline, and Excellence"
                    </p>
                </div>

                <p class="text-gray-300 text-lg leading-relaxed mb-10 max-w-xl mx-auto lg:mx-0">
                    Nurturing young minds, shaping future leaders, and instilling a lifelong love for learning through our modern Montessori approach.
                </p>

                <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                    <a href="admissions.php"
                       class="btn-primary px-8 py-4 text-base flex items-center justify-center gap-2 group">
                        <span><i class="fas fa-edit mr-1 group-hover:rotate-12 transition-transform"></i>Apply Now</span>
                    </a>
                    <a href="#gallery"
                       class="px-8 py-4 text-base rounded-xl border border-white/30 bg-white/10 text-white font-semibold hover:bg-white/20 transition-all duration-300 flex items-center justify-center gap-2">
                        <i class="fas fa-images"></i> View Gallery
                    </a>
                </div>
            </div>

            <!-- Right — image card -->
            <div class="relative animate-slide-right">
                <div class="relative bg-white/10 backdrop-blur-xl border border-white/20 rounded-3xl p-6 shadow-2xl">
                    <div class="relative overflow-hidden rounded-2xl mb-5">
                        <img
                            src="https://www.qpmcedu.com/queenest%20pic%20front.jpg"
                            alt="Queenest Paradise Montessori Complex Campus"
                            class="w-full h-80 object-cover rounded-2xl hover:scale-105 transition-transform duration-700"
                            onerror="this.src='https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=1200&q=80'"
                        />
                        <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent rounded-2xl"></div>
                        <span class="absolute bottom-4 left-4 px-4 py-2 rounded-full text-xs font-bold grad-brand text-white shadow">
                            <i class="fas fa-map-marker-alt mr-1"></i>Accra Campus
                        </span>
                    </div>

                    <!-- Stats -->
                    <div class="grid grid-cols-3 gap-3">
                        <div class="counter-item p-4 text-center">
                            <div class="text-2xl font-bold text-white count" data-target="150">0</div>
                            <div class="text-xs text-gray-300 mt-1">Students</div>
                        </div>
                        <div class="counter-item p-4 text-center">
                            <div class="text-2xl font-bold text-white count" data-target="12">0</div>
                            <div class="text-xs text-gray-300 mt-1">Faculty</div>
                        </div>
                        <div class="counter-item p-4 text-center">
                            <div class="text-2xl font-bold text-white count" data-target="18">0</div>
                            <div class="text-xs text-gray-300 mt-1">Programs</div>
                        </div>
                    </div>
                </div>

                <!-- Floating badges -->
                <div class="absolute -top-5 -right-5 grad-brand p-4 rounded-2xl shadow-2xl animate-float">
                    <i class="fas fa-users text-white text-xl"></i>
                </div>
                <div class="absolute -bottom-5 -left-5 grad-brand p-4 rounded-2xl shadow-2xl animate-float-slow">
                    <i class="fas fa-trophy text-white text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Scroll cue -->
    <a href="#programs" class="absolute bottom-8 left-1/2 -translate-x-1/2 text-white animate-bounce z-10">
        <i class="fas fa-chevron-down text-2xl"></i>
    </a>
</section>


<!-- ════════════════════════════════════════════════════════════
     PROGRAMS
═══════════════════════════════════════════════════════════════ -->
<section id="programs" class="py-24 bg-gradient-to-b from-white to-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Heading -->
        <div class="text-center mb-16">
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-orange-100 text-orange-800 text-xs font-bold tracking-wider uppercase mb-5">
                <i class="fas fa-star"></i> Extracurricular Programs
            </span>
            <h2 class="font-display text-4xl sm:text-5xl font-bold text-gray-900 mb-4">
                Nurturing <span class="grad-text">Talents &amp; Passions</span>
            </h2>
            <span class="section-rule"></span>
            <p class="text-gray-600 text-lg max-w-2xl mx-auto mt-8 leading-relaxed">
                At QueensPa we believe in holistic education. Our diverse extracurricular programmes help students discover passions and develop essential life skills.
            </p>
        </div>

        <!-- Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-16">
            <?php
            $programs = [
                ['icon'=>'fas fa-code',           'title'=>'Programming Club',      'desc'=>'Master coding with Python, JavaScript, and web development. Join our competitive team.',
                 'features'=>['Weekly Coding Sessions','Hackathons','Robotics Projects','App Development'],     'color'=>'from-blue-500 to-indigo-600'],
                ['icon'=>'fas fa-film',            'title'=>'Media &amp; Broadcasting', 'desc'=>'Explore digital media, photography, video editing, and school broadcasting.',
                 'features'=>['School TV Station','Photography Club','Video Production','Podcasting'],          'color'=>'from-purple-500 to-pink-600'],
                ['icon'=>'fas fa-broom',           'title'=>'KSIQ Program',          'desc'=>'Kids Sanitation Initiative — hygiene, environmental awareness, and community service.',
                 'features'=>['Hygiene Workshops','Recycling Projects','Community Clean-up','Eco Clubs'],       'color'=>'from-green-500 to-teal-600'],
                ['icon'=>'fas fa-futbol',          'title'=>'Sports Academy',        'desc'=>'Comprehensive training in football, basketball, athletics, and swimming.',
                 'features'=>['Inter-school Competitions','Professional Coaching','Fitness Training','Sports Scholarships'], 'color'=>'from-red-500 to-orange-600'],
                ['icon'=>'fas fa-hands-helping',   'title'=>'Girls Guide &amp; Scouts','desc'=>'Leadership development, entrepreneurship skills, and community service.',
                 'features'=>['Leadership Camps','Entrepreneurship Workshops','Community Projects','Life Skills'], 'color'=>'from-pink-500 to-rose-600'],
                ['icon'=>'fas fa-language',        'title'=>'Language &amp; Culture','desc'=>'Learn French, Chinese, and local languages while exploring diverse cultures.',
                 'features'=>['French Club','Chinese Language','Cultural Exchange','Debate Society'],           'color'=>'from-yellow-500 to-amber-600'],
            ];
            foreach ($programs as $p): ?>
            <div class="program-card-accent relative bg-white rounded-2xl p-7 shadow-md border border-gray-100 card-lift overflow-hidden">
                <div class="flex items-start gap-4 mb-5">
                    <div class="bg-gradient-to-br <?= $p['color'] ?> p-4 rounded-xl shadow-md flex-shrink-0">
                        <i class="<?= $p['icon'] ?> text-white text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 mb-1"><?= $p['title'] ?></h3>
                        <p class="text-gray-500 text-sm leading-relaxed"><?= $p['desc'] ?></p>
                    </div>
                </div>
                <ul class="space-y-2 mb-5">
                    <?php foreach ($p['features'] as $f): ?>
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <i class="fas fa-check-circle text-green-500 flex-shrink-0"></i><?= $f ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <span class="text-xs font-semibold text-orange-600"><i class="fas fa-users mr-1"></i>50+ Students</span>
                    <a href="programs.php" class="text-sm font-semibold text-orange-600 hover:text-orange-700 flex items-center gap-1 group">
                        Learn More <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Why Join banner -->
        <div class="grad-brand rounded-3xl p-10 text-white mb-12">
            <div class="grid lg:grid-cols-2 gap-10 items-center">
                <div>
                    <h3 class="font-display text-3xl font-bold mb-7">Why Join Our Programs?</h3>
                    <div class="space-y-5">
                        <?php
                        $whys = [
                            ['icon'=>'fas fa-medal',  'title'=>'Skill Development',        'desc'=>'Practical skills that prepare students for future careers.'],
                            ['icon'=>'fas fa-users',  'title'=>'Team Building',            'desc'=>'Collaborative projects that enhance social intelligence.'],
                            ['icon'=>'fas fa-trophy', 'title'=>'Achievement Recognition',  'desc'=>'Regular competitions, showcases, and award ceremonies.'],
                        ];
                        foreach ($whys as $w): ?>
                        <div class="flex items-start gap-4">
                            <div class="bg-white/20 p-3 rounded-xl flex-shrink-0"><i class="<?= $w['icon'] ?>"></i></div>
                            <div>
                                <div class="font-bold text-lg"><?= $w['title'] ?></div>
                                <div class="text-white/80 text-sm"><?= $w['desc'] ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="text-center">
                    <div class="inline-block bg-white/20 backdrop-blur-sm rounded-2xl p-10">
                        <div class="font-display text-6xl font-bold mb-2">85%</div>
                        <div class="text-lg font-semibold">Participation Rate</div>
                        <p class="text-white/75 text-sm mt-3 max-w-xs">of students participate in at least one extracurricular programme</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center">
            <a href="programs.php" class="btn-primary px-10 py-4 text-base inline-flex items-center gap-3 group">
                <span><i class="fas fa-clipboard-list group-hover:rotate-12 transition-transform mr-1"></i>
                Explore All 18+ Programs <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i></span>
            </a>
        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════════════
     TESTIMONIALS
═══════════════════════════════════════════════════════════════ -->
<section id="testimonials" class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center mb-16">
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-orange-100 text-orange-800 text-xs font-bold tracking-wider uppercase mb-5">
                <i class="fas fa-comments"></i> Voices From Our Community
            </span>
            <h2 class="font-display text-4xl sm:text-5xl font-bold text-gray-900 mb-4">
                What They Say <span class="grad-text">About QueensPa</span>
            </h2>
            <span class="section-rule"></span>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto mt-8">
                Hear from students, parents, and alumni about their experiences at Queenest Paradise Montessori Complex.
            </p>
        </div>

        <div class="grid md:grid-cols-2 gap-8 mb-12">
            <?php
            $testimonials = [
                ['name'=>'Blessing Animwaa',  'role'=>'Grade 8 Student',  'program'=>'Girls Guide',   'rating'=>5,
                 'text'=>'QueensPa has transformed my learning experience. The teachers are incredibly supportive, and the interactive lessons make every subject exciting.'],
                ['name'=>'Lawrence Adom',     'role'=>'Sports Captain',   'program'=>'Sports Academy','rating'=>5,
                 'text'=>'The Sports Academy helped me develop both athletic skills and leadership qualities. We\'ve won multiple inter-school competitions and learned teamwork and discipline along the way.'],
            ];
            foreach ($testimonials as $t): ?>
            <div class="testimonial-card rounded-2xl p-8 card-lift">
                <div class="flex items-center gap-4 mb-6">
                    <div class="w-16 h-16 rounded-full grad-brand flex items-center justify-center text-white text-2xl font-bold font-display flex-shrink-0 shadow-lg">
                        <?= strtoupper(substr($t['name'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="font-bold text-gray-900 text-lg"><?= $t['name'] ?></div>
                        <div class="text-gray-500 text-sm"><?= $t['role'] ?></div>
                        <span class="inline-block mt-1 px-2 py-0.5 bg-orange-100 text-orange-700 text-xs font-bold rounded-full"><?= $t['program'] ?></span>
                    </div>
                </div>
                <div class="flex mb-4">
                    <?php for ($i = 0; $i < $t['rating']; $i++): ?>
                    <i class="fas fa-star text-yellow-400 text-sm mr-0.5"></i>
                    <?php endfor; ?>
                </div>
                <div class="relative">
                    <i class="fas fa-quote-left text-orange-200 text-4xl absolute -top-2 -left-1"></i>
                    <p class="text-gray-700 leading-relaxed relative z-10 pl-6"><?= $t['text'] ?></p>
                </div>
                <div class="mt-6 pt-5 border-t border-gray-100 flex justify-between items-center">
                    <span class="text-xs text-gray-400"><i class="far fa-calendar-alt mr-1"></i><?= date('M Y') ?></span>
                    <a href="testimonials.php" class="text-sm font-semibold text-orange-600 hover:text-orange-700 transition-colors">Read Full Story</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center">
            <a href="testimonials.php" class="btn-primary px-10 py-4 text-base inline-flex items-center gap-3 group">
                <span><i class="fas fa-comments group-hover:rotate-12 transition-transform mr-1"></i>
                Read More Testimonials <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i></span>
            </a>
        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════════════
     GALLERY
═══════════════════════════════════════════════════════════════ -->
<section id="gallery" class="py-24 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center mb-16">
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-orange-100 text-orange-800 text-xs font-bold tracking-wider uppercase mb-5">
                <i class="fas fa-camera"></i> Campus Life
            </span>
            <h2 class="font-display text-4xl sm:text-5xl font-bold text-gray-900 mb-4">
                Campus <span class="grad-text">Gallery</span>
            </h2>
            <span class="section-rule"></span>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto mt-8">
                A visual journey through our campus, classrooms, and student activities.
            </p>
        </div>

        <div class="swiper gallery-swiper mb-12 pb-10">
            <div class="swiper-wrapper">
                <?php
                $gallery = [
                    ['title'=>'Modern Campus Architecture',    'desc'=>'State-of-the-art facilities designed for optimal learning.',  'cat'=>'Campus',    'img'=>'https://images.unsplash.com/photo-1580582932707-520aed937b7b?auto=format&fit=crop&w=800&q=80'],
                    ['title'=>'Science Laboratory Sessions',   'desc'=>'Hands-on experiments in our fully-equipped labs.',            'cat'=>'Academics', 'img'=>'https://images.unsplash.com/photo-1532094349884-543559196e89?auto=format&fit=crop&w=800&q=80'],
                    ['title'=>'Interactive Classroom Learning','desc'=>'Engaging lessons with modern teaching aids and technology.',   'cat'=>'Education', 'img'=>'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?auto=format&fit=crop&w=800&q=80'],
                    ['title'=>'Annual Sports Competition',     'desc'=>'Students showcasing athletic excellence during sports day.',  'cat'=>'Sports',    'img'=>'https://images.unsplash.com/photo-1546519638-68e109498ffc?auto=format&fit=crop&w=800&q=80'],
                    ['title'=>'Library &amp; Reading Hub',    'desc'=>'Extensive books and digital learning resources.',             'cat'=>'Facilities','img'=>'https://images.unsplash.com/photo-1507842217343-583bb7270b66?auto=format&fit=crop&w=800&q=80'],
                    ['title'=>'Art &amp; Creative Exhibition', 'desc'=>'Showcasing student creativity through various art forms.',    'cat'=>'Arts',      'img'=>'https://images.unsplash.com/photo-1513364776144-60967b0f800f?auto=format&fit=crop&w=800&q=80'],
                ];
                foreach ($gallery as $g): ?>
                <div class="swiper-slide">
                    <div class="bg-white rounded-2xl overflow-hidden shadow-lg h-full">
                        <div class="relative h-56 overflow-hidden">
                            <img src="<?= $g['img'] ?>" alt="<?= $g['title'] ?>"
                                 class="w-full h-full object-cover hover:scale-110 transition-transform duration-700" loading="lazy">
                            <span class="absolute top-3 left-3 px-3 py-1 grad-brand text-white text-xs font-bold rounded-full shadow">
                                <?= $g['cat'] ?>
                            </span>
                        </div>
                        <div class="p-5">
                            <h3 class="font-bold text-gray-900 mb-1 text-base"><?= $g['title'] ?></h3>
                            <p class="text-gray-500 text-sm mb-4 leading-relaxed"><?= $g['desc'] ?></p>
                            <a href="gallery.php" class="text-orange-600 font-semibold text-sm hover:text-orange-700 flex items-center gap-1 group">
                                View Details <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="swiper-button-next"></div>
            <div class="swiper-button-prev"></div>
            <div class="swiper-pagination"></div>
        </div>

        <div class="text-center">
            <a href="gallery.php" class="btn-primary px-10 py-4 text-base inline-flex items-center gap-3 group">
                <span><i class="fas fa-images group-hover:rotate-12 transition-transform mr-1"></i>
                Explore Full Gallery <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i></span>
            </a>
        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════════════
     NEWS
═══════════════════════════════════════════════════════════════ -->
<section id="news" class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center mb-16">
            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-orange-100 text-orange-800 text-xs font-bold tracking-wider uppercase mb-5">
                <i class="fas fa-newspaper"></i> Latest Updates
            </span>
            <h2 class="font-display text-4xl sm:text-5xl font-bold text-gray-900 mb-4">
                School <span class="grad-text">News &amp; Events</span>
            </h2>
            <span class="section-rule"></span>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto mt-8">
                Stay updated with the latest achievements, events, and developments at QueensPa.
            </p>
        </div>

        <!-- Featured event banner -->
        <div class="grad-brand rounded-3xl p-10 text-white mb-12">
            <div class="grid lg:grid-cols-2 gap-10 items-center">
                <div>
                    <span class="inline-block px-4 py-2 bg-white/20 rounded-full text-sm font-semibold mb-5">FEATURED EVENT</span>
                    <h3 class="font-display text-3xl font-bold mb-4">Annual Science &amp; Technology Fair 2026</h3>
                    <p class="text-white/85 mb-6 leading-relaxed">Join us for our biggest event of the year featuring innovative student projects, robotics demonstrations, and guest speakers from the tech industry.</p>
                    <div class="flex flex-wrap gap-5">
                        <span class="flex items-center gap-2"><i class="far fa-calendar-alt"></i> Coming Soon</span>
                        <span class="flex items-center gap-2"><i class="fas fa-map-marker-alt"></i> Main Campus</span>
                    </div>
                </div>
                <div class="text-center">
                    <div class="inline-block bg-white/20 backdrop-blur-sm rounded-2xl p-10">
                        <div class="font-display text-7xl font-bold mb-2">3</div>
                        <div class="text-xl font-semibold">Days of Innovation</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center">
            <a href="news.php" class="btn-primary px-10 py-4 text-base inline-flex items-center gap-3 group">
                <span><i class="fas fa-newspaper group-hover:rotate-12 transition-transform mr-1"></i>
                View All News &amp; Events <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i></span>
            </a>
        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════════════
     CTA
═══════════════════════════════════════════════════════════════ -->
<section class="py-24 grad-dark relative overflow-hidden">
    <div class="orb w-96 h-96 bg-orange-500/10 top-0 left-0 animate-float"></div>
    <div class="orb w-80 h-80 bg-red-500/10 bottom-0 right-0 animate-float-slow"></div>
    <div class="absolute inset-0 opacity-[0.03]"
         style="background-image:radial-gradient(circle,#f97316 1px,transparent 1px);background-size:32px 32px;"></div>

    <div class="relative z-10 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h2 class="font-display text-4xl sm:text-5xl lg:text-6xl font-bold text-white mb-6">
            Join the QueensPa <span class="grad-text">Family Today</span>
        </h2>
        <p class="text-gray-300 text-xl mb-10 max-w-2xl mx-auto leading-relaxed">
            Be part of an institution that values academic excellence, character development, and holistic growth. Apply now for the 2025/2026 academic year.
        </p>

        <div class="flex flex-col sm:flex-row gap-5 justify-center mb-16">
            <a href="admissions.php" class="btn-primary px-10 py-5 text-lg inline-flex items-center justify-center gap-2 group">
                <span><i class="fas fa-file-alt group-hover:rotate-12 transition-transform"></i> Start Application</span>
            </a>
            <a href="contact.php"
               class="px-10 py-5 text-lg rounded-xl border border-white/30 bg-white/10 text-white font-semibold hover:bg-white/20 transition-all duration-300 inline-flex items-center justify-center gap-2">
                <i class="fas fa-phone-alt"></i> Contact Admissions
            </a>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
            <?php
            $cta_stats = [['Limited','Spaces Available'],['Early Bird','Discount Available'],['Scholarship','Opportunities'],['Open Days','All Weekdays']];
            foreach ($cta_stats as [$title,$sub]): ?>
            <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-5 border border-white/10">
                <div class="font-display text-xl font-bold text-white mb-1"><?= $title ?></div>
                <div class="text-xs text-gray-300"><?= $sub ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════════════════════
     FOOTER
═══════════════════════════════════════════════════════════════ -->
<footer class="bg-gray-900 text-white relative pt-16 pb-8">
    <div class="absolute top-0 left-0 right-0 h-1 grad-brand"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-10 mb-14">

            <!-- Brand -->
            <div class="lg:col-span-2">
                <div class="flex items-center gap-4 mb-6">
                    <img
                        src="/public_html/assets/images/logo.png"
                        alt="QueensPa Logo"
                        class="h-16 w-auto object-contain"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    />
                    <div class="hidden items-center justify-center w-16 h-16 grad-brand rounded-2xl">
                        <i class="fas fa-graduation-cap text-white text-2xl"></i>
                    </div>
                    <div>
                        <div class="font-display text-xl font-bold">Queenest Paradise</div>
                        <div class="text-gray-400 text-sm font-semibold">MONTESSORI COMPLEX</div>
                        <span class="inline-block mt-1 px-3 py-0.5 grad-brand text-white text-xs font-bold rounded-full">QueensPa</span>
                    </div>
                </div>
                <p class="text-gray-400 mb-7 max-w-md leading-relaxed text-sm">
                    Nurturing young minds with Christ as our foundation, building character through integrity, discipline, and excellence in education since 2017.
                </p>
                <!-- Newsletter -->
                <h4 class="font-semibold mb-3">Subscribe to Newsletter</h4>
                <form class="flex gap-2" onsubmit="handleNewsletter(event)">
                    <input type="email" placeholder="Your email address"
                           class="flex-grow px-4 py-3 rounded-xl bg-gray-800 border border-gray-700 text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-orange-500 text-sm">
                    <button type="submit" class="btn-primary px-5 py-3 text-sm flex-shrink-0">
                        <span><i class="fas fa-paper-plane"></i></span>
                    </button>
                </form>
            </div>

            <!-- Quick Links -->
            <div>
                <h3 class="text-lg font-bold mb-5 font-display">Quick Links</h3>
                <ul class="space-y-3">
                    <?php
                    $links = [['#home','Home'],['about.php','About Us'],['#gallery','Gallery'],['#news','News & Events'],['admissions.php','Admissions'],['contact.php','Contact Us']];
                    foreach ($links as [$href,$label]): ?>
                    <li>
                        <a href="<?= $href ?>"
                           class="text-gray-400 hover:text-orange-300 transition-colors text-sm flex items-center gap-2 group">
                            <i class="fas fa-chevron-right text-orange-500 text-xs group-hover:translate-x-1 transition-transform"></i>
                            <?= $label ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Contact -->
            <div>
                <h3 class="text-lg font-bold mb-5 font-display">Contact Info</h3>
                <div class="space-y-4">
                    <?php
                    $contacts = [
                        ['fas fa-map-marker-alt','Main Campus','Accra, Ghana'],
                        ['fas fa-phone','Call Us','+233552025642'],
                        ['fas fa-envelope','Email Us','info@qpmcedu.com'],
                        ['fas fa-clock','Office Hours','Mon–Fri: 7:30 AM – 4:30 PM'],
                    ];
                    foreach ($contacts as [$icon,$label,$value]): ?>
                    <div class="flex items-start gap-3">
                        <i class="<?= $icon ?> text-orange-500 mt-0.5 w-4 flex-shrink-0"></i>
                        <div>
                            <div class="font-semibold text-sm"><?= $label ?></div>
                            <div class="text-gray-400 text-xs mt-0.5"><?= $value ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <h4 class="font-semibold mt-7 mb-3 text-sm">Follow Us</h4>
                <div class="flex gap-3">
                    <?php foreach (['fab fa-facebook-f','fab fa-twitter','fab fa-instagram','fab fa-youtube'] as $icon): ?>
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-xl flex items-center justify-center hover:grad-brand hover:text-white transition-all duration-300 text-gray-400 text-sm">
                        <i class="<?= $icon ?>"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Bottom bar -->
        <div class="border-t border-gray-800 pt-7 flex flex-col md:flex-row items-center justify-between gap-4">
            <p class="text-gray-500 text-xs">
                &copy; <?= date('Y') ?> Queenest Paradise Montessori Complex. All Rights Reserved.
            </p>
            <div class="flex gap-5">
                <a href="privacy.php"  class="text-gray-500 hover:text-white text-xs transition-colors">Privacy Policy</a>
                <a href="terms.php"    class="text-gray-500 hover:text-white text-xs transition-colors">Terms of Service</a>
                <a href="sitemap.php"  class="text-gray-500 hover:text-white text-xs transition-colors">Sitemap</a>
            </div>
        </div>
        <p class="text-gray-600 text-xs text-center mt-5">
            <i class="fas fa-heart text-orange-500 mr-1"></i>
            Christ Our Foundation, Integrity, Discipline, and Excellence
        </p>
    </div>
</footer>


<!-- Back to Top -->
<button id="backToTop"
        class="fixed bottom-8 right-8 z-50 grad-brand text-white w-12 h-12 rounded-full shadow-2xl flex items-center justify-center group"
        onclick="window.scrollTo({top:0,behavior:'smooth'})"
        aria-label="Back to top">
    <i class="fas fa-chevron-up group-hover:-translate-y-1 transition-transform"></i>
</button>


<!-- ════════════════════════════════════════════════════════════
     SCRIPTS
═══════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js"></script>
<script>
/* ── Swiper ──────────────────────────────────────────── */
new Swiper('.gallery-swiper', {
    slidesPerView: 1,
    spaceBetween: 24,
    loop: true,
    grabCursor: true,
    autoplay: { delay: 5000, disableOnInteraction: false },
    pagination: { el: '.swiper-pagination', clickable: true, dynamicBullets: true },
    navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' },
    breakpoints: { 640: { slidesPerView: 2 }, 1024: { slidesPerView: 3 } }
});

/* ── Mobile Menu ─────────────────────────────────────── */
const burger = document.getElementById('burger');
const mobileMenu = document.getElementById('mobile-menu');
const burgerIcon = document.getElementById('burger-icon');
burger.addEventListener('click', () => {
    mobileMenu.classList.toggle('hidden');
    burgerIcon.classList.toggle('fa-bars');
    burgerIcon.classList.toggle('fa-times');
});
document.querySelectorAll('#mobile-menu a').forEach(a =>
    a.addEventListener('click', () => {
        mobileMenu.classList.add('hidden');
        burgerIcon.classList.replace('fa-times','fa-bars');
    })
);

/* ── Scroll effects ──────────────────────────────────── */
const nav = document.getElementById('main-nav');
const backToTop = document.getElementById('backToTop');
window.addEventListener('scroll', () => {
    const y = window.scrollY;
    nav.classList.toggle('scrolled', y > 80);
    backToTop.classList.toggle('visible', y > 500);
});

/* ── Smooth scroll for # links ───────────────────────── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const id = a.getAttribute('href');
        if (id === '#') return;
        const el = document.querySelector(id);
        if (el) { e.preventDefault(); el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
});

/* ── Counter animation ───────────────────────────────── */
function animateCounter(el) {
    const target = +el.dataset.target;
    let count = 0;
    const step = Math.max(1, Math.ceil(target / 120));
    const timer = setInterval(() => {
        count = Math.min(count + step, target);
        el.textContent = count + '+';
        if (count >= target) clearInterval(timer);
    }, 16);
}

const counterObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.querySelectorAll('.count').forEach(el => {
                if (!el.dataset.done) { el.dataset.done = 1; animateCounter(el); }
            });
        }
    });
}, { threshold: 0.3 });
document.querySelectorAll('.count').forEach(el => counterObserver.observe(el.closest('section') || el));

/* ── Newsletter ──────────────────────────────────────── */
function handleNewsletter(e) {
    e.preventDefault();
    const input = e.target.querySelector('input[type="email"]');
    const btn   = e.target.querySelector('button');
    if (!input.value) return;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span><i class="fas fa-check"></i></span>';
    btn.style.background = '#22c55e';
    setTimeout(() => {
        alert('Thank you for subscribing!');
        e.target.reset();
        btn.innerHTML = orig;
        btn.style.background = '';
    }, 800);
}
</script>
</body>
</html>