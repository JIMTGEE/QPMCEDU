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
            $success_msg = "Evaluation marked as read.";
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error marking evaluation as read: " . $e->getMessage();
        error_log("Mark evaluation read error: " . $e->getMessage() . " | Evaluation ID: $evaluation_id");
    }
    $update_stmt->close();
}

// Fetch evaluations
$evaluations_sql = "SELECT te.*, 
                          c.class_name, 
                          s.subject_name, 
                          ac.term_name
                   FROM teacher_evaluations te
                   LEFT JOIN classes c ON te.class_id = c.id
                   LEFT JOIN subjects s ON te.subject_id = s.id
                   LEFT JOIN academic_calendar ac ON te.term_id = ac.id
                   WHERE te.teacher_id = ?";
if ($filter_status === 'read') {
    $evaluations_sql .= " AND te.is_read = 1";
} elseif ($filter_status === 'unread') {
    $evaluations_sql .= " AND te.is_read = 0";
}
$evaluations_sql .= " ORDER BY te.created_at DESC";
$evaluations_stmt = $conn->prepare($evaluations_sql);
$evaluations_stmt->bind_param("i", $user_id);
$evaluations_stmt->execute();
$evaluations_result = $evaluations_stmt->get_result();
$evaluations = $evaluations_result->fetch_all(MYSQLI_ASSOC);
$evaluations_stmt->close();

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher evaluations view for QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, evaluations, teacher feedback, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Teacher Evaluations">
    <meta property="og:description" content="Teacher interface for viewing admin evaluations in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/view_evaluations.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Teacher Evaluations">
    <meta name="twitter:description" content="Teacher interface for viewing admin evaluations in QueensPa School.">
    <title>Evaluations - QueensPa School Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-success {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-success:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .form-control, .form-select {
            border-radius: 8px;
        }
        .evaluation-card {
            margin-bottom: 1.5rem;
        }
        .rating-stars {
            color: #ffc107;
        }
        .badge-read {
            background-color: #28a745;
        }
        .badge-unread {
            background-color: #dc3545;
        }
        .score-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <h3 class="mb-0">📋 My Evaluations</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success animate__animated animate__fadeIn"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger animate__animated animate__shakeX"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <form class="mb-3" method="GET">
                        <div class="row g-3 align-items-center">
                            <div class="col-auto">
                                <label for="filter_status" class="form-label">Filter by Status</label>
                            </div>
                            <div class="col-auto">
                                <select class="form-select" id="filter_status" name="filter_status" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="read" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Read</option>
                                    <option value="unread" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Unread</option>
                                </select>
                            </div>
                        </div>
                    </form>
                    <?php if (empty($evaluations)): ?>
                        <div class="alert alert-info">No evaluations found.</div>
                    <?php else: ?>
                        <?php foreach ($evaluations as $eval): ?>
                            <div class="card evaluation-card animate__animated animate__fadeInUp">
                                <div class="card-body">
                                    <h5 class="card-title">Evaluation ID: <?php echo $eval['id']; ?></h5>
                                    <p class="card-text"><strong>Class:</strong> <?php echo htmlspecialchars($eval['class_name'] ?? 'N/A'); ?></p>
                                    <p class="card-text"><strong>Subject:</strong> <?php echo htmlspecialchars($eval['subject_name'] ?? 'N/A'); ?></p>
                                    <p class="card-text"><strong>Term:</strong> <?php echo htmlspecialchars($eval['term_name'] ?? 'N/A'); ?></p>
                                    <p class="card-text"><strong>Department:</strong> <?php echo htmlspecialchars($eval['department'] ?? 'N/A'); ?></p>
                                    <p class="card-text"><strong>CUAS:</strong> <?php echo htmlspecialchars($eval['cuas'] ?? 'N/A'); ?></p>
                                    <p class="card-text"><strong>Week:</strong> <?php echo htmlspecialchars($eval['week'] ?? 'N/A'); ?></p>
                                    <p class="card-text"><strong>Created:</strong> <?php echo $eval['created_at']; ?></p>
                                    <p class="card-text"><strong>Updated:</strong> <?php echo $eval['updated_at'] ?? 'N/A'; ?></p>
                                    <h6>Scores:</h6>
                                    <div class="score-grid">
                                        <?php for ($i = 1; $i <= 16; $i++): ?>
                                            <?php if (!is_null($eval["score_$i"])): ?>
                                                <p class="card-text"><strong>Score <?php echo $i; ?>:</strong> 
                                                    <?php for ($j = 1; $j <= 5; $j++): ?>
                                                        <i class="fas fa-star rating-stars <?php echo $j <= $eval["score_$i"] ? 'text-warning' : 'text-muted'; ?>"></i>
                                                    <?php endfor; ?>
                                                </p>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="card-text"><strong>Positive Notes:</strong> <?php echo htmlspecialchars($eval['positive_notes'] ?? 'N/A'); ?></p>
                                    <p class="card-text"><strong>Negative Notes:</strong> <?php echo htmlspecialchars($eval['negative_notes'] ?? 'N/A'); ?></p>
                                    <p class="card-text">
                                        <strong>Status:</strong>
                                        <span class="badge <?php echo $eval['is_read'] ? 'badge-read' : 'badge-unread'; ?>">
                                            <?php echo $eval['is_read'] ? 'Read' : 'Unread'; ?>
                                        </span>
                                    </p>
                                    <?php if (!$eval['is_read']): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="evaluation_id" value="<?php echo $eval['id']; ?>">
                                            <button type="submit" name="mark_read" class="btn btn-primary btn-sm">
                                                <i class="fas fa-check"></i> Mark as Read
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>