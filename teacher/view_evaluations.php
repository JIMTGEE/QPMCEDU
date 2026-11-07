<?php
session_start();

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Initialize variables
$user_id = $_SESSION['user_id'];
$success_msg = $error_msg = "";
$filter_status = isset($_GET['filter_status']) && in_array($_GET['filter_status'], ['all', 'read', 'unread']) ? $_GET['filter_status'] : 'all';
$filter_term = isset($_GET['filter_term']) ? (int)$_GET['filter_term'] : 0;

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $evaluation_id = (int)$_POST['evaluation_id'];
    $update_sql = "UPDATE teacher_evaluations SET is_read = 1 WHERE id = ? AND teacher_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $evaluation_id, $user_id);
    
    try {
        $conn->begin_transaction();
        $update_stmt->execute();
        
        if ($update_stmt->affected_rows > 0) {
            logActivity($conn, $user_id, "Marked Evaluation Read", "Evaluation ID: $evaluation_id");
            $success_msg = "Evaluation marked as read successfully.";
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error marking evaluation as read. Please try again.";
        error_log("Mark evaluation read error: " . $e->getMessage() . " | Evaluation ID: $evaluation_id");
    }
    $update_stmt->close();
}

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $update_sql = "UPDATE teacher_evaluations SET is_read = 1 WHERE teacher_id = ? AND is_read = 0";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("i", $user_id);
    
    try {
        $conn->begin_transaction();
        $update_stmt->execute();
        $affected_count = $update_stmt->affected_rows;
        
        if ($affected_count > 0) {
            logActivity($conn, $user_id, "Marked All Evaluations Read", "Count: $affected_count");
            $success_msg = "$affected_count evaluations marked as read successfully.";
        } else {
            $error_msg = "No unread evaluations found.";
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error marking evaluations as read. Please try again.";
        error_log("Mark all evaluations read error: " . $e->getMessage());
    }
    $update_stmt->close();
}

// Fetch terms for filter
$terms_sql = "SELECT id, term_name FROM academic_calendar ORDER BY created_at DESC";
$terms_result = $conn->query($terms_sql);
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);

// Fetch evaluations with filters - FIXED: Removed the users table join
$evaluations_sql = "SELECT te.*, 
                          c.class_name, 
                          s.subject_name, 
                          ac.term_name
                   FROM teacher_evaluations te
                   LEFT JOIN classes c ON te.class_id = c.id
                   LEFT JOIN subjects s ON te.subject_id = s.id
                   LEFT JOIN academic_calendar ac ON te.term_id = ac.id
                   WHERE te.teacher_id = ?";

$params = [$user_id];
$types = "i";

if ($filter_status === 'read') {
    $evaluations_sql .= " AND te.is_read = 1";
} elseif ($filter_status === 'unread') {
    $evaluations_sql .= " AND te.is_read = 0";
}

if ($filter_term > 0) {
    $evaluations_sql .= " AND te.term_id = ?";
    $params[] = $filter_term;
    $types .= "i";
}

$evaluations_sql .= " ORDER BY te.created_at DESC";

$evaluations_stmt = $conn->prepare($evaluations_sql);
$evaluations_stmt->bind_param($types, ...$params);
$evaluations_stmt->execute();
$evaluations_result = $evaluations_stmt->get_result();
$evaluations = $evaluations_result->fetch_all(MYSQLI_ASSOC);
$evaluations_stmt->close();

// Calculate statistics
$total_evaluations = count($evaluations);
$read_count = count(array_filter($evaluations, function($e) { return $e['is_read']; }));
$unread_count = $total_evaluations - $read_count;
$avg_score = 0;
$score_count = 0;

foreach ($evaluations as $eval) {
    for ($i = 1; $i <= 16; $i++) {
        if (!is_null($eval["score_$i"])) {
            $avg_score += $eval["score_$i"];
            $score_count++;
        }
    }
}
$avg_score = $score_count > 0 ? round($avg_score / $score_count, 1) : 0;

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View your teaching evaluations and feedback from administrators at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher evaluations, feedback, performance review, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>My Evaluations - QueensPa School</title>
    
    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-dark: #e03e00;
            --primary-light: #ff6b00;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-light: #f8f9fa;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-color);
        }

        /* Cards */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .card:hover {
            box-shadow: var(--shadow-md);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
            padding: 1.25rem;
        }
        
        .card-header h3 {
            margin: 0;
            font-weight: 600;
        }

        /* Stats Cards */
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border-bottom: 4px solid var(--primary-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            opacity: 0.8;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--secondary-color);
            font-weight: 500;
            margin: 0;
        }

        /* Evaluation Cards */
        .evaluation-card {
            border-left: 4px solid transparent;
            margin-bottom: 1.5rem;
        }
        
        .evaluation-card.unread {
            border-left-color: var(--primary-color);
            background: linear-gradient(to right, rgba(255, 69, 0, 0.02), white);
        }
        
        .evaluation-card.read {
            border-left-color: #28a745;
        }
        
        .evaluation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .evaluation-id {
            font-weight: 600;
            color: var(--primary-color);
            background: rgba(255, 69, 0, 0.1);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
        }
        
        .evaluation-date {
            color: var(--secondary-color);
            font-size: 0.875rem;
        }
        
        .evaluation-date i {
            margin-right: 0.25rem;
        }

        /* Badges */
        .badge {
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 20px;
        }
        
        .badge-read {
            background-color: #28a745;
            color: white;
        }
        
        .badge-unread {
            background-color: var(--primary-color);
            color: white;
        }

        /* Rating Stars */
        .rating-stars {
            color: var(--accent-color);
            font-size: 0.875rem;
        }
        
        .rating-stars .star-filled {
            color: var(--accent-color);
        }
        
        .rating-stars .star-empty {
            color: #e9ecef;
        }
        
        .score-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.5rem;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        
        .score-label {
            font-size: 0.75rem;
            color: var(--secondary-color);
            margin-bottom: 0.25rem;
        }
        
        .score-value {
            font-weight: 600;
            color: var(--text-color);
        }

        /* Notes */
        .notes-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .positive-notes {
            border-left: 4px solid #28a745;
            padding-left: 1rem;
        }
        
        .negative-notes {
            border-left: 4px solid #dc3545;
            padding-left: 1rem;
        }

        /* Buttons */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            border-radius: 8px;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 69, 0, 0.3);
        }
        
        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .form-select, .form-control {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 0.5rem 1rem;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 69, 0, 0.25);
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 1rem;
            }
            
            .evaluation-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .score-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 fade-in">
                <div>
                    <h2 class="mb-1" style="color: var(--primary-color);">
                        <i class="fas fa-clipboard-check me-2"></i>My Evaluations
                    </h2>
                    <p class="text-muted mb-0">View and manage your teaching performance evaluations</p>
                </div>
                
                <?php if ($unread_count > 0): ?>
                    <form method="POST" class="d-inline">
                        <button type="submit" name="mark_all_read" class="btn btn-primary">
                            <i class="fas fa-check-double me-2"></i>Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Stats Cards -->
            <div class="row mb-4 fade-in">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-number"><?php echo $total_evaluations; ?></div>
                                <p class="stat-label">Total Evaluations</p>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-number"><?php echo $unread_count; ?></div>
                                <p class="stat-label">Unread</p>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-number"><?php echo $read_count; ?></div>
                                <p class="stat-label">Read</p>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="stat-number"><?php echo $avg_score; ?></div>
                                <p class="stat-label">Average Score</p>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-star"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section fade-in">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="filter_status" class="form-label fw-semibold">
                            <i class="fas fa-filter me-1"></i>Status
                        </label>
                        <select class="form-select" id="filter_status" name="filter_status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Evaluations</option>
                            <option value="read" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Read</option>
                            <option value="unread" <?php echo $filter_status === 'unread' ? 'selected' : ''; ?>>Unread</option>
                        </select>
                    </div>
                    
                    <div class="col-md-5">
                        <label for="filter_term" class="form-label fw-semibold">
                            <i class="fas fa-calendar me-1"></i>Academic Term
                        </label>
                        <select class="form-select" id="filter_term" name="filter_term">
                            <option value="0">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo $term['id']; ?>" <?php echo $filter_term == $term['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($term['term_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Apply
                        </button>
                    </div>
                </form>
            </div>

            <!-- Messages -->
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Evaluations List -->
            <?php if (empty($evaluations)): ?>
                <div class="text-center py-5 fade-in">
                    <div class="mb-3">
                        <i class="fas fa-clipboard-list fa-4x" style="color: var(--primary-color); opacity: 0.3;"></i>
                    </div>
                    <h5 class="text-muted">No evaluations found</h5>
                    <p class="text-muted mb-0">There are no evaluations matching your criteria.</p>
                </div>
            <?php else: ?>
                <?php foreach ($evaluations as $index => $eval): ?>
                    <div class="card evaluation-card <?php echo $eval['is_read'] ? 'read' : 'unread'; ?> animate__animated animate__fadeInUp" style="animation-delay: <?php echo $index * 0.1; ?>s">
                        <div class="card-body">
                            <!-- Evaluation Header -->
                            <div class="evaluation-header">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="evaluation-id">
                                        <i class="fas fa-hashtag me-1"></i>Evaluation #<?php echo $eval['id']; ?>
                                    </span>
                                </div>
                                
                                <div class="evaluation-date">
                                    <i class="far fa-calendar-alt"></i>
                                    <?php echo date('F j, Y', strtotime($eval['created_at'])); ?>
                                </div>
                            </div>

                            <!-- Basic Info -->
                            <div class="row mb-3">
                                <div class="col-md-3 col-sm-6 mb-2">
                                    <small class="text-muted d-block">Class</small>
                                    <strong><?php echo htmlspecialchars($eval['class_name'] ?? 'N/A'); ?></strong>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-2">
                                    <small class="text-muted d-block">Subject</small>
                                    <strong><?php echo htmlspecialchars($eval['subject_name'] ?? 'N/A'); ?></strong>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-2">
                                    <small class="text-muted d-block">Term</small>
                                    <strong><?php echo htmlspecialchars($eval['term_name'] ?? 'N/A'); ?></strong>
                                </div>
                                <div class="col-md-3 col-sm-6 mb-2">
                                    <small class="text-muted d-block">Week</small>
                                    <strong>Week <?php echo htmlspecialchars($eval['week'] ?? 'N/A'); ?></strong>
                                </div>
                            </div>

                            <?php if (!empty($eval['department']) || !empty($eval['cuas'])): ?>
                                <div class="row mb-3">
                                    <?php if (!empty($eval['department'])): ?>
                                        <div class="col-md-6 mb-2">
                                            <small class="text-muted d-block">Department</small>
                                            <strong><?php echo htmlspecialchars($eval['department']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($eval['cuas'])): ?>
                                        <div class="col-md-6 mb-2">
                                            <small class="text-muted d-block">CUAS</small>
                                            <strong><?php echo htmlspecialchars($eval['cuas']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Scores -->
                            <div class="mb-3">
                                <h6 class="mb-3">
                                    <i class="fas fa-star me-2" style="color: var(--accent-color);"></i>Performance Scores
                                </h6>
                                <div class="row g-2">
                                    <?php 
                                    $score_count = 0;
                                    for ($i = 1; $i <= 16; $i++): 
                                        if (!is_null($eval["score_$i"])): 
                                            $score_count++;
                                    ?>
                                        <div class="col-md-3 col-6">
                                            <div class="score-item">
                                                <div class="score-label">Score <?php echo $i; ?></div>
                                                <div class="score-value">
                                                    <div class="rating-stars">
                                                        <?php for ($j = 1; $j <= 5; $j++): ?>
                                                            <i class="fas fa-star <?php echo $j <= $eval["score_$i"] ? 'star-filled' : 'star-empty'; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php 
                                        endif; 
                                    endfor; 
                                    
                                    if ($score_count === 0):
                                    ?>
                                        <div class="col-12">
                                            <p class="text-muted mb-0">No scores available</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Notes -->
                            <?php if (!empty($eval['positive_notes']) || !empty($eval['negative_notes'])): ?>
                                <div class="notes-section">
                                    <div class="row">
                                        <?php if (!empty($eval['positive_notes'])): ?>
                                            <div class="col-md-6">
                                                <div class="positive-notes">
                                                    <small class="text-success fw-bold">
                                                        <i class="fas fa-plus-circle me-1"></i>Positive Notes
                                                    </small>
                                                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($eval['positive_notes'])); ?></p>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($eval['negative_notes'])): ?>
                                            <div class="col-md-6">
                                                <div class="negative-notes">
                                                    <small class="text-danger fw-bold">
                                                        <i class="fas fa-minus-circle me-1"></i>Areas for Improvement
                                                    </small>
                                                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($eval['negative_notes'])); ?></p>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Footer -->
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div>
                                    <span class="badge <?php echo $eval['is_read'] ? 'badge-read' : 'badge-unread'; ?>">
                                        <i class="fas <?php echo $eval['is_read'] ? 'fa-check-circle' : 'fa-envelope'; ?> me-1"></i>
                                        <?php echo $eval['is_read'] ? 'Read' : 'Unread'; ?>
                                    </span>
                                    
                                    <?php if (!empty($eval['updated_at']) && $eval['updated_at'] !== $eval['created_at']): ?>
                                        <small class="text-muted ms-2">
                                            <i class="far fa-clock me-1"></i>
                                            Updated: <?php echo date('M j, Y', strtotime($eval['updated_at'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!$eval['is_read']): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="evaluation_id" value="<?php echo $eval['id']; ?>">
                                        <button type="submit" name="mark_read" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-check me-1"></i>Mark as Read
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    
    <?php 
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
    ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    
    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>