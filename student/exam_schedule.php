<?php
ob_start();
session_start();

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Ensure user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: /login.php');
    exit;
}

// Get student data
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'student'");
if (!$stmt) {
    error_log("Error preparing user query: " . $conn->error);
    die("System error: Please contact support.");
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) {
    header('Location: /login.php');
    exit;
}
$student_name = htmlspecialchars($student['name'] ?? 'Student');
$profile_picture = $student['profile_picture'] ? htmlspecialchars($student['profile_picture']) : '/assets/images/default_avatar.png';
$stmt->close();

// Current academic year
$current_academic_year = date('Y') . '/' . (date('Y') + 1);

// Fetch current academic term based on today's date (May 28, 2025)
$current_date = date('Y-m-d');
$stmt = $conn->prepare("SELECT id, term_name FROM academic_calendar WHERE start_date <= ? AND end_date >= ? LIMIT 1");
if (!$stmt) {
    error_log("Error preparing term query: " . $conn->error);
    die("System error: Please contact support.");
}
$stmt->bind_param("ss", $current_date, $current_date);
$stmt->execute();
$current_term = $stmt->get_result()->fetch_assoc();
$current_term_id = $current_term ? (int)$current_term['id'] : 0;
$current_term_name = $current_term ? htmlspecialchars($current_term['term_name']) : 'Current Term';
$stmt->close();

// Fetch all terms for filter
$stmt = $conn->prepare("SELECT id, term_name FROM academic_calendar ORDER BY start_date DESC");
if (!$stmt) {
    error_log("Error preparing terms query: " . $conn->error);
    die("System error: Please contact support.");
}
$stmt->execute();
$terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch classes for enrolled student
$classes = [];
$stmt = $conn->prepare("SELECT c.id, c.class_name 
    FROM classes c 
    JOIN student_classes sc ON c.id = sc.class_id 
    WHERE sc.user_id = ? AND sc.academic_year = ?");
if (!$stmt) {
    error_log("Error preparing classes query: " . $conn->error);
    die("System error retrieving classes.");
}
$stmt->bind_param("is", $user_id, $current_academic_year);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle filters
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$term_id = isset($_GET['term_id']) ? (int)$_GET['term_id'] : $current_term_id;

// Fetch exam schedules
$query = "
    SELECT es.id, es.class_id, es.subject_id, es.term_id, es.exam_date, es.start_time, es.end_time,
           c.class_name, s.subject_name, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name,
           ac.term_name
    FROM exam_schedules es
    JOIN classes c ON es.class_id = c.id
    JOIN student_classes sc ON es.class_id = sc.class_id
    JOIN subjects s ON es.subject_id = s.id
    JOIN teachers t ON s.teacher_id = t.id
    JOIN academic_calendar ac ON es.term_id = ac.id
    WHERE sc.user_id = ? AND sc.academic_year = ?
";
$params = [$user_id, $current_academic_year];
$types = "is";

if ($class_id > 0) {
    $query .= " AND es.class_id = ?";
    $params[] = $class_id;
    $types .= "i";
}
if ($term_id > 0) {
    $query .= " AND es.term_id = ?";
    $params[] = $term_id;
    $types .= "i";
}
$query .= " ORDER BY es.exam_date ASC, es.start_time";

$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Error preparing exam schedules query: " . $conn->error);
    die("System error retrieving exams.");
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Organize exams by status
$today = date('Y-m-d');
$upcoming_exams = [];
$ongoing_exams = [];
$past_exams = [];
foreach ($exams as $exam) {
    if ($exam['exam_date'] > $today) {
        $upcoming_exams[] = $exam;
    } elseif ($exam['exam_date'] == $today) {
        $ongoing_exams[] = $exam;
    } else {
        $past_exams[] = $exam;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
?>

<main class="container my-4">
    <!-- Header Section -->
    <section class="mb-5 text-center animate__animated animate__bounceIn">
        <div class="card hero-card">
            <div class="card-body position-relative">
                <h1 class="display-4 fw-bold text-dark">Your Exam Schedule, <?= $student_name ?>! 📅</h1>
                <p class="lead text-muted">Prepare for your exams like an anime champion!</p>
                <img src="/assets/images/chibi_mascot_exams.png" alt="Chibi Mascot" class="mascot-img" width="120">
            </div>
        </div>
    </section>

    <!-- Filters -->
    <section class="mb-4">
        <div class="card filter-card animate__animated animate__fadeIn">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="GET" action="">
                    <div class="col-md-4">
                        <label for="class_id" class="form-label">Filter by Class</label>
                        <select id="class_id" name="class_id" class="form-select">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="term_id" class="form-label">Filter by Term</label>
                        <select id="term_id" name="term_id" class="form-select">
                            <option value="0">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['id'] ?>" <?= $term_id == $term['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="submit" class="btn btn-primary ripple" data-tooltip-text="Apply filters">Apply Filters</button>
                        <a href="/student/exam_schedule.php" class="btn btn-outline-primary ripple" data-tooltip-text="Reset filters">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- Exam Schedule Display -->
    <section>
        <?php if (empty($exams)): ?>
            <div class="card text-center animate__animated animate__pulse">
                <div class="card-body">
                    <img src="/assets/images/no_exams.png" alt="No Exams" width="100" class="mb-3">
                    <h5 class="card-title">No Exam Schedules Found!</h5>
                    <p class="card-text">No exams have been scheduled yet, or try adjusting the filters!</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Upcoming Exams -->
            <?php if (!empty($upcoming_exams)): ?>
                <h4 class="mb-3">Upcoming Exams</h4>
                <div class="row g-3 mb-5">
                    <?php foreach ($upcoming_exams as $exam): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card exam-card animate__animated animate__zoomIn">
                                <div class="card-body position-relative">
                                    <h5 class="card-title"><?= htmlspecialchars($exam['subject_name']) ?> Exam</h5>
                                    <p class="mb-1">
                                        <i class="fas fa-chalkboard me-1"></i> 
                                        <?= htmlspecialchars($exam['class_name']) ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-book me-1"></i> 
                                        <?= htmlspecialchars($exam['subject_name'] ?? 'General') ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-user-tie me-1"></i> 
                                        <?= htmlspecialchars($exam['teacher_name'] ?? 'TBD') ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-calendar me-1"></i> 
                                        <?= htmlspecialchars($exam['term_name'] ?? 'Unknown Term') ?>
                                    </p>
                                    <p class="mb-0">
                                        <i class="fas fa-clock me-1"></i> 
                                        <?= date('F j, Y', strtotime($exam['exam_date'])) ?>, 
                                        <?= date('h:i A', strtotime($exam['start_time'])) ?> – 
                                        <?= date('h:i A', strtotime($exam['end_time'])) ?>
                                    </p>
                                    <span class="star-accent"></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Ongoing Exams -->
            <?php if (!empty($ongoing_exams)): ?>
                <h4 class="mb-3">Ongoing Exams</h4>
                <div class="row g-3 mb-5">
                    <?php foreach ($ongoing_exams as $exam): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card exam-card animate__animated animate__zoomIn">
                                <div class="card-body position-relative">
                                    <h5 class="card-title"><?= htmlspecialchars($exam['subject_name']) ?> Exam</h5>
                                    <p class="mb-1">
                                        <i class="fas fa-chalkboard me-1"></i> 
                                        <?= htmlspecialchars($exam['class_name']) ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-book me-1"></i> 
                                        <?= htmlspecialchars($exam['subject_name'] ?? 'General') ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-user-tie me-1"></i> 
                                        <?= htmlspecialchars($exam['teacher_name'] ?? 'TBD') ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-calendar me-1"></i> 
                                        <?= htmlspecialchars($exam['term_name'] ?? 'Unknown Term') ?>
                                    </p>
                                    <p class="mb-0">
                                        <i class="fas fa-clock me-1"></i> 
                                        Today, <?= date('F j, Y', strtotime($exam['exam_date'])) ?>, 
                                        <?= date('h:i A', strtotime($exam['start_time'])) ?> – 
                                        <?= date('h:i A', strtotime($exam['end_time'])) ?>
                                    </p>
                                    <span class="star-accent"></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Past Exams -->
            <?php if (!empty($past_exams)): ?>
                <h4 class="mb-3">Past Exams</h4>
                <div class="row g-3">
                    <?php foreach ($past_exams as $exam): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card exam-card animate__animated animate__zoomIn">
                                <div class="card-body position-relative">
                                    <h5 class="card-title"><?= htmlspecialchars($exam['subject_name']) ?> Exam</h5>
                                    <p class="mb-1">
                                        <i class="fas fa-chalkboard me-1"></i> 
                                        <?= htmlspecialchars($exam['class_name']) ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-book me-1"></i> 
                                        <?= htmlspecialchars($exam['subject_name'] ?? 'General') ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-user-tie me-1"></i> 
                                        <?= htmlspecialchars($exam['teacher_name'] ?? 'TBD') ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-calendar me-1"></i> 
                                        <?= htmlspecialchars($exam['term_name'] ?? 'Unknown Term') ?>
                                    </p>
                                    <p class="mb-0">
                                        <i class="fas fa-clock me-1"></i> 
                                        <?= date('F j, Y', strtotime($exam['exam_date'])) ?>, 
                                        <?= date('h:i A', strtotime($exam['start_time'])) ?> – 
                                        <?= date('h:i A', strtotime($exam['end_time'])) ?>
                                    </p>
                                    <span class="star-accent"></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

<style>
    :root {
        --primary-color: #ff4500;
        --primary-hover: #e03e00;
        --secondary-color: #00c4b4;
        --bg-gradient: linear-gradient(135deg, #f8f9fa, #fff3e0);
        --card-bg: #ffffff;
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        --gradient-button: linear-gradient(45deg, #ff4500, #ff6b00);
    }
    body {
        background: var(--bg-gradient);
        background-image: url('/assets/images/anime_sparkles.png');
        background-size: cover;
        background-attachment: fixed;
        font-family: 'Inter', sans-serif;
        overflow-x: hidden;
    }
    .hero-card {
        background: var(--card-bg);
        border: 2px solid var(--primary-color);
        border-radius: 15px;
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
    }
    .hero-card::before {
        content: '';
        position: absolute;
        background: url('/assets/images/sparkle_effect.png');
        background-size: cover;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        opacity: 0.1;
        z-index: 0;
    }
    .mascot-img {
        position: absolute;
        right: 20px;
        bottom: -10px;
        animation: float 3s ease-in-out infinite;
        z-index: 1;
    }
    .filter-card, .exam-card {
        background: var(--card-bg);
        border-radius: 15px;
        box-shadow: var(--shadow);
        backdrop-filter: blur(5px);
    }
    .exam-card {
        border: 2px solid transparent;
        border-image: linear-gradient(45deg, #ff4500, #00c4b4) 1;
        position: relative;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .exam-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }
    .star-accent {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 25px;
        height: 25px;
        background: url('/assets/images/anime_star.png') no-repeat center;
        background-size: contain;
        opacity: 0.7;
    }
    .btn-primary {
        background: var(--gradient-button);
        border: none;
        border-radius: 25px;
        padding: 0.5rem 1.5rem;
        transition: transform 0.2s ease;
    }
    .btn-primary:hover {
        transform: scale(1.05);
    }
    .btn-outline-primary {
        border-color: var(--primary-color);
        color: var(--primary-color);
        border-radius: 25px;
    }
    .btn-outline-primary:hover {
        background: var(--gradient-button);
        color: white;
    }
    [data-tooltip-text] {
        position: relative;
    }
    [data-tooltip-text]::after {
        content: attr(data-tooltip-text);
        position: absolute;
        background: var(--primary-color);
        color: white;
        padding: 0.5rem;
        border-radius: 8px;
        font-size: 0.8rem;
        top: -40px;
        left: 50%;
        transform: translateX(-50%);
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
        z-index: 1000;
        white-space: nowrap;
    }
    [data-tooltip-text]:hover::after {
        opacity: 1;
    }
    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    @media (max-width: 767px) {
        .hero-card h1 {
            font-size: 2rem;
        }
        .mascot-img {
            width: 80px;
            right: 10px;
            bottom: -5px;
        }
        .exam-card {
            margin-bottom: 1rem;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Ripple effect for buttons
    document.querySelectorAll('.ripple').forEach(el => {
        el.addEventListener('click', (e) => {
            const rect = el.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const ripple = document.createElement('span');
            ripple.style.position = 'absolute';
            ripple.style.background = 'rgba(255, 255, 255, 0.5)';
            ripple.style.borderRadius = '50%';
            ripple.style.transform = 'scale(0)';
            ripple.style.animation = 'ripple 0.6s linear';
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            el.appendChild(ripple);
            setTimeout(() => ripple.remove(), 600);
        });
    });

    // Define ripple animation
    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(styleSheet);
});
</script>

<?php
mysqli_close($conn);
ob_end_flush();
?>