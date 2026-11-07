<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];
$plan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$plan_id) {
    $_SESSION['error_msg'] = "Invalid lesson plan ID.";
    header("Location: /teacher/lesson_plan.php");
    exit();
}

// Verify subjects table structure
$schema_check = $conn->query("DESCRIBE subjects");
$schema = $schema_check ? $schema_check->fetch_all(MYSQLI_ASSOC) : [];
$has_subject_name = in_array('subject_name', array_column($schema, 'Field'));

// Fetch lesson plan details
$plan_sql = "SELECT tn.*, c.class_name, ac.term_name, 
             COALESCE(s.subject_name, 'Unknown Subject') AS subject_name 
             FROM teacher_notes tn 
             JOIN classes c ON tn.class_id = c.id 
             JOIN academic_calendar ac ON tn.term_id = ac.id 
             LEFT JOIN subjects s ON tn.subject_id = s.id 
             WHERE tn.id = ? AND tn.teacher_id = ?";
$plan_stmt = $conn->prepare($plan_sql);
if (!$plan_stmt) {
    error_log("Prepare failed: " . $conn->error . " - Query: $plan_sql");
    $_SESSION['error_msg'] = "System error: Unable to prepare query. Check database schema.";
    header("Location: /teacher/lesson_plan.php");
    exit();
}
$plan_stmt->bind_param("ii", $plan_id, $teacher_id);
$plan_stmt->execute();
$plan = $plan_stmt->get_result()->fetch_assoc();
$plan_stmt->close();

if (!$plan) {
    error_log("Lesson plan not found for user_id: $teacher_id, plan_id: $plan_id");
    $_SESSION['error_msg'] = "Lesson plan not found or access denied.";
    header("Location: /teacher/lesson_plan.php");
    exit();
}

// Log view access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Viewed Lesson Plan', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher viewed lesson plan ID: $plan_id";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log lesson plan view: " . $log_stmt->error);
}
$log_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Lesson Plan - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --hover-color: #e03e00;
            --secondary-color: #ff6b00;
            --secondary-hover: #e05a00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #fff3e0);
            color: var(--text-color);
            font-family: 'Arial', sans-serif;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), --secondary-color);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1rem;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 6px;
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--hover-color);
            border-color: var(--hover-color);
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            border-radius: 6px;
            color: white;
        }
        .btn-secondary:hover {
            background-color: var(--secondary-hover);
            border-color: var(--secondary-hover);
        }
        .form-label, .detail-label {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }
        .detail-value {
            background-color: #fff;
            padding: 0.75rem;
            border-radius: 6px;
            border: 1px solid #ddd;
            word-wrap: break-word;
        }
        .section-title {
            font-size: 1.5rem;
            margin-top: 1.5rem;
            color: var(--primary-color);
        }
        .back-link {
            display: inline-block;
            margin-top: 1rem;
            color: var(--secondary-color);
            text-decoration: none;
        }
        .back-link:hover {
            color: var(--secondary-hover);
            text-decoration: underline;
        }
        .print-btn {
            margin-top: 1rem;
        }
        @media print {
            .print-btn, .back-link, .card-header {
                display: none;
            }
            .card {
                box-shadow: none;
                border: none;
            }
            body {
                background: none;
            }
        }
    </style>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>
    <div class="container my-5">
        <section class="card">
            <div class="card-header">
                <h2 class="mb-0">View Lesson Plan</h2>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['error_msg'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
                    </div>
                <?php endif; ?>

                <button class="btn btn-primary print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>

                <div class="mb-4">
                    <h3 class="section-title">Basic Information</h3>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Week</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['week']); ?></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Class</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['class_name']); ?></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Subject</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['subject_name']); ?></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Term</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['term_name']); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="detail-label">Created At</label>
                            <div class="detail-value"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <h3 class="section-title">Curriculum Details</h3>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Strand</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['strand']); ?></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Sub-Strand</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['sub_strand']); ?></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Content Standard</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['content_standard']); ?></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="detail-label">Indicator</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['indicator']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <h3 class="section-title">Lesson Details</h3>
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="detail-label">Objectives</label>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($plan['objectives'])); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="detail-label">Reference</label>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($plan['reference'])); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="detail-label">Resources</label>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($plan['resources'])); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="detail-label">Keywords</label>
                            <div class="detail-value"><?php echo htmlspecialchars($plan['keywords']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <h3 class="section-title">Lesson Delivery</h3>
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="detail-label">Starter</label>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($plan['starter'])); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="detail-label">Presentation</label>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($plan['presentation'])); ?></div>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="detail-label">Summary</label>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($plan['summary'])); ?></div>
                        </div>
                    </div>
                </div>

                <a href="/teacher/lesson_plan.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Lesson Plans
                </a>
            </div>
        </section>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous"></script>
</body>
</html>
<?php
// Close database connection after all operations
mysqli_close($conn);
ob_end_flush();
?>