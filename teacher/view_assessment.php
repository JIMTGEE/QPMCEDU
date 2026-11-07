<?php
ob_start();
session_start();

// Restrict to teachers
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher and get department
$teacher_sql = "SELECT id, department_id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher || !$teacher['department_id']) {
    error_log("Teacher not found or no department assigned for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Teacher account or department not found.";
    header("Location: /login.php");
    exit();
}

// Validate assessment_id
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
if ($assessment_id <= 0) {
    $_SESSION['error_msg'] = "Invalid assessment ID.";
    header("Location: /teacher/create_assignment.php");
    exit();
}

// Get current academic term
$current_term_sql = "SELECT id, start_date, end_date FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
$current_term_result = $conn->query($current_term_sql);
$current_term = $current_term_result->fetch_assoc();
$current_term_id = $current_term ? (int)$current_term['id'] : null;
if (!$current_term_id) {
    error_log("No active academic term found.");
    $_SESSION['error_msg'] = "No active academic term. Please contact the administrator.";
    header("Location: /teacher/dashboard.php");
    exit();
}

// Fetch assessment details
$assessment_sql = "SELECT a.id, a.title, a.description, a.type, a.due_date, a.max_points, a.created_at, a.status, a.file_path, 
                          c.class_name, s.subject_name, d.name AS department_name
                   FROM assessments a 
                   JOIN classes c ON a.class_id = c.id 
                   LEFT JOIN subjects s ON a.subject_id = s.id 
                   LEFT JOIN departments d ON c.department_id = d.id 
                   WHERE a.id = ? AND a.created_by = ? AND a.status IN ('draft', 'published') AND a.term_id = ?";
$assessment_stmt = $conn->prepare($assessment_sql);
$assessment_stmt->bind_param("iii", $assessment_id, $teacher_id, $current_term_id);
$assessment_stmt->execute();
$assessment = $assessment_stmt->get_result()->fetch_assoc();
$assessment_stmt->close();

if (!$assessment) {
    error_log("Assessment not found or unauthorized access for assessment_id: $assessment_id, user_id: $teacher_id");
    $_SESSION['error_msg'] = "Assessment not found, not authorized, or not in current term.";
    header("Location: /teacher/create_assignment.php");
    exit();
}

// Fetch questions and their options
$questions_sql = "SELECT q.id, q.question_text, q.question_type, q.points, q.correct_answer
                  FROM questions q
                  JOIN question_assignments qa ON q.id = qa.question_id
                  WHERE qa.assessment_id = ?
                  ORDER BY qa.order ASC";
$questions_stmt = $conn->prepare($questions_sql);
$questions_stmt->bind_param("i", $assessment_id);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();
$questions = [];
while ($question = $questions_result->fetch_assoc()) {
    if ($question['question_type'] === 'multiple_choice') {
        $options_sql = "SELECT option_text, is_correct FROM question_options WHERE question_id = ? ORDER BY id ASC";
        $options_stmt = $conn->prepare($options_sql);
        $options_stmt->bind_param("i", $question['id']);
        $options_stmt->execute();
        $options_result = $options_stmt->get_result();
        $question['options'] = [];
        while ($option = $options_result->fetch_assoc()) {
            $question['options'][] = [
                'text' => $option['option_text'],
                'is_correct' => $option['is_correct']
            ];
        }
        $options_stmt->close();
    } else {
        $question['options'] = [];
    }
    $questions[] = $question;
}
$questions_stmt->close();

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed View Assessment Page', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher viewed assessment ID: $assessment_id";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

$errors = [];
$success_msg = '';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View assessment details and questions at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher, assessment view">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Assessment - QueensPa School">
    <meta property="og:description" content="Teacher interface to view assessment details and questions.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/view_assessment.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Assessment - QueensPa School">
    <meta name="twitter:description" content="Teacher interface to view assessment details and questions.">
    <title>View Assessment - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #007bff;
            --primary-hover: #0056b3;
            --secondary-color: #6c757d;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 1400px;
        }
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            animation: animate__pulse 0.5s;
        }
        .alert {
            border-radius: 8px;
            background-color: #fff3e0;
            color: var(--text-color);
            font-weight: 500;
        }
        .question-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f9f9f9;
        }
        .correct-answer {
            color: #28a745;
            font-weight: bold;
        }
        .deadline-passed {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>
    <main class="container my-5">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX">
                <?php foreach ($errors as $error): ?>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <section class="card mb-5 animate__animated animate__fadeInDown">
            <div class="card-header">
                <h3 class="mb-0">Assessment Details</h3>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h5>Title</h5>
                        <p><?php echo htmlspecialchars($assessment['title']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Type</h5>
                        <p><?php echo htmlspecialchars(ucfirst($assessment['type'])); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Class</h5>
                        <p><?php echo htmlspecialchars($assessment['class_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Subject</h5>
                        <p><?php echo htmlspecialchars($assessment['subject_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Department</h5>
                        <p><?php echo htmlspecialchars($assessment['department_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Due Date</h5>
                        <p class="<?php echo strtotime($assessment['due_date']) < time() ? 'deadline-passed' : ''; ?>">
                            <?php echo htmlspecialchars($assessment['due_date']); ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h5>Maximum Points</h5>
                        <p><?php echo htmlspecialchars($assessment['max_points']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Status</h5>
                        <p><?php echo htmlspecialchars(ucfirst($assessment['status'])); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>File</h5>
                        <p>
                            <?php if ($assessment['file_path']): ?>
                                <a href="<?php echo htmlspecialchars($assessment['file_path']); ?>" target="_blank">View File</a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h5>Created At</h5>
                        <p><?php echo htmlspecialchars($assessment['created_at']); ?></p>
                    </div>
                    <div class="col-12">
                        <h5>Description</h5>
                        <p><?php echo htmlspecialchars($assessment['description']) ?: 'No description provided.'; ?></p>
                    </div>
                </div>
            </div>
        </section>
        <section class="card animate__animated animate__fadeInUp">
            <div class="card-header">
                <h3 class="mb-0">Questions</h3>
            </div>
            <div class="card-body">
                <?php if (empty($questions)): ?>
                    <p>No questions found for this assessment.</p>
                <?php else: ?>
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="question-card">
                            <h6>Question <?php echo $index + 1; ?> (<?php echo htmlspecialchars(ucfirst($question['question_type'])); ?>, <?php echo $question['points']; ?> points)</h6>
                            <p><?php echo htmlspecialchars($question['question_text']); ?></p>
                            <?php if ($question['question_type'] === 'multiple_choice' && !empty($question['options'])): ?>
                                <h6>Options:</h6>
                                <ul>
                                    <?php foreach ($question['options'] as $option): ?>
                                        <li>
                                            <?php echo htmlspecialchars($option['text']); ?>
                                            <?php if ($option['is_correct']): ?>
                                                <span class="correct-answer">(Correct)</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php elseif ($question['question_type'] === 'multiple_choice' && empty($question['options'])): ?>
                                <p>No options available.</p>
                            <?php endif; ?>
                            <?php if ($question['question_type'] !== 'multiple_choice' && !empty($question['correct_answer'])): ?>
                                <h6>Correct Answer:</h6>
                                <p class="correct-answer"><?php echo htmlspecialchars($question['correct_answer']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a href="/teacher/manage_assignments.php" class="btn btn-primary">Back to Assessments</a>
            </div>
        </section>
        <section class="card animate__animated animate__fadeInUp">
            <div class="card-header">
                <h3 class="mb-0">Student Submissions</h3>
            </div>
            <div class="card-body">
                <p>No student submissions found for this assessment.</p>
                <!-- Placeholder for future student submissions logic -->
            </div>
        </section>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                $('.alert').each(function() {
                    bootstrap.Alert.getOrCreateInstance(this).close();
                });
            }, 5000);
        });
    </script>
</body>
</html>