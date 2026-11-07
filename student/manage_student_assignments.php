<?php
/**
 * Student Assignment Management Page — Redesigned for Clarity
 * @package QueensPa School Management System
 * @version 7.3.0  (edit-after-submission support)
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to students only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a student.";
    header("Location: /login.php");
    exit();
}

ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
ini_set('display_errors', 0);

$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT u.id, u.name, u.email, s.student_name 
                   FROM users u 
                   LEFT JOIN students s ON u.id = s.user_id 
                   WHERE u.id = ? AND u.role = 'student'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $student_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $student_name = $user['student_name'] ?? $user['name'];
    $user_check_stmt->close();
} else {
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

logActivity($conn, $student_id, "Page Access", "Student accessed manage assignments page");

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// AI Config
$ai_config = [
    'api_key'     =>$apiKey = getenv('GEMINI_API_KEY');
    'model'       => 'gemini-flash-latest',
    'endpoint'    => 'https://generativelanguage.googleapis.com/v1beta/models/',
    'timeout'     => 60,
    'max_retries' => 3,
    'temperature' => 0.3,
    'max_tokens'  => 2048,
];

// ==================== GEMINI API ====================
function callGeminiAPI($prompt, $config) {
    if (empty($config['api_key'])) throw new Exception("Gemini API key is not configured.");
    $url = rtrim($config['endpoint'], '/') . '/' . $config['model'] . ':generateContent?key=' . urlencode($config['api_key']);
    $payload = [
        "contents" => [["parts" => [["text" => $prompt]]]],
        "generationConfig" => ["temperature" => $config['temperature'], "maxOutputTokens" => $config['max_tokens']]
    ];
    $lastError = '';
    for ($attempt = 0; $attempt <= $config['max_retries']; $attempt++) {
        if ($attempt > 0) sleep(min(2 * $attempt, 6));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-goog-api-key: ' . $config['api_key']],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $config['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $result    = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) { $lastError = "Network error: $curlError"; continue; }
        $data   = json_decode($result, true);
        $errMsg = $data['error']['message'] ?? $result;
        if ($httpCode === 200) {
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to parse AI response.");
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($text === null) {
                $reason = $data['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                if (in_array($reason, ['SAFETY', 'RECITATION'])) throw new Exception("AI response blocked ($reason).");
                throw new Exception("AI returned empty response (reason: $reason).");
            }
            return $text;
        }
        if ($httpCode === 400) throw new Exception("AI request error (400): $errMsg");
        if ($httpCode === 403) throw new Exception("AI access denied (403): $errMsg");
        if ($httpCode === 404) throw new Exception("AI model not found (404).");
        if ($httpCode === 429) { $lastError = "Rate limit. Retrying…"; continue; }
        $lastError = "AI error (HTTP $httpCode): $errMsg";
    }
    throw new Exception($lastError ?: "Failed after {$config['max_retries']} retries.");
}

// ==================== AI GRADING ====================
function gradeWithAI($assignmentData, $answers, $apiConfig) {
    $prompt  = "You are an expert teacher grading a student assignment. Grade fairly and constructively.\n\n";
    $prompt .= "Assignment: " . $assignmentData['title'] . "\n";
    $prompt .= "Subject: " . ($assignmentData['subject'] ?? 'N/A') . "\n";
    $prompt .= "Description: " . ($assignmentData['description'] ?? 'N/A') . "\n";
    $prompt .= "Maximum Points: " . $assignmentData['max_points'] . "\n\n";
    $prompt .= "Questions and Student Answers:\n";
    $questions = $assignmentData['questions'] ?? [];
    if (empty($questions)) {
        $answerText = '';
        foreach ($answers as $ans) {
            $answerText .= (is_array($ans) ? ($ans['answer'] ?? $ans['text'] ?? json_encode($ans)) : $ans) . "\n";
        }
        $prompt .= "Student's Full Submission:\n" . trim($answerText) . "\n\n";
        $questionCount = 1;
    } else {
        foreach ($questions as $i => $q) {
            $qText = is_string($q) ? $q : ($q['question_text'] ?? $q['question'] ?? "Question " . ($i + 1));
            $pts   = (is_array($q) && ($q['points'] ?? 0) > 0) ? " [{$q['points']} pts]" : "";
            $answer = "No answer provided";
            if (isset($answers[$i])) {
                $ans = $answers[$i];
                $answer = is_array($ans) ? ($ans['answer'] ?? $ans['text'] ?? json_encode($ans)) : $ans;
            }
            $prompt .= "Q" . ($i + 1) . "$pts: $qText\nStudent's Answer: $answer\n\n";
        }
        $questionCount = count($questions);
    }
    $prompt .= "IMPORTANT: Respond with ONLY valid JSON — no markdown, no preamble.\n";
    $prompt .= 'Use: {"score":NUMBER,"feedback":"feedback","strengths":"strengths","areas_for_improvement":"areas"' . ($questionCount > 1 ? ',"per_question":["Q1 feedback","Q2 feedback"]' : '') . "}\n";
    $prompt .= "score must be 0–" . $assignmentData['max_points'] . ". Output ONLY JSON.";

    $response = callGeminiAPI($prompt, $apiConfig);
    $response = trim(preg_replace(['/^```(?:json)?\s*/i', '/\s*```\s*$/i'], '', $response));
    if (($pos = strpos($response, '{')) !== false) $response = substr($response, $pos);
    if (($pos = strrpos($response, '}')) !== false) $response = substr($response, 0, $pos + 1);
    $result = json_decode(trim($response), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        if (preg_match('/"score"\s*:\s*(\d+(?:\.\d+)?)/', $response, $m)) {
            $feedback = 'Assignment graded by AI.';
            if (preg_match('/"feedback"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $response, $fm)) $feedback = stripslashes($fm[1]);
            return ['score' => min((float)$assignmentData['max_points'], max(0, (float)$m[1])), 'feedback' => $feedback, 'strengths' => '', 'areas_for_improvement' => ''];
        }
        throw new Exception("Could not parse AI grading response.");
    }
    if (!isset($result['score'])) throw new Exception("AI response missing score field.");
    return [
        'score' => min((float)$assignmentData['max_points'], max(0, (float)$result['score'])),
        'feedback' => $result['feedback'] ?? 'Your assignment has been graded.',
        'strengths' => $result['strengths'] ?? '',
        'areas_for_improvement' => $result['areas_for_improvement'] ?? '',
        'per_question' => $result['per_question'] ?? []
    ];
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token. Please refresh the page.']);
        exit();
    }

    // AI Grading
    if ($_POST['action'] === 'ai_grade') {
        $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        if (!$submission_id || !$assignment_id) { http_response_code(400); echo json_encode(['error' => 'Missing parameters.']); exit(); }

        $verify_sql = "SELECT asub.id, asub.answers, asub.submission_text,
                              a.title, a.description, a.type, a.max_points, a.questions,
                              s.subject_name
                       FROM assignment_submissions asub
                       JOIN assignments a ON asub.assignment_id = a.id
                       LEFT JOIN subjects s ON a.subject_id = s.id
                       WHERE asub.id = ? AND asub.user_id = ? AND asub.is_graded = 0";
        $verify_stmt = $conn->prepare($verify_sql);
        if (!$verify_stmt) { http_response_code(500); echo json_encode(['error' => 'Database error.']); exit(); }
        $verify_stmt->bind_param("ii", $submission_id, $student_id);
        $verify_stmt->execute();
        $submission = $verify_stmt->get_result()->fetch_assoc();
        $verify_stmt->close();
        if (!$submission) { http_response_code(404); echo json_encode(['error' => 'Submission not found or already graded.']); exit(); }

        $questions = [];
        if (!empty($submission['questions'])) {
            $decoded = json_decode($submission['questions'], true);
            if (is_array($decoded)) $questions = $decoded;
        }
        $answers = [];
        if (!empty($submission['answers'])) {
            $decoded = json_decode($submission['answers'], true);
            if (is_array($decoded)) $answers = isset($decoded[0]) ? $decoded : array_values($decoded);
        }
        if (empty($answers) && !empty($submission['submission_text'])) $answers = [['answer' => $submission['submission_text']]];
        if (empty($answers)) { http_response_code(400); echo json_encode(['error' => 'No answers found.']); exit(); }

        $assignmentData = [
            'title' => $submission['title'], 'description' => $submission['description'] ?? '',
            'type' => $submission['type'] ?? 'assignment', 'max_points' => $submission['max_points'] ?? 10,
            'questions' => $questions, 'subject' => $submission['subject_name'] ?? 'N/A'
        ];

        try {
            $aiResult = gradeWithAI($assignmentData, $answers, $ai_config);
            $score = min(max(0, (float)$aiResult['score']), (float)$submission['max_points']);
            $feedback_json = json_encode([
                'ai_graded' => true, 'overall_feedback' => $aiResult['feedback'],
                'strengths' => $aiResult['strengths'], 'areas_for_improvement' => $aiResult['areas_for_improvement'],
                'per_question' => $aiResult['per_question'] ?? []
            ], JSON_UNESCAPED_UNICODE);
            $update_sql = "UPDATE assignment_submissions SET score=?,feedback=?,graded_by=?,graded_at=NOW(),status='graded',is_graded=1,updated_at=NOW() WHERE id=? AND user_id=?";
            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) { http_response_code(500); echo json_encode(['error' => 'Failed to save grade.']); exit(); }
            $ai_grader_id = 0;
            $update_stmt->bind_param("dsiii", $score, $feedback_json, $ai_grader_id, $submission_id, $student_id);
            if ($update_stmt->execute()) {
                logActivity($conn, $student_id, "AI Graded", "Assignment #$assignment_id. Score: $score/{$submission['max_points']}");
                echo json_encode(['success' => true, 'score' => $score, 'max_points' => (float)$submission['max_points'],
                    'score_percentage' => round(($score / max(1, (float)$submission['max_points'])) * 100, 1),
                    'feedback' => $aiResult['feedback'], 'strengths' => $aiResult['strengths'],
                    'areas_for_improvement' => $aiResult['areas_for_improvement'], 'per_question' => $aiResult['per_question'] ?? []]);
            } else {
                http_response_code(500); echo json_encode(['error' => 'Failed to save result.']);
            }
            $update_stmt->close();
        } catch (Exception $e) {
            error_log("AI Grading Error: " . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'AI grading failed: ' . $e->getMessage()]);
        }
        exit();
    }

    // Save single answer
    if ($_POST['action'] === 'save_answer') {
        $assignment_id   = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        $question_index  = filter_input(INPUT_POST, 'question_index', FILTER_VALIDATE_INT);
        $answer_text     = trim($_POST['answer_text'] ?? '');
        if (!$assignment_id || $question_index === null) { http_response_code(400); echo json_encode(['error' => 'Missing parameters.']); exit(); }
        $check_stmt = $conn->prepare("SELECT id, answers FROM assignment_submissions WHERE assignment_id=? AND user_id=?");
        $check_stmt->bind_param("ii", $assignment_id, $student_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        $existing_answers = $existing ? (json_decode($existing['answers'] ?? '[]', true) ?: []) : [];
        $existing_answers[$question_index] = ['answer' => $answer_text];
        $answers_json = json_encode($existing_answers, JSON_UNESCAPED_UNICODE);
        if ($existing) {
            $s = $conn->prepare("UPDATE assignment_submissions SET answers=?,submission_text=?,updated_at=NOW() WHERE id=?");
            $s->bind_param("ssi", $answers_json, $answer_text, $existing['id']);
            $s->execute(); $s->close();
        } else {
            $s = $conn->prepare("INSERT INTO assignment_submissions (assignment_id,user_id,answers,submission_text,status,submission_date,created_at) VALUES (?,?,?,?,'submitted',NOW(),NOW())");
            $s->bind_param("iiss", $assignment_id, $student_id, $answers_json, $answer_text);
            $s->execute(); $s->close();
        }
        echo json_encode(['success' => true]);
        exit();
    }

    // Submit all answers
    if ($_POST['action'] === 'submit_all') {
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        if (!$assignment_id) { http_response_code(400); echo json_encode(['error' => 'Missing assignment ID.']); exit(); }
        $s = $conn->prepare("UPDATE assignment_submissions SET status='submitted',submission_date=NOW(),updated_at=NOW() WHERE assignment_id=? AND user_id=?");
        $s->bind_param("ii", $assignment_id, $student_id);
        $s->execute(); $s->close();
        echo json_encode(['success' => true]);
        exit();
    }

    // ==================== NEW: Reopen submission for editing ====================
    // Allows a student to edit their submitted (but not yet graded) assignment.
    // Resets status back to 'pending' so all answer textareas become editable again.
    if ($_POST['action'] === 'reopen_submission') {
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
        if (!$assignment_id || !$submission_id) { http_response_code(400); echo json_encode(['error' => 'Missing parameters.']); exit(); }

        // Safety: only reopen if NOT yet graded
        $check_stmt = $conn->prepare("SELECT id, is_graded FROM assignment_submissions WHERE id=? AND user_id=? AND assignment_id=?");
        if (!$check_stmt) { http_response_code(500); echo json_encode(['error' => 'Database error.']); exit(); }
        $check_stmt->bind_param("iii", $submission_id, $student_id, $assignment_id);
        $check_stmt->execute();
        $row = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Submission not found.']); exit(); }
        if ($row['is_graded'] == 1) { http_response_code(403); echo json_encode(['error' => 'This submission has already been graded and cannot be edited.']); exit(); }

        $reopen_stmt = $conn->prepare("UPDATE assignment_submissions SET status='pending', submission_date=NULL, updated_at=NOW() WHERE id=? AND user_id=?");
        if (!$reopen_stmt) { http_response_code(500); echo json_encode(['error' => 'Failed to reopen submission.']); exit(); }
        $reopen_stmt->bind_param("ii", $submission_id, $student_id);
        if ($reopen_stmt->execute()) {
            logActivity($conn, $student_id, "Reopen Submission", "Student reopened submission for assignment #$assignment_id for editing");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500); echo json_encode(['error' => 'Failed to reopen submission.']);
        }
        $reopen_stmt->close();
        exit();
    }
}

// ==================== FETCH PAGE DATA ====================
$current_term_stmt = $conn->prepare("SELECT at2.id,at2.term_name,ay.year_label FROM academic_terms at2 JOIN academic_years ay ON at2.academic_year_id=ay.id WHERE at2.start_date<=CURDATE() AND at2.end_date>=CURDATE() AND at2.is_active=1 LIMIT 1");
$current_term_info = ['term_name' => 'Current Term', 'year_label' => '2024/2025'];
if ($current_term_stmt) {
    $current_term_stmt->execute();
    $ct = $current_term_stmt->get_result()->fetch_assoc();
    if ($ct) $current_term_info = $ct;
    $current_term_stmt->close();
}
$academic_year = $current_term_info['year_label'] ?? '2024/2025';

$classes_stmt = $conn->prepare("SELECT DISTINCT c.id,c.class_name FROM classes c JOIN student_classes sc ON c.id=sc.class_id WHERE sc.user_id=? AND sc.academic_year=?");
$classes_stmt->bind_param("is", $student_id, $academic_year);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

$subjects_stmt = $conn->prepare("SELECT DISTINCT s.id,s.subject_name FROM subjects s JOIN class_schedules cs ON s.id=cs.subject_id JOIN student_classes sc ON cs.class_id=sc.class_id WHERE sc.user_id=? AND cs.academic_year=?");
$subjects_stmt->bind_param("is", $student_id, $academic_year);
$subjects_stmt->execute();
$subjects = $subjects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subjects_stmt->close();

// Filters
$search         = trim(htmlspecialchars(filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW) ?? ''));
$class_filter   = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$subject_filter = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: 0;
$type_filter    = trim(htmlspecialchars(filter_input(INPUT_GET, 'type', FILTER_UNSAFE_RAW) ?? ''));

$where_conditions = ["(sc.user_id = ? OR gm.user_id = ?)"];
$params = [$student_id, $student_id];
$types  = "ii";
if ($search) { $where_conditions[] = "(a.title LIKE ? OR a.description LIKE ?)"; $sp = "%$search%"; $params[] = $sp; $params[] = $sp; $types .= "ss"; }
if ($class_filter) { $where_conditions[] = "a.class_id = ?"; $params[] = $class_filter; $types .= "i"; }
if ($subject_filter) { $where_conditions[] = "a.subject_id = ?"; $params[] = $subject_filter; $types .= "i"; }
if ($type_filter && in_array($type_filter, ['quiz','test','homework','classwork','group_work','project'])) { $where_conditions[] = "a.type = ?"; $params[] = $type_filter; $types .= "s"; }

$where_clause = implode(" AND ", $where_conditions);
$assignments_sql = "SELECT DISTINCT a.id,a.title,a.description,a.type,a.max_points,a.due_date,a.status,a.is_group_assignment,a.group_id,a.file_path,a.link_url,a.tags,a.questions,a.created_at,a.updated_at,c.class_name,s.subject_name,g.name as group_name,sub.id as submission_id,sub.status as submission_status,sub.score,sub.feedback,sub.file_paths as submission_files,sub.answers as submission_answers,sub.submission_text,sub.submission_date,sub.is_graded,sub.graded_at FROM assignments a LEFT JOIN classes c ON a.class_id=c.id LEFT JOIN subjects s ON a.subject_id=s.id LEFT JOIN student_classes sc ON a.class_id=sc.class_id LEFT JOIN group_members gm ON a.group_id=gm.group_id LEFT JOIN groups g ON a.group_id=g.id LEFT JOIN assignment_submissions sub ON a.id=sub.assignment_id AND (sub.user_id=? OR sub.group_id=a.group_id) WHERE a.status!='draft' AND $where_clause ORDER BY a.due_date ASC";
$params[] = $student_id; $types .= "i";
$assignments_stmt = $conn->prepare($assignments_sql);
if (!$assignments_stmt) { error_log("Prepare failed: " . $conn->error); $assignments = []; }
else {
    $assignments_stmt->bind_param($types, ...$params);
    $assignments_stmt->execute();
    $assignments = $assignments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $assignments_stmt->close();
}

// Organize
$now = time();
$overdue_assignments = $pending_assignments = $submitted_assignments = $graded_assignments = [];
foreach ($assignments as $a) {
    $status = $a['submission_status'] ?? 'pending';
    $due_ts = $a['due_date'] ? strtotime($a['due_date']) : null;
    if ($status === 'graded') { $graded_assignments[] = $a; }
    elseif ($status === 'submitted') { $submitted_assignments[] = $a; }
    elseif ($status === 'pending' || $status === null) {
        if ($due_ts && $due_ts < $now) { $a['days_overdue'] = ceil(($now - $due_ts) / 86400); $overdue_assignments[] = $a; }
        else { if ($due_ts) $a['days_remaining'] = ceil(($due_ts - $now) / 86400); $pending_assignments[] = $a; }
    } else { $submitted_assignments[] = $a; }
}
usort($overdue_assignments, fn($a,$b) => ($b['days_overdue']??0)-($a['days_overdue']??0));
usort($pending_assignments, fn($a,$b) => ($a['days_remaining']??999)-($b['days_remaining']??999));

$total_assignments = count($assignments);
$total_score = 0; $graded_with_score = 0;
foreach ($graded_assignments as $ga) { if ($ga['score'] !== null) { $total_score += (float)$ga['score']; $graded_with_score++; } }
$avg_score = $graded_with_score > 0 ? round($total_score / $graded_with_score, 1) : 0;
$completion_rate = $total_assignments > 0 ? round((count($submitted_assignments) + count($graded_assignments)) / $total_assignments * 100) : 0;

$type_labels = [
    'quiz'       => ['label' => 'Quiz',       'emoji' => '❓', 'color' => '#8b5cf6'],
    'test'       => ['label' => 'Test',       'emoji' => '📝', 'color' => '#ef4444'],
    'homework'   => ['label' => 'Homework',   'emoji' => '🏠', 'color' => '#3b82f6'],
    'classwork'  => ['label' => 'Classwork',  'emoji' => '✏️',  'color' => '#10b981'],
    'group_work' => ['label' => 'Group Work', 'emoji' => '👥', 'color' => '#f59e0b'],
    'project'    => ['label' => 'Project',    'emoji' => '🚀', 'color' => '#6366f1'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Assignments | QueensPa School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
/* ===== DESIGN SYSTEM ===== */
:root {
  --brand:    #4f46e5;
  --brand-lt: #eef2ff;
  --success:  #16a34a;
  --warn:     #d97706;
  --danger:   #dc2626;
  --info:     #0284c7;
  --surface:  #ffffff;
  --bg:       #f0f4ff;
  --border:   #e2e8f0;
  --text:     #1e293b;
  --muted:    #64748b;
  --radius:   16px;
  --shadow:   0 4px 24px -4px rgba(0,0,0,.10);
  --shadow-lg: 0 12px 40px -8px rgba(0,0,0,.18);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:15px}
h1,h2,h3,h4{font-family:'Nunito',sans-serif}
a{color:var(--brand);text-decoration:none}

/* ===== LAYOUT ===== */
.page-wrap{max-width:1100px;margin:0 auto;padding:24px 16px 60px}

/* ===== PAGE HEADER ===== */
.page-hero{background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);border-radius:var(--radius);padding:28px 32px;color:#fff;margin-bottom:24px;display:flex;align-items:center;gap:20px;position:relative;overflow:hidden}
.page-hero::before{content:'📚';position:absolute;right:24px;top:50%;transform:translateY(-50%);font-size:72px;opacity:.15}
.page-hero h1{font-size:1.8rem;font-weight:900;letter-spacing:-.5px}
.page-hero p{opacity:.85;margin-top:4px}
.hero-avatar{width:56px;height:56px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0}

/* ===== STAT BAR ===== */
.stat-bar{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px}
.stat-card{background:var(--surface);border-radius:14px;padding:16px 12px;text-align:center;border:2px solid transparent;cursor:pointer;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.stat-card .num{font-family:'Nunito',sans-serif;font-size:1.8rem;font-weight:900;line-height:1}
.stat-card .lbl{font-size:11px;color:var(--muted);margin-top:5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.stat-card.s-red{border-color:#fecaca}.stat-card.s-red .num{color:var(--danger)}
.stat-card.s-yellow{border-color:#fde68a}.stat-card.s-yellow .num{color:var(--warn)}
.stat-card.s-blue{border-color:#bfdbfe}.stat-card.s-blue .num{color:var(--info)}
.stat-card.s-green{border-color:#bbf7d0}.stat-card.s-green .num{color:var(--success)}
.stat-card.s-purple{border-color:#ddd6fe}.stat-card.s-purple .num{color:var(--brand)}

/* ===== FILTERS ===== */
.filter-bar{background:var(--surface);border-radius:var(--radius);padding:16px 20px;margin-bottom:24px;box-shadow:var(--shadow);display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filter-bar input,.filter-bar select{flex:1;min-width:150px;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;background:#f8fafc;font-family:'DM Sans',sans-serif;color:var(--text);outline:none;transition:border-color .2s}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--brand);background:#fff}
.filter-bar label{font-size:13px;font-weight:600;color:var(--muted);white-space:nowrap}

/* ===== SECTION ===== */
.section{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.section-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;cursor:pointer;user-select:none;border-bottom:1.5px solid var(--border)}
.section-header:hover{background:#f8fafc}
.section-title{display:flex;align-items:center;gap:12px;font-family:'Nunito',sans-serif;font-size:1.1rem;font-weight:800}
.section-title .dot{width:12px;height:12px;border-radius:50%;flex-shrink:0}
.section-badge{font-size:13px;font-weight:700;padding:3px 12px;border-radius:20px}
.section-toggle{font-size:16px;color:var(--muted);transition:transform .3s}
.section-body{padding:0}
.section.collapsed .section-body{display:none}
.section.collapsed .section-toggle{transform:rotate(-90deg)}

/* ===== ASSIGNMENT CARD ===== */
.a-card{display:flex;align-items:center;gap:14px;padding:14px 22px;border-bottom:1px solid #f1f5f9;cursor:pointer;transition:background .15s;text-align:left;width:100%;background:none;border-left:none;border-right:none;border-top:none;font-family:inherit;color:inherit}
.a-card:last-child{border-bottom:none}
.a-card:hover{background:#f8fafc}
.a-card .type-dot{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.a-card .info{flex:1;min-width:0}
.a-card .title{font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.a-card .meta{font-size:12px;color:var(--muted);margin-top:3px;display:flex;gap:8px;flex-wrap:wrap}
.a-card .right{flex-shrink:0;text-align:right}
.a-card .tag{font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;display:inline-block}
.a-card .score-chip{font-family:'Nunito',sans-serif;font-size:1.1rem;font-weight:900}

/* Tag colors */
.tag-overdue{background:#fee2e2;color:#b91c1c}
.tag-pending{background:#fef3c7;color:#92400e}
.tag-submitted{background:#dbeafe;color:#1d4ed8}
.tag-graded{background:#dcfce7;color:#166534}

/* ===== EMPTY STATE ===== */
.empty-state{padding:32px 22px;text-align:center;color:var(--muted)}
.empty-state .icon{font-size:36px;margin-bottom:8px}
.empty-state p{font-size:14px}

/* ===== MODAL ===== */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:1000;display:flex;align-items:flex-end;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-box{background:var(--surface);width:100%;max-width:700px;max-height:96vh;border-radius:24px 24px 0 0;overflow:hidden;display:flex;flex-direction:column;transform:translateY(60px);transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
.modal-overlay.open .modal-box{transform:translateY(0)}
@media(min-width:640px){
  .modal-overlay{align-items:center}
  .modal-box{border-radius:24px;max-height:90vh}
}
.modal-header{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:20px 24px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-shrink:0}
.modal-header h2{font-family:'Nunito',sans-serif;font-size:1.2rem;font-weight:900;line-height:1.3}
.modal-header .sub{font-size:12px;opacity:.8;margin-top:4px}
.modal-close{background:rgba(255,255,255,.2);border:none;color:#fff;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s}
.modal-close:hover{background:rgba(255,255,255,.35)}
.modal-body{flex:1;overflow-y:auto;padding:20px 24px}
.modal-footer{padding:16px 24px;border-top:1.5px solid var(--border);flex-shrink:0;display:flex;gap:10px;justify-content:flex-end}

/* ===== DETAIL BLOCKS ===== */
.detail-block{background:#f8fafc;border-radius:12px;padding:16px;margin-bottom:14px}
.detail-block h4{font-family:'Nunito',sans-serif;font-size:13px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.detail-block p,.detail-block .content{font-size:14px;line-height:1.7;color:var(--text)}

/* ===== EDIT NOTICE BANNER ===== */
.edit-notice{background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #f59e0b;border-radius:12px;padding:14px 16px;margin-bottom:14px;display:flex;align-items:center;gap:12px}
.edit-notice .edit-icon{font-size:22px;flex-shrink:0}
.edit-notice .edit-text{font-size:13px;color:#78350f;line-height:1.5}
.edit-notice .edit-text strong{display:block;font-size:14px;margin-bottom:2px}

/* ===== PROGRESS BAR ===== */
.progress-row{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.progress-bar{flex:1;height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.progress-fill{height:100%;border-radius:99px;transition:width .6s ease}

/* ===== QUESTION UI ===== */
.q-steps{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px}
.q-step{width:36px;height:36px;border-radius:10px;border:2px solid var(--border);background:#f8fafc;font-weight:700;font-size:13px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;color:var(--muted)}
.q-step:hover{border-color:var(--brand);color:var(--brand)}
.q-step.active{background:var(--brand);border-color:var(--brand);color:#fff}
.q-step.done{background:#dcfce7;border-color:#16a34a;color:#16a34a}
.q-panel{background:linear-gradient(135deg,#eef2ff,#f5f3ff);border-radius:14px;padding:20px;margin-bottom:14px}
.q-number{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;background:var(--brand);color:#fff;border-radius:50%;font-weight:800;font-size:13px;margin-right:10px;flex-shrink:0}
.q-text{font-weight:700;font-size:15px;line-height:1.5;display:flex;align-items:flex-start}
.q-pts{font-size:12px;color:var(--brand);font-weight:700;margin-top:6px;margin-left:40px}
.q-answer{width:100%;padding:12px 16px;border:2px solid var(--border);border-radius:12px;font-family:'DM Sans',sans-serif;font-size:14px;resize:none;margin-top:14px;background:#fff;outline:none;transition:border-color .2s;line-height:1.7}
.q-answer:focus{border-color:var(--brand);box-shadow:0 0 0 4px rgba(79,70,229,.1)}
.q-answer:disabled{background:#f1f5f9;color:var(--muted)}
.q-saved{font-size:12px;margin-top:6px;display:flex;align-items:center;gap:5px}

/* ===== SCORE DISPLAY ===== */
.score-big{text-align:center;padding:20px}
.score-big .num{font-family:'Nunito',sans-serif;font-size:3.5rem;font-weight:900;line-height:1}
.score-big .label{font-size:13px;color:var(--muted);margin-top:6px}

/* ===== AI GRADE RESULT ===== */
.fb-card{border-radius:12px;padding:14px 16px;margin-bottom:10px;font-size:14px;line-height:1.7}
.fb-card h5{font-weight:800;margin-bottom:6px;font-size:13px}
.fb-overall{background:#eef2ff;border-left:4px solid var(--brand)}
.fb-strength{background:#dcfce7;border-left:4px solid #16a34a}
.fb-improve{background:#fef3c7;border-left:4px solid var(--warn)}
.fb-q{background:#f8fafc;border-left:3px solid var(--border)}

/* ===== BUTTONS ===== */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;border:none;transition:all .2s;font-family:'DM Sans',sans-serif}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-primary{background:var(--brand);color:#fff}.btn-primary:hover{background:#4338ca}
.btn-success{background:var(--success);color:#fff}.btn-success:hover{background:#15803d}
.btn-danger{background:var(--danger);color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-ghost{background:#f1f5f9;color:var(--text)}.btn-ghost:hover{background:#e2e8f0}
.btn-ai{background:linear-gradient(135deg,#7c3aed,#db2777);color:#fff}.btn-ai:hover{opacity:.9}
.btn-edit{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff}.btn-edit:hover{opacity:.9}
.btn-sm{padding:7px 14px;font-size:13px;border-radius:8px}
.btn-full{width:100%;justify-content:center}

/* ===== TOAST ===== */
.toast-wrap{position:fixed;bottom:20px;right:20px;display:flex;flex-direction:column;gap:8px;z-index:9999}
.toast{background:#1e293b;color:#fff;padding:12px 18px;border-radius:12px;font-size:14px;font-weight:600;box-shadow:var(--shadow-lg);display:flex;align-items:center;gap:10px;animation:slideIn .3s ease;max-width:320px}
.toast.t-success{background:#166534;border-left:4px solid #4ade80}
.toast.t-error{background:#991b1b;border-left:4px solid #f87171}
.toast.t-info{background:#1e40af;border-left:4px solid #60a5fa}
.toast.t-warn{background:#92400e;border-left:4px solid #fbbf24}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes fadeOut{to{opacity:0;transform:translateX(60px)}}

/* ===== SPINNER ===== */
.spinner-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);z-index:9000;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;color:#fff}
.spinner{width:48px;height:48px;border:5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}
.spinner-overlay p{font-family:'Nunito',sans-serif;font-size:1rem;font-weight:700}
@keyframes spin{to{transform:rotate(360deg)}}

/* ===== NAV TABS ===== */
.nav-tabs{display:flex;gap:4px;margin-bottom:6px;border-bottom:2px solid var(--border);padding-bottom:0}
.nav-tab{padding:9px 18px;font-weight:700;font-size:14px;color:var(--muted);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;background:none;border-top:none;border-left:none;border-right:none;font-family:'DM Sans',sans-serif}
.nav-tab.active{color:var(--brand);border-bottom-color:var(--brand)}

/* ===== RESPONSIVE ===== */
@media(max-width:600px){
  .stat-bar{grid-template-columns:repeat(3,1fr)}
  .stat-bar .s-purple{grid-column:1/-1}
  .filter-bar{gap:8px}
  .filter-bar input,.filter-bar select{min-width:100%;flex:none}
  .page-hero h1{font-size:1.4rem}
  .page-hero::before{display:none}
}
</style>
</head>
<body>

<!-- Spinner -->
<div class="spinner-overlay" id="spinner" style="display:none">
  <div class="spinner"></div>
  <p id="spinner-msg">Please wait…</p>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toast-wrap"></div>

<?php
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
if (file_exists($header_path)) include $header_path;
?>

<div class="page-wrap">

  <!-- Hero -->
  <div class="page-hero">
    <div class="hero-avatar">🎓</div>
    <div>
      <h1>My Assignments</h1>
      <p>Hello, <strong><?= htmlspecialchars($student_name) ?></strong> — here's your work overview</p>
    </div>
  </div>

  <!-- Stats -->
  <div class="stat-bar">
    <div class="stat-card s-red" onclick="scrollTo('overdue')">
      <div class="num"><?= count($overdue_assignments) ?></div>
      <div class="lbl">⚠ Overdue</div>
    </div>
    <div class="stat-card s-yellow" onclick="scrollTo('pending')">
      <div class="num"><?= count($pending_assignments) ?></div>
      <div class="lbl">⏳ Pending</div>
    </div>
    <div class="stat-card s-blue" onclick="scrollTo('submitted')">
      <div class="num"><?= count($submitted_assignments) ?></div>
      <div class="lbl">📤 Submitted</div>
    </div>
    <div class="stat-card s-green" onclick="scrollTo('graded')">
      <div class="num"><?= count($graded_assignments) ?></div>
      <div class="lbl">✅ Graded</div>
    </div>
    <div class="stat-card s-purple">
      <div class="num"><?= $graded_with_score > 0 ? $avg_score : '—' ?></div>
      <div class="lbl">⭐ Avg Score</div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="filter-bar">
    <label><i class="fas fa-search" style="color:var(--muted)"></i></label>
    <input type="text" id="f-search" placeholder="Search assignments…" value="<?= htmlspecialchars($search) ?>">
    <select id="f-class">
      <option value="">All Classes</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $class_filter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="f-subject">
      <option value="">All Subjects</option>
      <?php foreach ($subjects as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $subject_filter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['subject_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="f-type">
      <option value="">All Types</option>
      <?php foreach ($type_labels as $val => $info): ?>
        <option value="<?= $val ?>" <?= $type_filter === $val ? 'selected' : '' ?>><?= $info['emoji'] ?> <?= $info['label'] ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php
  // Helper to render a card
  function renderCard($a, $statusTag, $type_labels) {
    $ti = $type_labels[$a['type']] ?? ['label' => ucfirst($a['type']), 'emoji' => '📄', 'color' => '#64748b'];
    $due = $a['due_date'] ? date('M j, Y', strtotime($a['due_date'])) : 'No due date';
    $pts = number_format((float)$a['max_points'], 1);
    ob_start();
    ?>
    <button class="a-card" onclick="openDetail(<?= $a['id'] ?>)">
      <div class="type-dot" style="background:<?= $ti['color'] ?>22"><?= $ti['emoji'] ?></div>
      <div class="info">
        <div class="title"><?= htmlspecialchars($a['title']) ?></div>
        <div class="meta">
          <?php if ($a['subject_name']): ?>
            <span>📚 <?= htmlspecialchars($a['subject_name']) ?></span>
          <?php endif; ?>
          <span>🗓 <?= $due ?></span>
          <span>⭐ <?= $pts ?> pts</span>
          <?php if (!empty($a['class_name'])): ?><span>🏫 <?= htmlspecialchars($a['class_name']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="right">
        <?php
        if ($statusTag === 'overdue') {
            echo '<span class="tag tag-overdue">' . $a['days_overdue'] . 'd overdue</span>';
        } elseif ($statusTag === 'pending') {
            if (isset($a['days_remaining'])) echo '<span class="tag tag-pending">' . $a['days_remaining'] . 'd left</span>';
        } elseif ($statusTag === 'submitted') {
            echo '<span class="tag tag-submitted">Submitted</span>';
        } elseif ($statusTag === 'graded' && $a['score'] !== null) {
            $pct = round(($a['score'] / max(1,$a['max_points'])) * 100);
            $col = $pct >= 70 ? 'var(--success)' : ($pct >= 50 ? 'var(--warn)' : 'var(--danger)');
            echo '<span class="score-chip" style="color:'.$col.'">'.number_format((float)$a['score'],1).'/'.$pts.'</span>';
        }
        ?>
      </div>
    </button>
    <?php
    return ob_get_clean();
  }
  ?>

  <!-- OVERDUE Section -->
  <div class="section" id="overdue">
    <div class="section-header" onclick="toggleSection(this)">
      <div class="section-title">
        <div class="dot" style="background:#ef4444"></div>
        Overdue
        <span class="section-badge" style="background:#fee2e2;color:#b91c1c"><?= count($overdue_assignments) ?></span>
      </div>
      <span class="section-toggle"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="section-body">
      <?php if (empty($overdue_assignments)): ?>
        <div class="empty-state"><div class="icon">🎉</div><p>No overdue assignments!</p></div>
      <?php else: foreach ($overdue_assignments as $a) echo renderCard($a, 'overdue', $type_labels); endif; ?>
    </div>
  </div>

  <!-- PENDING Section -->
  <div class="section" id="pending">
    <div class="section-header" onclick="toggleSection(this)">
      <div class="section-title">
        <div class="dot" style="background:#f59e0b"></div>
        Pending
        <span class="section-badge" style="background:#fef3c7;color:#92400e"><?= count($pending_assignments) ?></span>
      </div>
      <span class="section-toggle"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="section-body">
      <?php if (empty($pending_assignments)): ?>
        <div class="empty-state"><div class="icon">✅</div><p>All caught up! No pending work.</p></div>
      <?php else: foreach ($pending_assignments as $a) echo renderCard($a, 'pending', $type_labels); endif; ?>
    </div>
  </div>

  <!-- SUBMITTED Section -->
  <div class="section" id="submitted">
    <div class="section-header" onclick="toggleSection(this)">
      <div class="section-title">
        <div class="dot" style="background:#0284c7"></div>
        Submitted — Awaiting Grade
        <span class="section-badge" style="background:#dbeafe;color:#1d4ed8"><?= count($submitted_assignments) ?></span>
      </div>
      <span class="section-toggle"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="section-body">
      <?php if (empty($submitted_assignments)): ?>
        <div class="empty-state"><div class="icon">📤</div><p>Nothing submitted yet.</p></div>
      <?php else: foreach ($submitted_assignments as $a) echo renderCard($a, 'submitted', $type_labels); endif; ?>
    </div>
  </div>

  <!-- GRADED Section -->
  <div class="section" id="graded">
    <div class="section-header" onclick="toggleSection(this)">
      <div class="section-title">
        <div class="dot" style="background:#16a34a"></div>
        Graded
        <span class="section-badge" style="background:#dcfce7;color:#166634"><?= count($graded_assignments) ?></span>
      </div>
      <span class="section-toggle"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="section-body">
      <?php if (empty($graded_assignments)): ?>
        <div class="empty-state"><div class="icon">📊</div><p>No grades yet.</p></div>
      <?php else: foreach ($graded_assignments as $a) echo renderCard($a, 'graded', $type_labels); endif; ?>
    </div>
  </div>

</div><!-- /page-wrap -->

<!-- ==================== MODAL ==================== -->
<div class="modal-overlay" id="modal-overlay" onclick="handleOverlayClick(event)">
  <div class="modal-box" id="modal-box">
    <div class="modal-header">
      <div>
        <h2 id="m-title">Assignment</h2>
        <div class="sub" id="m-sub"></div>
      </div>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="m-body">
      <div style="text-align:center;padding:40px">
        <div class="spinner" style="margin:0 auto;border-color:rgba(79,70,229,.2);border-top-color:var(--brand)"></div>
        <p style="margin-top:14px;color:var(--muted)">Loading…</p>
      </div>
    </div>
    <div class="modal-footer" id="m-footer" style="display:none"></div>
  </div>
</div>

<?php
$footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php';
if (file_exists($footer_path)) include $footer_path;
mysqli_close($conn);
ob_end_flush();
?>

<script>
// ===== DATA =====
const ALL_DATA    = <?= json_encode($assignments, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const CSRF        = '<?= $csrf_token ?>';
const PAGE_URL    = window.location.pathname + window.location.search;
const TYPE_LABELS = <?= json_encode($type_labels) ?>;

let activeAssignmentId  = null;
let activeSubmissionId  = null;
let activeQuestions     = [];
let activeAnswers       = {};
let activeQuestionIdx   = 0;
let activeStatus        = 'pending';

// Track whether the student chose to edit a submitted assignment in this session.
// When true, the modal behaves as if status === 'pending' (editable textareas, save/submit buttons).
let isEditingSubmitted  = false;

let autoSaveTimer       = null;

// ===== TOAST =====
function toast(msg, type = 'info') {
  const icons = { success: '✅', error: '❌', warn: '⚠️', info: 'ℹ️' };
  const el = document.createElement('div');
  el.className = `toast t-${type}`;
  el.innerHTML = `<span>${icons[type]||'ℹ️'}</span><span>${msg}</span>`;
  document.getElementById('toast-wrap').appendChild(el);
  setTimeout(() => { el.style.animation = 'fadeOut .4s forwards'; setTimeout(() => el.remove(), 400); }, 3800);
}

// ===== SPINNER =====
function showSpinner(msg = 'Please wait…') { document.getElementById('spinner-msg').textContent = msg; document.getElementById('spinner').style.display = 'flex'; }
function hideSpinner() { document.getElementById('spinner').style.display = 'none'; }

// ===== SECTION TOGGLE =====
function toggleSection(header) { header.closest('.section').classList.toggle('collapsed'); }

// ===== SCROLL UTIL =====
function scrollTo(id) { document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

// ===== FILTERS =====
function applyFilters() {
  const p = new URLSearchParams();
  const s = document.getElementById('f-search').value.trim();
  const c = document.getElementById('f-class').value;
  const sub = document.getElementById('f-subject').value;
  const t = document.getElementById('f-type').value;
  if (s) p.set('search', s); if (c) p.set('class_id', c); if (sub) p.set('subject_id', sub); if (t) p.set('type', t);
  window.location.href = '?' + p.toString();
}
let searchTimer;
document.getElementById('f-search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 500); });
['f-class','f-subject','f-type'].forEach(id => document.getElementById(id).addEventListener('change', applyFilters));

// ===== OPEN DETAIL =====
function openDetail(id) {
  activeAssignmentId = id;
  activeQuestionIdx  = 0;
  activeAnswers      = {};
  isEditingSubmitted = false;   // reset edit mode on each open

  const data = ALL_DATA.find(a => a.id == id);
  if (!data) { toast('Assignment not found.', 'error'); return; }

  document.getElementById('m-title').textContent = data.title || 'Assignment';
  const ti = TYPE_LABELS[data.type] || { label: data.type, emoji: '📄' };
  document.getElementById('m-sub').textContent = `${ti.emoji} ${ti.label}  •  ${data.subject_name || ''}  •  ${data.max_points} pts`;

  const overlay = document.getElementById('modal-overlay');
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';

  // Parse questions
  let qs = [];
  try { qs = data.questions ? (typeof data.questions === 'string' ? JSON.parse(data.questions) : data.questions) : []; } catch(e) {}
  if (!Array.isArray(qs)) qs = [];
  activeQuestions = qs;

  // Parse saved answers
  if (data.submission_answers) {
    try { activeAnswers = typeof data.submission_answers === 'string' ? JSON.parse(data.submission_answers) : data.submission_answers; } catch(e) {}
  }
  activeStatus       = data.submission_status || 'pending';
  activeSubmissionId = data.submission_id || null;

  renderModal(data);
}

// ===== EFFECTIVE STATUS (accounts for in-session edit mode) =====
// When student clicks "Edit Answers" on a submitted assignment, isEditingSubmitted = true
// and we render the modal exactly as if status were 'pending'.
function effectiveStatus() {
  if (isEditingSubmitted && activeStatus === 'submitted') return 'pending';
  return activeStatus;
}

function renderModal(data) {
  const body   = document.getElementById('m-body');
  const footer = document.getElementById('m-footer');
  const status = effectiveStatus(); // use effective (possibly edit-mode) status
  let html = '';

  // ---- Edit-mode notice banner (shown only when actively editing a submitted assignment) ----
  if (isEditingSubmitted) {
    html += `<div class="edit-notice">
      <div class="edit-icon">✏️</div>
      <div class="edit-text">
        <strong>Editing your submission</strong>
        Update your answers below and press <em>Re-submit</em> when done. You can still request AI grading afterwards.
      </div>
    </div>`;
  }

  // ---- Description ----
  if (data.description) {
    html += `<div class="detail-block"><h4>📋 Description</h4><div class="content">${escH(data.description).replace(/\n/g,'<br>')}</div></div>`;
  }

  // ---- Due date / resources ----
  let metaHtml = '';
  if (data.due_date) metaHtml += `<span style="margin-right:16px">🗓 Due: <strong>${new Date(data.due_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</strong></span>`;
  if (data.file_path) metaHtml += `<a href="${escH(data.file_path)}" target="_blank" class="btn btn-ghost btn-sm">📎 Download File</a> `;
  if (data.link_url)  metaHtml += `<a href="${escH(data.link_url)}"  target="_blank" class="btn btn-ghost btn-sm">🔗 Open Link</a>`;
  if (metaHtml) html += `<div class="detail-block"><h4>ℹ️ Info</h4><div class="content">${metaHtml}</div></div>`;

  // ---- Graded result ----
  if (activeStatus === 'graded') {
    const score = data.score !== null ? parseFloat(data.score) : null;
    const maxPts = parseFloat(data.max_points);
    const pct = score !== null ? Math.round((score / maxPts) * 100) : null;
    const col = pct >= 70 ? 'var(--success)' : (pct >= 50 ? 'var(--warn)' : 'var(--danger)');

    if (score !== null) {
      html += `<div class="score-big">
        <div class="num" style="color:${col}">${score.toFixed(1)}<span style="font-size:1.5rem;color:var(--muted)">/${maxPts.toFixed(1)}</span></div>
        <div class="label">${pct}% — ${pct>=70?'Great job! 🎉':pct>=50?'Good effort 👍':'Keep practicing 💪'}</div>
        <div style="margin-top:12px"><div class="progress-bar" style="width:220px;margin:0 auto"><div class="progress-fill" style="width:${pct}%;background:${col}"></div></div></div>
      </div>`;
    }

    let feedbackParsed = null;
    try { if (data.feedback) feedbackParsed = typeof data.feedback === 'string' ? JSON.parse(data.feedback) : data.feedback; } catch(e) {}
    if (feedbackParsed) {
      if (feedbackParsed.overall_feedback) html += `<div class="fb-card fb-overall">${feedbackParsed.ai_graded ? '<h5>🤖 AI Feedback</h5>' : '<h5>📝 Teacher Feedback</h5>'}<p>${escH(feedbackParsed.overall_feedback)}</p></div>`;
      if (feedbackParsed.strengths) html += `<div class="fb-card fb-strength"><h5>💪 Strengths</h5><p>${escH(feedbackParsed.strengths)}</p></div>`;
      if (feedbackParsed.areas_for_improvement) html += `<div class="fb-card fb-improve"><h5>📈 What to improve</h5><p>${escH(feedbackParsed.areas_for_improvement)}</p></div>`;
    }
  }

  // ---- Submitted (not yet graded) — show notice only when NOT in edit mode ----
  if (activeStatus === 'submitted' && !isEditingSubmitted) {
    html += `<div class="detail-block" style="text-align:center;padding:20px">
      <div style="font-size:40px;margin-bottom:8px">📤</div>
      <h4 style="font-size:16px;color:var(--text)">Submitted — waiting for grade</h4>
      <p style="color:var(--muted);font-size:13px;margin-top:6px">Your work is in! You can edit your answers or request AI grading below.</p>
    </div>`;
    if (data.submission_date) html += `<p style="font-size:13px;color:var(--muted);margin-bottom:14px">Submitted on ${new Date(data.submission_date).toLocaleString()}</p>`;
  }

  // ---- Questions panel ----
  // Show questions if: pending/editing, submitted (read-only preview), or graded (read-only with feedback)
  if (activeQuestions.length > 0) {
    html += buildQuestionsPanel(status);
  } else if (status === 'pending') {
    // Free-text answer box — pre-fill with saved text
    html += `<div class="detail-block" style="background:#fff;border:1.5px solid var(--border)">
      <h4>📝 Your Answer</h4>
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">This assignment has no structured questions. Write your full answer below.</p>
      <textarea id="free-text-answer" class="q-answer" rows="6" placeholder="Write your answer here…">${escH(data.submission_text || '')}</textarea>
    </div>`;
  }

  body.innerHTML = html;

  // ---- Footer buttons ----
  footer.innerHTML = '';
  footer.style.display = 'flex';

  if (status === 'pending') {
    // Pending OR editing a submitted assignment — show save / submit controls
    if (activeQuestions.length > 0) {
      // Label the submit button differently when re-submitting after edit
      const submitLabel = isEditingSubmitted
        ? '<i class="fas fa-paper-plane"></i> Re-submit'
        : '<i class="fas fa-paper-plane"></i> Submit All';
      footer.innerHTML = `
        <button class="btn btn-ghost" id="btn-prev" onclick="qNav(-1)" disabled><i class="fas fa-arrow-left"></i> Prev</button>
        <button class="btn btn-primary" onclick="saveCurrentAnswer()"><i class="fas fa-save"></i> Save Answer</button>
        <button class="btn btn-ghost" id="btn-next" onclick="qNav(1)">Next <i class="fas fa-arrow-right"></i></button>
        <button class="btn btn-success" onclick="submitAll()">${submitLabel}</button>
      `;
      updateQNav();
    } else {
      const submitLabel = isEditingSubmitted
        ? '<i class="fas fa-paper-plane"></i> Re-submit Answer'
        : '<i class="fas fa-paper-plane"></i> Submit Answer';
      footer.innerHTML = `<button class="btn btn-success btn-full" onclick="submitFreeText(${data.id})">${submitLabel}</button>`;
    }
  } else if (activeStatus === 'submitted') {
    // Submitted, not graded, not in edit mode
    // Show BOTH "Edit Answers" and "Get AI Grade" buttons
    if (activeQuestions.length > 0) {
      footer.innerHTML = `
        <button class="btn btn-ghost" id="btn-prev" onclick="qNav(-1)" disabled><i class="fas fa-arrow-left"></i> Prev</button>
        <button class="btn btn-ghost" id="btn-next" onclick="qNav(1)">Next <i class="fas fa-arrow-right"></i></button>
        <button class="btn btn-edit" onclick="startEditMode()"><i class="fas fa-pencil-alt"></i> Edit Answers</button>
        <button class="btn btn-ai" onclick="requestAIGrade(${activeSubmissionId}, ${activeAssignmentId})"><i class="fas fa-robot"></i> Get AI Grade</button>
      `;
      updateQNav();
    } else {
      footer.innerHTML = `
        <button class="btn btn-edit" onclick="startEditMode()"><i class="fas fa-pencil-alt"></i> Edit Answers</button>
        <button class="btn btn-ai" onclick="requestAIGrade(${activeSubmissionId}, ${activeAssignmentId})"><i class="fas fa-robot"></i> Get AI Grade</button>
      `;
    }
  } else if (activeStatus === 'graded') {
    footer.innerHTML = `<button class="btn btn-ghost" onclick="closeModal()"><i class="fas fa-times"></i> Close</button>`;
    if (activeQuestions.length > 0) {
      footer.innerHTML = `
        <button class="btn btn-ghost" id="btn-prev" onclick="qNav(-1)" disabled><i class="fas fa-arrow-left"></i> Prev</button>
        <button class="btn btn-ghost" id="btn-next" onclick="qNav(1)">Next <i class="fas fa-arrow-right"></i></button>
        <button class="btn btn-ghost" onclick="closeModal()"><i class="fas fa-times"></i> Close</button>
      `;
      updateQNav();
    }
  }

  if (activeQuestions.length > 0) showQuestion(0);
}

// ===== START EDIT MODE =====
// Called when student clicks "Edit Answers" on a submitted (ungraded) assignment.
// 1. Calls the server reopen_submission action to reset DB status to 'pending'
// 2. Flips isEditingSubmitted = true and re-renders the modal in editable form
async function startEditMode() {
  if (!activeSubmissionId || !activeAssignmentId) { toast('Cannot find submission to edit.', 'error'); return; }

  if (!confirm('Edit your submitted answers?\n\nThis will allow you to update your responses before getting a grade.\n\nYou can re-submit once you\'re happy with your changes.')) return;

  showSpinner('Reopening submission for editing…');
  try {
    const res = await $.post(location.pathname, {
      action: 'reopen_submission',
      csrf_token: CSRF,
      submission_id: activeSubmissionId,
      assignment_id: activeAssignmentId
    });
    if (res.error) throw new Error(res.error);

    // Switch to edit mode in the UI without a full page reload
    isEditingSubmitted = true;
    activeStatus = 'pending'; // treat as pending locally so save_answer & submit_all work normally

    // Also update the live data object so answer fields pre-fill correctly
    const data = ALL_DATA.find(a => a.id == activeAssignmentId);
    if (data) {
      data.submission_status = 'pending';
    }

    hideSpinner();
    toast('You can now edit your answers. Remember to re-submit when done.', 'info');

    // Re-render with editable mode
    renderModal(data || ALL_DATA.find(a => a.id == activeAssignmentId));
  } catch(e) {
    hideSpinner();
    const msg = e.responseJSON?.error || e.message || 'Unknown error';
    toast('Could not reopen submission: ' + msg, 'error');
  }
}

function buildQuestionsPanel(status) {
  const isReadOnly = status === 'submitted' || status === 'graded';
  const total = activeQuestions.length;
  let navHtml = '';
  for (let i = 0; i < total; i++) {
    const ans = activeAnswers[i]?.answer?.trim();
    const isDone = !!ans;
    navHtml += `<button class="q-step ${i === 0 ? 'active' : (isDone ? 'done' : '')}" id="qstep-${i}" onclick="showQuestion(${i})">${i+1}</button>`;
  }
  return `<div class="detail-block" style="background:#fff;border:1.5px solid var(--border)">
    <h4>❓ Questions <span style="font-weight:400;text-transform:none;font-size:12px;color:var(--muted)">(${total} total)</span></h4>
    <div class="q-steps" id="q-steps">${navHtml}</div>
    <div id="q-panel"></div>
  </div>`;
}

function showQuestion(idx) {
  if (idx < 0 || idx >= activeQuestions.length) return;
  saveCurrentAnswer(false); // silent save
  activeQuestionIdx = idx;

  // Update step nav
  document.querySelectorAll('.q-step').forEach((btn, i) => {
    btn.classList.remove('active');
    const ans = activeAnswers[i]?.answer?.trim();
    btn.classList.toggle('done', !!ans && i !== idx);
    if (i === idx) btn.classList.add('active');
  });

  const q = activeQuestions[idx];
  const qText = q.question_text || q.question || `Question ${idx + 1}`;
  const pts = q.points > 0 ? q.points : null;
  const savedAnswer = activeAnswers[idx]?.answer || '';
  // Read-only when: graded, OR submitted and NOT in edit mode
  const isReadOnly = activeStatus === 'graded' || (activeStatus === 'submitted' && !isEditingSubmitted);

  // Find per-question AI feedback if graded
  let qFeedbackHtml = '';
  const data = ALL_DATA.find(a => a.id == activeAssignmentId);
  if (data && data.feedback) {
    try {
      const fb = typeof data.feedback === 'string' ? JSON.parse(data.feedback) : data.feedback;
      if (fb.per_question && fb.per_question[idx]) {
        qFeedbackHtml = `<div class="fb-card fb-q" style="margin-top:10px"><h5>💬 Feedback for this question</h5><p>${escH(fb.per_question[idx])}</p></div>`;
      }
    } catch(e) {}
  }

  const panel = document.getElementById('q-panel');
  if (!panel) return;
  panel.innerHTML = `
    <div class="q-panel">
      <div class="q-text"><span class="q-number">${idx + 1}</span><span>${escH(qText)}</span></div>
      ${pts ? `<div class="q-pts">⭐ ${pts} points</div>` : ''}
      <textarea id="qa-input" class="q-answer" rows="5" placeholder="Type your answer here…" ${isReadOnly ? 'disabled' : ''}>${escH(savedAnswer)}</textarea>
      <div class="q-saved" id="qa-saved">
        ${savedAnswer ? '<span style="color:var(--success)">✅ Saved</span>' : '<span style="color:var(--muted)">💬 Not answered yet</span>'}
      </div>
    </div>
    ${qFeedbackHtml}
  `;

  // Auto-save on typing (only when editable)
  if (!isReadOnly) {
    const ta = document.getElementById('qa-input');
    ta.addEventListener('input', () => {
      clearTimeout(autoSaveTimer);
      autoSaveTimer = setTimeout(() => {
        saveCurrentAnswer(true);
      }, 1200);
    });
  }

  updateQNav();
}

function saveCurrentAnswer(sendToServer = true) {
  const ta = document.getElementById('qa-input');
  if (!ta || ta.disabled) return;
  const val = ta.value.trim();
  activeAnswers[activeQuestionIdx] = { answer: val };

  const saved = document.getElementById('qa-saved');
  if (saved) saved.innerHTML = val ? '<span style="color:var(--success)">✅ Saved</span>' : '<span style="color:var(--muted)">💬 Not answered yet</span>';

  // Update step indicator
  const stepBtn = document.getElementById(`qstep-${activeQuestionIdx}`);
  if (stepBtn) {
    stepBtn.classList.toggle('done', !!val);
  }

  if (sendToServer && activeAssignmentId) {
    $.post(location.pathname, {
      action: 'save_answer', csrf_token: CSRF,
      assignment_id: activeAssignmentId,
      question_index: activeQuestionIdx,
      answer_text: val
    });
  }
}

function updateQNav() {
  const prev = document.getElementById('btn-prev');
  const next = document.getElementById('btn-next');
  const total = activeQuestions.length;
  if (prev) prev.disabled = activeQuestionIdx === 0;
  if (next) {
    if (activeQuestionIdx === total - 1) { next.innerHTML = 'Done ✓'; next.disabled = true; }
    else { next.innerHTML = 'Next <i class="fas fa-arrow-right"></i>'; next.disabled = false; }
  }
}

function qNav(dir) {
  saveCurrentAnswer(true);
  showQuestion(activeQuestionIdx + dir);
}

async function submitAll() {
  saveCurrentAnswer(true);
  // Check unanswered
  const unanswered = activeQuestions.map((q, i) => i).filter(i => !activeAnswers[i]?.answer?.trim());
  if (unanswered.length > 0) {
    toast(`Please answer question(s): ${unanswered.map(i => i+1).join(', ')}`, 'warn');
    return;
  }
  const confirmMsg = isEditingSubmitted
    ? 'Re-submit your updated answers?\n\nYou can still request AI grading after re-submission.'
    : 'Submit all your answers?\n\nYou can request AI grading after submission.';
  if (!confirm(confirmMsg)) return;

  showSpinner(isEditingSubmitted ? 'Re-submitting your answers…' : 'Submitting your answers…');
  try {
    await $.post(location.pathname, { action: 'submit_all', csrf_token: CSRF, assignment_id: activeAssignmentId });
    toast(isEditingSubmitted ? 'Re-submitted! You can now request AI grading.' : 'Submitted! You can now request AI grading.', 'success');
    setTimeout(() => location.reload(), 1600);
  } catch(e) {
    toast('Submission failed. Please try again.', 'error');
    hideSpinner();
  }
}

async function submitFreeText(assignmentId) {
  const ta = document.getElementById('free-text-answer');
  if (!ta || !ta.value.trim()) { toast('Please write your answer first.', 'warn'); return; }
  const label = isEditingSubmitted ? 'Saving updated answer…' : 'Saving answer…';
  showSpinner(label);
  try {
    await $.post(location.pathname, { action: 'save_answer', csrf_token: CSRF, assignment_id: assignmentId, question_index: 0, answer_text: ta.value.trim() });
    await $.post(location.pathname, { action: 'submit_all', csrf_token: CSRF, assignment_id: assignmentId });
    toast(isEditingSubmitted ? 'Re-submitted successfully!' : 'Submitted!', 'success');
    setTimeout(() => location.reload(), 1400);
  } catch(e) {
    toast('Failed. Try again.', 'error');
    hideSpinner();
  }
}

async function requestAIGrade(submissionId, assignmentId) {
  if (!submissionId) { toast('No submission found.', 'error'); return; }
  if (!confirm('Request AI grading?\n\nAI will review your answers and give a score with feedback.')) return;

  showSpinner('AI is reviewing your answers…');
  try {
    const res = await $.post(location.pathname, { action: 'ai_grade', csrf_token: CSRF, submission_id: submissionId, assignment_id: assignmentId });
    if (res.error) throw new Error(res.error);
    toast(`AI graded! Score: ${res.score}/${res.max_points} (${res.score_percentage}%)`, 'success');
    setTimeout(() => location.reload(), 1600);
  } catch(e) {
    const msg = e.responseJSON?.error || e.message || 'Unknown error';
    toast('AI grading failed: ' + msg, 'error');
    hideSpinner();
  }
}

// ===== MODAL CLOSE =====
function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
  document.body.style.overflow = '';
  activeAssignmentId = activeSubmissionId = null;
  activeQuestions = []; activeAnswers = {};
  isEditingSubmitted = false;
}
function handleOverlayClick(e) { if (e.target === document.getElementById('modal-overlay')) closeModal(); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ===== ESCAPE HTML =====
function escH(str) {
  if (!str) return '';
  const d = document.createElement('div');
  d.textContent = String(str);
  return d.innerHTML;
}
</script>
</body>
</html>